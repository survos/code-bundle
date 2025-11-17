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
use Symfony\Component\HttpKernel\KernelInterface;
use \OpenAI;

#[AsCommand(
    name: 'code:js:twig',
    description: 'Generate a Twig file for JS rendering from JSONL data using OpenAI and a reference Twig template'
)]
final class CodeJsTwigCommand
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly Filesystem $filesystem,
        private MeiliService $meiliService,
        #[Autowire('%env(OPENAI_API_KEY)%')] private readonly string $openaiApiKey,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument('index name to query to for sample data')]
        ?string $indexName = null, // @todo, prompt if missing

        #[Argument('Relative path to the generated Twig file, e.g. templates/js/movies.html.twig')]
        ?string $output=null,

        #[Option('Path to the reference Twig (e.g. templates/js/raw.html.twig)', shortcut: 't')]
        ?string $templatePath = null,

        #[Option('OpenAI model to use (default: gpt-4o-mini)', shortcut: 'm')]
        ?string $modelName = null,
    ): int {
        $projectDir = $this->kernel->getProjectDir();

        $meiliClient = $this->meiliService->getMeiliClient();
        $index = $meiliClient->getIndex($this->meiliService->getPrefixedIndexName($indexName));
        // actual settings, not calculated
        $settings = $index->getSettings(); // filterableAttributes, sortableAttributes, etc. :contentReference[oaicite:6]{index=6}

// 2) Facet distribution + stats
        $facetResponse = $index->search('', [
            'limit'  => 0,
            'facets' => ['*'], // or a curated subset if you prefer
        ]);

        $facetDistribution = $facetResponse->getFacetDistribution();
        $facetStats        = $facetResponse->getFacetStats();

// 3) (Optional) a few sample hits for concrete examples
        $results = $index->search('', [
            'limit' => 5,
        ]);
        $sampleHits = $results->getHits();
        $templatePath ??= 'vendor/survos/code-bundle/twig/js/detail.html.twig';
        $output ??= sprintf('templates/js/%s.html.twig', $indexName);

//        $jsonPath     = $this->normalizePath($projectDir, $jsonPath);
        $templatePath = $this->normalizePath($projectDir, $templatePath);
        $outputPath   = $this->normalizePath($projectDir, $output);

        if (!is_file($templatePath)) {
            $io->error(sprintf('Reference template not found: %s', $templatePath));

            return 1;
        }

        $io->section('Reading reference Twig template…');
        $rawTemplate = file_get_contents($templatePath) ?: '';

        $io->section('Sampling JSONL data…');
        $sampleJson = json_encode($sampleHits, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $schema = [
            'indexUid'          => $indexName,
            'settings'          => $settings,
            'facetDistribution' => $facetDistribution,
            'facetStats'        => $facetStats,
            'sampleHits'        => $sampleHits,
        ];
        $io->section('Calling OpenAI ');

        $systemPrompt = <<<'SYS'
You are an expert Symfony/Twig developer.

Your job is to generate a Twig template file that renders an individual hit from a meilisearch response.
The twig file is actually JsTwig, but supports path and stimulus_* calls.

The user will give you:
  - A reference Twig template, which shows the desired HTML structure and styling,
    but with example/static data.
  - A JSON sample describing the real data schema.
  - Meilisearch Settings and facet distribution
  - The underlying Doctrine Entity (or entities, if nested) to leverage ApiProperty data
  - The class may include fields that are no indexed, the authority is the meilisearch 'settings'
  - Look for "Ai Agent: " to see comments.

You MUST:
  - Output ONLY valid Twig code, with no explanations or Markdown.
  - Use Twig loops and variables instead of hard-coded example data.
  - Keep the overall HTML structure from the reference template, but replace static
    content with Twig expressions/loops that match the JSON schema.
  - use regular loops for rendering arrays (e.g. genres), not the twig map function (js-twig doesn't support it)
  - do not add any new icons remove any stimulus_* calls.  They are there for a reason.
  - look for comments like "AI: do not remove this section"
SYS;

        // No backticks in here so the outer bash heredoc stays intact.
        $userPrompt = sprintf(
            <<<'TXT'
REFERENCE_TWIG_TEMPLATE_START
%s
REFERENCE_TWIG_TEMPLATE_END

JSON_SAMPLE_START
%s
JSON_SAMPLE_END

Generate a Twig template that renders a 'hit' from meilisearch.
When full-text search, you can highlight terms like this
 {{ hit._highlightResult.title.value|raw }}

The name of the object is 'hit'.  There are some globals you can use, including the _config.
Since this is tabler/bootstrap 5, display the primary image in a way that it wraps.

Output ONLY Twig code, nothing else.
TXT,
            $rawTemplate,
            $sampleJson
        );

        if (!class_exists(OpenAI::class)) {
            $io->error('composer req openai-php/client');
            return Command::FAILURE;
        }
        $client    = OpenAI::client($this->openaiApiKey);
        $modelName = $modelName ?: 'gpt-4o-mini';

            $response = $client->responses()->create([
                'model' => $modelName,
                'input' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
                'max_output_tokens' => 4096,
            ]);
        try {
        } catch (\Throwable $e) {
            $io->error('Error calling OpenAI: '.$e->getMessage());

            return 1;
        }
        file_put_contents($outputPath, $response->outputText);

        return Command::SUCCESS;
    }

    private function normalizePath(string $projectDir, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $projectDir.'/'.ltrim($path, '/');
    }

}
