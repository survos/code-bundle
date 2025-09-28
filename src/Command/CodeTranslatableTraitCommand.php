<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Command;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Pretty;
use Psr\Log\LoggerInterface;
use Survos\CodeBundle\Service\EntityTranslatableUpdater;
use Survos\CodeBundle\Service\TranslatableTraitGenerator;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds #[Translatable] in entities and in any used traits.
 * Generates <Entity>TranslationsTrait only for fields *owned by the entity*.
 * Trait-owned translatable fields are reported (cannot be refactored from the entity safely).
 */
#[AsCommand('code:translatable:trait', 'Generate <Entity>TranslationsTrait and update entities (scan-all if no path)')]
final class CodeTranslatableTraitCommand
{
    private Pretty $pp;

    public function __construct(
        private readonly TranslatableTraitGenerator $traitGen,
        private readonly EntityTranslatableUpdater  $updater,
        private readonly LoggerInterface            $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire(param: 'kernel.default_locale')] private readonly string $defaultLocale = 'en',
        #[Autowire(param: 'kernel.enabled_locales')] private readonly array $enabledLocales = [],
    ) { $this->pp = new Pretty(); }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Optional path to entity file or directory; when omitted, scan src/Entity')]
        ?string $path = null,
        #[Option('Trait namespace base (defaults to <EntityNs>\\Translations)')]
        ?string $traitNsBase = null,
        #[Option('Dry-run: only show diffs / do not write')]
        bool $dryRun = false,
    ): int {
        $this->reportLocales($io);

        $targets = $this->resolveTargets($path);
        if (!$targets) { $io->warning('No entity files detected.'); return 0; }

        $parser = (new ParserFactory())->createForHostVersion();

        $totalUpdated = 0;
        $totalTraits  = 0;

        foreach ($targets as $entityFile) {
            $code = @file_get_contents($entityFile);
            if ($code === false) { $this->logger->warning('Skip unreadable entity', ['file'=>$entityFile]); continue; }

            $ast = $parser->parse($code);
            if (!$ast) { $this->logger->warning('Skip unparsable entity', ['file'=>$entityFile]); continue; }

            [$nsName, $className, $classNode, $kind] = $this->findNamespaceAndClass($ast);
            if (!$classNode || !$className || !$nsName) { continue; }

// Skip interfaces entirely (they are not entities)
            if ($kind === 'interface') {
                $this->logger->info('Skipping interface', ['file' => $entityFile, 'fqcn' => $nsName.'\\'.$className]);
                continue;
            }

            if ($kind === 'trait') {
                $io->writeln(' • Found trait: reporting translatables only; no entity update.');
                // fall through; your code already reports trait-owned fields but does not generate hooks
            }


            $entityFqcn = $nsName.'\\'.$className;
            $entityDir  = \dirname($entityFile);

            // 1) Collect entity-owned translatables (public props marked #[Translatable] in the entity file)
            [$ownFields, $ownNames] = $this->scanEntityOwnedTranslatables($classNode);

            // 2) Collect translatables from used traits (recursive)
            $traitMap = $this->collectTraitFqcns($classNode, $nsName);
            [$traitFields, $byTrait] = $this->scanTranslatablesFromTraits($traitMap);

            $hasAnyTranslatables = !empty($ownNames) || !empty($traitFields);

            $this->logger->info('Entity scan', [
                'entity'       => $entityFqcn,
                'ownFields'    => array_values($ownNames),
                'traitFields'  => array_keys($traitFields),
                'traitSources' => $byTrait,
            ]);

            // Report to user
            $io->section($entityFqcn);
            if ($ownNames) {
                $io->writeln(' • Will convert entity-owned fields: <info>'.implode(', ', $ownNames).'</info>');
            }
            if ($traitFields) {
                $io->writeln(' • Skipping trait-owned fields (cannot refactor from entity):');
                foreach ($byTrait as $traitFqcn => $names) {
                    $io->writeln('    - '.$traitFqcn.': '.implode(', ', $names));
                }
                $io->writeln('   (These still work with Babel; hooks not required.)');
            }
            if (!$hasAnyTranslatables) {
                $this->logger->info('No translatables; skipping entity update', ['entity'=>$entityFqcn]);
                $io->writeln(' • No translatables found. Skipped.');
                continue;
            }

            // Only generate trait for OWN fields
            if ($ownNames) {
                $traitNamespace = ($traitNsBase ?? $nsName.'\\Translations');
                $traitName      = $className.'TranslationsTrait';
                $traitFqcn      = $traitNamespace.'\\'.$traitName;
                $traitPath      = $entityDir.'/Translations/'.$traitName.'.php';

                $this->traitGen->generateTrait(
                    traitFqcn:  $traitFqcn,
                    fields:     $ownFields,
                    targetPath: $traitPath
                );
                $totalTraits++;
                $io->writeln(sprintf(' • Trait <info>%s</info> → <comment>%s</comment>', $traitFqcn, $this->rel($traitPath)));

                $updated = $this->updater->updateEntity(
                    entityPath:       $entityFile,
                    entityFqcn:       $entityFqcn,
                    traitFqcn:        $traitFqcn,
                    fieldNames:       $ownNames,
                    hasTranslatables: true,
                    dryRun:           $dryRun,
                    backup:           false
                );
                if ($updated) {
                    $totalUpdated++;
                    $io->writeln(sprintf(' • Updated entity <info>%s</info>', $this->rel($entityFile)));
                }
            } else {
                $io->writeln(' • No entity-owned fields to convert. Nothing to generate.');
            }
        }

        $scaffolded = $this->scaffoldStrEntitiesIfMissing($io);

        $io->newLine();
        $io->success(sprintf('Done. Traits: %d, Entities updated: %d%s',
            $totalTraits, $totalUpdated, $scaffolded ? ", Scaffolds: $scaffolded" : ''
        ));
        return 0;
    }

    private function reportLocales(SymfonyStyle $io): void
    {
        $enabled = array_values(array_unique(array_filter($this->enabledLocales, 'strlen')));
        $io->section('Locales');
        $io->text('Default: <info>'.$this->defaultLocale.'</info>');
        $io->text('Enabled: <info>'.($enabled ? implode(', ', $enabled) : '(none)').'</info>');
        if (!$enabled || (count($enabled) === 1 && $enabled[0] === $this->defaultLocale)) {
            $io->warning("Meaningful 'framework.enabled_locales' is not configured.");
            $io->writeln("Add something like:");
            $io->writeln("  framework:");
            $io->writeln("    default_locale: ".$this->defaultLocale);
            $io->writeln("    enabled_locales: ['".$this->defaultLocale."', 'es', 'de', 'fr']");
        }
    }

    /** @return list<string> */
    private function resolveTargets(?string $path): array
    {
        $targets = [];
        $scan = function (string $root) use (&$targets): void {
            if (!is_dir($root)) return;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->getExtension() !== 'php') continue;
                $p = $f->getPathname();
                if (str_contains($p, '/Translations/')) continue;
                if (str_ends_with($p, 'Interface.php')) continue;   // <-- skip interfaces
                $targets[] = $p;
            }
        };

        if ($path === null) {
            $scan($this->projectDir . '/src/Entity');
        } elseif (is_dir($path)) {
            $scan($path);
        } elseif (is_file($path) && str_ends_with($path, '.php') && !str_ends_with($path, 'Interface.php')) {
            $targets[] = $path;
        }

        sort($targets);
        return $targets;
    }

    /** @return array{0:?string,1:?string,2:Node\Stmt|null,3:?string} [ns, name, node, kind] */
    private function findNamespaceAndClass(array $ast): array
    {
        $nsName = null; $name = null; $node = null; $kind = null;

        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $nsName = $stmt->name?->toString();
                foreach ($stmt->stmts as $s) {
                    if ($s instanceof \PhpParser\Node\Stmt\Class_) {
                        $name = $s->name?->toString();
                        $node = $s;
                        $kind = 'class';
                        break 2;
                    }
                    if ($s instanceof \PhpParser\Node\Stmt\Trait_) {
                        $name = $s->name?->toString();
                        $node = $s;
                        $kind = 'trait';
                        break 2;
                    }
                    if ($s instanceof \PhpParser\Node\Stmt\Interface_) {
                        $name = $s->name?->toString();
                        $node = $s;
                        $kind = 'interface';
                        break 2;
                    }
                }
            }
        }
        return [$nsName, $name, $node, $kind];
    }

    /**
     * Scan only entity-owned public properties with #[Translatable]
     * Returns [fieldSpecs, names]
     */
    private function scanEntityOwnedTranslatables(Class_ $class): array
    {
        $fields = []; $names = [];
        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) continue;
            // we possible _could_ support non-public properties
            if (!$stmt->isPublic()) continue;

            if (!$this->hasTranslatableAttr($stmt->attrGroups)) continue;

            $hookAttrs = $this->copyNonColumnAttributes($stmt->attrGroups);

            foreach ($stmt->props as $prop) {
                $name = $prop->name->toString();
                $fields[]  = ['name'=>$name,'hookAttrs'=>$hookAttrs,'columnAttr'=>null];
                $names[]   = $name;
            }
        }
        return [$fields, $names];
    }

    /** Collect trait FQCNs from "use FooTrait;" entries (best-effort resolution) */
    private function collectTraitFqcns(Class_ $class, string $nsName): array
    {
        $traits = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $nameNode) {
                    $traits[] = $this->normalizeFqcn($nameNode, $nsName);
                }
            }
        }
        return $traits;
    }

    /**
     * Parse trait files and collect public #[Translatable] properties.
     * Returns [flatList, byTraitMap]
     *
     * @param list<string> $traitFqcns
     * @return array{0:array<string,true>,1:array<string,list<string>>}
     */
    private function scanTranslatablesFromTraits(array $traitFqcns): array
    {
        $byTrait = [];
        $flat    = [];

        $parser  = (new ParserFactory())->createForHostVersion();

        foreach ($traitFqcns as $fqcn) {
            $file = $this->resolveFqcnToFile($fqcn);
            if (!$file || !is_file($file)) continue;

            $code = @file_get_contents($file);
            if ($code === false) continue;

            $ast = $parser->parse($code);
            if (!$ast) continue;

            // Find first trait node
            $traitNode = null; $nsName = null;
            foreach ($ast as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $nsName = $stmt->name?->toString();
                    foreach ($stmt->stmts as $s) {
                        if ($s instanceof \PhpParser\Node\Stmt\Trait_) { $traitNode = $s; break 2; }
                    }
                }
            }
            if (!$traitNode) continue;

            foreach ($traitNode->stmts as $stmt) {
                if (!$stmt instanceof Property) continue;
                if (!$stmt->isPublic()) continue;
                if (!$this->hasTranslatableAttr($stmt->attrGroups)) continue;

                foreach ($stmt->props as $prop) {
                    $name = $prop->name->toString();
                    $flat[$name] = true;
                    $byTrait[$fqcn][] = $name;
                }
            }
        }

        return [$flat, $byTrait];
    }

    /** True if any AttributeGroup contains #[Translatable] */
    private function hasTranslatableAttr(array $groups): bool
    {
        foreach ($groups as $grp) {
            foreach ($grp->attrs as $attr) {
                $n = $attr->name->toString();
                if ($n === 'Translatable' || $n === 'Survos\\BabelBundle\\Attribute\\Translatable') return true;
            }
        }
        return false;
    }

    /** Copy all non-Column attributes into the generated hook */
    private function copyNonColumnAttributes(array $groups): array
    {
        $hook = [];
        foreach ($groups as $grp) {
            foreach ($grp->attrs as $attr) {
                $n = $attr->name->toString();
                if ($n === 'Column' || $n === 'Doctrine\\ORM\\Mapping\\Column' || str_ends_with($n, '\\Column')) continue;

                $parts = [];
                foreach ($attr->args as $arg) {
                    $k = $arg->name?->toString();
                    $v = $this->pp->prettyPrintExpr($arg->value);
                    $parts[] = $k ? ($k.': '.$v) : $v;
                }
                $hook[] = '#['.$n.($parts?'('.implode(', ',$parts).')':'').']';
            }
        }
        return $hook;
    }

    private function normalizeFqcn(Name|FullyQualified $name, string $nsName): string
    {
        $fq = $name->toString();
        if ($name->isFullyQualified()) return $fq;
        // best effort: resolve relative to entity namespace
        if (!str_starts_with($fq, '\\')) {
            return $nsName.'\\'.$fq;
        }
        return ltrim($fq, '\\');
    }

    private function resolveFqcnToFile(string $fqcn): ?string
    {
        // PSR-4 best effort: App\ → src/
        if (str_starts_with($fqcn, 'App\\')) {
            $rel = str_replace('\\', DIRECTORY_SEPARATOR, substr($fqcn, 4)).'.php';
            $file = $this->projectDir.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$rel;
            return $file;
        }
        // Add more roots if needed
        return null;
    }

    private function rel(string $abs): string
    {
        return str_starts_with($abs, $this->projectDir.'/') ? substr($abs, strlen($this->projectDir) + 1) : $abs;
    }

    private function scaffoldStrEntitiesIfMissing(SymfonyStyle $io): int
    {
        $created = 0;

        $entityDir = $this->projectDir.'/src/Entity';
        $repoDir   = $this->projectDir.'/src/Repository';

        if (!is_dir($entityDir)) { mkdir($entityDir, 0775, true); }
        if (!is_dir($repoDir))   { mkdir($repoDir, 0775, true); }

        // Str entity
        if (!file_exists($entityDir.'/Str.php')) {
            $code = <<<'PHPSTR'
<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\StrRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StrRepository::class)]
#[ORM\Table(name: 'str')]
class Str extends \Survos\BabelBundle\Entity\Base\StrBase {}
PHPSTR;
            file_put_contents($entityDir.'/Str.php', $code);
            $created++;
            $io->writeln(' • Created <info>App\Entity\Str</info>');
        }
        // Str repo
        if (!file_exists($repoDir.'/StrRepository.php')) {
            $code = <<<'PHPSTRR'
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Str;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StrRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry){ parent::__construct($registry, Str::class); }
}
PHPSTRR;
            file_put_contents($repoDir.'/StrRepository.php', $code);
            $created++;
            $io->writeln(' • Created <info>App\Repository\StrRepository</info>');
        }

        // StrTranslation entity
        if (!file_exists($entityDir.'/StrTranslation.php')) {
            $code = <<<'PHPTR'
<?php
declare(strict_types=1);

namespace App\Entity;

use App\Repository\StrTranslationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StrTranslationRepository::class)]
#[ORM\Table(name: 'str_translation')]
class StrTranslation extends \Survos\BabelBundle\Entity\Base\StrTranslationBase {}
PHPTR;
            file_put_contents($entityDir.'/StrTranslation.php', $code);
            $created++;
            $io->writeln(' • Created <info>App\Entity\StrTranslation</info>');
        }
        // StrTranslation repo
        if (!file_exists($repoDir.'/StrTranslationRepository.php')) {
            $code = <<<'PHPTRR'
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\StrTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class StrTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry){ parent::__construct($registry, StrTranslation::class); }
}
PHPTRR;
            file_put_contents($repoDir.'/StrTranslationRepository.php', $code);
            $created++;
            $io->writeln(' • Created <info>App\Repository\StrTranslationRepository</info>');
        }

        if ($created) {
            $io->warning('Str/StrTranslation scaffolded. Create a migration or run doctrine:schema:update --force.');
        }
        return $created;
    }
}
