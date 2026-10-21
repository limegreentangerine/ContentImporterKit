<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Entity;

use PHPUnit\Framework\TestCase;
use ClassKit\Entity\Core\BaseEntity;
use ContentImporterKit\Entity\ImportSource;
use ContentImporterKit\Scrape\InvalidSourceException;

final class ImportSourceTest extends TestCase
{
    private static function source(): ImportSource
    {
        return (new ImportSource())
            ->setName('Adferiad news')
            ->setNewsUrl('https://adferiad.org/cym/')
            ->setSitemapUrl('https://adferiad.org/cym/post-sitemap.xml')
            ->setTitleSelector('h1')
            ->setArticleSelector('.full_width.single_post .two_thirds')
            ->setBannerSelector('.page_banner_wrap .image_wrap')
            ->setSinceDays(30)
            ->setMaxPerRun(50)
            ->setParentPageID(7)
            ->setPageTypeHandle('page')
            ->setPageTemplateHandle('full')
            ->setAreaName('Main')
            ->setBannerAttribute('thumbnail');
    }

    public function testSettersAreFluentAndGettersReturnWhatWasSet(): void
    {
        $source = self::source();

        self::assertSame('Adferiad news', $source->getName());
        self::assertSame('https://adferiad.org/cym/', $source->getNewsUrl());
        self::assertSame(30, $source->getSinceDays());
        self::assertSame(50, $source->getMaxPerRun());
        self::assertSame(7, $source->getParentPageID());
        self::assertSame('thumbnail', $source->getBannerAttribute());
    }

    public function testANewSourceIsNotPersistedAndDoesNotThrowWhenAsked(): void
    {
        self::assertFalse((new ImportSource())->isPersisted());
    }

    public function testASourceWithAnIdIsPersisted(): void
    {
        $source = new ImportSource();
        (new \ReflectionProperty(BaseEntity::class, 'id'))->setValue($source, 7);

        self::assertTrue($source->isPersisted());
        self::assertSame(7, $source->getID());
    }

    public function testANewSourceIsEnabled(): void
    {
        self::assertSame(1, (new ImportSource())->getEnabled());
        self::assertSame('Yes', (new ImportSource())->getEnabledString());
        self::assertSame('No', (new ImportSource())->setEnabled(0)->getEnabledString());
    }

    public function testItBecomesASourceConfig(): void
    {
        $config = self::source()->toSourceConfig();

        self::assertSame('https://adferiad.org/cym/', $config->newsUrl);
        self::assertSame('https://adferiad.org/cym/post-sitemap.xml', $config->sitemapUrl);
        self::assertSame('h1', $config->titleSelector);
        self::assertSame('.page_banner_wrap .image_wrap', $config->bannerSelector);
        self::assertSame(30, $config->sinceDays);
        self::assertSame(50, $config->maxPerRun);
    }

    public function testTheIgnoreListReachesTheSourceConfig(): void
    {
        $config = self::source()->setExcludedSlugs("blog\ncategory-*")->toSourceConfig();

        self::assertSame(['blog', 'category-*'], $config->excludedSlugs);
        self::assertTrue($config->isExcluded('category-news'));
    }

    public function testNoIgnoreListMeansNothingIsIgnored(): void
    {
        self::assertSame([], self::source()->toSourceConfig()->excludedSlugs);
        self::assertSame([], self::source()->setExcludedSlugs('')->toSourceConfig()->excludedSlugs);
    }

    public function testABadIgnoreListIsRejectedWhenConverted(): void
    {
        $this->expectException(InvalidSourceException::class);

        self::source()->setExcludedSlugs('*')->toSourceConfig();
    }

    public function testAnIncompleteSourceIsRejectedWhenConverted(): void
    {
        $this->expectException(InvalidSourceException::class);

        (new ImportSource())->setName('Nothing else set')->toSourceConfig();
    }

    public function testASourceWithAnUnsupportedSelectorIsRejectedWhenConverted(): void
    {
        $this->expectException(InvalidSourceException::class);

        self::source()->setTitleSelector('h1:first-child')->toSourceConfig();
    }

    public function testItBecomesAPublishTarget(): void
    {
        $target = self::source()->toPublishTarget();

        self::assertSame(7, $target->parentPageID);
        self::assertSame('page', $target->pageTypeHandle);
        self::assertSame('full', $target->pageTemplateHandle);
        self::assertSame('Main', $target->areaName);
        self::assertSame(['banner' => 'thumbnail'], $target->attributeMap);
    }

    public function testNoBannerAttributeMeansNothingIsMapped(): void
    {
        self::assertSame([], self::source()->setBannerAttribute(null)->toPublishTarget()->attributeMap);
        self::assertSame([], self::source()->setBannerAttribute('')->toPublishTarget()->attributeMap);
    }
}
