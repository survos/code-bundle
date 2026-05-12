<?php
declare(strict_types=1);

// File: src/Command/CodeJsTwigCommand.php
// Survos\CodeBundle — Generate / refine templates for Meilisearch indexes:
//   - JS-Twig hit card templates
//   - Liquid embedder document templates

namespace Survos\CodeBundle\Command;

use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use OpenAI;

#[AsCommand(
    name: 'code:templates',
    description: 'Generate / refine JS-Twig and Liquid templates from Jsonl profile + Meilisearch settings.'
)]
final class CodeJsTwigCommand
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(OPENAI_API_KEY)%')]
        private readonly ?string $openaiApiKey = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Index / template name (e.g. "movies", "book", "wam")')]
        string $indexName,
        #[Option('Path to JS-Twig template (default: templates/js/<index>.html.twig)', shortcut: 'o')]
        ?string $output = null,
        #[Option('Path to Jsonl profile (default: data/<index>.jsonl.profile.json)', shortcut: 'P')]
        ?string $profilePath = null,
        #[Option('Generate JS-Twig template from heuristics and write it to disk', shortcut: 'p')]
        bool $publish = false,
        #[Option('Refine existing JS-Twig template using OpenAI', shortcut: 'a')]
        bool $ai = false,
        #[Option('OpenAI model to use (default: gpt-4o-mini)')]
        ?string $modelName = null,
        #[Option('Also generate a Liquid embedder template')]
        ?bool $liquid = null,
    ): int {
        $io->title(sprintf('code:templates — %s', $indexName));

        if (!$publish && !$ai && !$liquid) {
            $io->warning('Nothing to do: use --publish, --ai, and/or --liquid.');
            return Command::SUCCESS;
        }

        $output ??= sprintf('templates/js/%s.html.twig', $indexName);
        $outputPath = $this->normalizePath($output);

        // Profile JSONL artifact
        $profilePath ??= sprintf('data/%s.jsonl.profile.json', $indexName);
        $profilePath = $this->normalizePath($profilePath);

        $profile  = null;
        $settings = null;
        $config   = null;
        $index    = null;

        // We only need profile/settings/config when publishing Twig and/or Liquid
        if ($publish || $liquid) {
            if (!is_file($profilePath)) {
                $io->error(sprintf('Profile file missing: %s. Cannot generate heuristics without a Jsonl profile.', $profilePath));
                return Command::FAILURE;
            }

            $profileRaw = file_get_contents($profilePath) ?: '';
            $profile    = json_decode($profileRaw, true, 512, \JSON_THROW_ON_ERROR);

            $io->section(sprintf('Loading Meilisearch settings for index "%s"...', $indexName));
            $client   = $this->meiliService->getMeiliClient();
            $index    = $client->getIndex($this->meiliService->getPrefixedIndexName($indexName));
            $settings = $index->getSettings();

            [$config, $settings] = $this->buildConfigFromProfile($index, $profile, $settings);
        }

        // 1) Publish base JS-Twig template
        if ($publish) {
            $io->section('Generating base JS-Twig template from heuristics…');
            $twigSource = $this->generateJsTwigFromConfig($config, $settings);

            $this->filesystem->mkdir(\dirname($outputPath));
            $this->filesystem->dumpFile($outputPath, $twigSource);

            $io->success(sprintf('Base JS-Twig template written to %s', $outputPath));
        }

        // 2) Generate Liquid embedder template
        if ($liquid) {
            if ($config === null || $profile === null) {
                $io->error('Cannot generate Liquid template without profile and config (run with --publish or ensure profile exists).');
                return Command::FAILURE;
            }

            $io->section('Generating Liquid embedder template from heuristics…');

            // Convention: templates/liquid/<index>.liquid
            $liquidPath = $this->normalizePath(sprintf('templates/liquid/%s.liquid', $indexName));
            $this->filesystem->mkdir(\dirname($liquidPath));

            $liquidSource = $this->generateLiquidFromConfig($config, $profile, $indexName);
            $this->filesystem->dumpFile($liquidPath, $liquidSource);

            $len = strlen($liquidSource);
            $io->success(sprintf('Liquid template written to %s (%d characters)', $liquidPath, $len));

            if ($io->isVerbose()) {
                $io->writeln("\n<comment>Liquid template content:</comment>\n");
                $io->writeln($liquidSource);
            }
        }

        // 3) AI refinement (card body only)
        if ($ai) {
            if (!is_file($outputPath)) {
                $io->error(sprintf('Template file %s does not exist. Use --publish first or create it manually.', $outputPath));
                return Command::FAILURE;
            }

            if (!$this->openaiApiKey) {
                $io->error('OPENAI_API_KEY is not configured. Cannot use --ai without an API key.');
                return Command::FAILURE;
            }

            if (!class_exists(OpenAI::class)) {
                $io->error('openai-php/client is missing. Run: composer require openai-php/client');
                return Command::FAILURE;
            }

            $originalTemplate = file_get_contents($outputPath) ?: '';
            $profileRaw       = is_file($profilePath) ? (file_get_contents($profilePath) ?: '') : '';

            $io->section(sprintf('Refining card body in %s with OpenAI…', $outputPath));

            $client    = OpenAI::client($this->openaiApiKey);
            $modelName = $modelName ?: 'gpt-4o-mini';

            $systemPrompt = <<<'SYS'
You are an expert Symfony/Twig front-end developer.

You are given:
  - A JS-Twig template that renders a Meilisearch "hit" inside a Bootstrap/Tabler card.
  - A Jsonl profile describing the fields, types, distributions, and facet candidates for that index.

IMPORTANT:
  - The template contains a "_config" block and some preamble code that prepares variables
    like "title", "description", "imageUrl", "labels", "maxList", etc.
  - That preamble MUST be preserved exactly; you should only rewrite the HTML card body,
    starting from the first "<div class=\"card" and ending at the matching closing "</div>" of the card.

Your job:
  - Improve the visual layout and readability of the card body WITHOUT breaking JS-Twig compatibility.
  - Keep the same general structure (image, title, description, scalar badges, tag badges, footer),
    but you may:
    • Adjust spacing, typography, badges, and ordering of fields.
    • Use field names and profile hints to decide importance.
    • Replace generic loops over "_config.scalarFields" / "_config.tagFields" with explicit, concrete fields
      (e.g. hit.title, hit.year, hit.genres, hit.budget) when that improves clarity.
  - You MUST:
    • Output ONLY the full Twig template, with the _config block and preamble intact.
    • NOT wrap the template in ``` or ```twig fences.
    • Preserve existing Stimulus hooks (e.g. stimulus_action, data-hit-id).
    • Avoid Twig features not supported by twig.js (e.g. "map" filter, macros).
    • Keep using the "hit" variable as the main object.
SYS;

            $userPrompt = sprintf(
                <<<'TXT'
BASE_TEMPLATE_START
%s
BASE_TEMPLATE_END

PROFILE_JSON_START
%s
PROFILE_JSON_END

Please return ONLY the improved Twig template, nothing else. Do not wrap it in ``` fences.
TXT,
                $originalTemplate,
                $profileRaw
            );

            try {
                $response = $client->responses()->create([
                    'model' => $modelName,
                    'input' => [
                        [
                            'role'    => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role'    => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                    'max_output_tokens' => 4096,
                ]);
            } catch (\Throwable $e) {
                $io->error('Error calling OpenAI: ' . $e->getMessage());
                return Command::FAILURE;
            }

            $raw = $response->outputText;

            $refined  = $this->stripCodeFences($raw);
            $stitched = $this->stitchCardBody($originalTemplate, $refined);

            $this->filesystem->dumpFile($outputPath, $stitched);

            $io->success(sprintf('AI-refined template written to %s', $outputPath));
        }

        return Command::SUCCESS;
    }

    private function stripCodeFences(string $text): string
    {
        $trimmed = trim($text);

        if (preg_match('/^```[a-zA-Z0-9]*\R/', $trimmed)) {
            $trimmed = preg_replace('/^```[a-zA-Z0-9]*\R/', '', $trimmed, 1) ?? $trimmed;
        }

        if (preg_match('/\R```$/', $trimmed)) {
            $trimmed = preg_replace('/\R```$/', '', $trimmed, 1) ?? $trimmed;
        } elseif (str_ends_with($trimmed, '```')) {
            $trimmed = substr($trimmed, 0, -3);
        }

        return trim($trimmed);
    }

    private function stitchCardBody(string $original, string $refined): string
    {
        $marker  = '<div class="card';
        $origPos = strpos($original, $marker);
        $newPos  = strpos($refined,  $marker);

        if ($origPos === false || $newPos === false) {
            // If we can't find the marker, fallback to refined.
            return $refined;
        }

        $prefix      = substr($original, 0, $origPos);
        $refinedCard = substr($refined,  $newPos);

        return rtrim($prefix, "\r\n") . "\n" . ltrim($refinedCard, "\r\n");
    }

    private function isIdLike(string $fieldName): bool
    {
        $lower = strtolower($fieldName);

        if (preg_match('/(^|_)id$/', $lower)) {
            return true;
        }

        if (str_ends_with($lower, 'id') && !str_ends_with($lower, 'grid')) {
            return true;
        }

        return false;
    }

    private function profileFieldToMeiliField(string $profileField): string
    {
        $propName = preg_replace('/[^a-zA-Z0-9_]/', '_', $profileField);
        $propName = strtolower($propName);

        $parts = explode('_', $propName);
        $first = array_shift($parts);
        $camel = $first;

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $camel .= ucfirst($part);
        }

        return $camel;
    }

    private function humanizeField(string $field): string
    {
        $s = preg_replace('/(?<!^)[A-Z]/', ' $0', $field) ?? $field;
        $s = str_replace('_', ' ', $s);
        $s = trim($s);

        return ucwords(strtolower($s));
    }

    /**
     * Build _config from Meili settings + Jsonl profile.
     */
    private function buildConfigFromProfile(object $index, array $profile, array $settings): array
    {
        $fields        = $profile['fields'] ?? [];
        $pkFromProfile = $profile['pk'] ?? null;

        $primaryKey = $index->getPrimaryKey() ?? $pkFromProfile ?? 'id';

        $searchable    = $settings['searchableAttributes'] ?? [];
        $rawFilterable = $settings['filterableAttributes'] ?? [];

        $profileToMeili = [];
        $meiliToProfile = [];
        foreach ($fields as $profileField => $_meta) {
            $meiliField                     = $this->profileFieldToMeiliField($profileField);
            $profileToMeili[$profileField]  = $meiliField;
            $meiliToProfile[$meiliField]    = $profileField;
        }

        $filterable = array_values(array_filter(
            $rawFilterable,
            fn (string $f) => !$this->isIdLike($f)
        ));

        $pickProfileString = static function (array $profileFieldNames, array $profileFields, array $preferred): ?string {
            $fallback = null;
            foreach ($profileFieldNames as $pf) {
                if (!isset($profileFields[$pf])) {
                    continue;
                }
                $meta = $profileFields[$pf];
                if (!in_array('string', $meta['types'] ?? [], true)) {
                    continue;
                }
                if (in_array($pf, $preferred, true)) {
                    return $pf;
                }
                $fallback ??= $pf;
            }

            return $fallback;
        };

        $allProfileFieldNames = array_keys($fields);

        // Title
        $profileTitleField = $pickProfileString(
            $allProfileFieldNames,
            $fields,
            ['title', 'original_title', 'name', 'label', 'heading']
        );
        $titleField = $profileTitleField
            ? ($profileToMeili[$profileTitleField] ?? $profileTitleField)
            : null;

        if (!$titleField) {
            foreach ($searchable as $s) {
                $profileField = $meiliToProfile[$s] ?? null;
                if (!$profileField) {
                    continue;
                }
                $meta = $fields[$profileField] ?? null;
                if (!$meta || !in_array('string', $meta['types'] ?? [], true)) {
                    continue;
                }
                $titleField = $s;
                break;
            }
        }

        $titleField ??= $primaryKey;

        // Description: prefer description-ish names first
        $descriptionField = null;
        $descriptionCandidates = ['description', 'overview', 'summary', 'abstract', 'notes'];
        foreach ($descriptionCandidates as $cand) {
            if (isset($fields[$cand]) && $cand !== $profileTitleField) {
                $descriptionField = $profileToMeili[$cand] ?? $cand;
                break;
            }
        }

        if (!$descriptionField) {
            foreach ($allProfileFieldNames as $pf) {
                if ($pf === $profileTitleField) {
                    continue;
                }
                $meta = $fields[$pf] ?? null;
                if (!$meta || !in_array('string', $meta['types'] ?? [], true)) {
                    continue;
                }
                $maxLen = $meta['stringLengths']['max'] ?? 0;
                if ($maxLen > 40) {
                    $descriptionField = $profileToMeili[$pf] ?? $pf;
                    break;
                }
            }
        }

        // Scalar fields
        $scalarFields = [];
        foreach ($filterable as $meiliField) {
            if ($this->isIdLike($meiliField)) {
                continue;
            }
            $profileField = $meiliToProfile[$meiliField] ?? null;
            if (!$profileField) {
                continue;
            }
            $meta = $fields[$profileField] ?? null;
            if (!$meta) {
                continue;
            }

            $hint = $meta['storageHint'] ?? null;
            $bool = $meta['booleanLike'] ?? false;

            if (in_array($hint, ['int', 'float', 'number'], true) || $bool) {
                $scalarFields[] = $meiliField;
            }
            if (count($scalarFields) >= 3) {
                break;
            }
        }

        // Tag fields
        $tagFields    = [];
        $tagNameHints = [
            'genres', 'genre',
            'tags', 'tag',
            'categories', 'category',
            'keywords', 'labels',
            'authors', 'powers', 'teams', 'species', 'partners',
        ];

        foreach ($filterable as $meiliField) {
            if ($this->isIdLike($meiliField)) {
                continue;
            }
            $profileField = $meiliToProfile[$meiliField] ?? null;
            if (!$profileField) {
                continue;
            }

            $meta  = $fields[$profileField] ?? null;
            if (!$meta) {
                continue;
            }
            $types = $meta['types'] ?? [];
            $facet = $meta['facetCandidate'] ?? false;
            $lname = strtolower($meiliField);

            $isArrayish    = in_array('array', $types, true);
            $isStringFacet = in_array('string', $types, true) && $facet;
            $isNameHint    = in_array($lname, $tagNameHints, true);

            $distribution = $meta['distribution']['values'] ?? null;
            $total        = $meta['total'] ?? null;
            $degenerate   = false;
            if (is_array($distribution) && $total) {
                if (count($distribution) === 1 && reset($distribution) === $total) {
                    $degenerate = true;
                }
            }
            if ($degenerate) {
                continue;
            }

            if ($isArrayish || $isStringFacet || $isNameHint) {
                $tagFields[] = $meiliField;
            }
            if (count($tagFields) >= 2) {
                break;
            }
        }

        // Image field
        $imageField = null;
        foreach ($fields as $profileField => $meta) {
            $key = strtolower($profileField);
            if (
                (str_contains($key, 'image') ||
                 str_contains($key, 'thumb') ||
                 str_contains($key, 'poster') ||
                 str_contains($key, 'cover')) &&
                (($meta['storageHint'] ?? '') === 'string')
            ) {
                $imageField = $profileToMeili[$profileField] ?? $profileField;
                break;
            }
        }

        // Human labels
        $labels = [];
        foreach ($fields as $profileField => $_meta) {
            $meiliField          = $profileToMeili[$profileField] ?? $profileField;
            $labels[$meiliField] = $this->humanizeField($meiliField);
        }

        $maxLen  = 100;
        $maxList = 3;

        return [
            [
                'primaryKey'       => $primaryKey,
                'titleField'       => $titleField,
                'descriptionField' => $descriptionField,
                'imageField'       => $imageField,
                'scalarFields'     => $scalarFields,
                'tagFields'        => $tagFields,
                'filterableFields' => $filterable,
                'labels'           => $labels,
                'maxLen'           => $maxLen,
                'maxList'          => $maxList,
            ],
            $settings,
        ];
    }

    /**
     * Generate the JS-Twig card (you can paste the full version we already settled on here).
     */
    private function generateJsTwigFromConfig(array $config, array $settings): string
    {
        $configJson   = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $settingsJson = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // TODO: paste your established JS-Twig template here.
        return sprintf("{# _config: %s #}\n{# settings: %s #}\n<div class=\"card\">{{ hit|json_encode }}</div>\n", $configJson, $settingsJson);
    }

    /**
     * Generate a first-pass Liquid embedder template based on _config + profile.
     * Avoids sprintf; concatenates strings to stay safe with Liquid's {% %} syntax.
     */
    private function generateLiquidFromConfig(array $config, array $profile, string $indexName): string
    {
        $titleField   = $config['titleField']       ?? 'title';
        $descField    = $config['descriptionField'] ?? null;
        $scalarFields = $config['scalarFields']     ?? [];
        $tagFields    = $config['tagFields']        ?? [];
        $labels       = $config['labels']           ?? [];
        $fields       = $profile['fields']          ?? [];

        $lines = [];

        $lines[] = '{%- comment -%}';
        $lines[] = sprintf('Auto-generated embedder template for index "%s".', $indexName);
        $lines[] = 'Edit this file to refine what goes into the embedding.';
        $lines[] = 'Uses only Meilisearch-supported Liquid + compact Markdown.';
        $lines[] = '{%- endcomment -%}';
        $lines[] = '';

        // Title: allow for ID-ish titles (registrationNumber, etc.).
        $lines[] = '{%- assign title = doc.' . $titleField . ' -%}';
        $lines[] = '';
        $lines[] = '# {{ title }}';
        $lines[] = '';

        // Description-ish block
        if ($descField) {
            $lines[] = '{% if doc.' . $descField . ' %}';
            $lines[] = '{{ doc.' . $descField . ' }}';
            $lines[] = '{% endif %}';
            $lines[] = '';
        }

        // Details (scalar fields / strong metadata)
        if (!empty($scalarFields)) {
            // Only add section if at least one scalar exists on doc
            $condParts = [];
            foreach ($scalarFields as $sf) {
                $condParts[] = 'doc.' . $sf;
            }
            $lines[] = '{% if ' . implode(' or ', $condParts) . ' %}';
            $lines[] = '## Details';
            foreach ($scalarFields as $sf) {
                $label = $labels[$sf] ?? $sf;
                $lines[] = '{% if doc.' . $sf . ' %}' . $label . ': {{ doc.' . $sf . ' }}{% endif %}';
            }
            $lines[] = '{% endif %}';
            $lines[] = '';
        }

        // Tag-like / array-ish fields as sections
        foreach ($tagFields as $tf) {
            $label = $labels[$tf] ?? $tf;
            $lower = strtolower($tf);

            $lines[] = '{% if doc.' . $tf . ' %}';
            $lines[] = '## ' . $label;

            // Heuristic: split pipe-delimited fields like keywords/subject
            if (in_array($lower, ['keywords', 'subject', 'subjects'], true)) {
                $lines[] = '{% assign values = doc.' . $tf . ' | split: "|" %}';
                $lines[] = '{{ values | join: " | " }}';
            } else {
                $lines[] = '{{ doc.' . $tf . ' }}';
            }

            $lines[] = '{% endif %}';
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->projectDir . '/' . ltrim($path, '/');
    }
}
