<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use Psr\Log\LoggerInterface;

final class EntityTranslatableUpdater
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Ensure the entity uses the generated trait (exactly once)
     * and remove converted public properties.
     *
     * @param string[] $fieldNames
     */
    public function updateEntity(
        string $entityPath,
        string $entityFqcn,
        string $traitFqcn,
        array $fieldNames,
        bool $hasTranslatables,
        bool $dryRun = false,
        bool $backup = false
    ): bool {
        $this->logger->info('Updater: begin', compact(
            'entityPath','entityFqcn','traitFqcn','fieldNames','hasTranslatables','dryRun','backup'
        ));

        $code = @file_get_contents($entityPath);
        if ($code === false) {
            $this->logger->warning('Updater: unreadable file', ['file' => $entityPath]);
            return false;
        }

        $parser = (new ParserFactory())->createForHostVersion();
        $ast    = $parser->parse($code);
        if (!$ast) {
            $this->logger->warning('Updater: unparsable entity', ['file' => $entityPath]);
            return false;
        }

        // Find namespace + class
        $nsNode = null;
        $class  = null;
        $entityNs = null;

        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $nsNode   = $stmt;
                $entityNs = $stmt->name?->toString() ?? '';
                foreach ($stmt->stmts as $s) {
                    if ($s instanceof Class_) { $class = $s; break 2; }
                }
            }
        }
        if (!$class || !$nsNode) {
            $this->logger->warning('Updater: no class found', ['file' => $entityPath]);
            return false;
        }

        $rel = null;
        if ($entityNs !== '' && str_starts_with($traitFqcn, $entityNs.'\\')) {
            $rel = substr($traitFqcn, strlen($entityNs.'\\'));
        }
        $traitNameNode = $rel ? new Name($rel) : new FullyQualified($traitFqcn);
        $traitNameStr  = ltrim($traitNameNode->toString(), '\\');

        $changed = false;

        // 1) Remove converted public properties (owned by entity) using isPublic() (no deprecated flags)
        $removed = $this->removeConvertedPublicProps($class, array_values(array_unique($fieldNames)));
        $changed = $changed || $removed;

        // 2) Ensure the class uses the translations trait exactly once
        if ($hasTranslatables) {
            $added = $this->ensureTraitUseOnce($class, $traitNameNode, $traitFqcn);
            $changed = $changed || $added;
        }

        if (!$changed) {
            $this->logger->info('Updater: no changes needed');
            return false;
        }

        $pp      = new PrettyPrinter();
        $newCode = $pp->prettyPrintFile($ast);

        if ($dryRun) {
            $this->logger->info('Updater: dry-run; not writing changes', ['file' => $entityPath]);
            return false;
        }

        if ($backup) {
            @copy($entityPath, $entityPath.'.bak');
        }

        file_put_contents($entityPath, $newCode);
        $this->logger->info('Updater: file updated', ['file' => $entityPath, 'bytes' => strlen($newCode)]);
        return true;
    }

    /**
     * Remove public properties that match $fieldNames.
     * If a Property node declares multiple variables, keep the non-matching ones.
     *
     * @param string[] $fieldNames
     */
    private function removeConvertedPublicProps(Class_ $class, array $fieldNames): bool
    {
        if ($fieldNames === []) return false;

        $fieldSet = array_fill_keys($fieldNames, true);
        $changed  = false;
        $newBody  = [];
        $removedNames = [];

        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property || !$stmt->isPublic()) {
                $newBody[] = $stmt;
                continue;
            }

            $keepProps = [];
            foreach ($stmt->props as $prop) {
                $name = $prop->name->toString();
                if (isset($fieldSet[$name])) {
                    $removedNames[] = $name;
                    continue;
                }
                $keepProps[] = $prop;
            }

            if (\count($keepProps) === \count($stmt->props)) {
                $newBody[] = $stmt;
                continue;
            }

            $changed = true;

            if ($keepProps === []) {
                // remove whole declaration
                continue;
            }

            // rebuild property with remaining props (preserve flags/type/attrs/comments)
            $newProp = new Property(
                $stmt->flags,
                $keepProps,
                $stmt->getAttributes(),
                $stmt->type,
                $stmt->attrGroups
            );
            if ($stmt->getDocComment()) {
                $newProp->setDocComment($stmt->getDocComment());
            }
            $newBody[] = $newProp;
        }

        if ($changed) {
            $class->stmts = $newBody;
        }

        if ($removedNames) {
            $this->logger->info('Updater: removed public properties', ['props' => $removedNames]);
        }

        return $changed;
    }

    private function ensureTraitUseOnce(Class_ $class, Name|FullyQualified $traitNameNode, string $traitFqcn): bool
    {
        $targetNames = $this->candidateTraitNames($traitNameNode, $traitFqcn);

        // Already present?
        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof TraitUse) continue;
            foreach ($stmt->traits as $tn) {
                if (\in_array($this->normalizeName($tn->toString()), $targetNames, true)) {
                    return false;
                }
            }
        }

        // Append to first existing TraitUse if available
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                $stmt->traits[] = $traitNameNode;
                return true;
            }
        }

        // Otherwise insert at top
        array_unshift($class->stmts, new TraitUse([$traitNameNode]));
        return true;
    }

    /** @return list<string> */
    private function candidateTraitNames(Name|FullyQualified $node, string $fqcn): array
    {
        $nodeStr = $this->normalizeName($node->toString());
        $fqcnStr = $this->normalizeName($fqcn);
        return array_values(array_unique([
            $nodeStr, $fqcnStr, ltrim('\\'.$nodeStr, '\\'), ltrim('\\'.$fqcnStr, '\\'),
        ]));
    }

    private function normalizeName(string $name): string
    {
        return ltrim($name, '\\');
    }
}
