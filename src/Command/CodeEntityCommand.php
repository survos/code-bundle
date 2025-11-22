<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Command;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Survos\CodeBundle\Service\GeneratorService;
use Survos\CodeBundle\Service\ProfileResolver;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generate a Doctrine entity class from a JSONL profile (or raw data).
 *
 * Typical pipeline:
 *   bin/console import:convert data/movies.csv
 *   bin/console code:entity var/data/movies.profile.json "App\\Entity\\Movie"
 *   bin/console import:entities App\\Entity\\Movie var/data/movies.jsonl
 */
#[AsCommand('code:entity', 'Generate a Doctrine entity class from analyzed data')]
final class CodeEntityCommand
{
    public function __construct(
        private readonly ProfileResolver $profileResolver,
        private GeneratorService $generatorService, // kept for historical reasons
        private readonly string $projectDir,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to a CSV/JSON/JSONL or .profile.json file (profiler will be used)')]
        string $inputPath,
        #[Argument('Fully-qualified class name for the generated entity (e.g. "App\\Entity\\Movie")')]
        string $className,
        #[Option('Output PHP file (defaults to PSR-4 guess: src/Entity/<ShortName>.php)')]
        ?string $output = null,
        #[Option('Repository FQCN (defaults to "<EntityNamespace>\\Repository\\<ShortName>Repository")')]
        ?string $repositoryClass = null,
        #[Option('Mark the "id" field as auto-generated integer primary key if present')]
        bool $useIdField = true,
    ): int {
        $io->title('Code / Entity Generator');

        // 1) Resolve profile (existing .profile.json or fresh profile)
        $profile = $this->profileResolver->resolve($inputPath, $io);
        $fields = $profile['fields'];

        if (empty($fields)) {
            $io->error('No fields found in profile; cannot generate entity.');

            return Command::FAILURE;
        }

        // 2) Parse FQCN
        if (!\str_contains($className, '\\')) {
            $io->error('Class name must be fully-qualified (e.g. "App\\Entity\\Movie").');

            return Command::FAILURE;
        }

        $nsName = \substr($className, 0, \strrpos($className, '\\'));
        $shortName = \substr($className, \strrpos($className, '\\') + 1);

        // 3) Guess repository FQCN if not provided
        if ($repositoryClass === null) {
            $repositoryClass = $nsName . '\\Repository\\' . $shortName . 'Repository';
        }

        // 4) Determine output path
        $outputPath = $output ?? $this->guessOutputPath($nsName, $shortName);
        $io->text(sprintf('Target class: <info>%s</info>', $className));
        $io->text(sprintf('Repository   : <info>%s</info>', $repositoryClass));
        $io->text(sprintf('Output file  : <info>%s</info>', $outputPath));

        // 5) Build PHP file with Nette\PhpGenerator
        $file = new PhpFile();
        $file->setStrictTypes();

        $ns = new PhpNamespace($nsName);
        $ns->addUse(ORM::class);
        $ns->addUse(Types::class);

        $class = $ns->addClass($shortName);
        $class->setFinal();
        $class->addComment(sprintf('Auto-generated from %s via code:entity.', $inputPath));

        // #[ORM\Entity(repositoryClass: MovieRepository::class)]
        $class->addAttribute(ORM\Entity::class, [
            'repositoryClass' => new Literal($repositoryClass . '::class'),
        ]);

        // 6) Create properties from fields
        if ($useIdField && isset($fields['id'])) {
            $this->addIdProperty($class, $fields['id']);
            unset($fields['id']);
        }

        foreach ($fields as $name => $stats) {
            $this->addPropertyFromStats($class, (string) $name, $stats);
        }

        $file->addNamespace($ns);

        // 7) Write file
        $dir = \dirname($outputPath);
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o777, true);
        }

        \file_put_contents($outputPath, (string) $file);

        $io->success(sprintf('Entity %s generated at %s', $className, $outputPath));

        return Command::SUCCESS;
    }

    private function guessOutputPath(string $namespace, string $shortName): string
    {
        // Basic guess: respect "Entity" segment; default to projectDir/src/Entity/<ShortName>.php
        $segments = \explode('\\', $namespace);
        $entityIndex = array_search('Entity', $segments, true);

        $baseDir = \rtrim($this->projectDir, '/') . '/src';

        if ($entityIndex !== false) {
            $subPath = \implode('/', \array_slice($segments, $entityIndex));
            return sprintf('%s/%s/%s.php', $baseDir, $subPath, $shortName);
        }

        return sprintf('%s/Entity/%s.php', $baseDir, $shortName);
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function addIdProperty(ClassType $class, array $stats): void
    {
        $prop = $class->addProperty('id');
        $prop->setProtected();
        $prop->setType('?int');
        $prop->setComment('@var int|null');

        $prop->addAttribute(ORM\Id::class);
        $prop->addAttribute(ORM\GeneratedValue::class);
        $prop->addAttribute(ORM\Column::class, [
            'type' => new Literal('Types::INTEGER'),
        ]);
    }

    /**
     * @param array<string, mixed> $stats
     */
    private function addPropertyFromStats(ClassType $class, string $name, array $stats): void
    {
        $storageHint = $stats['storageHint'] ?? null;
        $booleanLike = $stats['booleanLike'] ?? false;
        $nulls = (int) ($stats['nulls'] ?? 0);
        $lengths = $stats['stringLengths'] ?? ['max' => null];

        $nullable = $nulls > 0;

        [$phpType, $doctrineType, $length] = $this->inferTypes($storageHint, $booleanLike, $lengths);

        $prop = $class->addProperty($name);
        $prop->setProtected();
        $prop->setType($nullable ? '?' . $phpType : $phpType);
        $prop->setComment(sprintf('@var %s%s', $phpType, $nullable ? '|null' : ''));

        $columnArgs = [
            'type' => new Literal('Types::' . $doctrineType),
        ];
        if ($nullable) {
            $columnArgs['nullable'] = true;
        }
        if ($length !== null && $doctrineType === 'STRING') {
            $columnArgs['length'] = $length;
        }

        $prop->addAttribute(ORM\Column::class, $columnArgs);
    }

    /**
     * @param array<string, mixed> $lengths
     * @return array{0: string, 1: string, 2: int|null} [phpType, doctrineTypeConst, length]
     */
    private function inferTypes(?string $storageHint, bool $booleanLike, array $lengths): array
    {
        if ($booleanLike || $storageHint === 'bool') {
            return ['bool', 'BOOLEAN', null];
        }

        if ($storageHint === 'int') {
            return ['int', 'INTEGER', null];
        }

        if ($storageHint === 'float') {
            return ['float', 'FLOAT', null];
        }

        if ($storageHint === 'json') {
            return ['array', 'JSON', null];
        }

        // Default string logic
        $max = $lengths['max'] ?? null;
        if (\is_int($max) && $max > 0 && $max <= 255) {
            return ['string', 'STRING', 255];
        }

        return ['string', 'TEXT', null];
    }
}
