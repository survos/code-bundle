<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Psr\Log\LoggerInterface;
use Survos\BabelBundle\Attribute\Translatable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Emits a complete <Entity>TranslationsTrait file (namespace, uses, trait, backings, hooks).
 * We generate the whole file in a single pass to avoid partial writes/insertions.
 */
final class TranslatableTraitGenerator
{
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * @param array<int, array{name:string, hookAttrs:string[], columnAttr?:string|null}> $fields
     */
    public function generateTrait(string $traitFqcn, array $fields, string $targetPath): void
    {
        [$nsName, $traitName] = $this->splitFqcn($traitFqcn);

        $this->logger->info('Generating translations trait (single-pass render)', [
            'fqcn'   => $traitFqcn,
            'target' => $targetPath,
            'fields' => array_column($fields, 'name'),
        ]);

        // ensure directory
        $dir = \dirname($targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger->error('Failed to create trait directory', ['dir' => $dir]);
            throw new \RuntimeException("Cannot create directory: $dir");
        }

        // build backings
        $backings = '';
        foreach ($fields as $f) {
            $name = $f['name'];
            $backings .= "    #[ORM\\Column(type: Types::TEXT, nullable: true)]\n";
            $backings .= "    private ?string \${$name}Backing = null;\n\n";
        }

        // build hooks (attrs are already fully-qualified lines from the scanner)
        $hooks = '';
        foreach ($fields as $f) {
            $name   = $f['name'];
            $attrs  = $f['hookAttrs'] ?: ['#[\\Survos\\BabelBundle\\Attribute\\Translatable]'];
            $joined = implode(' ', $attrs);
            $ctx    = str_contains($joined, 'context:') ? 'null /* context via attribute */' : "'$name'";

            foreach ($attrs as $a) { $hooks .= "    $a\n"; }
            $hooks .= "    public ?string \${$name} {\n";
            $hooks .= "        get => \$this->resolveTranslatable('{$name}', \$this->{$name}Backing, {$ctx});\n";
            $hooks .= "        set => \$this->{$name}Backing = \$value;\n";
            $hooks .= "    }\n\n";
        }

        // render full file
        $src = <<<PHPFILE
<?php
declare(strict_types=1);

namespace {$nsName};

use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;
use Survos\BabelBundle\Attribute\Translatable;
use Symfony\Component\Serializer\Attribute\Groups;

trait {$traitName}
{
{$backings}{$hooks}}
PHPFILE;

        $ok = @file_put_contents($targetPath, $src);
        if ($ok === false) {
            $this->logger->error('Failed to write generated trait', ['path' => $targetPath]);
            throw new \RuntimeException("Failed to write trait: $targetPath");
        }

        $this->logger->info('Trait generated', [
            'path'  => $targetPath,
            'bytes' => strlen($src),
        ]);
    }

    private function splitFqcn(string $fqcn): array
    {
        $p = strrpos($fqcn, '\\');
        return $p === false ? ['', $fqcn] : [substr($fqcn, 0, $p), substr($fqcn, $p + 1)];
    }
}
