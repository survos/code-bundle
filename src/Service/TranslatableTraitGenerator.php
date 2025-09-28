<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Generates/updates <Entity>TranslationsTrait.
 *
 * - First render: writes the full trait with all requested fields.
 * - Subsequent runs: parses existing #[Translatable] properties and APPENDS ONLY the missing ones.
 * - Never deletes or overwrites existing fields.
 */
final class TranslatableTraitGenerator
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param array<int, array{name:string, hookAttrs:array, columnAttr:mixed}> $fields
     */
    public function generateTrait(string $traitFqcn, array $fields, string $targetPath): void
    {
        // Dedupe input fields by name
        $byName = [];
        foreach ($fields as $f) {
            $name = (string)($f['name'] ?? '');
            if ($name === '') continue;
            $byName[$name] ??= $f;
        }
        $fields = array_values($byName);

        if (!file_exists($targetPath)) {
            $this->logger->info('Generating translations trait (initial render)', [
                'fqcn'   => $traitFqcn,
                'target' => $targetPath,
                'fields' => array_column($fields, 'name'),
            ]);
            $this->writeNewTraitFile($traitFqcn, $fields, $targetPath);
            return;
        }

        // Upsert: keep existing fields, append only new ones
        $this->logger->info('Generating translations trait (upsert)', [
            'fqcn'   => $traitFqcn,
            'target' => $targetPath,
        ]);

        $existingCode  = (string)file_get_contents($targetPath);
        $existingNames = $this->scanExistingHookNames($existingCode);

        $toAppend = array_values(
            array_filter($fields, fn(array $f) => !in_array($f['name'], $existingNames, true))
        );

        if ($toAppend === []) {
            $this->logger->info('Trait up-to-date; nothing to append', [
                'fqcn'     => $traitFqcn,
                'existing' => $existingNames,
            ]);
            return;
        }

        $this->logger->info('Appending new field(s) to trait', [
            'fqcn'    => $traitFqcn,
            'append'  => array_column($toAppend, 'name'),
            'existing'=> $existingNames,
        ]);

        $updated = $this->appendBlocksBeforeClosingBrace($existingCode, $toAppend);
        $this->ensureTraitHeaderImports($updated);

        @mkdir(\dirname($targetPath), 0775, true);
        file_put_contents($targetPath, $updated);
        $this->logger->info('Trait appended', ['path' => $targetPath, 'bytes' => strlen($updated)]);
    }

    /* ======================= internals ======================= */

    /**
     * Render a complete trait (first time).
     *
     * @param array<int, array{name:string, hookAttrs:array, columnAttr:mixed}> $fields
     */
    private function writeNewTraitFile(string $traitFqcn, array $fields, string $targetPath): void
    {
        [$ns, $traitName] = $this->splitFqcn($traitFqcn);

        $blocks = [];
        foreach ($fields as $f) {
            $blocks[] = $this->renderHookBlock($f);
        }

        $code = <<<PHP
<?php
declare(strict_types=1);

namespace {$ns};

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Survos\BabelBundle\Attribute\Translatable;
use Symfony\Component\Serializer\Attribute\Groups;

trait {$traitName}
{
{$this->indent(implode("\n\n", $blocks), 4)}
}

PHP;

        @mkdir(\dirname($targetPath), 0775, true);
        file_put_contents($targetPath, $code);
        $this->logger->info('Trait generated', ['path' => $targetPath, 'bytes' => strlen($code)]);
    }

    /**
     * Return existing translatable hook property names in a trait file.
     */
    private function scanExistingHookNames(string $code): array
    {
        $names = [];
        $re = '/\#\[\s*(?:Survos\\\\BabelBundle\\\\Attribute\\\\)?Translatable[^\]]*\]\s*public\s+[^\$]*\$(\w+)\s*\{/mi';
        if (preg_match_all($re, $code, $m)) {
            foreach ($m[1] as $n) $names[] = (string)$n;
        }
        return array_values(array_unique($names));
    }

    /**
     * Append hook blocks before the final closing brace of the trait.
     *
     * @param array<int, array{name:string, hookAttrs:array, columnAttr:mixed}> $toAppend
     */
    private function appendBlocksBeforeClosingBrace(string $existing, array $toAppend): string
    {
        $blocks = [];
        foreach ($toAppend as $f) {
            $blocks[] = $this->renderHookBlock($f);
        }
        $insertion = "\n\n".$this->indent(implode("\n\n", $blocks), 4)."\n";

        $pos = strrpos($existing, "}\n");
        if ($pos === false) $pos = strrpos($existing, "}");
        if ($pos === false) {
            return rtrim($existing).$insertion."\n}\n";
        }

        return substr($existing, 0, $pos) . rtrim($insertion) . "\n}\n";
    }

    private function ensureTraitHeaderImports(string &$code): void
    {
        $need = [
            'use Doctrine\ORM\Mapping as ORM;',
            'use Doctrine\DBAL\Types\Types;',
            'use Survos\BabelBundle\Attribute\Translatable;',
            'use Symfony\Component\Serializer\Attribute\Groups;',
        ];
        foreach ($need as $use) {
            if (strpos($code, $use) === false) {
                $code = preg_replace(
                    '/(\nnamespace\s+[^\;]+;\s*\R)/',
                    "$1".$use."\n",
                    $code,
                    1
                ) ?? $code;
            }
        }
    }

    /**
     * Render one translatable hook block.
     * NOTE: backing is **protected** so other trait methods (e.g. from BabelHooksTrait) can access it safely.
     */
    private function renderHookBlock(array $f): string
    {
        $name = (string)$f['name'];
        $back = $name.'Backing';

        $attrs = array_map('trim', array_values(array_filter((array)($f['hookAttrs'] ?? []), 'strlen')));
        $hasTrans = false;
        foreach ($attrs as $a) {
            if (preg_match('/^\#\[\s*(?:Survos\\\\BabelBundle\\\\Attribute\\\\)?Translatable\b/', $a)) {
                $hasTrans = true; break;
            }
        }
        if (!$hasTrans) {
            array_unshift($attrs, '#[Translatable]');
        }
        $attrLines = $attrs ? (implode("\n    ", $attrs)."\n    ") : '';

        return <<<PHP
#[ORM\Column(type: Types::TEXT, nullable: true)]
protected ?string \${$back} = null;

{$attrLines}public ?string \${$name} {
    get => \$this->resolveTranslatable('{$name}', \$this->{$back}, '{$name}');
    set => \$this->{$back} = \$value;
}
PHP;
    }

    private function splitFqcn(string $fqcn): array
    {
        $fqcn = ltrim($fqcn, '\\');
        $pos  = strrpos($fqcn, '\\');
        if ($pos === false) return ['', $fqcn];
        return [substr($fqcn, 0, $pos), substr($fqcn, $pos + 1)];
    }

    private function indent(string $s, int $spaces): string
    {
        $pad = str_repeat(' ', $spaces);
        return preg_replace('/^/m', $pad, $s) ?? $s;
    }
}
