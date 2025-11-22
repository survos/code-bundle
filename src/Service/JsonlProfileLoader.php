<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use RuntimeException;

/**
 * Tiny helper to load the profile array produced by import:convert.
 *
 * Your CodeEntityCommand can type-hint this and then use the profile
 * to derive fields, types, and Meili suggestions.
 */
final class JsonlProfileLoader
{
    /**
     * @return array{
     *     input: string,
     *     output: string,
     *     recordCount: int,
     *     tags: array<int, string>,
     *     fields: array<string, array<string, mixed>>
     * }
     */
    public function load(string $path): array
    {
        if (!\is_file($path)) {
            throw new RuntimeException(sprintf('Profile file "%s" not found.', $path));
        }

        $raw = \file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Unable to read profile file "%s".', $path));
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            throw new RuntimeException(sprintf('Profile file "%s" does not contain valid JSON object.', $path));
        }

        if (!isset($data['fields']) || !\is_array($data['fields'])) {
            throw new RuntimeException(sprintf('Profile file "%s" is missing "fields" key.', $path));
        }

        return $data;
    }
}
