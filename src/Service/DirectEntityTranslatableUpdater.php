<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Visibility;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpNamespace;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PhpPrinter;
use Psr\Log\LoggerInterface;

/**
 * Converts #[Translatable] public string properties to PHP 8.4 property hooks in-place.
 * - Preserves non-property members verbatim (methods, consts, traits, etc.)
 * - Copies CLASS-LEVEL attributes (e.g. #[ORM\Entity]) with arguments
 * - Resolves attribute names & interface names via existing use-aliases
 * - Normalizes class TYPES (e.g., Collection/DateTimeImmutable) to short names and adds uses
 *
 * PhpGenerator constraints:
 *  - Attribute NAMES must be UNPREFIXED (no leading '\'), e.g. 'Doctrine\ORM\Mapping\Column'
 *  - Attribute VALUES (expressions) may use leading '\', e.g. '\Doctrine\DBAL\Types\Types::TEXT'
 */
final class DirectEntityTranslatableUpdater
{
    private PsrPrinter $printer;

    /** @var array<string,string> alias => FQN (e.g. 'ORM' => 'Doctrine\ORM\Mapping') */
    private array $useMap = [];

    /** Current namespace being generated (so we can add uses on the fly) */
    private ?PhpNamespace $currentNs = null;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->printer = new PsrPrinter();
    }

    /**
     * @param array<string> $translatableFields
     */
    public function updateEntity(
        string $entityPath,
        array $translatableFields,
        bool $dryRun = false,
        bool $backup = false
    ): bool {
        if (!$translatableFields) {
            $this->logger->info('No translatable fields to convert', ['path' => $entityPath]);
            return false;
        }

        $this->logger->info('Converting entity to use property hooks', [
            'path' => $entityPath,
            'fields' => $translatableFields,
            'dryRun' => $dryRun
        ]);

        $code = @file_get_contents($entityPath);
        if ($code === false) {
            $this->logger->error('Cannot read entity file', ['path' => $entityPath]);
            return false;
        }

        try {
            return $this->convertEntityWithNette($entityPath, $code, $translatableFields, $dryRun, $backup);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to convert entity', [
                'path' => $entityPath,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException(
                "Cannot convert entity at {$entityPath}. Error: ".$e->getMessage(),
                0,
                $e
            );
        }
    }

    private function convertEntityWithNette(
        string $entityPath,
        string $code,
        array $translatableFields,
        bool $dryRun,
        bool $backup
    ): bool {
        $parser = new ParserFactory()->createForHostVersion();
        $ast = $parser->parse($code);
        if (!$ast) {
            throw new \RuntimeException('Cannot parse PHP file');
        }

        [$nsName, $className, $classNode] = $this->extractNamespaceAndClass($ast);
        if (!$classNode || !$className || !$nsName) {
            throw new \RuntimeException('Cannot find namespace and class in file');
        }

        $this->logger->info('Scanning class', [
            'fqcn' => $nsName.'\\'.$className,
            'file' => $entityPath,
        ]);

        // Prepare new file
        $file = new PhpFile();
        $file->setStrictTypes();
        $ns = $file->addNamespace($nsName);
        $this->currentNs = $ns;

        // Build alias map and re-apply existing uses
        $this->useMap = [];
        $this->copyUseStatements($ns, $code);

        $class = $ns->addClass($className);
        $this->copyClassMetadata($ns, $class, $classNode);
        $this->copyClassAttributes($class, $classNode); // class-level attributes

        $pp = new PhpPrinter();
        $verbatim = [];

        $fieldSet  = array_flip($translatableFields);
        $converted = [];
        $propCount = 0; $otherCount = 0;

        foreach ($classNode->stmts as $stmt) {
            if ($stmt instanceof Property) {
                $propCount++;
                $this->processProperty($class, $stmt, $fieldSet, $converted);
            } else {
                $otherCount++;
                $snippet = $pp->prettyPrint([$stmt]);
                if ($snippet !== '') {
                    $verbatim[] = $snippet;
                }
            }
        }

        $this->logger->debug('Members summary', [
            'properties' => $propCount,
            'others'     => $otherCount,
        ]);

        if (!$converted) {
            $this->logger->info('No translatable properties found to convert', ['path' => $entityPath]);
            return false;
        }

        $this->logger->info('Converted properties to hooks', [
            'path' => $entityPath,
            'converted' => $converted
        ]);

        $newCode = $this->printer->printFile($file);

        // Inject verbatim members back before the closing class brace
        $newCode = preg_replace('/}\s*\z/', "\n".implode("\n\n", $verbatim)."\n}\n", $newCode, 1);

        // FINAL PATCH: normalize lingering backslashes & ensure imports
        $newCode = $this->postProcessGeneratedCode($newCode);

        return $this->writeUpdatedFile($entityPath, $newCode, $dryRun, $backup);
    }

    private function processProperty(
        ClassType $class,
        Property $property,
        array $fieldSet,
        array &$converted
    ): void {
        if (!$property->isPublic()) {
            $this->copyPropertyToClass($class, $property);
            return;
        }

        $translatableProps = [];
        $regularProps = [];

        foreach ($property->props as $prop) {
            $name = $prop->name->toString();
            if (isset($fieldSet[$name]) && $this->hasTranslatableAttribute($property)) {
                $translatableProps[] = $prop;
                $converted[] = $name;
            } else {
                $regularProps[] = $prop;
            }
        }

        if ($regularProps) {
            $clone = clone $property;
            $clone->props = $regularProps;
            $this->copyPropertyToClass($class, $clone);
        }

        foreach ($translatableProps as $prop) {
            $this->createTranslatableHook($class, $property, $prop);
        }
    }

    private function createTranslatableHook(ClassType $class, Property $originalProperty, \PhpParser\Node\Stmt\PropertyProperty $prop): void
    {
        $fieldName   = $prop->name->toString();
        $backingName = $fieldName.'Backing';

        // Backing: protected ?string with Column(type: Types::TEXT, nullable: true)
        $backing = $class->addProperty($backingName)
            ->setType('?string')
            ->setVisibility(Visibility::Protected);

        if ($prop->default) {
            $backing->setValue($this->convertDefaultValue($prop->default));
        }

        // Ensure "use Doctrine\DBAL\Types\Types;" so we can emit 'Types::TEXT'
        $this->ensureTypesImport();

        $backing->addAttribute(ltrim(\Doctrine\ORM\Mapping\Column::class, '\\'), [
            'type'     => new Literal('Types::TEXT'),
            'nullable' => true,
        ]);

        // Hooked property
        $hook = $class->addProperty($fieldName)
            ->setType('?string')
            ->setVisibility(Visibility::Public);

        // copy original non-Column attributes (Groups, Assert, etc.)
        $this->copyNonColumnAttributes($hook, $originalProperty);

        // ensure Translatable attribute
        $hook->addAttribute(ltrim(\Survos\BabelBundle\Attribute\Translatable::class, '\\'));

        $hook->addHook('get')
            ->setBody('return $this->resolveTranslatable(?, $this->?, ?);', [$fieldName, $backingName, $fieldName]);
        $hook->addHook('set')
            ->setBody('$this->? = $value;', [$backingName]);

        $this->logger->debug('Created translatable hook', [
            'field'   => $fieldName,
            'backing' => $backingName,
        ]);
    }

    /**
     * Copy original property attributes except Column/Translatable onto the hook property.
     * Resolves names via alias map and preserves arguments.
     */
    private function copyNonColumnAttributes(\Nette\PhpGenerator\Property $hookProp, Property $originalProperty): void
    {
        foreach ($originalProperty->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $raw = $attr->name->toString();

                if ($this->isColumnAttr($raw) || $this->isTranslatableAttr($raw)) {
                    continue;
                }

                $resolved = $this->resolveNameUsingAliases($raw); // UNPREFIXED for PhpGenerator
                $hookProp->addAttribute($resolved, $this->extractAttributeArgs($attr));
            }
        }
    }

    // ----------------------- Copy attributes & metadata ------------------------

    private function copyClassAttributes(ClassType $class, Class_ $classNode): void
    {
        foreach ($classNode->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $this->resolveNameUsingAliases($attr->name->toString()); // unprefixed
                $class->addAttribute($name, $this->extractAttributeArgs($attr));
            }
        }
    }

    private function hasTranslatableAttribute(Property $property): bool
    {
        foreach ($property->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($this->isTranslatableAttr($attr->name->toString())) {
                    return true;
                }
            }
        }
        return false;
    }

    private function isColumnAttr(string $name): bool
    {
        $n = ltrim($name, '\\');
        return $n === 'Column'
            || str_ends_with($n, '\\Column')
            || str_contains($n, 'ORM\\Column')
            || str_contains($n, 'Doctrine\\ORM\\Mapping\\Column');
    }

    private function isTranslatableAttr(string $name): bool
    {
        $n = ltrim($name, '\\');
        return $n === 'Translatable'
            || str_ends_with($n, '\\Translatable')
            || str_contains($n, 'Survos\\BabelBundle\\Attribute\\Translatable');
    }

    /**
     * Expand names via alias map and return an UNPREFIXED class string for PhpGenerator.
     */
    private function resolveNameUsingAliases(string $name): string
    {
        if ($name === '') return $name;

        $trim = ltrim($name, '\\');

        if (!str_contains($trim, '\\')) {
            return $trim;
        }

        [$head, $tail] = explode('\\', $trim, 2);
        if (isset($this->useMap[$head])) {
            return ltrim($this->useMap[$head], '\\').'\\'.$tail;
        }

        return $trim;
    }

    // -------- Attribute argument extraction (preserve common cases) ------------

    /**
     * @return array<int|string, mixed>
     */
    private function extractAttributeArgs(Node\Attribute $attr): array
    {
        $out = [];
        foreach ($attr->args as $arg) {
            $value = $this->exprToPhpGenValue($arg->value);
            if ($arg->name instanceof Node\Identifier) {
                $out[$arg->name->toString()] = $value;
            } else {
                $out[] = $value;
            }
        }
        return $out;
    }

    private function exprToPhpGenValue(Expr $expr): mixed
    {
        if ($expr instanceof Node\Scalar\String_)   return $expr->value;
        if ($expr instanceof Node\Scalar\LNumber)   return $expr->value;
        if ($expr instanceof Node\Scalar\DNumber)   return $expr->value;

        if ($expr instanceof Expr\ConstFetch) {
            $name = $expr->name->toString();
            $lower = strtolower($name);
            if ($lower === 'true')  return true;
            if ($lower === 'false') return false;
            if ($lower === 'null')  return null;
            return new Literal($this->qualifyNameForExpr($name));
        }

        if ($expr instanceof Expr\ClassConstFetch) {
            $className = $this->resolveNameUsingAliases($expr->class->toString()); // unprefixed
            $constName = $expr->name->toString();
            $prefix = str_contains($className, '\\') ? '\\' : '';
            return new Literal($prefix.$className.'::'.$constName);
        }

        if ($expr instanceof Expr\Array_) {
            $arr = [];
            foreach ($expr->items as $item) {
                if (!$item) continue;
                $k = $item->key ? $this->exprToPhpGenValue($item->key) : null;
                $v = $this->exprToPhpGenValue($item->value);
                if ($k === null) { $arr[] = $v; } else { $arr[$k] = $v; }
            }
            return $arr;
        }

        if ($expr instanceof Expr\UnaryMinus || $expr instanceof Expr\UnaryPlus) {
            $v = $this->exprToPhpGenValue($expr->expr);
            if (is_int($v) || is_float($v)) {
                return ($expr instanceof Expr\UnaryMinus) ? -$v : +$v;
            }
        }

        if ($expr instanceof Expr\Name || $expr instanceof Node\Name) {
            $name = $this->resolveNameUsingAliases($expr->toString());
            $prefix = str_contains($name, '\\') ? '\\' : '';
            return new Literal($prefix.$name);
        }

        return null;
    }

    private function qualifyNameForExpr(string $name): string
    {
        return str_contains($name, '\\') ? '\\'.ltrim($name, '\\') : $name;
    }

    // ------------------------ Class metadata & uses ----------------------------

    private function extractNamespaceAndClass(array $ast): array
    {
        $nsName = null;
        $className = null;
        $classNode = null;

        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $nsName = $stmt->name?->toString();
                foreach ($stmt->stmts as $s) {
                    if ($s instanceof Class_) {
                        $classNode = $s;
                        $className = $s->name?->toString();
                        break;
                    }
                }
            }
        }

        return [$nsName, $className, $classNode];
    }

    private function copyClassMetadata(PhpNamespace $ns, ClassType $class, Class_ $classNode): void
    {
        if ($classNode->extends) {
            $class->setExtends($classNode->extends->toString());
        }

        foreach ($classNode->implements as $interface) {
            $name     = $interface->toString();
            $resolved = $this->resolveNameUsingAliases($name); // unprefixed

            // Prefer short name and ensure 'use' if we know the FQCN
            $short = ltrim($resolved, '\\');
            if (str_contains($short, '\\')) {
                $shortName = substr($short, strrpos($short, '\\') + 1);
                $this->safeAddUse($ns, $short, null);
                $class->addImplement($shortName);
            } else {
                // heuristic: common interface without namespace — add Survos contract use
                if ($short === 'TranslatableResolvedInterface') {
                    $this->safeAddUse($ns, 'Survos\\BabelBundle\\Contract\\BabelHooksInterface', null);
                }
                $class->addImplement($short);
            }
        }
    }

    private function copyUseStatements(PhpNamespace $ns, string $code): void
    {
        if (preg_match_all('/^use\s+([^;]+);/m', $code, $matches)) {
            foreach ($matches[1] as $line) {
                $line = trim($line);

                // skip stale trait import
                if ($line === 'App\\Entity\\Translations\\ArticleTranslationsTrait') {
                    continue;
                }

                $fqn = $line;
                $alias = null;

                if (stripos($line, ' as ') !== false) {
                    [$fqn, $alias] = array_map('trim', explode(' as ', $line, 2));
                } else {
                    $alias = substr($fqn, strrpos($fqn, '\\') + 1);
                }

                $this->safeAddUse($ns, $fqn, $alias);
                $this->useMap[$alias] = ltrim($fqn, '\\');
            }
        }

        $this->logger->debug('Collected use aliases', ['map' => $this->useMap]);
    }

    private function copyPropertyToClass(ClassType $class, Property $property): void
    {
        $names = [];
        foreach ($property->props as $prop) {
            $names[] = $prop->name->toString();

            $gp = $class->addProperty($prop->name->toString());

            if ($property->isPublic()) {
                $gp->setVisibility(Visibility::Public);
            } elseif ($property->isProtected()) {
                $gp->setVisibility(Visibility::Protected);
            } else {
                $gp->setVisibility(Visibility::Private);
            }

            $gp->setStatic($property->isStatic());
            $gp->setReadOnly($property->isReadonly());

            // Type (nullable/simple). Normalize class types to short names and add uses.
            if ($property->type) {
                $type = $this->stringifyType($property->type);
                if ($type !== null) {
                    $gp->setType($type);
                }
            }

            if ($prop->default) {
                $gp->setValue($this->convertDefaultValue($prop->default));
            }

            // Copy attributes with args preserved
            foreach ($property->attrGroups as $group) {
                foreach ($group->attrs as $attr) {
                    $name = $this->resolveNameUsingAliases($attr->name->toString()); // unprefixed
                    $gp->addAttribute($name, $this->extractAttributeArgs($attr));
                }
            }
        }

        $this->logger->debug('Copied non-translatable property', ['names' => $names]);
    }

    // ------------------------ Type normalization helpers -----------------------

    private function stringifyType(Node $type): ?string
    {
        // NullableType like ?string / ?Collection / ?Category / ?DateTimeImmutable
        if ($type instanceof Node\NullableType) {
            $inner = $this->stringifyType($type->type);
            return $inner ? '?'.$inner : null;
        }

        // Plain identifier (scalar)
        if ($type instanceof Node\Identifier) {
            return $type->toString(); // int, string, bool, etc.
        }

        // Names (class/interface types)
        if ($type instanceof Node\Name) {
            // Normalize leading "\" and resolve aliases
            $raw = ltrim($type->toString(), '\\');
            $resolved = $this->resolveNameUsingAliases($raw);

            // Doctrine Collection helper: if short "Collection", ensure use is present
            if ($resolved === 'Collection') {
                if ($this->currentNs) {
                    $this->safeAddUse($this->currentNs, 'Doctrine\\Common\\Collections\\Collection', null);
                }
                return 'Collection';
            }

            // Global DateTime* helpers: add use for core classes
            if (in_array($resolved, ['DateTimeImmutable','DateTimeInterface','DateTime'], true)) {
                if ($this->currentNs) {
                    $this->safeAddUse($this->currentNs, '\\'.$resolved, null);
                }
                return $resolved;
            }

            if (str_contains($resolved, '\\')) {
                // Add a use and return short name
                $short = substr($resolved, strrpos($resolved, '\\') + 1);
                if ($this->currentNs) {
                    $this->safeAddUse($this->currentNs, $resolved, null);
                }
                return $short;
            }

            // e.g., Category, Tag (likely same namespace) or already imported
            return $resolved;
        }

        // (Optional) TODO: handle UnionType / IntersectionType if needed
        return null;
    }

    // ------------------------ Default value & write helpers --------------------

    private function convertDefaultValue($default)
    {
        return null; // extend if/when needed
    }

    private function writeUpdatedFile(string $entityPath, string $newCode, bool $dryRun, bool $backup): bool
    {
        if ($dryRun) {
            $this->logger->info('Dry run - would write updated entity', [
                'path' => $entityPath,
                'size' => strlen($newCode)
            ]);
            return true;
        }

        if ($backup) {
            @copy($entityPath, $entityPath.'.bak');
        }

        file_put_contents($entityPath, $newCode);

        $this->logger->info('Entity updated successfully', [
            'path' => $entityPath,
            'size' => strlen($newCode)
        ]);

        return true;
    }

    /**
     * Ensure "use Doctrine\DBAL\Types\Types;" is present (alias not required).
     */
    private function ensureTypesImport(): void
    {
        if ($this->currentNs === null) {
            return;
        }
        // Add the import if it isn't already present (no alias, treat as default short name "Types")
        $this->safeAddUse($this->currentNs, 'Doctrine\\DBAL\\Types\\Types', null);
    }

    /**
     * Final pass on emitted PHP to fix any lingering backslashes on simple names,
     * ensure Doctrine Collection & core DateTime imports when used,
     * and remove leading "\" on single-segment attribute names (e.g., #[\BabelStorage] -> #[BabelStorage]).
     */
    private function postProcessGeneratedCode(string $code): string
    {
        // 1) Fix "implements \Foo" -> "implements Foo"  (only when Foo has no namespace separator)
        $code = preg_replace('/implements\s+\\\\([A-Za-z_][A-Za-z0-9_]*)/', 'implements $1', $code);

        // 2) Fix typed properties like "?\Foo $x" -> "?Foo $x"
        $code = preg_replace('/\?\s*\\\\([A-Za-z_][A-Za-z0-9_]*)\s+\$/', '?$1 $', $code);

        // 3) Fix typed properties like "\Foo $x" -> "Foo $x"
        $code = preg_replace('/([^?:])\s\\\\([A-Za-z_][A-Za-z0-9_]*)\s+\$/', '$1 $2 $', $code);

        // 4) Fix standalone ":\ \Foo" return type hints -> ": Foo"
        $code = preg_replace('/:\s*\\\\([A-Za-z_][A-Za-z0-9_]*)\b/', ': $1', $code);

        // 5) Ensure Collection import when type used
        if (preg_match('/\bCollection\b/', $code) && !preg_match('/^use\s+Doctrine\\\\Common\\\\Collections\\\\Collection;/m', $code)) {
            $code = preg_replace('/(\nnamespace\s+[^\n]+;\n)/', "$1\nuse Doctrine\\Common\\Collections\\Collection;\n", $code, 1);
        }

        // 6) Ensure DateTime* imports when used (if not already imported)
        foreach (['DateTimeImmutable','DateTimeInterface','DateTime'] as $dt) {
            if (preg_match('/\b'.$dt.'\b/', $code) && !preg_match('/^use\s+\\\\?'.$dt.';/m', $code)) {
                $code = preg_replace('/(\nnamespace\s+[^\n]+;\n)/', "$1\nuse \\\\{$dt};\n", $code, 1);
            }
        }

        // 7) Heuristic: ensure use for Survos contract if implemented (bare)
        if (preg_match('/implements\s+TranslatableResolvedInterface\b/', $code)
            && !preg_match('/use\s+Survos\\\\BabelBundle\\\\Contract\\\\TranslatableResolvedInterface;/', $code)) {
            $code = preg_replace('/(\nnamespace\s+[^\n]+;\n)/', "$1\nuse Survos\\BabelBundle\\Contract\\TranslatableResolvedInterface;\n", $code, 1);
        }

        // 8) Strip leading "\" on single-segment attribute names: #[\Foo( ... )] -> #[Foo( ... )]
        $code = preg_replace('/#\[\s*\\\\([A-Za-z_][A-Za-z0-9_]*)\s*\(/', '#[${1}(', $code);

        return $code;
    }

    /**
     * Add a use statement only if it isn't already present and alias isn't colliding.
     */
    private function safeAddUse(PhpNamespace $ns, string $fqn, ?string $alias = null): void
    {
        $fqn = ltrim($fqn, '\\');

        // Skip stale trait import explicitly
        if ($fqn === 'App\\Entity\\Translations\\ArticleTranslationsTrait') {
            return;
        }

        foreach ($ns->getUses() as $usedFqn => $usedAlias) {
            if ($usedFqn === $fqn) {
                return; // already present
            }
            if ($alias !== null && $usedAlias === $alias && $usedFqn !== $fqn) {
                $this->logger->debug('Skipping use due to alias collision', [
                    'alias' => $alias,
                    'existing' => $usedFqn,
                    'wanted' => $fqn,
                ]);
                return;
            }
        }

        $ns->addUse($fqn, $alias ?? null);
    }
}
