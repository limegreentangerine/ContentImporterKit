<?php

namespace ContentImporterKit\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use ClassKit\Entity\Core\UpdatedEntity;

/**
 * What the importer remembers about one article of one source: where it came from, the page it became, and how the
 * last attempt went. Doubles as the import history shown in the dashboard.
 *
 * @ORM\Entity
 * @ORM\Table(
 *      name="ciItems",
 *      uniqueConstraints={@ORM\UniqueConstraint(name="source_slug", columns={"source_id", "slug"})},
 *      indexes={@ORM\Index(name="status", columns={"status"})},
 *      options={"comment": "Articles the content importer has seen, and what became of them"}
 * )
 */
class ImportedItem extends UpdatedEntity
{
    /**
     * @ORM\ManyToOne(targetEntity="ContentImporterKit\Entity\ImportSource")
     * @ORM\JoinColumn(name="source_id", referencedColumnName="id", nullable=false, onDelete="CASCADE")
     */
    protected ImportSource $source;

    /**
     * @ORM\Column(type="string", length=128, nullable=false, options={"comment": "Article slug"})
     */
    protected string $slug = '';

    /**
     * @ORM\Column(type="string", length=1024, nullable=false, options={"comment": "Address the article was fetched from"})
     */
    protected string $url = '';

    /**
     * @ORM\Column(type="integer", nullable=true, options={"comment": "Page the article became"})
     */
    protected ?int $pageID = null;

    /**
     * @ORM\Column(type="string", length=255, nullable=true, options={"comment": "ETag of the last successful fetch"})
     */
    protected ?string $etag = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true, options={"comment": "Last-Modified of the last successful fetch"})
     */
    protected ?string $lastModified = null;

    /**
     * @ORM\Column(type="datetime_immutable", nullable=true, options={"comment": "lastmod the sitemap gave at the last import"})
     */
    protected ?DateTimeImmutable $remoteLastmod = null;

    /**
     * @ORM\Column(type="string", length=64, nullable=true, options={"comment": "Hash of what was put on the page"})
     */
    protected ?string $contentHash = null;

    /**
     * @ORM\Column(type="string", length=16, nullable=false, options={"comment": "new, imported, updated, unchanged or failed", "default": "new"})
     */
    protected string $status = 'new';

    /**
     * @ORM\Column(type="string", length=500, nullable=true, options={"comment": "Why the last attempt failed"})
     */
    protected ?string $error = null;

    public function __construct(ImportSource $source, string $slug)
    {
        parent::__construct();

        $this->source = $source;
        $this->slug = $slug;
    }

    /**
     * Get the value of source
     */
    public function getSource(): ImportSource
    {
        return $this->source;
    }

    /**
     * Set the value of source
     *
     * @return self
     */
    public function setSource(ImportSource $source)
    {
        $this->source = $source;

        return $this;
    }

    /**
     * Get the value of slug
     */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Set the value of slug
     *
     * @return self
     */
    public function setSlug(string $slug)
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * Get the value of url
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Set the value of url
     *
     * @return self
     */
    public function setUrl(string $url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get the value of pageID
     */
    public function getPageID(): ?int
    {
        return $this->pageID;
    }

    /**
     * Set the value of pageID
     *
     * @return self
     */
    public function setPageID(?int $pageID)
    {
        $this->pageID = $pageID;

        return $this;
    }

    /**
     * Get the value of etag
     */
    public function getEtag(): ?string
    {
        return $this->etag;
    }

    /**
     * Set the value of etag
     *
     * @return self
     */
    public function setEtag(?string $etag)
    {
        $this->etag = $etag;

        return $this;
    }

    /**
     * Get the value of lastModified
     */
    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }

    /**
     * Set the value of lastModified
     *
     * @return self
     */
    public function setLastModified(?string $lastModified)
    {
        $this->lastModified = $lastModified;

        return $this;
    }

    /**
     * Get the value of remoteLastmod
     */
    public function getRemoteLastmod(): ?DateTimeImmutable
    {
        return $this->remoteLastmod;
    }

    /**
     * Set the value of remoteLastmod
     *
     * @return self
     */
    public function setRemoteLastmod(?DateTimeImmutable $remoteLastmod)
    {
        $this->remoteLastmod = $remoteLastmod;

        return $this;
    }

    /**
     * Get the value of contentHash
     */
    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    /**
     * Set the value of contentHash
     *
     * @return self
     */
    public function setContentHash(?string $contentHash)
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    /**
     * Get the value of status
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Set the value of status
     *
     * @return self
     */
    public function setStatus(string $status)
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get the value of error
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Set the value of error
     *
     * @return self
     */
    public function setError(?string $error)
    {
        $this->error = $error;

        return $this;
    }
}
