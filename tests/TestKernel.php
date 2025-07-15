<?php

namespace Survos\CodeBundle\Tests;

use Survos\CodeBundle\SurvosCodeBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
class TestKernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        return [
            new TwigBundle(),
            new FrameworkBundle(),
            new SurvosCodeBundle(),
            new DoctrineBundle(),
        ];
    }

    /**
     * @throws \Exception
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/config.yml');
    }

    public function getProjectDir(): string
    {
        return __DIR__ . '/project-dir';
    }
}
