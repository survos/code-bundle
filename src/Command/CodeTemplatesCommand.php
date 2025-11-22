<?php
declare(strict_types=1);

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
final class CodeTemplatesCommand extends Command
{
    public function __construct(
        private readonly MeiliService $meiliService,
        private readonly Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(OPENAI_API_KEY)%')]
        private readonly ?string $openaiApiKey = null,
    ) {
        parent::__construct();
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Index / template name (e.g. "movies", "book", "wam")')]
        string $indexName,
        #[Option('Path to JS-Twig template (default: templates/js/<index>.html.twig)', shortcut: 't')]
        ?string $output = null,
        #[Option('Path to Jsonl profile (default: data/<index>.jsonl.profile.json)', shortcut: 'P')]
        ?string $profilePath = null,
        #[Option('Generate JS-Twig template', shortcut: 'j')]
        bool $twig = false,
        #[Option('Generate Liquid embedder template')]
        bool $liquid = false,
        #[Option('Use AI to generate/refine the templates', shortcut: 'a')]
        bool $ai = false,
        #[Option('OpenAI model to use (default: gpt-4o-mini)')]
        ?string $modelName = null,
    ): int {
        $io->title(sprintf('code:templates — %s', $indexName));

        if (!$twig && !$liquid && !$ai) {
            $io->warning('Nothing to do: use --twig, --liquid and/or --ai.');
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

        // We only need profile/settings/config when generating from heuristics
        if ($twig || $liquid) {
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

        $liquidPath = $this->normalizePath(sprintf('templates/liquid/%s.liquid', $indexName));
        $profileRawForAi = is_file($profilePath) ? (file_get_contents($profilePath) ?: '') : '';

        // 1) Generate JS-Twig from heuristics
        if ($twig) {
            $io->section('Generating base JS-Twig template from heuristics…');
            $twigSource = $this->generateJsTwigFromConfig($config, $settings);

            $this->filesystem->mkdir(\dirname($outputPath));
            $this->filesystem->dumpFile($outputPath, $twigSource);

            $io->success(sprintf('JS-Twig template written to %s', $outputPath));
        }

        // 2) Generate Liquid from heuristics
        if ($liquid) {
            if ($config === null || $profile === null) {
                $io->error('Cannot generate Liquid template without profile and config.');
                return Command::FAILURE;
            }

            $io->section('Generating Liquid embedder template from heuristics…');

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

        // 3) AI refinement / generation for Twig and/or Liquid
        if ($ai) {
            if (!$this->openaiApiKey) {
                $io->error('OPENAI_API_KEY is not configured. Cannot use --ai without an API key.');
                return Command::FAILURE;
            }

            if (!class_exists(OpenAI::class)) {
                $io->error('openai-php/client is missing. Run: composer require openai-php/client');
                return Command::FAILURE;
            }

            $client    = OpenAI::client($this->openaiApiKey);
            $modelName = $modelName ?: 'gpt-4o-mini';

            // Twig refinement
            if ($twig) {
                if (!is_file($outputPath)) {
                    $io->error(sprintf('Twig template %s does not exist (generate it first).', $outputPath));
                    return Command::FAILURE;
                }

                $io->section(sprintf('Refining Twig card body in %s with OpenAI…', $outputPath));
                $originalTemplate = file_get_contents($outputPath) ?: '';

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
                    $profileRawForAi
                );

                try {
                    $response = $client->responses()->create([
                        'model' => $modelName,
                        'input' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user',   'content' => $userPrompt],
                        ],
                        'max_output_tokens' => 4096,
                    ]);
                } catch (\Throwable $e) {
                    $io->error('Error calling OpenAI for Twig: ' . $e->getMessage());
                    return Command::FAILURE;
                }

                $raw     = $response->outputText;
                $refined = $this->stripCodeFences($raw);
                $stitched = $this->stitchCardBody($originalTemplate, $refined);
                $this->filesystem->dumpFile($outputPath, $stitched);

                $io->success(sprintf('AI-refined Twig template written to %s', $outputPath));
            }

            // Liquid generation/refinement
            if ($liquid) {
                if (!is_file($liquidPath)) {
                    $io->error(sprintf('Liquid template %s does not exist (generate it first with --liquid).', $liquidPath));
                    return Command::FAILURE;
                }

                $io->section(sprintf('Refining Liquid template in %s with OpenAI…', $liquidPath));
                $originalLiquid = file_get_contents($liquidPath) ?: '';

                $systemPromptLiquid = <<<'SYS'
You are an expert at designing Liquid templates for Meilisearch embedders.

You are given:
  - A Liquid template that builds a Markdown document from a "doc" object.
  - A Jsonl profile describing the fields, types, distributions, and facet candidates for that index.

Your job:
  - Improve the template so that it produces a compact, semantically rich Markdown summary,
    emphasizing the fields that matter (title, description, creator, subjects, keywords, provenance).
  - Use profile hints (string length, facetCandidate, distribution) to decide what to include or de-emphasize.
  - Prefer headings (##), short labels, and pipe-joined lists for multi-valued fields.

You MUST:
  - Output ONLY Liquid code, nothing else.
  - NOT wrap the template in ``` or ```liquid fences.
  - Keep using "doc" as the variable for the record.
  - Use only Meilisearch-supported Liquid features (no custom filters).
SYS;

                $userPromptLiquid = sprintf(
                    <<<'TXT'
BASE_LIQUID_TEMPLATE_START
%s
BASE_LIQUID_TEMPLATE_END

PROFILE_JSON_START
%s
PROFILE_JSON_END

Please return ONLY the improved Liquid template, nothing else. Do not wrap it in ``` fences.
TXT,
                    $originalLiquid,
                    $profileRawForAi
                );

                try {
                    $responseLiquid = $client->responses()->create([
                        'model' => $modelName,
                        'input' => [
                            ['role' => 'system', 'content' => $systemPromptLiquid],
                            ['role' => 'user',   'content' => $userPromptLiquid],
                        ],
                        'max_output_tokens' => 4096,
                    ]);
                } catch (\Throwable $e) {
                    $io->error('Error calling OpenAI for Liquid: ' . $e->getMessage());
                    return Command::FAILURE;
                }

                $rawLiquid     = $responseLiquid->outputText;
                $refinedLiquid = $this->stripCodeFences($rawLiquid);

                $this->filesystem->dumpFile($liquidPath, $refinedLiquid);
                $len = strlen($refinedLiquid);

                $io->success(sprintf('AI-refined Liquid template written to %s (%d characters)', $liquidPath, $len));

                if ($io->isVerbose()) {
                    $io->writeln("\n<comment>Liquid template content (AI-refined):</comment>\n");
                    $io->writeln($refinedLiquid);
                }
            }
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

    private function generateJsTwigFromConfig(array $config, array $settings): string
    {
        $configJson   = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $settingsJson = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<TWIG
{# Generated automatically from JSONL profile + Meilisearch settings.
   Safe to edit; re-generating will overwrite.

   Meilisearch settings:
$settingsJson
#}

{% set _config = $configJson %}

{# A minimal but real card; you can swap this with your full card template. #}
{% set pk        = attribute(hit, _config.primaryKey|default('id')) ?? (hit.id ?? null) %}
{% set titleKey  = _config.titleField|default('title') %}
{% set descKey   = _config.descriptionField|default(null) %}
{% set imageKey  = _config.imageField|default(null) %}
{% set maxLen    = _config.maxLen|default(100) %}
{% set maxList   = _config.maxList|default(3) %}
{% set labels    = _config.labels|default({}) %}

{% set highlightedTitle = (hit._highlightResult is defined
    and attribute(hit._highlightResult, titleKey) is defined
    and attribute(attribute(hit._highlightResult, titleKey), 'value') is defined)
    ? attribute(attribute(hit._highlightResult, titleKey), 'value')
    : null
%}
{% set title = highlightedTitle ?? (attribute(hit, titleKey)|default(pk)) %}

{% set description = null %}
{% if descKey %}
    {% set highlightedDesc = (hit._highlightResult is defined
        and attribute(hit._highlightResult, descKey) is defined
        and attribute(attribute(hit._highlightResult, descKey), 'value') is defined)
        ? attribute(attribute(hit._highlightResult, descKey), 'value')
        : null
    %}
    {% set rawDesc = attribute(hit, descKey)|default(null) %}
    {% set description = highlightedDesc ?? rawDesc %}
{% endif %}

{% set imageUrl = imageKey ? (attribute(hit, imageKey)|default(null)) : null %}

<div class="card h-100 shadow-sm border-0">
    <div class="card-body p-3">
        <div class="d-flex gap-3 mb-2">
            {% if imageUrl %}
                <div style="flex:0 0 96px;">
                    <img
                        src="{{ imageUrl }}"
                        alt="{{ title|striptags }}"
                        class="img-fluid rounded"
                        loading="lazy"
                        decoding="async"
                        style="max-height:120px;object-fit:cover;"
                    >
                </div>
            {% endif %}

            <div class="flex-grow-1">
                <div class="d-flex justify-content-between gap-2 mb-1">
                    <h5 class="card-title mb-0 text-wrap" style="word-break:break-word;">
                        {{ title|raw }}
                    </h5>
                </div>

                {% if description %}
                    <p
                        class="text-body-secondary mb-0"
                        style="
                            display:-webkit-box;
                            -webkit-line-clamp:3;
                            -webkit-box-orient:vertical;
                            overflow:hidden;
                            text-overflow:ellipsis;
                        "
                    >
                        {{ description|raw }}
                    </p>
                {% endif %}
            </div>
        </div>
    </div>

    <div class="card-footer bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex flex-wrap align-items-center gap-2 small text-body-secondary">
            {% if pk is not null %}
                <span>{{ _config.primaryKey }}: {{ pk }}</span>
            {% endif %}
        </div>

        <button
            {{ stimulus_action(globals._sc_modal, 'modal') }}
            data-hit-id="{{ pk }}"
            class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
        >
            {{ ux_icon('json')|raw }}
            <span>Details</span>
        </button>
    </div>
</div>
TWIG;
    }

    /**
     * Generate a first-pass Liquid embedder template based on _config + profile.
     * Avoids sprintf for Liquid tags; concatenates strings to stay safe.
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

        $lines[] = '{%- assign title = doc.' . $titleField . ' -%}';
        $lines[] = '';
        $lines[] = '# {{ title }}';
        $lines[] = '';

        if ($descField) {
            $lines[] = '{% if doc.' . $descField . ' %}';
            $lines[] = '{{ doc.' . $descField . ' }}';
            $lines[] = '{% endif %}';
            $lines[] = '';
        }

        // Details section from scalar fields
        if (!empty($scalarFields)) {
            $conds = [];
            foreach ($scalarFields as $sf) {
                $conds[] = 'doc.' . $sf;
            }
            $lines[] = '{% if ' . implode(' or ', $conds) . ' %}';
            $lines[] = '## Details';
            foreach ($scalarFields as $sf) {
                $label = $labels[$sf] ?? $sf;
                $lines[] = '{% if doc.' . $sf . ' %}' . $label . ': {{ doc.' . $sf . ' }}{% endif %}';
            }
            $lines[] = '{% endif %}';
            $lines[] = '';
        }

        // Tag-like fields as sections
        foreach ($tagFields as $tf) {
            $label = $labels[$tf] ?? $tf;
            $lower = strtolower($tf);

            $lines[] = '{% if doc.' . $tf . ' %}';
            $lines[] = '## ' . $label;

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
