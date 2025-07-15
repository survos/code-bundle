<?php

namespace Survos\CodeBundle\Command;

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Survos\Bundle\MakerBundle\Service\MakerService;
use Survos\CodeBundle\Service\GeneratorService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use function Symfony\Component\String\u;

// removed until nullable string options are allowed again
#[AsCommand('survos:make:controller', 'Generate a controller method on an existing controller class')]
// class must exist before the method.
//
class BaseMaker
{
    public function __construct(
        protected GeneratorService                         $generatorService,
        #[Autowire('%kernel.project_dir%')] protected string $projectDir,
    )
    {
    }

    // move to generator service?

    public function getClass(string $namespace, string $shortClassName, ?string $filename=null)
    {
        if (!$namespace) {
            $namespace = match (true) {
                str_ends_with($shortClassName, 'Command') => 'App\\Command',
                str_ends_with($shortClassName, 'Controller') => 'App\\Controller',
                default => throw new \Exception("namespace must be explicitly passed.")
            };
        }

//        $phpNamespace = new PhpNamespace($namespace);

        $class = $namespace . '\\' . $shortClassName;

//        $this->FqClassName = $class;

        $path = $this->generatorService->namespaceToPath($namespace, $this->projectDir);

//        dump($filename, $path, $shortClassName, file_exists($filename));
        $filename = $path . '/' . $shortClassName . '.php';
        // foreach (explode(':', $filename) as $part) {
        //     $filename .= u($part)->title();
        // }
        //$filename .= '.php';
        //dd($path,$class,$shortClassName,$filename);
//        assert(file_exists($filename), "File $filename does not exist");
        //dd($filename);

        if (file_exists($filename)) {

            $file = PhpFile::fromCode(file_get_contents($filename));
            $namespaces = $file->getNamespaces();
            // hack to get the class from the _existing_ php file
            $ns = $namespaces[$namespace];
            foreach ($ns->getClasses() as $className => $existingClass) {
                if ($className === $shortClassName) {
                    $class = $existingClass;
                    break;
                }
            }
        } else {
            // generate just the file with no methods
            $ns = new PhpNamespace($namespace);
            $class = $ns->addClass($shortClassName);
//            $ns = $this->generatorService->generateController($class, $namespace);
        }

        return [$class, $ns, $filename];
//        dd($file, $namespaces[$namespace]);

//        $class = $this->phpNamespace->getClass($this->className);
//        dd($class, $file);

    }


}
