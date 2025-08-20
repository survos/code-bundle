<?php

namespace Survos\CodeBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nette\PhpGenerator\ClassManipulator;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\Type;
use Psr\Log\LoggerInterface;
use Survos\Bundle\MakerBundle\Service\MakerService;
use Survos\CodeBundle\Service\GeneratorService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function Symfony\Component\String\u;

use Nette\PhpGenerator\PsrPrinter;

use Nette\PhpGenerator\PhpFile;

// removed until nullable string options are allowed again
#[AsCommand('code:constructor', 'inject dependencies into a constructor', aliases: ['_construct'])]
// class must exist before the method.
//
final class MakeConstructor extends BaseMaker
{
    private PhpNamespace $phpNamespace;
    private ClassType $phpClass;
    private string $className;
    private string $FqClassName;
    private Method $constructorMethod;
    //filename
    private string $filename;
//    public function __construct(
//        protected GeneratorService $generatorService,
//        #[Autowire('%kernel.project_dir%')] protected string $projectDir,
//    )
//    {
//    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: "filename of the EXISTING class, e.g. TestCommand")]
        string       $shortClassName,

        #[Argument(description: "initial category, e.g. em")]
        ?string      $category = null,

        #[Option(description: 'namespace to use (detect based on name, e.g. TextCommand = App\\Command)', shortcut: 'ns')]
        ?string      $namespace = null,

        #[Option(description: "Overwrite the file if it exists")]
        ?bool        $force = null
    ): int
    {
        if (!$namespace) {
            $namespace = match (true) {
                str_ends_with($shortClassName, 'Service') => 'App\\Service',
                str_ends_with($shortClassName, 'Workflow') => 'App\\Workflow',
                str_ends_with($shortClassName, 'Command') => 'App\\Command',
                str_ends_with($shortClassName, 'Controller') => 'App\\Controller',
                default => throw new \Exception("namespace must be explicitly passed.")
            };
        }
        $this->phpNamespace = new PhpNamespace($namespace);

        $class = $namespace . '\\' . $shortClassName;

        $this->FqClassName = $class;

        $path = $this->generatorService->namespaceToPath($namespace, $this->projectDir);

        $this->className = $shortClassName;
        $filename = $path . '/' . $shortClassName . '.php';
        $this->filename = $filename;
        // foreach (explode(':', $filename) as $part) {
        //     $filename .= u($part)->title();
        // }
        //$filename .= '.php';
        //dd($path,$class,$shortClassName,$filename);
        assert(file_exists($filename), "File $filename does not exist");
        //dd($filename);

        $file = PhpFile::fromCode(file_get_contents($filename));
        $namespaces = $file->getNamespaces();
        // hack to get the class from the _existing_ php file
        $existingNs = $namespaces[$namespace];
        foreach ($existingNs->getClasses() as $className => $existingClass) {
            if ($className === $shortClassName) {
                $class = $existingClass;
                break;
            }
        }

        $constructor = $class->hasMethod('__construct')
            ? $class->getMethod('__construct')
            : $class->addMethod('__construct');

        $categories = new ChoiceQuestion('What type of dependencies do you want to inject?', [
            'em' => 'em/repos',
            'app' => 'application services',
            'workflows' => 'workflows',
            'data' => 'data manipulation (csv, json)',
            '' => "exit"
        ]);


        while ($category || ($category = $io->askQuestion($categories))) {
            $this->$category($io, $constructor, $existingNs);
//            $io->writeln((string) $existingNs);
//            $printer = new CustomPrettyPrinter();
//            $newCode = $printer->prettyPrintFile($modifiedAst);
            file_put_contents($this->filename, "<?php\n\n" . $existingNs);
            // reload the file
            [$existingClass, $existingNs, $filename] =
                $this->getClass($existingNs->getName(), $shortClassName, $this->filename);
            // at this point, it should always exist
            $constructor = $existingClass->getMethod('__construct');
//            $io->writeln((string)$constructor);
            if ($io->isVerbose()) {
                $io->writeln($existingNs);
            }
//            $io->success($category . ' now.');
            $category = null;
        }


        return Command::SUCCESS;
    }

    private function em(SymfonyStyle $io, Method $constructor, PhpNamespace $ns): mixed
    {
        $options = iterator_to_array($this->generatorService->getRepositories());
        $options[] = HttpClientInterface::class;
        $options[] = LoggerInterface::class;
        $options[] = EntityManagerInterface::class;
//        $options = [...$options, iterator_to_array($this->generatorService->getWorkflows())];
        foreach ($options as $option) {
            $shortName = new \ReflectionClass($option)->getShortName();
            $keyedOptions[lcfirst($shortName)] = $option;
        }
        $entityClasses = $io->askQuestion(new ChoiceQuestion("please select",
            $keyedOptions)
            ->setMultiselect(true)
        );
        foreach ($entityClasses as $entityClass) {
            $entityClass = $keyedOptions[$entityClass];

            $this->dumpParameters($io, $constructor);
            $parameterName = lcfirst(new \ReflectionClass($entityClass)->getShortName());
            $parameterName = str_replace('Interface', '', $parameterName);
            $constructor
                ->addPromotedParameter($parameterName)
                ->setPrivate()
                ->setReadOnly()
                ->setType($entityClass);

            $ns->addUse($entityClass);
        }
        return [];
    }

    private function dumpParameters($io, $method)
    {
        $table = new Table($io);
        $table->setHeaderTitle($method);
        foreach ($method->getParameters() as $param) {
            $table->addRow([$param->getName(), $param->getType()]);
        }
        $table->render();

    }

    private function workflows(SymfonyStyle $io): mixed
    {
        $entityClass = $io->askQuestion(new ChoiceQuestion("select a workflow class",
            iterator_to_array($this->generatorService->getWorkflows())));
        $io->warning("@todo: add the #[Target] parameter to the constructor " . $entityClass);
        return [];
    }


    private function askEntities(): array
    {
        return $this->generatorService->getAllDoctrineEntitiesFqcn();
    }

}
