<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\Model\JsonlProfile;
use Survos\JsonlBundle\Service\JsonlProfilerInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ProfileResolver
{
    public function __construct(
        private readonly ?JsonlProfilerInterface $profiler = null,
    ) {
    }

    /**
     * Resolve a profile from:
     *   - *.profile.json  (load it)
     *   - *.jsonl         (profile it)
     *
     * CSV or raw JSON are *not supported* here and must be converted via import:convert.
     *
     * @return array{
     *     input: string,
     *     output: string|null,
     *     recordCount: int,
     *     tags: array<int,string>,
     *     fields: array<string,array<string,mixed>>
     * }
     */
    public function resolve(string $path, ?SymfonyStyle $io = null): array
    {
        if (!\is_file($path)) {
            throw new \RuntimeException(sprintf('Input file "%s" does not exist.', $path));
        }

        // 1) *.profile.json
        if (\str_ends_with($path, '.profile.json')) {
            return $this->loadExistingProfile($path);
        }

        // 2) *.jsonl (profile it)
        if (\str_ends_with($path, '.jsonl')) {
            return $this->profileJsonl($path, $io);
        }

        // 3) Unsupported formats — enforce the pipeline
        throw new \RuntimeException(
            sprintf(
                "Unsupported file '%s'.\n".
                "CodeBundle only accepts:\n".
                "  • .profile.json (pre-analyzed)\n".
                "  • .jsonl (will be analyzed)\n\n".
                "To use CSV or JSON, run:\n".
                "  bin/console import:convert %s",
                $path,
                $path
            )
        );
    }

    /**
     * Load an existing *.profile.json file.
     */
    private function loadExistingProfile(string $path): array
    {
        $raw = \file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException(sprintf('Unable to read profile file "%s".', $path));
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data) || !isset($data['fields'])) {
            throw new \RuntimeException(sprintf('Profile file "%s" is invalid or missing "fields".', $path));
        }

        return [
            'input'       => $data['input']       ?? $path,
            'output'      => $data['output']      ?? null,
            'recordCount' => $data['recordCount'] ?? 0,
            'tags'        => $data['tags']        ?? [],
            'fields'      => $data['fields'],
        ];
    }

    /**
     * Create a profile from a *.jsonl file using JsonlReader + JsonlProfilerInterface.
     */
    private function profileJsonl(string $path, ?SymfonyStyle $io = null): array
    {
        if ($io) {
            $io->section(sprintf('Profiling JSONL file %s', $path));
        }
        if (!class_exists(JsonlProfilerInterface::class)) {
            throw new \RuntimeException("composer run survos/jsonl-bundle");
        }

        $reader = new JsonlReader($path);
        $rows = \iterator_to_array($reader);

        return [
            'input'       => $path,
            'output'      => null,
            'recordCount' => \count($rows),
            'tags'        => [],
            'fields'      => $this->profiler?->profile($rows),
        ];
    }
}
