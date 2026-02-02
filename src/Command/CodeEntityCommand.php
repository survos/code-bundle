<?php
declare(strict_types=1);

// File: src/Command/CodeEntityCommand.php
// Profile-driven entity generator.
//
// Adds --babel:
//  - If enabled, marks string properties with profile naturalLanguageLike=true as #[Translatable].
//  - Does not force class-level Babel config (BabelLocale/BabelStorage) yet.

namespace Survos\CodeBundle\Command;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\Visibility;
use Survos\BabelBundle\Attribute\Translatable;
use Survos\CodeBundle\Service\GeneratorService;
use Survos\ImportBundle\Contract\DatasetPathsFactoryInterface;
use Survos\JsonlBundle\Model\FieldStats;
use Survos\JsonlBundle\Model\JsonlProfile;
use Survos\MeiliBundle\Metadata\MeiliIndex;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Serializer\Attribute\Groups;
use function Symfony\Component\String\u;

#[AsCommand('code:entity', 'Generate a Doctrine entity from a Jsonl profile (no CSV/JSON/STDIN).')]
final class CodeEntityCommand extends Command
{
    // Add after the class declaration (around line 35)
    private const RESERVED_PROPERTY_NAMES = ['table', 'class', 'key', 'index', 'order', 'group', 'select', 'from', 'where'];

    public function __construct(
        private readonly GeneratorService $generatorService, // kept for BC / future
        private readonly string $projectDir,
        private readonly ?DatasetPathsFactoryInterface $pathsFactory = null,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Short or FQCN. Short names default to App\\Index when --dto, otherwise App\\Entity')]
        string $entityFqcn,
        #[Argument('Path to the Jsonl profile (e.g. data/amst.profile.json). Optional when --dataset is provided.')]
        ?string $profileFile = null,
        #[Option('primary key name if known', name: 'pk')]
        ?string $primaryField = null,
        #[Option('Add a MeiliIndex attribute (default: true)')]
        ?bool $meili = null,
        #[Option('Configure as an API Platform resource')]
        ?bool $api = null,
        #[Option('Generate an Index DTO instead of a Doctrine entity', name: 'dto')]
        bool $dto = false,
        #[Option('Dataset key (uses data/21_profile/obj.profile.json when data-bundle is installed)')]
        ?string $dataset = null,
        #[Option('Mark natural-language fields as #[Translatable] (BabelBundle)', name: 'babel')]
        bool $babel = false,
        #[Option('Overwrite existing files without confirmation', name: 'force')]
        bool $force = false,
    ): int {
        $io->title('Entity generator (profile-only) — ' . $this->projectDir);

        if (($profileFile === null || $profileFile === '') && $dataset !== null && $dataset !== '') {
            if ($this->pathsFactory === null) {
                $io->error(\sprintf(
                    'Missing profile path. You passed --dataset=%s, but no DatasetPathsFactoryInterface is registered. ' .
                    'Enable survos/data-bundle or pass an explicit profile path.',
                    $dataset
                ));
                return Command::FAILURE;
            }

            $paths = $this->pathsFactory->for($dataset);
            $profileFile = $paths->profileObjectPath();
        }

        if ($profileFile === null || $profileFile === '') {
            $io->error('Missing profile path. Provide the profile file or pass --dataset.');
            return Command::FAILURE;
        }

        if (!\is_file($profileFile)) {
            $io->error(\sprintf('Profile file "%s" does not exist.', $profileFile));
            return Command::FAILURE;
        }

        // ---------------------------------------------------------------------
        // Load profile JSON
        // ---------------------------------------------------------------------
        try {
            $raw     = (string) \file_get_contents($profileFile);
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Failed to read/parse profile "%s": %s', $profileFile, $e->getMessage()));
            return Command::FAILURE;
        }

        if (!\is_array($decoded)) {
            $io->error('Profile JSON did not decode to an array.');
            return Command::FAILURE;
        }

        try {
            $profile = JsonlProfile::fromArray($decoded);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Failed to hydrate JsonlProfile from "%s": %s', $profileFile, $e->getMessage()));
            return Command::FAILURE;
        }

        if (!\is_array($profile->fields) || $profile->fields === []) {
            $io->error('Profile has no fields. Aborting.');
            return Command::FAILURE;
        }

        $fieldNames   = \array_keys($profile->fields);
        $uniqueFields = $decoded['uniqueFields'] ?? [];
        if (!\is_array($uniqueFields)) {
            $uniqueFields = [];
        }

        // ---------------------------------------------------------------------
        // Determine entity namespace + class name from FQCN
        // ---------------------------------------------------------------------
        $pos = \strrpos($entityFqcn, '\\');
        if ($pos === false) {
            $entityFqcn = ($dto ? 'App\\Index\\' : 'App\\Entity\\') . $entityFqcn;
            $pos = \strrpos($entityFqcn, '\\');
        }

        $entityNamespace = \substr($entityFqcn, 0, $pos);
        $entityName      = \substr($entityFqcn, $pos + 1);

        if ($entityName === '') {
            $io->error(\sprintf('Invalid entity FQCN "%s".', $entityFqcn));
            return Command::FAILURE;
        }

        // Repository FQCN (entities only)
        $repoNamespace = null;
        $repoClass = null;
        $repoFqcn = null;
        if (!$dto) {
            $repoNamespace = \str_contains($entityNamespace, '\\Entity')
                ? \str_replace('\\Entity', '\\Repository', $entityNamespace)
                : $entityNamespace . '\\Repository';

            $repoClass = $entityName . 'Repository';
            $repoFqcn  = $repoNamespace . '\\' . $repoClass;
        }

        // ---------------------------------------------------------------------
        // Primary key selection (profile.uniqueFields / --pk only, no heuristics)
        // ---------------------------------------------------------------------
        if ($primaryField !== null) {
            if (!\array_key_exists($primaryField, $profile->fields)) {
                $io->error(\sprintf(
                    'Requested --pk="%s" not found in profile fields. Available: %s',
                    $primaryField,
                    \implode(', ', \array_keys($profile->fields))
                ));
                return Command::FAILURE;
            }
        } else {
            if ($uniqueFields !== []) {
                $primaryField = (string) $uniqueFields[0];
                if (!\array_key_exists($primaryField, $profile->fields)) {
                    $io->error(\sprintf(
                        'Profile uniqueFields[0] = "%s" but that field is not present in profile->fields.',
                        $primaryField
                    ));
                    return Command::FAILURE;
                }
            } else {
                $io->error('No primary key specified and profile has no uniqueFields. Pass --pk=fieldName or fix the profile.');
                return Command::FAILURE;
            }
        }

        $pkProp             = \preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $primaryField);
        $primaryKeyProperty = u((string) $pkProp)->camel()->toString();

        $meili ??= true;
        $api ??= $dto ? true : false;

        // ---------------------------------------------------------------------
        // Build entity
        // ---------------------------------------------------------------------
        $phpFile = new PhpFile();
        $phpFile->setStrictTypes();

        $ns = new PhpNamespace($entityNamespace);
        if (!$dto) {
            $ns->addUse(Entity::class);
            $ns->addUse(Column::class);
            $ns->addUse(Id::class);
        }
        $ns->addUse(DateTimeImmutable::class);
        if (!$dto) {
            $ns->addUse(Types::class);
        }
        if ($repoFqcn !== null) {
            $ns->addUse($repoFqcn);
        }

        if ($babel) {
            $ns->addUse(Translatable::class);
        }

        $class = new ClassType($entityName);
        $class->setFinal();
        $class->addComment('@generated by code:entity from profile');
        $class->addComment('@profile ' . $profileFile);

        if (!$dto && $repoClass !== null) {
            $class->addAttribute(Entity::class, [
                'repositoryClass' => new Literal($repoClass . '::class'),
            ]);
        }

        $filterable = [];
        $sortable   = [];
        $searchable = [];

        $code      = u($entityName)->camel()->toString();
        $readGroup = "$code.read";

        if ($api) {
            // Note: ApiFilter deprecation will be addressed separately (you flagged it; agreed).
            $ns->addUse(ApiProperty::class);
            $ns->addUse(ApiResource::class);
            $ns->addUse(ApiFilter::class);
            $ns->addUse(Get::class);
            $ns->addUse(GetCollection::class);
            $ns->addUse(SearchFilter::class);
            $ns->addUse(OrderFilter::class);
            $ns->addUse(Groups::class);

            $class->addAttribute(ApiResource::class, [
                'operations' => [
                    new Literal(\sprintf('new Get(normalizationContext: ["groups" => ["%s"]])', $readGroup)),
                    new Literal(\sprintf('new GetCollection(normalizationContext: ["groups" => ["%s"]])', $readGroup)),
                ],
            ]);
        }

        foreach ($fieldNames as $field) {
            \assert(\is_string($field), "$field is not a string.");

            $propName = \preg_replace('/[^a-zA-Z0-9_]/', '_', $field);
            $propName = u((string) $propName)->camel()->toString();

            if (\in_array(\strtolower($propName), self::RESERVED_PROPERTY_NAMES, true)) {
                $propName .= 'Field';
            }

            /** @var FieldStats $stats */
            $stats = $profile->fields[$field] ?? null;
            if (!$stats instanceof FieldStats) {
                continue;
            }

            $rawFieldStats = $decoded['fields'][$field] ?? [];
            if (!\is_array($rawFieldStats)) {
                $rawFieldStats = [];
            }

            [$phpType, $ormArgs] = $this->determineTypesFromStats($rawFieldStats, $stats);

            $property = $class->addProperty($propName)->setVisibility(Visibility::Public);

            $property->addComment(\sprintf('Profile field "%s"', $field));

            $originalName = $rawFieldStats['originalName'] ?? null;
            if (\is_string($originalName) && $originalName !== '' && $originalName !== $field) {
                $property->addComment(\sprintf('@original %s', $originalName));
            }

            $types = $rawFieldStats['types'] ?? [];
            $typesLabel = \is_array($types) ? \implode(', ', \array_map('strval', $types)) : 'n/a';
            $storageHint = (string) ($rawFieldStats['storageHint'] ?? ($stats->storageHint ?? 'n/a'));

            $property->addComment(\sprintf('@types %s (storageHint=%s)', $typesLabel, $storageHint));

            $total    = (int) ($rawFieldStats['total'] ?? ($stats->total ?? 0));
            $nulls    = (int) ($rawFieldStats['nulls'] ?? ($stats->nulls ?? 0));
            $distinct = (string) ($rawFieldStats['distinct'] ?? ($stats->distinct ?? ''));

            $property->addComment(\sprintf('@stats total=%d, nulls=%d, distinct=%s', $total, $nulls, $distinct));

            $propertyType = $phpType;
            $propertyValue = null;
            if ($phpType === '?array' && $nulls === 0) {
                $propertyType = 'array';
                $propertyValue = [];
            }

            $property->setType($propertyType);
            $property->setValue($propertyValue);

            // Flags from profiler (raw JSON)
            foreach (['booleanLike','urlLike','jsonLike','imageLike','naturalLanguageLike'] as $k) {
                if (!empty($rawFieldStats[$k])) {
                    $property->addComment('@' . $k . ' true');
                }
            }
            if (!empty($rawFieldStats['localeGuess'])) {
                $property->addComment('@localeGuess ' . (string) $rawFieldStats['localeGuess']);
            }

            // Babel: mark natural-language string fields as Translatable
            if ($babel) {
                $nlLike = !empty($rawFieldStats['naturalLanguageLike']);
                $basePhpType = \ltrim($phpType, '?');

                if ($nlLike && $basePhpType === 'string') {
                    $property->addAttribute(Translatable::class);
                }
            }

            // PK / Column
            $isPk = ($field === $primaryField);
            $nullable = !$isPk;
            if ($propertyType === 'array' && $nulls === 0) {
                $nullable = false;
            }
            $ormArgs['nullable'] = $nullable;

            if (!$dto) {
                $property->addAttribute(Column::class, $ormArgs);
                if ($isPk) {
                    $property->addAttribute(Id::class);
                }
            }

            // API bits (unchanged)
            if ($api) {
                $property->addAttribute(ApiProperty::class, [
                    'description' => \sprintf(
                        'Profile field "%s": types=[%s], storageHint=%s, distinct=%s, nulls=%d',
                        $field,
                        $typesLabel,
                        $storageHint,
                        $distinct,
                        $nulls
                    ),
                ]);
                $property->addAttribute(Groups::class, ['groups' => [$readGroup]]);
            }

            // -----------------------------------------------------------------
            // Meili: generate sane defaults
            // -----------------------------------------------------------------
            if ($meili) {
                $lower = \strtolower($field);

                $isUnique = $isPk
                    || \in_array($field, $uniqueFields, true)
                    || \str_contains($lower, 'id');

                $basePhpType  = \ltrim($phpType, '?');
                $isArrayField = ($basePhpType === 'array');
                $isIntField   = ($basePhpType === 'int');
                $isFloatField = ($basePhpType === 'float');

                $facetCandidate = !empty($rawFieldStats['facetCandidate'])
                    || (\method_exists($stats, 'isFacetCandidate') && $stats->isFacetCandidate());
                $booleanLike = !empty($rawFieldStats['booleanLike'])
                    || (\method_exists($stats, 'isBooleanLike') && $stats->isBooleanLike());

                $nlLike    = !empty($rawFieldStats['naturalLanguageLike']);
                $urlLike   = !empty($rawFieldStats['urlLike']);
                $jsonLike  = !empty($rawFieldStats['jsonLike']);
                $imageLike = !empty($rawFieldStats['imageLike']);

                $totalCount    = (int) ($rawFieldStats['total'] ?? ($stats->total ?? 0));
                $distinctCount = (int) ($rawFieldStats['distinct'] ?? ($stats->distinct ?? 0));

                $isPayloadish = $this->isPayloadishField($lower, $urlLike, $imageLike, $jsonLike);
                $highCardinality = $this->isHighCardinality($totalCount, $distinctCount);

                // FILTERABLE
                // - Never facet payload-ish or high-cardinality fields (URLs, JSON blobs, per-row identifiers, etc.)
                // - Allow booleans/enums and *low-cardinality* facet candidates
                // - Arrays are NOT auto-facets; only facet them when they look like real tags (low-cardinality)
                if (
                    !$isUnique
                    && !$isPayloadish
                    && !$highCardinality
                ) {
                    if ($booleanLike) {
                        $filterable[] = $propName;
                    } elseif ($facetCandidate) {
                        $filterable[] = $propName;
                    } elseif ($isArrayField) {
                        // arrays: conservative default — only allow if they are low-cardinality (guard above)
                        $filterable[] = $propName;
                    }
                }

                // SORTABLE (+ numeric filterable when low-cardinality)
                if ($isIntField && !$booleanLike) {
                    $sortable[] = $propName;

                    if (
                        !$isUnique
                        && !$isPayloadish
                        && !$highCardinality
                    ) {
                        $filterable[] = $propName;
                    }
                } elseif ($isFloatField) {
                    $sortable[] = $propName;
                }

                // SEARCHABLE (natural language strings only; exclude payload-ish)
                if (
                    $basePhpType === 'string'
                    && $nlLike
                    && !$isPayloadish
                    && !$facetCandidate
                    && !$booleanLike
                    && !$urlLike
                    && !$jsonLike
                    && !$imageLike
                ) {
                    if (!\str_contains($lower, 'id') && !\str_contains($lower, 'code')) {
                        $searchable[] = $propName;
                    }
                }
            }
        }

        $filterable = \array_values(\array_unique($filterable));
        $sortable   = \array_values(\array_unique($sortable));
        $searchable = \array_values(\array_unique($searchable));

        $class->addConstant('FILTERABLE_FIELDS', $filterable)->setPublic();
        $class->addConstant('SORTABLE_FIELDS',   $sortable)->setPublic();
        $class->addConstant('SEARCHABLE_FIELDS', $searchable)->setPublic();

        if ($api) {
            $class->addAttribute(ApiFilter::class, [
                'filterClass' => new Literal('\\' . SearchFilter::class . '::class'),
                'properties'  => new Literal('self::FILTERABLE_FIELDS'),
            ]);
            $class->addAttribute(ApiFilter::class, [
                'filterClass' => new Literal('\\' . SearchFilter::class . '::class'),
                'properties'  => new Literal('self::SEARCHABLE_FIELDS'),
            ]);
            $class->addAttribute(ApiFilter::class, [
                'filterClass' => new Literal('\\' . OrderFilter::class . '::class'),
                'properties'  => new Literal('self::SORTABLE_FIELDS'),
            ]);
        }

        if ($meili) {
            $ns->addUse(MeiliIndex::class);
            $class->addAttribute(MeiliIndex::class, [
                'primaryKey' => $primaryKeyProperty,
                'filterable' => new Literal('self::FILTERABLE_FIELDS'),
                'sortable'   => new Literal('self::SORTABLE_FIELDS'),
                'searchable' => new Literal('self::SEARCHABLE_FIELDS'),
            ]);
        }

        $ns->add($class);
        $phpFile->addNamespace($ns);

        $code = (string) $phpFile;

        $fs = new Filesystem();

        $relative   = \preg_replace('/^App\\\\/', '', $entityFqcn);
        $relative   = \str_replace('\\', '/', (string) $relative);
        $targetPath = 'src/' . $relative . '.php';

        if (\is_file($targetPath) && !$force) {
            $overwrite = $io->confirm(\sprintf('File %s already exists. Overwrite it?', $targetPath), false);
            if (!$overwrite) {
                $io->warning(\sprintf('Skipped overwriting existing entity: %s', $targetPath));
                $this->createRepo($repoFqcn, $entityFqcn, $entityName);
                return Command::SUCCESS;
            }
        }

        $fs->dumpFile($targetPath, $code);
        if (!$dto && $repoFqcn !== null) {
            $this->createRepo($repoFqcn, $entityFqcn, $entityName);
        }

        $io->success(\sprintf('Created %s: %s (%s)', $dto ? 'DTO' : 'entity', $entityFqcn, $targetPath));
        return Command::SUCCESS;
    }

    private function isPayloadishField(string $lowerFieldName, bool $urlLike, bool $imageLike, bool $jsonLike): bool
    {
        if ($urlLike || $imageLike || $jsonLike) {
            return true;
        }

        // name-based backstops (kept intentionally blunt; these are almost always payload)
        foreach (['url', 'uri', 'image', 'media', 'thumbnail', 'link', 'href'] as $needle) {
            if (\str_contains($lowerFieldName, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isHighCardinality(int $total, int $distinct): bool
    {
        if ($total <= 0 || $distinct <= 0) {
            return false;
        }

        // High-cardinality guardrails:
        // - distinct/total >= 0.50 is usually per-row identity (URLs, IDs, etc.) and not a facet
        // - absolute cap also prevents small datasets from facetting essentially-unique fields
        if (($distinct / $total) >= 0.50) {
            return true;
        }

        if ($distinct >= 500) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string,mixed> $rawFieldStats
     * @return array{0:string,1:array<string,mixed>} [phpType, ormArgs]
     */
    private function determineTypesFromStats(array $rawFieldStats, FieldStats $stats): array
    {
        $storageHint = (string) ($rawFieldStats['storageHint'] ?? ($stats->storageHint ?? 'string'));

        $types = $rawFieldStats['types'] ?? [];
        $typesHasArray = \is_array($types) && \in_array('array', $types, true);

        if ($storageHint === 'json' || $typesHasArray) {
            return [
                '?array',
                ['type' => new Literal('Types::JSON')],
            ];
        }

        if ($storageHint === 'bool') {
            return [
                '?bool',
                ['type' => new Literal('Types::BOOLEAN')],
            ];
        }

        if ($storageHint === 'int') {
            return [
                '?int',
                ['type' => new Literal('Types::INTEGER')],
            ];
        }

        if ($storageHint === 'float') {
            return [
                '?float',
                ['type' => new Literal('Types::FLOAT')],
            ];
        }

        $maxLen = null;
        if (isset($rawFieldStats['stringLengths']) && \is_array($rawFieldStats['stringLengths'])) {
            $maxLen = $rawFieldStats['stringLengths']['max'] ?? null;
        }

        if ($storageHint === 'text' || (\is_int($maxLen) && $maxLen > 255)) {
            return [
                '?string',
                ['type' => new Literal('Types::TEXT')],
            ];
        }

        $length = 255;
        if (\is_int($maxLen) && $maxLen > 0) {
            $length = \min($maxLen, 255);
        }

        return [
            '?string',
            ['length' => $length],
        ];
    }

    private function createRepo(string $repoFqcn, string $entityFqcn, string $entityName): void
    {
        $pos = \strrpos($repoFqcn, '\\');
        if ($pos === false) {
            return;
        }

        $repoNamespace = \substr($repoFqcn, 0, $pos);
        $repoClass     = \substr($repoFqcn, $pos + 1);

        $relativeRepo = \preg_replace('/^App\\\\/', '', $repoFqcn);
        $relativeRepo = \str_replace('\\', '/', (string) $relativeRepo);
        $repoFilename = 'src/' . $relativeRepo . '.php';

        if (\file_exists($repoFilename)) {
            return;
        }

        $code = \sprintf(<<<'PHPSTR'
<?php
declare(strict_types=1);

namespace %s;

use %s;
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
            $repoNamespace,
            $entityFqcn,
            $repoClass,
            $entityName
        );

        (new Filesystem())->dumpFile($repoFilename, $code);
    }
}
