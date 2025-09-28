<?php
declare(strict_types=1);

namespace Survos\CodeBundle\Service;

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Nette\PhpGenerator\Attribute as GenAttribute;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates App\Entity\Str, App\Entity\StrTranslation and their repositories
 * if missing. Uses PhpGenerator for clean, PSR-compliant output.
 */
final class StrEntitiesScaffolder
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        private readonly string $baseNamespace = 'App', // change if your app namespace differs
    ) {}

    /**
     * Create Str & StrTranslation entity + repo if missing.
     * Returns list of created relative paths.
     *
     * @return string[]
     */
    public function scaffoldIfMissing(): array
    {
        $created = [];

        // target FQCNs
        $strEntityFqcn = $this->baseNamespace.'\\Entity\\Str';
        $strRepoFqcn   = $this->baseNamespace.'\\Repository\\StrRepository';

        $stEntityFqcn  = $this->baseNamespace.'\\Entity\\StrTranslation';
        $stRepoFqcn    = $this->baseNamespace.'\\Repository\\StrTranslationRepository';

        // target paths
        $strEntityPath = $this->projectDir.'/src/Entity/Str.php';
        $strRepoPath   = $this->projectDir.'/src/Repository/StrRepository.php';

        $stEntityPath  = $this->projectDir.'/src/Entity/StrTranslation.php';
        $stRepoPath    = $this->projectDir.'/src/Repository/StrTranslationRepository.php';

        // ensure dirs exist
        $this->mkdirp(\dirname($strEntityPath));
        $this->mkdirp(\dirname($strRepoPath));

        // Str entity
        if (!file_exists($strEntityPath)) {
            $code = $this->buildEntityFile(
                $this->baseNamespace.'\\Entity',
                'Str',
                $strRepoFqcn,
                'str',
                '\\Survos\\BabelBundle\\Entity\\Base\\StrBase'
            );
            $this->dump($strEntityPath, $code);
            $created[] = $this->rel($strEntityPath);
        }

        // Str repo
        if (!file_exists($strRepoPath)) {
            $code = $this->buildRepositoryFile(
                $this->baseNamespace.'\\Repository',
                'StrRepository',
                $strEntityFqcn
            );
            $this->dump($strRepoPath, $code);
            $created[] = $this->rel($strRepoPath);
        }

        // StrTranslation entity
        if (!file_exists($stEntityPath)) {
            $code = $this->buildEntityFile(
                $this->baseNamespace.'\\Entity',
                'StrTranslation',
                $stRepoFqcn,
                'str_translation',
                '\\Survos\\BabelBundle\\Entity\\Base\\StrTranslationBase'
            );
            $this->dump($stEntityPath, $code);
            $created[] = $this->rel($stEntityPath);
        }

        // StrTranslation repo
        if (!file_exists($stRepoPath)) {
            $code = $this->buildRepositoryFile(
                $this->baseNamespace.'\\Repository',
                'StrTranslationRepository',
                $stEntityFqcn
            );
            $this->dump($stRepoPath, $code);
            $created[] = $this->rel($stRepoPath);
        }

        if ($created) {
            $this->logger->info('Scaffolded Str/StrTranslation files', ['created' => $created]);
        } else {
            $this->logger->info('Str/StrTranslation scaffolding not needed (all files present).');
        }

        return $created;
    }

    private function buildEntityFile(
        string $ns,
        string $shortClass,
        string $repoFqcn,
        string $tableName,
        string $baseFqcn
    ): string {
        $file = new PhpFile();
        $file->setStrictTypes();
        $printer = new PsrPrinter();

        $namespace = $file->addNamespace($ns);
        $namespace->addUse('Doctrine\ORM\Mapping', alias: 'ORM');

        $namespace->addUse(ltrim($baseFqcn, '\\'));
        $repoNs = substr($repoFqcn, 0, strrpos($repoFqcn, '\\'));
        if ($repoNs !== $ns) {
            $namespace->addUse($repoFqcn);
        }

        $class = $namespace->addClass($shortClass);
        $class->setExtends(ltrim($baseFqcn, '\\'));

        // #[ORM\Entity(repositoryClass: StrRepository::class)]
        $class->addAttribute(new GenAttribute('ORM\Entity', [
            'repositoryClass' => new \Nette\PhpGenerator\Literal($this->shortName($repoFqcn).'::class'),
        ]));

        // #[ORM\Table(name: 'str'|'str_translation')]
        $class->addAttribute(new GenAttribute('ORM\Table', [
            'name' => $tableName,
        ]));

        return $printer->printFile($file);
    }

    private function buildRepositoryFile(string $ns, string $repoShort, string $entityFqcn): string
    {
        $file = new PhpFile();
        $file->setStrictTypes();
        $printer = new PsrPrinter();

        $namespace = $file->addNamespace($ns);
        $namespace->addUse('Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository');
        $namespace->addUse('Doctrine\Persistence\ManagerRegistry');

        $entityShort = $this->shortName($entityFqcn);
        $entityNs = substr($entityFqcn, 0, strrpos($entityFqcn, '\\'));
        if ($entityNs !== $ns) {
            $namespace->addUse($entityFqcn);
        }

        $class = $namespace->addClass($repoShort);
        $class->setFinal();
        $class->setExtends('ServiceEntityRepository');

        $ctor = $class->addMethod('__construct');
        $ctor->setPublic();
        $ctor->addParameter('registry')->setType('ManagerRegistry');
        $ctor->setBody('parent::__construct($registry, '.$entityShort.'::class);');

        return $printer->printFile($file);
    }

    private function mkdirp(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }

    private function dump(string $path, string $code): void
    {
        $this->mkdirp(\dirname($path));
        file_put_contents($path, $code);
    }

    private function rel(string $absolute): string
    {
        $prefix = rtrim($this->projectDir, '/').'/';
        return str_starts_with($absolute, $prefix) ? substr($absolute, \strlen($prefix)) : $absolute;
    }

    private function shortName(string $fqcn): string
    {
        return substr($fqcn, strrpos($fqcn, '\\') + 1);
    }
}
