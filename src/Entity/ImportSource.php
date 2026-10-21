<?php

namespace ContentImporterKit\Entity;

use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\BaseEntity;
use ClassKit\Entity\Core\UpdatedEntity;
use ContentImporterKit\Scrape\SourceConfig;
use ContentImporterKit\Import\PublishTarget;

/**
 * A site the importer scrapes: where its articles are listed, how to read them, and where they become pages.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="ciSources",
 *      options={"comment": "Sites the content importer scrapes"}
 * )
 */
class ImportSource extends UpdatedEntity
{
    /**
     * @ORM\Column(type="string", length=255, nullable=false, options={"comment": "Name shown in the dashboard"})
     */
    protected string $name = '';

    /**
     * @ORM\Column(type="string", length=1024, nullable=false, options={"comment": "Articles must be under this URL"})
     */
    protected string $newsUrl = '';

    /**
     * @ORM\Column(type="string", length=1024, nullable=false, options={"comment": "Sitemap that lists the articles"})
     */
    protected string $sitemapUrl = '';

    /**
     * @ORM\Column(type="string", length=500, nullable=false, options={"comment": "CSS selector for the article title"})
     */
    protected string $titleSelector = '';

    /**
     * @ORM\Column(type="string", length=500, nullable=false, options={"comment": "CSS selector for the article content"})
     */
    protected string $articleSelector = '';

    /**
     * @ORM\Column(type="string", length=500, nullable=true, options={"comment": "CSS selector for the banner image"})
     */
    protected ?string $bannerSelector = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"comment": "Only articles changed in the last N days"})
     */
    protected ?int $sinceDays = null;

    /**
     * @ORM\Column(type="integer", nullable=true, options={"comment": "Most articles to import in one run"})
     */
    protected ?int $maxPerRun = null;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"comment": "Page the articles are created under"})
     */
    protected int $parentPageID = 0;

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"comment": "Page type handle"})
     */
    protected string $pageTypeHandle = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"comment": "Page template handle"})
     */
    protected string $pageTemplateHandle = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=false, options={"comment": "Area the article content goes into"})
     */
    protected string $areaName = '';

    /**
     * @ORM\Column(type="string", length=64, nullable=true, options={"comment": "Page attribute that receives the banner image"})
     */
    protected ?string $bannerAttribute = null;

    /**
     * @ORM\Column(type="text", nullable=true, options={"comment": "Article slugs to ignore, one per line, * is a wildcard"})
     */
    protected ?string $excludedSlugs = null;

    /**
     * @ORM\Column(type="integer", nullable=false, options={"comment": "Imported by the scheduled task", "default": 1})
     */
    protected int $enabled = 1;

    /**
     * Whether this source has been saved yet. A new one has no ID, and reading it would throw.
     */
    public function isPersisted(): bool
    {
        return (new \ReflectionProperty(BaseEntity::class, 'id'))->isInitialized($this);
    }

    /**
     * The settings the scraper reads. Throws if they are not valid, which the dashboard form checks before saving.
     *
     * @throws \ContentImporterKit\Scrape\InvalidSourceException
     */
    public function toSourceConfig(): SourceConfig
    {
        return SourceConfig::fromArray([
            'newsUrl' => $this->newsUrl,
            'sitemapUrl' => $this->sitemapUrl,
            'titleSelector' => $this->titleSelector,
            'articleSelector' => $this->articleSelector,
            'bannerSelector' => $this->bannerSelector,
            'sinceDays' => $this->sinceDays,
            'maxPerRun' => $this->maxPerRun,
            'excludedSlugs' => $this->excludedSlugs,
        ]);
    }

    /**
     * Where and how the articles of this source become pages.
     */
    public function toPublishTarget(): PublishTarget
    {
        return new PublishTarget(
            $this->parentPageID,
            $this->pageTypeHandle,
            $this->pageTemplateHandle,
            $this->areaName,
            ($this->bannerAttribute !== null && $this->bannerAttribute !== '') ? ['banner' => $this->bannerAttribute] : [],
        );
    }

    /**
     * Get the value of name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set the value of name
     *
     * @return self
     */
    public function setName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get the value of newsUrl
     */
    public function getNewsUrl(): string
    {
        return $this->newsUrl;
    }

    /**
     * Set the value of newsUrl
     *
     * @return self
     */
    public function setNewsUrl(string $newsUrl)
    {
        $this->newsUrl = $newsUrl;

        return $this;
    }

    /**
     * Get the value of sitemapUrl
     */
    public function getSitemapUrl(): string
    {
        return $this->sitemapUrl;
    }

    /**
     * Set the value of sitemapUrl
     *
     * @return self
     */
    public function setSitemapUrl(string $sitemapUrl)
    {
        $this->sitemapUrl = $sitemapUrl;

        return $this;
    }

    /**
     * Get the value of titleSelector
     */
    public function getTitleSelector(): string
    {
        return $this->titleSelector;
    }

    /**
     * Set the value of titleSelector
     *
     * @return self
     */
    public function setTitleSelector(string $titleSelector)
    {
        $this->titleSelector = $titleSelector;

        return $this;
    }

    /**
     * Get the value of articleSelector
     */
    public function getArticleSelector(): string
    {
        return $this->articleSelector;
    }

    /**
     * Set the value of articleSelector
     *
     * @return self
     */
    public function setArticleSelector(string $articleSelector)
    {
        $this->articleSelector = $articleSelector;

        return $this;
    }

    /**
     * Get the value of bannerSelector
     */
    public function getBannerSelector(): ?string
    {
        return $this->bannerSelector;
    }

    /**
     * Set the value of bannerSelector
     *
     * @return self
     */
    public function setBannerSelector(?string $bannerSelector)
    {
        $this->bannerSelector = $bannerSelector;

        return $this;
    }

    /**
     * Get the value of sinceDays
     */
    public function getSinceDays(): ?int
    {
        return $this->sinceDays;
    }

    /**
     * Set the value of sinceDays
     *
     * @return self
     */
    public function setSinceDays(?int $sinceDays)
    {
        $this->sinceDays = $sinceDays;

        return $this;
    }

    /**
     * Get the value of maxPerRun
     */
    public function getMaxPerRun(): ?int
    {
        return $this->maxPerRun;
    }

    /**
     * Set the value of maxPerRun
     *
     * @return self
     */
    public function setMaxPerRun(?int $maxPerRun)
    {
        $this->maxPerRun = $maxPerRun;

        return $this;
    }

    /**
     * Get the value of parentPageID
     */
    public function getParentPageID(): int
    {
        return $this->parentPageID;
    }

    /**
     * Set the value of parentPageID
     *
     * @return self
     */
    public function setParentPageID(int $parentPageID)
    {
        $this->parentPageID = $parentPageID;

        return $this;
    }

    /**
     * Get the value of pageTypeHandle
     */
    public function getPageTypeHandle(): string
    {
        return $this->pageTypeHandle;
    }

    /**
     * Set the value of pageTypeHandle
     *
     * @return self
     */
    public function setPageTypeHandle(string $pageTypeHandle)
    {
        $this->pageTypeHandle = $pageTypeHandle;

        return $this;
    }

    /**
     * Get the value of pageTemplateHandle
     */
    public function getPageTemplateHandle(): string
    {
        return $this->pageTemplateHandle;
    }

    /**
     * Set the value of pageTemplateHandle
     *
     * @return self
     */
    public function setPageTemplateHandle(string $pageTemplateHandle)
    {
        $this->pageTemplateHandle = $pageTemplateHandle;

        return $this;
    }

    /**
     * Get the value of areaName
     */
    public function getAreaName(): string
    {
        return $this->areaName;
    }

    /**
     * Set the value of areaName
     *
     * @return self
     */
    public function setAreaName(string $areaName)
    {
        $this->areaName = $areaName;

        return $this;
    }

    /**
     * Get the value of bannerAttribute
     */
    public function getBannerAttribute(): ?string
    {
        return $this->bannerAttribute;
    }

    /**
     * Set the value of bannerAttribute
     *
     * @return self
     */
    public function setBannerAttribute(?string $bannerAttribute)
    {
        $this->bannerAttribute = $bannerAttribute;

        return $this;
    }

    /**
     * Get the value of excludedSlugs
     */
    public function getExcludedSlugs(): ?string
    {
        return $this->excludedSlugs;
    }

    /**
     * Set the value of excludedSlugs
     *
     * @return self
     */
    public function setExcludedSlugs(?string $excludedSlugs)
    {
        $this->excludedSlugs = $excludedSlugs;

        return $this;
    }

    /**
     * Get the value of enabled
     */
    public function getEnabled(): int
    {
        return $this->enabled;
    }

    /**
     * Set the value of enabled
     *
     * @return self
     */
    public function setEnabled(int $enabled)
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Whether the scheduled task imports this source, as text for lists
     */
    public function getEnabledString(): string
    {
        return $this->enabled > 0 ? 'Yes' : 'No';
    }
}
