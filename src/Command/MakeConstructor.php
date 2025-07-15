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
use function Symfony\Component\String\u;

use Nette\PhpGenerator\PsrPrinter;
use PhpParser\PrettyPrinter;


use PhpParser\ParserFactory;
use PhpParser\BuilderFactory;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node;
use PhpParser\Node\Param;

use PhpParser\Node\Stmt\Class_;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

use PhpParser\Error;
use PhpParser\Node\Name;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;

use Kdyby\ParseUseStatements\ParseUseStatements;
use Nette\PhpGenerator\PhpFile;

// removed until nullable string options are allowed again
#[AsCommand('survos:make:constructor', 'inject dependencies into a constructor', aliases: ['_construct'])]
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
            $constructor
                ->addPromotedParameter(lcfirst(new \ReflectionClass($entityClass)->getShortName()))
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

    private function updateConstructorFile(SymfonyStyle $io, string $var, string $type): mixed
    {
        $code = file_get_contents($this->filename);
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $ast = $parser->parse($code);
        $factory = new BuilderFactory();

        $printer = new Standard();

        //$injectedVar = 'logger';
        $injectedVar = $var;
        //$injectedType = 'Psr\Log\LoggerInterface';
        $injectedType = $type;
        //$shortType = 'LoggerInterface'; // used in type hint, use() will handle import
        $shortType = (new \ReflectionClass($type))->getShortName();

        $traverser = new NodeTraverser();

        // 1. Add the constructor parameter using the short type for the variable name, ensuring uniqueness
        $traverser->addVisitor(new class($shortType) extends NodeVisitorAbstract {
            private string $shortType;

            public function __construct(string $shortType)
            {
                $this->shortType = $shortType;
            }

            public function enterNode(Node $node)
            {
                if (!$node instanceof Class_) {
                    return null;
                }

                // Find existing __construct method
                $constructor = null;
                foreach ($node->getMethods() as $method) {
                    if ($method->name->toString() === '__construct') {
                        $constructor = $method;
                        break;
                    }
                }

                // Collect all parameter variable names to avoid collision
                $existingVars = [];
                if ($constructor) {
                    foreach ($constructor->params as $param) {
                        $existingVars[] = $param->var->name;
                    }
                }

                // Use short type for variable name, lowercased first letter
                $baseVar = lcfirst($this->shortType);
                $var = $baseVar;
                $i = 2;
                while (in_array($var, $existingVars, true)) {
                    $var = $baseVar . $i;
                    $i++;
                }

                // If no constructor, create one with the injected param
                if (!$constructor) {
                    $constructor = new Node\Stmt\ClassMethod('__construct', [
                        'flags' => Class_::MODIFIER_PUBLIC,
                        'params' => [
                            new Param(
                                var: new Variable($var),
                                type: new Name($this->shortType),
                                flags: Class_::MODIFIER_PRIVATE | Class_::MODIFIER_READONLY
                            ),
                        ],
                        'stmts' => [],
                    ]);
                    // Insert constructor at the top of the class statements
                    array_unshift($node->stmts, $constructor);
                    return null;
                }

                // Check if parameter already exists with correct type and visibility
                foreach ($constructor->params as $param) {
                    $isSameVar = $param->var->name === $var;
                    $isSameType = $param->type instanceof Name && $param->type->toString() === $this->shortType;
                    $isPromoted = ($param->flags & (Class_::MODIFIER_PRIVATE | Class_::MODIFIER_READONLY)) === (Class_::MODIFIER_PRIVATE | Class_::MODIFIER_READONLY);

                    if ($isSameVar && $isSameType && $isPromoted) {
                        // Already injected correctly
                        return null;
                    }
                }

                // Otherwise, inject parameter with unique name
                $constructor->params[] = new Param(
                    var: new Variable($var),
                    type: new Name($this->shortType),
                    flags: Class_::MODIFIER_PRIVATE | Class_::MODIFIER_READONLY
                );

                return null;
            }
        });

        // 2. Add the use statement if missing
        $traverser->addVisitor(new class($injectedType) extends NodeVisitorAbstract {
            private string $fqcn;
            private bool $alreadyUsed = false;

            public function __construct(string $fqcn)
            {
                $this->fqcn = $fqcn;
            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        if ($use->name->toString() === $this->fqcn) {
                            $this->alreadyUsed = true;
                        }
                    }
                }
            }

            public function afterTraverse(array $nodes): ?array
            {
                if ($this->alreadyUsed) {
                    return null;
                }

                $useStmt = new Use_([
                    new UseUse(new Name($this->fqcn))
                ]);

                foreach ($nodes as $node) {
                    if ($node instanceof Node\Stmt\Namespace_) {
                        array_unshift($node->stmts, $useStmt);
                        return $nodes;
                    }
                }

                array_unshift($nodes, $useStmt);
                return $nodes;
            }
        });

        $modifiedAst = $traverser->traverse($ast);
        //$printer = new Standard();
        //$prettyPrinter = new PrettyPrinter\Standard;
        //$newCode = $prettyPrinter->prettyPrintFile($modifiedAst);

        $printer = new CustomPrettyPrinter();
        $newCode = $printer->prettyPrintFile($modifiedAst);
        file_put_contents($this->filename, $newCode);

        //file_put_contents($this->filename,$newCode);

        return $newCode;
    }

    private function updateConstructorHybrid(SymfonyStyle $io, string $var, string $type): mixed
    {
        //we ll recreate the php class and all the file content with minimized effort

        //init the file using the generator service
        //init file / class namespace
        $namespace = new PhpNamespace($this->phpNamespace->getName());

        //print : showing use statements
        //$io->writeln("use statements:");
        //extract use statements from existing file

        $code = file_get_contents($this->filename);
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $ast = $parser->parse($code);

        //$injectedVar = 'logger';
        $injectedVar = $var;
        //$injectedType = 'Psr\Log\LoggerInterface';
        $injectedType = $type;
        //$shortType = 'LoggerInterface'; // used in type hint, use() will handle import
        $shortType = (new \ReflectionClass($type))->getShortName();

        $traverser = new NodeTraverser();

        $useStatements = [];
        $collector = new UseCollector();
        // Collect use statements into $useStatements array
        $traverser->addVisitor(new class($collector) extends NodeVisitorAbstract {

            public function __construct(private UseCollector $collector)
            {

            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Use_) {
                    foreach ($node->uses as $use) {
                        $this->collector->useStatements[] = $use->name->toString();
                    }
                }
                //return $this->useStatements;
            }
        });

        $modifiedAst = $traverser->traverse($ast);

        //dd($collector->useStatements);
        //add the use statements to the namespace
        foreach ($collector->useStatements as $use) {
            $namespace->addUse($use);
            //$io->writeln($use);
        }

        //prepare the class
        //$class = $namespace->addClass($this->className);
        $class = ClassType::from($this->FqClassName, withBodies: true);
        //extract class arttributes (#[AsCommand('app:load', 'Load the chijal data')])
        $attributesCollector = new AttributesCollector();
        $traverser = new NodeTraverser;

        $traverser->addVisitor(new class($attributesCollector) extends NodeVisitorAbstract {
            public function __construct(private AttributesCollector $attributesCollector)
            {

            }

            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Class_) {
                    foreach ($node->attrGroups as $attrGroup) {
                        foreach ($attrGroup->attrs as $attr) {
                            $attributeName = $attr->name->toString();
                            $args = [];
                            foreach ($attr->args as $arg) {
                                // Try to get the value, fallback to the raw node if not scalar
                                $args[] = property_exists($arg->value, 'value') ? $arg->value->value : $arg->value;
                            }
                            $this->attributesCollector->attributes[] = [
                                'name' => $attributeName,
                                'args' => $args,
                            ];
                        }
                    }
                }
            }
        });
        $traverser->traverse($ast);

        //add the attributes to the class
        foreach ($attributesCollector->attributes as $attribute) {
            $class->addAttribute($attribute['name'], $attribute['args']);
            //$io->writeln($attribute['name']);
        }
        //add the constructor if it does not exist
        $constructor = $class->getMethod('__construct');
        if (!$constructor) {
            $constructor = $class->addMethod('__construct');
        }
        //add the new parameter to the constructor
        // $parameter = $constructor->addPromotedParameter($injectedVar, null);
        // $parameter->setVisibility('private');
        // $parameter->setType($injectedType);
        // //add the attribute to the constructor
        // $parameter->addAttribute(Target::class, []);
        // //add the use statement to the namespace
        // $namespace->addUse($injectedType);

        //generate the new code
        $file = new PhpFile();
        $file->addNamespace($namespace);
        //and namespace statement

        $file->setStrictTypes();
        $file->addClass($class);
        //$printer = new Standard();
        //$newCode = $printer->
        echo (new PsrPrinter)->printFile($file);
        //show the new code
        //$io->writeln($newCode);
        //write the new code to the file
        //file_put_contents($this->filename, $newCode);

        return null;
    }

    private function workflows(SymfonyStyle $io): mixed
    {
        $entityClass = $io->askQuestion(new ChoiceQuestion("select a workflow class",
            iterator_to_array($this->generatorService->getWorkflows())));
        $io->warning("@todo: add the #[Target] parameter to the constructor " . $entityClass);
        return [];
    }

    private function updateConstructor(SymfonyStyle $io, string $var, string $type): mixed
    {

        $this->phpNamespace->addUse($type);
        // we have the namespace already, we just want to update the constructor and add the type to $use
        //
        $method = new Method('__construct');

        //dd($var);

        $parameter = $method->addPromotedParameter($var, null);
        $parameter->setVisibility('private');
        $parameter->setType($type);
//        $this->phpClass->addMethod($method);
        // for workflow only.
//        $parameter->addAttribute(Target::class, []);
        $io->writeln((string)$method);
        return $method;

    }

    private function askEntities(): array
    {
        return $this->generatorService->getAllDoctrineEntitiesFqcn();
    }


}

class UseCollector
{
    public array $useStatements = [];
}

class AttributesCollector
{
    public array $attributes = [];
}

class CustomPrettyPrinter extends Standard
{
    public function pStmt_ClassMethod(Node\Stmt\ClassMethod $node): string
    {
        if ($node->name->toString() === '__construct' && !empty($node->params)) {
            $params = array_map(fn($param) => $this->p($param), $node->params);

            $paramString = "\n        " . implode(",\n        ", $params) . "\n    ";

            $header = sprintf(
                '%s function %s(%s)%s',
                $this->pModifiers($node->flags),
                $node->name,
                $paramString,
                $node->returnType !== null ? ': ' . $this->p($node->returnType) : ''
            );

            // Handle body
            if (is_array($node->stmts)) {
                if (count($node->stmts) === 0) {
                    return $header . " {\n    }";
                } else {
                    $body = $this->pStmts($node->stmts, true);
                    // Indent each line of the body
                    $indentedBody = preg_replace('/^/m', '    ', $body);
                    return $header . $indentedBody;
                }
            } else {
                return $header . ';';
            }
        }

        return parent::pStmt_ClassMethod($node);
    }
}
