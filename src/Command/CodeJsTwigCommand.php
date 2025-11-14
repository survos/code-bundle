<?php

declare(strict_types=1);

namespace Survos\CodeBundle\Command;

use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\OpenAI\GPT;
use Symfony\AI\Platform\Bridge\OpenAI\PlatformFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'code:js:twig',
    description: 'Generate a Twig file for JS rendering from JSONL data using OpenAI and a reference Twig template'
)]
final class CodeJsTwigCommand
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly Filesystem $filesystem,
        #[Autowire('%env(OPEN_API_KEY)%')] private readonly string $openaiApiKey,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,

        #[Argument(
            name: 'output',
            description: 'Relative path to the generated Twig file, e.g. templates/js/movies.html.twig'
        )]
        string $output,

        #[Option(
            name: 'json',
            shortcut: 'j',
            description: 'Path to the JSONL file with data (e.g. var/movies.jsonl)'
        )]
        ?string $jsonPath = null,

        #[Option(
            name: 'template',
            shortcut: 't',
            description: 'Path to the reference Twig (e.g. templates/js/raw.html.twig)'
        )]
        ?string $templatePath = null,

        #[Option(
            name: 'model',
            shortcut: 'm',
            description: 'OpenAI model to use (default: gpt-4o-mini)'
        )]
        ?string $modelName = null,
    ): int {
        $projectDir = $this->kernel->getProjectDir();

        if (null === $jsonPath || null === $templatePath) {
            $io->error('You must pass both --json=... and --template=....');

            return 1;
        }

        $jsonPath     = $this->normalizePath($projectDir, $jsonPath);
        $templatePath = $this->normalizePath($projectDir, $templatePath);
        $outputPath   = $this->normalizePath($projectDir, $output);

        if (!is_file($templatePath)) {
            $io->error(sprintf('Reference template not found: %s', $templatePath));

            return 1;
        }

        if (!is_file($jsonPath)) {
            $io->error(sprintf('JSONL file not found: %s', $jsonPath));

            return 1;
        }

        $io->section('Reading reference Twig template…');
        $rawTemplate = file_get_contents($templatePath) ?: '';

        $io->section('Sampling JSONL data…');
        $sample = $this->sampleJsonl($jsonPath, 25);

        if (empty($sample)) {
            $io->error('JSONL file appears to be empty or invalid.');

            return 1;
        }

        $sampleJson = json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $io->section('Calling OpenAI via Symfony AI Platform…');

        $platform = PlatformFactory::create($this->openaiApiKey);
        $model    = new GPT($modelName ?? GPT::GPT_4O_MINI);
        $agent    = new Agent($platform, $model);

        $system = Message::forSystem(
            <<<'SYS'
You are an expert Symfony/Twig developer.

Your job is to generate a Twig template file that renders a collection of items
based on JSON data provided by the user.

The user will give you:
  - A reference Twig template, which shows the desired HTML structure and styling,
    but with example/static data.
  - A JSON sample describing the real data schema.

You MUST:
  - Output ONLY valid Twig code, with no explanations or Markdown.
  - Use Twig loops and variables instead of hard-coded example data.
  - Assume the Twig variable is called "items" (array of associative arrays).
  - Keep the overall HTML structure from the reference template, but replace static
    content with Twig expressions/loops that match the JSON schema.
SYS
        );

        // No backticks in here so the outer bash heredoc stays intact.
        $userPrompt = sprintf(
            <<<'TXT'
REFERENCE_TWIG_TEMPLATE_START
%s
REFERENCE_TWIG_TEMPLATE_END

JSON_SAMPLE_START
%s
JSON_SAMPLE_END

Generate a Twig template that renders all items from the "items" variable.
Name the main loop variable "item".

Output ONLY Twig code, nothing else.
TXT,
            $rawTemplate,
            $sampleJson
        );

        $user = Message::ofUser($userPrompt);

        $messages = new MessageBag($system, $user);

        $response = $agent->call($messages);
        $twigCode = trim($response->getContent());

        if ('' === $twigCode) {
            $io->error('OpenAI returned empty content.');

            return 1;
        }

        // Just in case the model adds any markdown fences or markers.
        $twigCode = preg_replace('/^```(?:twig)?\s*/', '', $twigCode);
        $twigCode = preg_replace('/```$/', '', $twigCode);
        $twigCode = trim($twigCode);

        $this->filesystem->mkdir(\dirname($outputPath));
        $this->filesystem->dumpFile($outputPath, $twigCode);

        $io->success(sprintf('Twig file generated at %s', $outputPath));

        return 0;
    }

    private function normalizePath(string $projectDir, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $projectDir.'/'.ltrim($path, '/');
    }

    /**
     * Read at most $maxLines JSONL rows and decode them.
     *
     * @return array<int, array<string,mixed>>
     */
    private function sampleJsonl(string $filename, int $maxLines = 25): array
    {
        $handle = @fopen($filename, 'rb');
        if (false === $handle) {
            return [];
        }

        $rows = [];

        while (!feof($handle) && \count($rows) < $maxLines) {
            $line = fgets($handle);
            if (false === $line) {
                break;
            }

            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        fclose($handle);

        return $rows;
    }
}
