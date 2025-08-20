<?php

namespace Survos\CodeBundle\Command;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Inflector\InflectorFactory;
use Doctrine\ORM\Mapping as ORM;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\ParserFactory;
use ReflectionClass;
use Survos\CodeBundle\Service\GeneratorService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\UnicodeString;

#[AsCommand('code:relation', 'add relation properties to existing entities')]
final class MakeRelation extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        private GeneratorService $generatorService,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        parent::__construct();
    }

    /**
     * Example:
     *  bin/console code:relation many-to-one App\\Entity\\Comment article App\\Entity\\Article --nullable=0
     */
    public function __invoke(
        SymfonyStyle $io,

        #[Argument('Relation type (only "many-to-one" supported)')]
        string $type,

        #[Argument('Owning side FQCN (e.g. App\\Entity\\Comment) or short (e.g. Comment)')]
        string $owning,

        #[Argument('Owning side field name (e.g. article)')]
        string $field,

        #[Argument('Inverse side FQCN (e.g. App\\Entity\\Article) or short (e.g. Article)')]
        string $inverse,

        #[Option('Nullable relation; pass --nullable to make it nullable (default: false)')]
        bool $nullable = false,

        #[Option('inversedBy name on target (default: plural of owning class, e.g. comments)')]
        ?string $inversedBy = null,

        #[Option('mappedBy name on owning side (default: the field name)')]
        ?string $mappedBy = null,

        #[Option('Overwrite existing properties/methods if present')]
        bool $force = false,
    ): int {
        $type = strtolower(trim($type));
        if (!in_array($type, ['many-to-one', 'manytoone'], true)) {
            $io->error('Only "many-to-one" is supported right now.');
            return Command::INVALID;
        }

        // Normalize field to camelCase
        $field = (string) (new UnicodeString($field))->camel();

        // Resolve classes (accept short names like "Comment" and "Article")
        try {
            $owning = $this->resolveEntityClass($owning);
            $inverse = $this->resolveEntityClass($inverse);
            $owningRef  = new ReflectionClass(ltrim($owning, '\\'));
            $inverseRef = new ReflectionClass(ltrim($inverse, '\\'));
        } catch (\Throwable $e) {
            $io->error('Both classes must exist and be autoloadable. ' . $e->getMessage());
            return Command::FAILURE;
        }

        // Defaults
        $mappedBy ??= $field;
        $inflector = InflectorFactory::create()->build();
        $inversedBy ??= lcfirst($inflector->pluralize($owningRef->getShortName()));

        $owningPath  = $owningRef->getFileName();
        $inversePath = $inverseRef->getFileName();
        if (!$owningPath || !$inversePath) {
            $io->error('Could not resolve file paths for one or both classes.');
            return Command::FAILURE;
        }

        // Parse files
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $owningCode  = file_get_contents($owningPath) ?: '';
        $inverseCode = file_get_contents($inversePath) ?: '';

        try {
            $owningAst  = $parser->parse($owningCode);
            $inverseAst = $parser->parse($inverseCode);
        } catch (\PhpParser\Error $e) {
            $io->error('Parse error: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $owningClass  = $this->findFirstClass($owningAst);
        $inverseClass = $this->findFirstClass($inverseAst);
        if (!$owningClass || !$inverseClass) {
            $io->error('Could not find class nodes.');
            return Command::FAILURE;
        }

        // Imports
        $this->ensureUseFromClass($owningAst, \Doctrine\ORM\Mapping\ManyToOne::class, alias: 'ORM');
        $this->ensureUseFromClass($inverseAst, \Doctrine\ORM\Mapping\OneToMany::class, alias: 'ORM');
        $this->ensureUseFromClass($inverseAst, \Doctrine\Common\Collections\Collection::class);
        $this->ensureUseFromClass($inverseAst, \Doctrine\Common\Collections\ArrayCollection::class);

        // Presence checks
        $owningHasField  = $this->classHasProperty($owningClass, $field);
        $inverseHasField = $this->classHasProperty($inverseClass, $inversedBy);

        if (($owningHasField || $inverseHasField) && !$force) {
            $io->warning('One or both target properties already exist. Use --force to overwrite.');
            return Command::INVALID;
        }

        // Build snippets to match your expected output exactly
        $owningShort  = $owningRef->getShortName();   // e.g. Comment
        $inverseShort = $inverseRef->getShortName();  // e.g. Article

        $owningPropSnippet = $this->renderOwningProperty(
            field: $field,
            targetShort: $inverseShort,
            inversedBy: $inversedBy,
            nullable: $nullable
        );

        $inverseBlockSnippet = $this->renderInverseBlock(
            collectionName: $inversedBy,
            elementShort: $owningShort,
            mappedBy: $mappedBy
        );

        // Inject
        $newOwningCode  = $this->injectSnippetIntoClassSource($owningCode, $owningClass, $owningPropSnippet, replaceProperty: $owningHasField ? $field : null);
        $newInverseCode = $this->injectSnippetIntoClassSource($inverseCode, $inverseClass, $inverseBlockSnippet, replaceProperty: $inverseHasField ? $inversedBy : null);

        // Write back
        $this->fs->dumpFile($owningPath, $newOwningCode);
        $this->fs->dumpFile($inversePath, $newInverseCode);

        $io->success(sprintf(
            'Added ManyToOne %s::$%s → %s and OneToMany %s::$%s ← %s',
            $owningShort, $field, $inverseShort, $inverseShort, $inversedBy, $owningShort
        ));

        return Command::SUCCESS;
    }

    // --------------------------
    // Rendering (matches your examples)
    // --------------------------

    private function renderOwningProperty(
        string $field,
        string $targetShort,
        string $inversedBy,
        bool $nullable
    ): string {
        $type = $nullable ? "?{$targetShort}" : $targetShort;
        $nullableFlag = $nullable ? 'true' : 'false';

        return <<<PHP

    #[ORM\\ManyToOne(inversedBy: '{$inversedBy}')]
    #[ORM\\JoinColumn(nullable: {$nullableFlag})]
    public {$type} \${$field} = null;

PHP;
    }

    private function renderInverseBlock(
        string $collectionName,
        string $elementShort,
        string $mappedBy
    ): string {
        $ucCollection = ucfirst($collectionName);
        $varName = lcfirst($elementShort);

        return <<<PHP

    /**
     * @var Collection<int, {$elementShort}>
     */
    #[ORM\\OneToMany(targetEntity: {$elementShort}::class, mappedBy: '{$mappedBy}', orphanRemoval: true)]
    public Collection \${$collectionName};

    public function __construct()
    {
        \$this->{$collectionName} = new \\Doctrine\\Common\\Collections\\ArrayCollection();
    }

    public function add{$elementShort}({$elementShort} \${$varName}): static
    {
        if (!\$this->{$collectionName}->contains(\${$varName})) {
            \$this->{$collectionName}->add(\${$varName});
            \${$varName}->{$mappedBy} = \$this;
        }

        return \$this;
    }

    public function remove{$elementShort}({$elementShort} \${$varName}): static
    {
        if (\$this->{$collectionName}->removeElement(\${$varName})) {
            // set the owning side to null (unless already changed)
            if (\${$varName}->{$mappedBy} === \$this) {
                \${$varName}->{$mappedBy} = null;
            }
        }

        return \$this;
    }

PHP;
    }

    // --------------------------
    // Injection helpers
    // --------------------------

    private function injectSnippetIntoClassSource(string $code, Class_ $classNode, string $snippet, ?string $replaceProperty = null): string
    {
        // (Optional) remove an existing property block by name
        if ($replaceProperty) {
            $propPattern = '/\\n\\s*(?:\\/\\*\\*.*?\\*\\/\\s*)?(?:#[^\\n]*\\n\\s*)*(public|protected|private)\\s+[^\\n]*\\$' . preg_quote($replaceProperty, '/') . '\\b[^{;]*?(?:\\{.*?\\}|;)/s';
            $code = preg_replace($propPattern, "\n", $code) ?? $code;
        }

        $classStart = $classNode->getStartFilePos();
        $classEnd   = $classNode->getEndFilePos();
        $classChunk = substr($code, $classStart, $classEnd - $classStart + 1);

        // Insert before the final "}" of the class
        $inserted = preg_replace('/}\\s*$/', rtrim("\n" . $snippet) . "\n}\n", $classChunk, 1);
        if (!$inserted) {
            return rtrim($code) . "\n\n" . $snippet . "\n";
        }
        return substr($code, 0, $classStart) . $inserted . substr($code, $classEnd + 1);
    }

    private function findFirstClass(array $ast): ?Class_
    {
        foreach ($ast as $node) {
            if ($node instanceof Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Class_) {
                        return $stmt;
                    }
                }
            } elseif ($node instanceof Class_) {
                return $node;
            }
        }
        return null;
    }

    private function classHasProperty(Class_ $class, string $name): bool
    {
        foreach ($class->getProperties() as $prop) {
            foreach ($prop->props as $p) {
                if ($p->name->toString() === $name) {
                    return true;
                }
            }
        }
        return false;
    }

    private function ensureUse(array &$ast, string $namespace, ?string $alias = null): void
    {
        foreach ($ast as $node) {
            if ($node instanceof Namespace_) {
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Use_) {
                        foreach ($stmt->uses as $useUse) {
                            $sameNs = $useUse->name->toString() === ltrim($namespace, '\\');
                            $sameAlias = ($alias === null && $useUse->alias === null)
                                || ($alias !== null && $useUse->alias?->toString() === $alias);
                            if ($sameNs && $sameAlias) {
                                return;
                            }
                        }
                    }
                }
                // Prepend the use
                $use = new Node\Stmt\Use_([
                    new Node\Stmt\UseUse(new Node\Name(ltrim($namespace, '\\')), $alias ? new Node\Identifier($alias) : null),
                ]);
                array_unshift($node->stmts, $use);
                return;
            }
        }
    }

    private function ensureUseFromClass(array &$ast, string $fqcn, ?string $alias = null): void
    {
        $parts = explode('\\', ltrim($fqcn, '\\'));
        if (count($parts) > 1) {
            array_pop($parts);
            $ns = implode('\\', $parts);
            $this->ensureUse($ast, $ns, $alias);
        } else {
            $this->ensureUse($ast, ltrim($fqcn, '\\'), $alias);
        }
    }

    private function resolveEntityClass(string $input, string $defaultNs = 'App\\Entity'): string
    {
        $raw = trim($input);
        $candidates = [];
        $candidates[] = ltrim($raw, '\\');
        $candidates[] = '\\' . ltrim($raw, '\\');

        if (!str_contains($raw, '\\')) {
            $base = ltrim($raw, '\\');
            $ucfirst = ucfirst($base);
            $candidates[] = $defaultNs . '\\' . $base;
            $candidates[] = $defaultNs . '\\' . $ucfirst;
            $candidates[] = '\\' . $defaultNs . '\\' . $base;
            $candidates[] = '\\' . $defaultNs . '\\' . $ucfirst;
        }

        $seen = [];
        foreach ($candidates as $cand) {
            $norm = ltrim($cand, '\\');
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;

            if (class_exists($norm)) {
                return '\\' . $norm;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Could not resolve entity class for "%s". Tried: %s',
            $input,
            implode(', ', array_map(fn($c) => '\\' . ltrim($c, '\\'), array_keys($seen)))
        ));
    }
}
