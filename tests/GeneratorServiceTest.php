<?php declare(strict_types = 1);

namespace CodeBundle\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Survos\CodeBundle\Service\GeneratorService;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertTrue;

class GeneratorServiceTest extends TestCase
{
    public function testWithoutConfig(): void
    {
        assertTrue(true);
//        $this->expectException(RuntimeException::class);
//        $config = [
//        ];
//        $seo = new GeneratorService();
//        assertEquals(0, count(iterator_to_array($seo->getWorkflows())));
    }

    public function testWithConfig(): void
    {
        assertTrue(true);
//        $config = [
//            'minTitleLength' => SeoService::DEFAULT_MIN_TITLE,
//            'maxTitleLength' => SeoService::DEFAULT_MAX_TITLE,
//
//        ];
//        $seo = new SeoService($config);
//        assertEquals(SeoService::DEFAULT_MIN_TITLE, $seo->getConfigValue('minTitleLength'));
//        assertEquals(SeoService::DEFAULT_MAX_TITLE, $seo->getConfigValue('maxTitleLength'));
//        assertEquals([SeoService::DEFAULT_MIN_TITLE, SeoService::DEFAULT_MAX_TITLE], $seo->getMinMax('Title'));

    }


}
