<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Import;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ContentImporterKit\Import\ArticleRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use ContentImporterKit\Import\ArticleFileParser;
use ContentImporterKit\Import\InvalidArticleException;

final class ArticleFileParserTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function article(array $overrides = []): array
    {
        return $overrides + [
            'urlSlug' => 'my-article',
            'title' => 'My Article',
            'description' => 'Short text',
            'published_date' => '2025-03-14T16:11:16+00:00',
            'meta_description' => 'Meta',
            'meta_keywords' => null,
            'attributes' => ['banner' => 'https://example.org/a.png'],
            'body_html' => [['block_type' => 'content', 'data' => '<p>Hi</p>']],
        ];
    }

    public function testValidScraperRecord(): void
    {
        $record = ArticleRecord::fromArray(self::article());

        self::assertSame('my-article', $record->urlSlug);
        self::assertSame('2025-03-14', $record->publishedAt->format('Y-m-d'));
        self::assertNull($record->metaKeywords);
        self::assertSame(['banner' => 'https://example.org/a.png'], $record->attributes);
        self::assertSame([['block_type' => 'content', 'data' => '<p>Hi</p>']], $record->bodyBlocks);
    }

    public function testSlugIsLowercased(): void
    {
        self::assertSame('my-article', ArticleRecord::fromArray(self::article(['urlSlug' => 'My-Article']))->urlSlug);
    }

    public function testNullAttributeIsDropped(): void
    {
        $record = ArticleRecord::fromArray(self::article(['attributes' => ['banner' => null, 'n' => 3]]));

        self::assertSame(['n' => '3'], $record->attributes);
    }

    public function testMissingOptionalFieldsDefault(): void
    {
        $record = ArticleRecord::fromArray(['urlSlug' => 'a', 'title' => 'A', 'published_date' => '2025-01-02']);

        self::assertSame('', $record->description);
        self::assertSame([], $record->attributes);
        self::assertSame([], $record->bodyBlocks);
    }

    public function testNaiveDatesUseTheGivenTimezone(): void
    {
        $record = ArticleRecord::fromArray(
            self::article(['published_date' => '2025-06-01 10:00:00']),
            new DateTimeZone('Europe/London'),
        );

        self::assertSame('+01:00', $record->publishedAt->format('P'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRecords(): iterable
    {
        yield 'not an object' => [['x']];
        yield 'missing slug' => [self::article(['urlSlug' => null])];
        yield 'slug traversal' => [self::article(['urlSlug' => '../../x'])];
        yield 'slug with slash' => [self::article(['urlSlug' => 'a/b'])];
        yield 'slug with space' => [self::article(['urlSlug' => 'a b'])];
        yield 'slug double hyphen' => [self::article(['urlSlug' => 'a--b'])];
        yield 'slug too long' => [self::article(['urlSlug' => str_repeat('a', 129)])];
        yield 'slug not a string' => [self::article(['urlSlug' => 12])];
        yield 'empty title' => [self::article(['title' => '  '])];
        yield 'title array' => [self::article(['title' => ['x']])];
        yield 'title control char' => [self::article(['title' => "a\x00b"])];
        yield 'relative date' => [self::article(['published_date' => 'tomorrow'])];
        yield 'impossible date' => [self::article(['published_date' => '2025-02-30'])];
        yield 'missing date' => [self::article(['published_date' => null])];
        yield 'attributes list' => [self::article(['attributes' => ['a', 'b']])];
        yield 'attribute bad key' => [self::article(['attributes' => ['<script>' => 'x']])];
        yield 'attribute nested' => [self::article(['attributes' => ['a' => ['b']]])];
        yield 'body not a list' => [self::article(['body_html' => ['block_type' => 'content']])];
        yield 'block not an object' => [self::article(['body_html' => ['text']])];
        yield 'block bad type' => [self::article(['body_html' => [['block_type' => 'Content!', 'data' => 'x']]])];
        yield 'block missing data' => [self::article(['body_html' => [['block_type' => 'content']]])];
    }

    /**
     * @param mixed $raw
     */
    #[DataProvider('invalidRecords')]
    public function testInvalidRecordsAreRejected(mixed $raw): void
    {
        $this->expectException(InvalidArticleException::class);

        ArticleRecord::fromArray($raw);
    }

    public function testBadRecordsAreReportedAndSkipped(): void
    {
        $json = json_encode([
            self::article(['urlSlug' => 'one']),
            self::article(['urlSlug' => '../bad']),
            self::article(['urlSlug' => 'two', 'attributes' => ['author' => 'Sam']]),
            self::article(['urlSlug' => 'one']),
        ], JSON_THROW_ON_ERROR);

        $result = (new ArticleFileParser())->parse($json);

        self::assertSame(['one', 'two'], array_map(static fn(ArticleRecord $r): string => $r->urlSlug, $result->records));
        self::assertSame([1, 3], array_keys($result->errors));
        self::assertSame(['banner', 'author'], $result->attributeKeys());
    }

    public function testBomIsIgnored(): void
    {
        $json = "\xEF\xBB\xBF" . json_encode([self::article()], JSON_THROW_ON_ERROR);

        self::assertCount(1, (new ArticleFileParser())->parse($json)->records);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableFiles(): iterable
    {
        yield 'not json' => ['{nope'];
        yield 'empty' => [''];
        yield 'scalar' => ['"text"'];
        yield 'object, not a list' => ['{"urlSlug": "a"}'];
        yield 'too deep' => [str_repeat('[', 40) . str_repeat(']', 40)];
    }

    #[DataProvider('unusableFiles')]
    public function testUnusableFilesThrow(string $json): void
    {
        $this->expectException(InvalidArticleException::class);

        (new ArticleFileParser())->parse($json);
    }

    public function testTooManyRecordsThrow(): void
    {
        $json = '[' . implode(',', array_fill(0, ArticleFileParser::MAX_RECORDS + 1, '{}')) . ']';

        $this->expectException(InvalidArticleException::class);

        (new ArticleFileParser())->parse($json);
    }

    public function testErrorMessagesDoNotEchoValues(): void
    {
        try {
            ArticleRecord::fromArray(self::article(['urlSlug' => '<script>alert(1)</script>']));
            self::fail('Expected an InvalidArticleException.');
        } catch (InvalidArticleException $e) {
            self::assertStringNotContainsString('script', $e->getMessage());
        }
    }
}
