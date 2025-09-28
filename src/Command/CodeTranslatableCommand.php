<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Command;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\ParserFactory;
use Psr\Log\LoggerInterface;
use Survos\CodeBundle\Service\DirectEntityTranslatableUpdater;
use Survos\CodeBundle\Service\StrEntitiesScaffolder;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds #[Translatable] properties in entities and converts them directly to property hooks.
 */
#[AsCommand('code:translatable:hooks', 'Convert #[Translatable] properties to property hooks directly in entities')]
final class CodeTranslatableCommand
{
    public function __construct(
        private readonly DirectEntityTranslatableUpdater $updater,
        private readonly LoggerInterface                 $logger,
        private readonly StrEntitiesScaffolder           $scaffolder,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire(param: 'kernel.default_locale')] private readonly string $defaultLocale = 'en',
        #[Autowire(param: 'kernel.enabled_locales')] private readonly array $enabledLocales = [],
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Optional path to entity file or directory; when omitted, scan src/Entity')]
        ?string $path = null,
        #[Option('Dry-run: show what would be changed without writing files')]
        bool $dryRun = false,
        #[Option('Create backup files (.bak) before modifying')]
        bool $backup = false,
    ): int {
        $this->reportLocaleConfiguration($io);

        // NEW: use scaffolder service
        $created = $this->scaffolder->scaffoldIfMissing();
        if ($created) {
            $io->warning('Scaffolded files:');
            foreach ($created as $r) {
                $io->writeln(' • '.$r);
            }
            $io->writeln('Run doctrine:migrations:diff or doctrine:schema:update --force');
        }

        return 0;

        $targets = $this->resolveEntityTargets($path);
        if (!$targets) {
            $io->warning('No entity files detected.');
            return 0;
        }

        $parser = (new ParserFactory())->createForHostVersion();
        $statistics = ['processed' => 0, 'updated' => 0, 'errors' => 0];

        foreach ($targets as $entityFile) {
            try {
                $this->processEntityFile($io, $parser, $entityFile, $dryRun, $backup, $statistics);
            } catch (\Exception $e) {
                $statistics['errors']++;
                $io->error("Failed to process {$this->getRelativePath($entityFile)}: {$e->getMessage()}");
                $this->logger->error('Entity processing failed', [
                    'file' => $entityFile,
                    'error' => $e->getMessage()
                ]);
            }
        }


        $this->displayCompletionSummary($io, $statistics, \count($created));
        return $statistics['errors'] > 0 ? 1 : 0;
    }

    private function processEntityFile(
        SymfonyStyle $io,
        \PhpParser\Parser $parser,
        string $entityFile,
        bool $dryRun,
        bool $backup,
        array &$statistics
    ): void {
        $statistics['processed']++;

        $code = @file_get_contents($entityFile);
        if ($code === false) {
            throw new \RuntimeException('Cannot read file');
        }

        $ast = $parser->parse($code);
        if (!$ast) {
            throw new \RuntimeException('Cannot parse PHP file');
        }

        [$nsName, $className, $classNode] = $this->extractNamespaceAndClass($ast);
        if (!$classNode || !$className || !$nsName) {
            throw new \RuntimeException('Cannot find namespace and class');
        }

        $entityFqcn = $nsName.'\\'.$className;

        // Find translatable properties
        $translatableFields = $this->findTranslatableFields($classNode);

        if (empty($translatableFields)) {
            $this->logger->debug('No translatable fields found', ['entity' => $entityFqcn]);
            $io->writeln("📄 <comment>{$this->getRelativePath($entityFile)}</comment> - No translatable fields");
            return;
        }

        // Validate that properties are simple (not already hooks)
        $this->validateSimpleProperties($classNode, $translatableFields, $entityFile);

        $io->section($entityFqcn);
        $io->writeln(" • Found translatable fields: <info>" . implode(', ', $translatableFields) . "</info>");

        if ($dryRun) {
            $io->writeln(" • <comment>[DRY RUN]</comment> Would convert to property hooks");
        } else {
            $updated = $this->updater->updateEntity($entityFile, $translatableFields, $dryRun, $backup);

            if ($updated) {
                $statistics['updated']++;
                $io->writeln(" • ✅ <info>Converted to property hooks</info>");
            } else {
                $io->writeln(" • ⚠️  <comment>No changes made</comment>");
            }
        }
    }

    private function findTranslatableFields(Class_ $classNode): array
    {
        $fields = [];
        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof Property || !$stmt->isPublic()) {
                continue;
            }
            if (!$this->hasTranslatableAttribute($stmt)) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                $fields[] = $prop->name->toString();
            }
        }
        return $fields;
    }

    private function validateSimpleProperties(Class_ $classNode, array $translatableFields, string $entityFile): void
    {
        $fieldSet = array_flip($translatableFields);
        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof Property || !$stmt->isPublic()) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                $name = $prop->name->toString();
                if (isset($fieldSet[$name]) && !empty($prop->hooks ?? [])) {
                    throw new \RuntimeException(
                        "Property '{$name}' already has property hooks. ".
                        "This command requires simple properties without existing hooks. ".
                        "File: " . basename($entityFile)
                    );
                }
            }
        }
    }

    private function hasTranslatableAttribute(Property $property): bool
    {
        foreach ($property->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name->toString();
                if ($name === 'Translatable' ||
                    $name === 'Survos\\BabelBundle\\Attribute\\Translatable' ||
                    str_ends_with($name, '\\Translatable')) {
                    return true;
                }
            }
        }
        return false;
    }

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

    private function reportLocaleConfiguration(SymfonyStyle $io): void
    {
        $enabled = array_values(array_unique(array_filter($this->enabledLocales, 'strlen')));
        $io->section('Locale Configuration');
        $io->text('Default: <info>'.$this->defaultLocale.'</info>');
        $io->text('Enabled: <info>'.($enabled ? implode(', ', $enabled) : '(none)').'</info>');

        if (!$enabled || (count($enabled) === 1 && $enabled[0] === $this->defaultLocale)) {
            $io->warning("Consider configuring 'framework.enabled_locales' for multiple languages.");
            $io->writeln("Example configuration:");
            $io->writeln("  framework:");
            $io->writeln("    default_locale: ".$this->defaultLocale);
            $io->writeln("    enabled_locales: ['".$this->defaultLocale."', 'es', 'de', 'fr']");
        }
    }

    private function resolveEntityTargets(?string $path): array
    {
        $targets = [];

        if ($path === null) {
            $root = $this->projectDir.'/src/Entity';
            if (is_dir($root)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        if (str_contains($file->getPathname(), '/Translations/')) {
                            continue;
                        }
                        $targets[] = $file->getPathname();
                    }
                }
            }
        } else {
            if (is_dir($path)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $targets[] = $file->getPathname();
                    }
                }
            } elseif (is_file($path)) {
                $targets[] = $path;
            }
        }

        sort($targets);
        return $targets;
    }

    private function getRelativePath(string $absolutePath): string
    {
        return str_starts_with($absolutePath, $this->projectDir.'/')
            ? substr($absolutePath, strlen($this->projectDir) + 1)
            : $absolutePath;
    }

    private function displayCompletionSummary(SymfonyStyle $io, array $statistics, int $scaffoldCount): void
    {
        $io->newLine();

        if ($statistics['errors'] > 0) {
            $io->error(sprintf(
                'Completed with errors. Processed: %d, Updated: %d, Errors: %d%s',
                $statistics['processed'],
                $statistics['updated'],
                $statistics['errors'],
                $scaffoldCount ? ", Scaffolds: $scaffoldCount" : ''
            ));
        } else {
            $io->success(sprintf(
                'All done! Processed: %d, Updated: %d%s',
                $statistics['processed'],
                $statistics['updated'],
                $scaffoldCount ? ", Scaffolds: $scaffoldCount" : ''
            ));
        }

        if ($statistics['updated'] > 0) {
            $io->note('Remember to update your database schema after converting properties to hooks.');
        }
    }
}
