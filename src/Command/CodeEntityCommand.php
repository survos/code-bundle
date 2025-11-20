<?php
declare(strict_types=1);

// File: src/Command/CodeEntityCommand.php
// Survos\CodeBundle — Generate a Doctrine entity from sample data,
// enriched by Jsonl profile (FieldStats / JsonlProfile).

namespace Survos\CodeBundle\Command;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use League\Csv\Reader as CsvReader;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\Visibility;
use Survos\CodeBundle\Service\GeneratorService;
use Survos\JsonlBundle\Model\JsonlProfile;
use Survos\JsonlBundle\Model\FieldStats;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use function Symfony\Component\String\u;

// Optional: use survos/jsonl-bundle reader when available
use Survos\JsonlBundle\Reader\JsonlReader as SurvosJsonlReader;

#[AsCommand('code:entity', 'Generate a PHP 8.4 Doctrine entity from sample data (optionally with Jsonl profile).')]
final class CodeEntityCommand extends Command
{
    public function __construct(
        private readonly GeneratorService $generatorService,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('short name of the entity to generate')]
        string $name,
        #[Option('Inline JSON; if omitted, read from STDIN')]
        ?string $json = null,
        #[Option('primary key name if known', name: 'pk')]
        ?string $primaryField = null,
        #[Option('Entity namespace', name: 'ns')]
        string $entityNamespace = 'App\\Entity',
        #[Option('Repository namespace')]
        string $repositoryNamespace = 'App\\Repository',
        #[Option('Output directory')]
        string $outputDir = 'src/Entity',
        #[Option('Path to a CSV/JSON/JSONL file (first record will be used, profile if present)')]
        ?string $file = null,
        #[Option('Add a MeiliIndex attribute')]
        ?bool $meili = null,
        #[Option('Configure as an API Platform resource')]
        ?bool $api = null,
        #[Option('Overwrite existing files without confirmation', name: 'force')]
        bool $force = false,
    ): int {
        $io->title('Entity generator — ' . $this->projectDir);

        $profile = null;
        $data    = null;
        $meili ??= true; // true by default, whereas api is false

        // If --file is given, prefer profile; fall back to single-record sample.
        if ($file) {
            $profilePath = $file . '.profile.json';
            if (is_file($profilePath)) {
                try {
                    $raw = file_get_contents($profilePath);
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    $profile = JsonlProfile::fromArray($decoded);
                    $io->note(sprintf('Loaded profile from %s', $profilePath));
                } catch (\Throwable $e) {
                    $io->warning(sprintf(
                        'Could not read profile artifact (%s): %s',
                        $profilePath,
                        $e->getMessage()
                    ));
                }
            }

            // If no profile or profile didn’t parse, we still try a first-record sample
            if ($profile === null) {
                $data = $this->firstRecordFromFile($file);
            }
        }

        // No --file or profile: fallback to JSON / STDIN
        if ($file === null) {
            if ($json === null) {
                $stdin = trim((string) stream_get_contents(STDIN));
                $json = $stdin !== '' ? $stdin : null;
            }
            if ($json === null) {
                $io->error('Provide --file=... (csv/json/jsonl), or --json=..., or pipe JSON on STDIN.');
                return Command::FAILURE;
            }
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $data = is_array($decoded) ? $decoded : null;
        }

        if ($profile === null && (!is_array($data) || $data === [])) {
            $io->error('Could not load a non-empty first record and no profile is available.');
            return Command::FAILURE;
        }

        // Field names come from profile if available, else from data
        $fieldNames = [];
        if ($profile) {
            $fieldNames = array_keys($profile->fields);
        } elseif (is_array($data)) {
            $fieldNames = array_keys($data);
        }

        if ($fieldNames === []) {
            $io->error('No fields detected.');
            return Command::FAILURE;
        }

        // ---------------------------------------------------------------------
        // Build entity
        // ---------------------------------------------------------------------
        $phpFile = new PhpFile();
        $phpFile->setStrictTypes();

        $class = new ClassType($name);
        $class->setFinal();
        $class->addComment('@generated by code:entity');
        if ($file) {
            $class->addComment('@source ' . $file);
        }

        $repoName = $name . 'Repository';
        $repoFqcn = $repositoryNamespace . '\\' . $repoName;
        $class->addAttribute(Entity::class, [
            'repositoryClass' => new Literal($repoName . '::class'),
        ]);

        $ns = new PhpNamespace($entityNamespace);
        $ns->addUse(Entity::class);
        $ns->addUse(Column::class);
        $ns->addUse(Id::class);
        $ns->addUse(Types::class);
        $ns->addUse(DateTimeImmutable::class);
        $ns->addUse($repoFqcn);

        $filterable = [];
        $sortable   = [];
        $searchable = [];

        if ($api) {
            $ns->addUse(ApiProperty::class);
            $ns->addUse(ApiResource::class);
            $class->addAttribute(ApiResource::class);
        }

        // ---------------------------------------------------------------------
        // Primary key heuristic (now can use uniqueness from profile when present)
        // ---------------------------------------------------------------------
        if (!$primaryField) {
            $pkCandidates = ['id','code','sku','ssn','uid','uuid','key'];

            // 1) Named candidates
            foreach ($pkCandidates as $c) {
                if (in_array($c, $fieldNames, true)) {
                    $primaryField = $c;
                    break;
                }
            }

            // 2) If still unknown and we have a profile, pick first unique field
            if (!$primaryField && $profile) {
                foreach ($profile->fields as $nameField => $fs) {
                    if ($fs->total > 0
                        && !$fs->distinctCapReached
                        && $fs->distinctCount === $fs->total
                        && !$fs->isBooleanLike()
                        && $fs->storageHint !== 'json'
                    ) {
                        $primaryField = $nameField;
                        break;
                    }
                }
            }

            // 3) Fallback to first field name
            $primaryField ??= ($fieldNames[0] ?? null);
        }

        // Primary key property name (for MeiliIndex primaryKey), based on the
        // same transformation used for properties.
        $primaryKeyProperty = null;
        if ($primaryField) {
            $pkProp = preg_replace('/[^a-zA-Z0-9_]/', '_', $primaryField);
            $primaryKeyProperty = u((string) $pkProp)->camel()->toString();
        }

        // ---------------------------------------------------------------------
        // Field loop
        // ---------------------------------------------------------------------
        foreach ($fieldNames as $field) {
            assert(is_string($field), "$field is not a string.");

            $propName = preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
            $propName = u($propName)->camel()->toString();

            /** @var FieldStats|null $stats */
            $stats = $profile?->fields[$field] ?? null;

            // Determine Doctrine / PHP types from profile if present, else fallback sample-based heuristics
            if ($stats) {
                [$phpType, $ormArgs] = $this->determineTypesFromStats($field, $stats);
            } else {
                $value = $data[$field] ?? null;
                $value = $this->coerceValue($field, $value);
                [$phpType, $ormArgs] = $this->inferFromSample($field, $value);
            }

            $property = $class->addProperty($propName)->setVisibility(Visibility::Public);
            $property->setType($phpType);
            $property->setValue(null);

            // -----------------------------------------------------------------
            // Comments from FieldStats
            // -----------------------------------------------------------------
            if ($stats) {
                $property->addComment(sprintf('Field: %s', $field));
                if (property_exists($stats, 'originalKey') && $stats->originalKey && $stats->originalKey !== $field) {
                    $property->addComment(sprintf('Original key: %s', $stats->originalKey));
                }
                $property->addComment(sprintf(
                    'total=%d, nulls=%d, distinct=%s',
                    $stats->total,
                    $stats->nulls,
                    $stats->getDistinctLabel()
                ));

                $range = $stats->getRangeLabel();
                if ($range !== '') {
                    $property->addComment(sprintf('length: %s', $range));
                }

                $topFirst = $stats->getTopOrFirstValueLabel();
                if ($topFirst !== '') {
                    $property->addComment(sprintf('Top/First value: %s', $topFirst));
                }

                if ($stats->isFacetCandidate()) {
                    $property->addComment('Facet candidate');
                }
                if ($stats->isBooleanLike()) {
                    $property->addComment('Boolean-like');
                }
                if ($stats->distinctCapReached) {
                    $property->addComment('Distinct counting capped in profile.');
                }
            }

            // -----------------------------------------------------------------
            // ORM Column + Id
            // -----------------------------------------------------------------
            $ormArgs['nullable'] = true;
            $property->addAttribute(Column::class, $ormArgs);

            $isPk = ($field === $primaryField);
            if ($isPk) {
                $property->addAttribute(Id::class);
            }

            // -----------------------------------------------------------------
            // ApiProperty (optional)
            // -----------------------------------------------------------------
            if ($api) {
                $apiArgs = [];

                if ($stats) {
                    $descParts = [];
                    $descParts[] = sprintf('types=[%s]', $stats->getTypesString());
                    $descParts[] = sprintf('distinct=%s', $stats->getDistinctLabel());
                    $range = $stats->getRangeLabel();
                    if ($range !== '') {
                        $descParts[] = sprintf('range=%s', $range);
                    }
                    if ($stats->isFacetCandidate()) {
                        $descParts[] = 'facetCandidate';
                    }
                    if ($stats->isBooleanLike()) {
                        $descParts[] = 'booleanLike';
                    }

                    $apiArgs['description'] = sprintf(
                        'Field "%s": %s',
                        $field,
                        implode(', ', $descParts)
                    );

                    $example = $stats->getTopOrFirstValueLabel();
                    if ($example !== '') {
                        $apiArgs['example'] = $example;
                    }
                }

                $property->addAttribute(ApiProperty::class, $apiArgs);
            }

            // -----------------------------------------------------------------
            // Meili heuristics (optional)
            // -----------------------------------------------------------------
            if ($meili && $stats && $profile) {
                $sh    = $stats->storageHint;
                $lower = strtolower($field);

                // Name used in PHP + serialization → must match Meili docs
                $meiliField = $propName;

                // Facet & bool-like → filterable
                if ($stats->isFacetCandidate() || $stats->isBooleanLike()) {
                    $filterable[] = $meiliField;
                }

                // Numeric → sortable
                if (in_array($sh, ['int','float'], true)) {
                    $sortable[] = $meiliField;
                }

                // Full-text searchable:
                // Text fields that are not facets / boolean-like and not obvious IDs/codes.
                if ($sh === 'text'
                    && !$stats->isFacetCandidate()
                    && !$stats->isBooleanLike()
                ) {
                    if (!str_contains($lower, 'id') && !str_contains($lower, 'code')) {
                        $searchable[] = $meiliField;
                    }
                }
            }
        }

        // Finalize MeiliIndex attribute
        if ($meili) {
            $ns->addUse(MeiliIndex::class);
            $class->addAttribute(MeiliIndex::class, [
                'primaryKey' => $primaryKeyProperty ?? $primaryField,
                'filterable' => array_values(array_unique($filterable)),
                'sortable'   => array_values(array_unique($sortable)),
                'searchable' => array_values(array_unique($searchable)),
            ]);
        }

        $ns->add($class);
        $phpFile->addNamespace($ns);

        $code = (string) $phpFile;

        $fs = new Filesystem();
        $targetPath = rtrim($outputDir, '/').'/'.$name.'.php';
        $fs->mkdir(\dirname($targetPath));

        // If file exists and --force not given, ask before overwriting.
        if (is_file($targetPath) && !$force) {
            $overwrite = $io->confirm(
                sprintf('File %s already exists. Overwrite it?', $targetPath),
                false
            );

            if (!$overwrite) {
                $io->warning(sprintf('Skipped overwriting existing entity: %s', $targetPath));
                $this->createRepo($outputDir, $name);
                return Command::SUCCESS;
            }
        }

        $fs->dumpFile($targetPath, $code);

        $this->createRepo($outputDir, $name);

        $io->success(sprintf('Created entity: %s (%s)', $name, $targetPath));
        return Command::SUCCESS;
    }

    /**
     * Use FieldStats to determine PHP and Doctrine types.
     *
     * @return array{0:string,1:array<string,mixed>} [phpType, ormArgs]
     */
    private function determineTypesFromStats(string $field, FieldStats $stats): array
    {
        $sh = $stats->storageHint;
        $lowerField = strtolower($field);

        // Boolean:
        // - explicit bool storageHint, OR
        // - boolean-like data *and* field name that looks like a flag.
        if ($sh === 'bool' || ($stats->isBooleanLike() && $this->looksBooleanField($lowerField))) {
            return [
                '?bool',
                ['type' => new Literal('Types::BOOLEAN')],
            ];
        }

        // Integer
        if ($sh === 'int') {
            return [
                '?int',
                ['type' => new Literal('Types::INTEGER')],
            ];
        }

        // Float
        if ($sh === 'float') {
            return [
                '?float',
                ['type' => new Literal('Types::FLOAT')],
            ];
        }

        // JSON
        if ($sh === 'json') {
            return [
                '?array',
                [
                    'type'    => new Literal('Types::JSON'),
                    'options' => ['jsonb' => true],
                ],
            ];
        }

        // Text vs string
        if ($sh === 'text') {
            return [
                '?string',
                ['type' => new Literal('Types::TEXT')],
            ];
        }

        // Default: string with length
        $length = 255;
        if ($stats->stringMaxLength !== null && $stats->stringMaxLength > 0) {
            $length = min($stats->stringMaxLength, 255);
        }

        return [
            '?string',
            ['length' => $length],
        ];
    }

    /**
     * Legacy inference from a single value (used only if no profile exists).
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function inferFromSample(string $field, mixed $value): array
    {
        $lower = strtolower($field);

        if ($field === 'id') {
            $isInt = is_int($value) || (is_string($value) && ctype_digit($value));
            return [
                $isInt ? '?int' : '?string',
                $isInt
                    ? ['type' => new Literal('Types::INTEGER')]
                    : ['length' => 255],
            ];
        }

        $isIsoDate = is_string($value) && preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/',
                $value
            ) === 1;
        if ($isIsoDate || in_array($lower, ['createdat','updatedat','scrapedat','fetchedat'], true)) {
            return [
                '?DateTimeImmutable',
                ['type' => new Literal('Types::DATETIME_IMMUTABLE')],
            ];
        }

        if (is_bool($value) || in_array($lower, ['enabled','active','deleted','featured','fetched'], true)) {
            return [
                '?bool',
                ['type' => new Literal('Types::BOOLEAN')],
            ];
        }

        if (is_int($value) || in_array($lower, ['page','count','index','position','rank','duration','size'], true)) {
            return [
                '?int',
                ['type' => new Literal('Types::INTEGER')],
            ];
        }

        if (is_float($value)) {
            return [
                '?float',
                ['type' => new Literal('Types::FLOAT')],
            ];
        }

        if (is_array($value)) {
            return [
                '?array',
                [
                    'type'    => new Literal('Types::JSON'),
                    'options' => ['jsonb' => true],
                ],
            ];
        }

        $isUrlField   = str_ends_with($field, 'Url');
        $looksLikeUrl = is_string($value) && filter_var($value, FILTER_VALIDATE_URL);

        if ($isUrlField || $looksLikeUrl) {
            return [
                '?string',
                ['length' => 2048],
            ];
        }

        return [
            '?string',
            ['length' => 255],
        ];
    }

    private function firstRecordFromFile(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // CSV/TSV
        if (in_array($ext, ['csv', 'tsv', 'txt'], true)) {
            $sample    = file_get_contents($path, false, null, 0, 8192) ?: '';
            $delimiter = str_contains($sample, "\t") ? "\t" : ',';

            $csv = CsvReader::createFromPath($path, 'r');
            $csv->setHeaderOffset(0);
            $csv->setDelimiter($delimiter);
            $csv->setEnclosure('"');

            foreach ($csv->getRecords() as $row) {
                return (array) $row;
            }
            return [];
        }

        // JSON
        if ($ext === 'json') {
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new \RuntimeException("Unable to read $path");
            }
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($decoded) || $decoded === []) {
                return [];
            }

            if (array_is_list($decoded)) {
                $first = $decoded[0] ?? null;
                return is_array($first) ? $first : [];
            }
            return $decoded;
        }

        // JSONL / NDJSON
        if ($ext === 'jsonl' || $ext === 'ndjson') {
            if (class_exists(SurvosJsonlReader::class)) {
                $reader = new SurvosJsonlReader($path);
                foreach ($reader as $row) {
                    return (array) $row;
                }
                return [];
            }

            $fh = fopen($path, 'r');
            if ($fh === false) {
                throw new \RuntimeException("Unable to open $path");
            }
            try {
                while (($line = fgets($fh)) !== false) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    $row = json_decode($line, true);
                    if (is_array($row)) {
                        return $row;
                    }
                    break;
                }
                return [];
            } finally {
                fclose($fh);
            }
        }

        throw new \InvalidArgumentException("Unsupported file extension: .$ext (use csv, json, or jsonl)");
    }

    private function coerceValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (!is_string($value)) {
            return $value;
        }

        $v = trim($value);

        $looksPlural = static function (string $name): bool {
            $n = strtolower($name);
            if (\in_array($n, ['is','has','was','ids','status'], true)) {
                return false;
            }
            return str_ends_with($n, 's');
        };

        if ($looksPlural($field) && (str_contains($v, ',') || str_contains($v, '|'))) {
            $parts = preg_split('/[|,]/', $v);
            $parts = array_map(static fn(string $s) => trim($s), $parts);
            $parts = array_values(array_filter($parts, static fn($s) => $s !== ''));
            return $parts;
        }

        if ($v === '') {
            return null;
        }

        if (str_contains($v, '|')) {
            $parts = array_map(static fn(string $s) => trim($s), explode('|', $v));
            $parts = array_values(array_filter($parts, static fn($s) => $s !== ''));
            return $parts;
        }

        $l = strtolower($v);
        if (in_array($l, ['true','false','yes','no','y','n','on','off'], true)) {
            return in_array($l, ['true','yes','y','on','1'], true);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/', $v) === 1) {
            try {
                return new DateTimeImmutable($v);
            } catch (\Throwable) {
            }
        }

        $numericPreferred = [
            'page','count','index','position','rank','duration','size',
            'budget','revenue','popularity','score','rating','price','quantity'
        ];
        $preferNumeric = in_array(strtolower($field), $numericPreferred, true);

        if (preg_match('/^-?\d+$/', $v) === 1) {
            $hasLeadingZero = strlen($v) > 1 && $v[0] === '0';
            if ($preferNumeric || !$hasLeadingZero) {
                return (int) $v;
            }
            return $v;
        }

        if (is_numeric($v) && preg_match('/^-?(?:\d+\.\d+|\d+\.|\.\d+|\d+)(?:[eE][+\-]?\d+)?$/', $v) === 1) {
            return (float) $v;
        }

        return $v;
    }

    private function looksBooleanField(string $field): bool
    {
        return str_starts_with($field, 'is_')
            || str_starts_with($field, 'has_')
            || str_ends_with($field, '_flag')
            || str_ends_with($field, '_bool')
            || in_array($field, ['enabled','disabled','active','deleted','featured','fetched'], true);
    }

    private function createRepo(string $entityDir, string $entityName): void
    {
        $repoDir = str_replace('Entity', 'Repository', $entityDir);
        $repoClass = $entityName . 'Repository';

        if (!is_dir($repoDir)) {
            mkdir($repoDir, 0o775, true);
        }

        $repoFilename = $repoDir . '/' . $repoClass . '.php';

        if (!file_exists($repoFilename)) {
            $code = sprintf(<<<'PHPSTR'
<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\%s;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class %s extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, %s::class);
    }
}

PHPSTR,
                $entityName,
                $repoClass,
                $entityName
            );

            file_put_contents($repoFilename, $code);
        }
    }
}

