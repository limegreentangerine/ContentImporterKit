<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Import;

use PHPUnit\Framework\TestCase;
use ContentImporterKit\Import\AttributeAction;
use PHPUnit\Framework\Attributes\DataProvider;
use ContentImporterKit\Import\AttributeValueResolver;

final class AttributeValueResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, AttributeAction}>
     */
    public static function cases(): iterable
    {
        $png = 'https://example.org/a/logo.png';

        yield 'plain text on text' => ['Hello', 'text', AttributeAction::StoreText];
        yield 'plain text on a file attribute' => ['42', 'image_file', AttributeAction::StoreText];
        yield 'plain text on a select' => ['Red', 'select', AttributeAction::StoreText];
        yield 'empty-looking text' => ['not a url at all', 'textarea', AttributeAction::StoreText];

        yield 'png on a file attribute' => [$png, 'image_file', AttributeAction::DownloadImage];
        yield 'jpg uppercase' => ['https://example.org/A.JPG', 'image_file', AttributeAction::DownloadImage];
        yield 'jpeg with query' => ['https://example.org/a.jpeg?w=100', 'image_file', AttributeAction::DownloadImage];
        yield 'http png' => ['http://example.org/a.png', 'image_file', AttributeAction::DownloadImage];

        yield 'png on a text attribute' => [$png, 'text', AttributeAction::StoreText];
        yield 'png on a url attribute' => [$png, 'url', AttributeAction::StoreText];

        yield 'page url on text' => ['https://example.org/original-post', 'text', AttributeAction::StoreText];
        yield 'page url on textarea' => ['https://example.org/original-post', 'textarea', AttributeAction::StoreText];
        yield 'page url on url' => ['https://example.org/original-post', 'url', AttributeAction::StoreText];
        yield 'page url on a file attribute' => ['https://example.org/original-post', 'image_file', AttributeAction::Skip];
        yield 'page url on a select' => ['https://example.org/original-post', 'select', AttributeAction::Skip];
        yield 'page url on a number' => ['https://example.org/original-post', 'number', AttributeAction::Skip];
        yield 'gif is not downloaded' => ['https://example.org/a.gif', 'image_file', AttributeAction::Skip];
        yield 'gif on text' => ['https://example.org/a.gif', 'text', AttributeAction::StoreText];

        yield 'javascript url on text' => ['javascript://example.org/%0aalert(1)', 'text', AttributeAction::Skip];
        yield 'javascript url on url' => ['javascript://example.org/%0aalert(1)', 'url', AttributeAction::Skip];
        yield 'ftp url' => ['ftp://example.org/file.png', 'image_file', AttributeAction::Skip];
        yield 'mailto' => ['mailto:hello@example.org', 'text', AttributeAction::Skip];
        yield 'file url' => ['file:///etc/passwd', 'text', AttributeAction::Skip];
        yield 'data url' => ['data:text/html;base64,PHNjcmlwdD4=', 'text', AttributeAction::Skip];
        yield 'javascript without slashes' => ['javascript:alert(1)', 'text', AttributeAction::Skip];
        yield 'javascript mixed case with tab' => ["JaVa\tScRiPt:alert(1)", 'url', AttributeAction::Skip];
        yield 'javascript with leading space' => ['  javascript:alert(1)', 'textarea', AttributeAction::Skip];
        yield 'vbscript' => ['vbscript:msgbox(1)', 'text', AttributeAction::Skip];
        yield 'ordinary text with a colon' => ['Note: see below', 'text', AttributeAction::StoreText];
        yield 'text mentioning data' => ['data: 42 items', 'text', AttributeAction::StoreText];
    }

    #[DataProvider('cases')]
    public function testDecide(string $value, string $typeHandle, AttributeAction $expected): void
    {
        self::assertSame($expected, (new AttributeValueResolver())->decide($value, $typeHandle));
    }
}
