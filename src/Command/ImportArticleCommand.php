<?php

namespace ContentImporterKit\Command;

use Concrete\Core\Foundation\Command\Command;

/**
 * Import one article of one source. One of these is queued per article, so a failure only affects its own article and
 * a run can be stopped and resumed. It only holds plain values, so it can be serialised onto the queue.
 */
class ImportArticleCommand extends Command
{
    /**
     * Not constructor-promoted and with defaults, so a message queued before languages existed still unserialises.
     */
    protected ?string $hreflang = null;

    /** @var list<string> */
    protected array $alternates = [];

    /**
     * @param list<string> $alternates addresses of the article's translations
     */
    public function __construct(
        protected int $sourceID,
        protected string $url,
        protected string $slug,
        protected ?string $lastModified,
        ?string $hreflang = null,
        array $alternates = [],
    ) {
        $this->hreflang = $hreflang;
        $this->alternates = $alternates;
    }

    /**
     * The article's language, or null for the source's own.
     */
    public function getHreflang(): ?string
    {
        return $this->hreflang;
    }

    /**
     * @return list<string>
     */
    public function getAlternates(): array
    {
        return $this->alternates;
    }

    public function getSourceID(): int
    {
        return $this->sourceID;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * The sitemap's lastmod for the article, as an ISO 8601 string, or null when it has none.
     */
    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }
}
