<?php

namespace Survos\CodeBundle;

use Survos\CodeBundle\Command\CodeCommand;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;


class SurvosCodeBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->autowire(CodeCommand::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->addTag('console.command')
        ;

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('base_layout')->defaultValue('base.html.twig')->end()
            ->end();
    }

}
