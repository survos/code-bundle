<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Command;

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
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
        #[Argument('Optional path to entity file or directory; when omitted, scan src/Entity')] ?string $path = null,
        #[Option('Trait namespace base (defaults to <EntityNs>\\Translations)')] ?string $traitNsBase = null,
        #[Option('Dry-run: only show diffs / do not write')] bool $dryRun = false,
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

            [$nsName, $className, $classNode] = $this->findNamespaceAndClass($ast);
            if (!$classNode || !$className || !$nsName) { continue; }

            $entityFqcn = $nsName.'\\'.$className;
            $entityDir  = \dirname($entityFile);

            [$fields, $fieldNames] = $this->scanTranslatableFieldsFromAst($classNode);
            $hasTranslatables = !empty($fieldNames);
            $this->logger->info('Entity scan', ['entity'=>$entityFqcn, 'fields'=>$fieldNames]);

            $traitNamespace = ($traitNsBase ?? $nsName.'\\Translations');
            $traitName      = $className.'TranslationsTrait';
            $traitFqcn      = $traitNamespace.'\\'.$traitName;
            $traitPath      = $entityDir.'/Translations/'.$traitName.'.php';

            if ($hasTranslatables) {
                $this->traitGen->generateTrait(
                    traitFqcn:  $traitFqcn,
                    fields:     $fields,
                    targetPath: $traitPath
                );
                $totalTraits++;
                $io->writeln(sprintf(' • Trait <info>%s</info> → <comment>%s</comment>', $traitFqcn, $this->rel($traitPath)));
            } else {
                $this->logger->info('No translatables; skipping entity update', ['entity'=>$entityFqcn]);
                continue; // <= IMPORTANT: do not touch this entity if no translatables
            }

            $updated = $this->updater->updateEntity(
                entityPath:       $entityFile,
                entityFqcn:       $entityFqcn,
                traitFqcn:        $traitFqcn,
                fieldNames:       $fieldNames,
                hasTranslatables: true,
                dryRun:           $dryRun,
                backup:           false
            );
            if ($updated) {
                $totalUpdated++;
                $io->writeln(sprintf(' • Updated entity <info>%s</info>', $this->rel($entityFile)));
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
        if ($path === null) {
            $root = $this->projectDir.'/src/Entity';
            if (is_dir($root)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        if (str_contains($f->getPathname(), '/Translations/')) continue;
                        $targets[] = $f->getPathname();
                    }
                }
            }
        } else {
            if (is_dir($path)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) if ($f->isFile() && $f->getExtension() === 'php') $targets[] = $f->getPathname();
            } elseif (is_file($path)) {
                $targets[] = $path;
            }
        }
        sort($targets);
        return $targets;
    }

    /** @return array{0:?string,1:?string,2:?Class_} */
    private function findNamespaceAndClass(array $ast): array
    {
        $nsName = null; $className = null; $class = null;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $nsName = $stmt->name?->toString();
                foreach ($stmt->stmts as $s) {
                    if ($s instanceof Class_) { $class = $s; $className = $s->name?->toString(); break; }
                }
            }
        }
        return [$nsName, $className, $class];
    }

    private function scanTranslatableFieldsFromAst(Class_ $class): array
    {
        $fields = []; $names = [];
        foreach ($class->stmts as $stmt) {
            if (!$stmt instanceof Property) continue;
            if (!($stmt->flags & Class_::MODIFIER_PUBLIC)) continue;

            $has = false;
            foreach ($stmt->attrGroups as $grp)
                foreach ($grp->attrs as $attr) {
                    $n = $attr->name->toString();
                    if ($n === 'Translatable' || $n === 'Survos\\BabelBundle\\Attribute\\Translatable') { $has = true; break; }
                }
            if (!$has) continue;

            $hook = [];
            foreach ($stmt->attrGroups as $grp)
                foreach ($grp->attrs as $attr) {
                    $n = $attr->name->toString();
                    if ($n === 'Column' || $n === 'Doctrine\\ORM\\Mapping\\Column' || str_ends_with($n, '\\Column')) continue;
                    $parts = [];
                    foreach ($attr->args as $arg) {
                        $k = $arg->name?->toString();
                        $v = $this->pp->prettyPrintExpr($arg->value);
                        $parts[] = $k ? ($k.': '.$v) : $v;
                    }
                    if ($n === 'Translatable' || $n === 'Survos\\BabelBundle\\Attribute\\Translatable') $hook[] = '#[Translatable'.($parts?'('.implode(', ',$parts).')':'').']';
                    elseif ($n === 'Groups' || $n === 'Symfony\\Component\\Serializer\\Attribute\\Groups') $hook[] = '#[Groups'.($parts?'('.implode(', ',$parts).')':'').']';
                    else $hook[] = '#['.$n.($parts?'('.implode(', ',$parts).')':'').']';
                }

            foreach ($stmt->props as $prop) {
                $name = $prop->name->toString();
                $fields[]  = ['name'=>$name,'hookAttrs'=>$hook,'columnAttr'=>null];
                $names[]   = $name;
            }
        }
        return [$fields, $names];
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
