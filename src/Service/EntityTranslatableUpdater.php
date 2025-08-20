<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as Pretty;
use Psr\Log\LoggerInterface;

final class EntityTranslatableUpdater
{
    private Pretty $pp;

    public function __construct(private readonly LoggerInterface $logger)
    { $this->pp = new Pretty(); }

    /**
     * @param string[] $fieldNames
     */
    public function updateEntity(
        string $entityPath,
        string $entityFqcn,
        string $traitFqcn,
        array  $fieldNames,
        bool   $hasTranslatables,
        bool   $dryRun = false,
        bool   $backup = false
    ): bool {
        if (!$hasTranslatables) {
            $this->logger->info('Skip entity update (no translatable fields)', ['entity'=>$entityFqcn]);
            return false;
        }

        $code = @file_get_contents($entityPath);
        if ($code === false) { throw new \RuntimeException("Cannot read $entityPath"); }

        $parser = (new ParserFactory())->createForHostVersion();
        $ast    = $parser->parse($code);
        if (!$ast) { throw new \RuntimeException("Parse failed for $entityPath"); }

        // locate ns/class
        $ns=null; $class=null;
        foreach ($ast as $stmt) if ($stmt instanceof Namespace_) {
            $ns=$stmt; foreach($ns->stmts as $s) if($s instanceof Class_){ $class=$s; break; }
        }
        if (!$ns || !$class) { throw new \RuntimeException("Namespace/class not found in $entityPath"); }

        $traitShort = substr($traitFqcn, strrpos($traitFqcn, '\\')+1);
        $ifaceFqcn  = 'Survos\\BabelBundle\\Contract\\TranslatableResolvedInterface';
        $ifaceShort = 'TranslatableResolvedInterface';

        // header uses
        $this->ensureUses($ns, [
            $ifaceFqcn,
            'Survos\\BabelBundle\\Entity\\Traits\\TranslatableHooksTrait',
            $traitFqcn,
        ]);

        // implements -> short
        $this->normalizeImplementsToShort($class, $ifaceFqcn, $ifaceShort);

        // traits -> short
        $this->ensureTraitUseShort($class, 'TranslatableHooksTrait');
        $this->ensureTraitUseShort($class, $traitShort);

        // remove props and annotate
        $this->removeConflictingProperties($class, $code, $fieldNames);
        $this->addPropertyAnnotations($class, $fieldNames);

        $new = $this->pp->prettyPrintFile($ast);
        if ($new === $code) return false;

        if ($dryRun) { echo "--- original\n$code\n+++ updated\n$new\n"; return true; }
        if ($backup) { @copy($entityPath, $entityPath.'.bak'); }
        if (@file_put_contents($entityPath, $new) === false) { throw new \RuntimeException("Failed to write $entityPath"); }
        return true;
    }

    // ------- helpers (same as previously patched version) -------

    private function ensureUses(Namespace_ $ns, array $fqcnList): void
    {
        foreach ($fqcnList as $fq) {
            if ($this->hasUse($ns, $fq)) continue;
            array_unshift($ns->stmts, new Use_([new UseUse(new Name($fq))]));
        }
    }
    private function hasUse(Namespace_ $ns, string $fqcn): bool
    {
        foreach ($ns->stmts as $s) if ($s instanceof Use_) foreach ($s->uses as $u) if ($u->name->toString() === $fqcn) return true;
        return false;
    }
    private function normalizeImplementsToShort(Class_ $class, string $ifaceFqcn, string $ifaceShort): void
    {
        foreach ($class->implements as $i=>$impl) if ($impl->toString() === $ifaceFqcn) unset($class->implements[$i]);
        foreach ($class->implements as $impl) if ($impl->toString() === $ifaceShort) return;
        $class->implements[] = new Name($ifaceShort);
    }
    private function ensureTraitUseShort(Class_ $class, string $traitShort): void
    {
        foreach ($class->stmts as $s) if ($s instanceof Stmt\TraitUse)
            foreach ($s->traits as $t) { $tShort = substr($t->toString(), strrpos($t->toString(),'\\')+1); if ($tShort === $traitShort) return; }
        array_unshift($class->stmts, new Stmt\TraitUse([new Name($traitShort)]));
    }
    private function removeConflictingProperties(Class_ $class, string $origSource, array $fieldNames): void
    {
        foreach ($class->stmts as $idx=>$stmt) {
            if (!$stmt instanceof Property) continue;
            $has=false; foreach($stmt->attrGroups as $g) foreach($g->attrs as $a){ $n=$a->name->toString(); if($n==='Translatable'||$n==='Survos\\BabelBundle\\Attribute\\Translatable'){ $has=true; break; } }
            if (!$has) continue;
            foreach ($stmt->props as $prop) {
                $name=$prop->name->toString(); if(!\in_array($name,$fieldNames,true)) continue;
                $stub="/* Translated field '$name' moved to *TranslationsTrait.\n   To revert: remove the trait and uncomment below.\n   ".$this->pp->prettyPrint([$stmt])."\n*/";
                $class->stmts[$idx]=new Stmt\Nop(['comments'=>[new Doc($stub)]]);
            }
        }
    }
    private function addPropertyAnnotations(Class_ $class, array $fieldNames): void
    {
        $doc=$class->getDocComment();
        $lines=$doc?explode("\n",$doc->getText()):["/**"," */"];
        if(trim(end($lines))!=='*/') $lines[]=' */';
        foreach($fieldNames as $field){
            $ann=" * @property string|null \$$field [translatable via *TranslationsTrait]";
            if(!in_array($ann,$lines,true)) array_splice($lines,count($lines)-1,0,$ann);
        }
        $class->setDocComment(new Doc(implode("\n",$lines)));
    }
}
