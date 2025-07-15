<?php

namespace Survos\CodeBundle\Command;

use Nette\PhpGenerator\PhpNamespace;
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
#[AsCommand('survos:make:service', 'Generate a service')]
// class must exist before the method.
//
final class MakeService
{
    public function __construct(
        private GeneratorService $generatorService,
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
    )
    {
    }

    public function __invoke(
        SymfonyStyle     $io,
        #[Argument(description: 'Service class name, e.g. App')]
        string $name,

        #[Option(description: "Overwrite the file if it exists")]
        bool   $force = false
    ): int
    {
        if (empty($namespace)) {
            $namespace = 'App\\Service';
        }
        if (!u($name)->endsWith('Service')) {
            $name .= 'Service';
        }

        // for twig stuff, see https://github.com/zenstruck/twig-service-bundle
        // @todo: instead of generating the controller, read it and append/replace the new method
        $ns = $this->generatorService->generateService($name, $namespace);

        $class = $ns->getClasses()[array_key_first($ns->getClasses())];
        $path = $this->generatorService->namespaceToPath($namespace, $this->projectDir);
        $filename = $path . '/';
        foreach (explode(':', $name) as $part) {
            $filename .= u($part)->title();
        }
        $filename .= '.php';

        if (!file_exists($filename) || $force) {
            $dir = dirname($filename);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($filename, '<?php ' . "\n\n" . $ns);
        } else {
            throw new \Exception("$filename already exists");
        }

        $io->success(sprintf('service %s generated.', $filename));
        if ($io->isVerbose()) {
            $io->writeln($ns);
        }
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
