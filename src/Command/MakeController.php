<?php

namespace Survos\CodeBundle\Command;

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
final class MakeController extends BaseMaker
{
//    public function __construct(
//        private GeneratorService $generatorService,
//        #[Autowire('%kernel.project_dir%')] private string $projectDir,
//    )
//    {
//    }

    public function __invoke(
        SymfonyStyle     $io,
        #[Argument(description: 'Controller class name, e.g. App')]
        string $name,
        #[Option(description: 'controller route name (e.g. admin_do_something)', shortcut: 'r')]
        ?string $routeName = null,
        #[Option('method name, default to routeName', shortcut: 'm')]
        ?string $method = null,

        #[Option(description: 'make an invokable controller', shortcut: 'inv')] // save i for interactive
        bool   $invokable = true,
        #[Option(name: 'template', shortcut: 't', description: 'template name with path')]
        string $templateName = '',
        #[Option(description:  'secure route with a role')]
        string $security = '',
        #[Option(description: 'add a cache attribute', shortcut: 'c')]
        string $cache = '', // @todo: add timeout?

        #[Option(description: 'path, defaults to /[routeName]', shortcut: 'p')]
        string $path = '',
// @todo: move to make/update:class controller
        #[Option(description: 'class route, e.g. /product', shortcut: 'cr')]
        string $classRoute = '',

        #[Option(description: 'namespace to use (will determine file location)', shortcut: 'ns')]
        string $namespace = '',

        #[Option(description: "Overwrite the file if it exists")]
        bool   $force = false
    ): int
    {
        if (empty($namespace)) {
            $namespace = 'App\\Controller';
        }
        if (!u($name)->endsWith('Controller')) {
            $name .= 'Controller';
        }
        [$class, $ns, $filename]  = $this->getClass($namespace, $name);

        // for twig stuff, see https://github.com/zenstruck/twig-service-bundle
        // generate controller method. Ignore if it already exists (@todo: delete first)
        $ns = $this->generatorService->generateController(
            $class, $ns,
            $name, $namespace, $routeName, $path,
            $security, $cache, $templateName, $classRoute);

        $class = $ns->getClasses()[array_key_first($ns->getClasses())];

        if (!$class->hasMethod('__construct')) {
            $class->addMethod('__construct');
        }

        if ($routeName) {
            $this->generatorService->addMethod($class, $routeName, $templateName);
            if ($templateName) {
                // @todo: library of templates
                $this->createTemplate($name, $routeName, $templateName, $force);
            }
        }

        // we already have this, because we get the existing methods.
        $path = $this->generatorService->namespaceToPath($namespace, $this->projectDir);
        $filename = $path . '/';
        foreach (explode(':', $name) as $part) {
            $filename .= u($part)->title();
        }
        $filename .= '.php';

        $io->writeln($ns);
        file_put_contents($filename, '<?php ' . "\n\n" . $ns);
//        if (!file_exists($filename) || $force) {
//        } else {
//            throw new \Exception("$filename already exists");
//        }

        $io->success(sprintf('controller %s generated.', $filename));
        return Command::SUCCESS;
    }

    private function createTemplate(string $controllerName,
                                    string $routeName,
                                    ?string $templateName=null,
                                    bool $force = false)
    {
        if (empty($templateName)) {
            $templatePrefix = u($controllerName)->replace('Controller', '')->lower();
            $templateName = "$templatePrefix/$routeName";
        }
        if (!u($templateName)->endsWith('.html.twig')) {
            $templateName .= ".html.twig";
        }

        $templatePath = 'templates/' . $templateName;
        if (!file_exists($templatePath) || $force) {
            $dir = pathinfo($templatePath, PATHINFO_DIRNAME);
            if (!file_exists($dir)) {
                mkdir($dir, recursive: true);
            }
            // @todo: get template paths
            $fn = __DIR__ . '/../../twig/symfony.html.twig';
            assert(file_exists($fn), $fn);
            $templateCode = file_get_contents($fn);

            file_put_contents(getcwd() . '/' . $templatePath, $templateCode);
        }

        return Command::SUCCESS;

    }


}
