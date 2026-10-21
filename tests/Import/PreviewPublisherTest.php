<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Import;

use PHPUnit\Framework\TestCase;
use ContentImporterKit\Import\Sanitiser;
use ContentImporterKit\Import\ArticleRecord;
use ContentImporterKit\Import\PublishTarget;
use ContentImporterKit\Import\PreviewPublisher;
use ContentImporterKit\Import\SanitisedArticle;

final class PreviewPublisherTest extends TestCase
{
    private static function article(): SanitisedArticle
    {
        return (new Sanitiser())->sanitiseRecord(ArticleRecord::fromArray([
            'urlSlug' => 'hello',
            'title' => 'Hello',
            'published_date' => '2025-03-14 10:00:00',
            'body_html' => [['block_type' => 'content', 'data' => '<p>Hi</p>']],
        ]));
    }

    public function testAnArticleWithoutAPageWouldBeCreated(): void
    {
        $result = (new PreviewPublisher())->publish(self::article(), new PublishTarget(1, 'page', 'full', 'Main'), null);

        self::assertTrue($result->created);
        self::assertSame(0, $result->pageID, 'no page exists, so there is no ID to report');
    }

    public function testAnArticleWithAPageWouldBeUpdatedInPlace(): void
    {
        $result = (new PreviewPublisher())->publish(self::article(), new PublishTarget(1, 'page', 'full', 'Main'), 42);

        self::assertFalse($result->created);
        self::assertSame(42, $result->pageID);
    }
}
