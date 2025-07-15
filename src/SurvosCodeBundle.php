<?php

namespace Survos\CodeBundle;

use Survos\Bundle\MakerBundle\Service\MakerService;
use Survos\CodeBundle\Command\MakeCommand;
use Survos\CodeBundle\Command\MakeConstructor;
use Survos\CodeBundle\Command\MakeController;
use Survos\CodeBundle\Command\MakeService;
use Survos\CodeBundle\Service\GeneratorService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


class SurvosCodeBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        $builder->autowire(GeneratorService::class)
            ->setArgument('$doctrine', new Reference('doctrine'))
            ->setAutoconfigured(true) // bad practice! Better to inject
            ->setPublic(true);

        array_map(fn(string $class) => $builder->autowire($class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setArgument('$generatorService', new Reference(GeneratorService::class))
            ->addTag('console.command')
            , [MakeCommand::class, MakeService::class, MakeController::class, MakeConstructor::class]);

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('base_layout')->defaultValue('base.html.twig')->end()
            ->end();
    }

}
