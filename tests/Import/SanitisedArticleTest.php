<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Import;

use PHPUnit\Framework\TestCase;
use ContentImporterKit\Import\Sanitiser;
use ContentImporterKit\Import\ArticleRecord;

final class SanitisedArticleTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private static function record(array $overrides = []): ArticleRecord
    {
        return ArticleRecord::fromArray($overrides + [
            'urlSlug' => 'my-article',
            'title' => 'My Article',
            'description' => 'Short',
            'published_date' => '2025-03-14T16:11:16+00:00',
            'attributes' => ['banner' => 'https://example.org/a.png', 'author' => 'Sam'],
            'body_html' => [['block_type' => 'content', 'data' => '<p>Hello <script>alert(1)</script></p>']],
        ]);
    }

    public function testBodyBlocksAreSanitised(): void
    {
        $article = (new Sanitiser())->sanitiseRecord(self::record());

        self::assertSame([['block_type' => 'content', 'data' => '<p>Hello </p>']], $article->record->bodyBlocks);
    }

    public function testOtherBlockTypesAndEmptyBlocksAreDropped(): void
    {
        $article = (new Sanitiser())->sanitiseRecord(self::record(['body_html' => [
            ['block_type' => 'gallery', 'data' => '<p>not html we can vouch for</p>'],
            ['block_type' => 'content', 'data' => '<script>alert(1)</script>'],
            ['block_type' => 'content', 'data' => '<p>kept</p>'],
        ]]));

        self::assertSame([['block_type' => 'content', 'data' => '<p>kept</p>']], $article->record->bodyBlocks);
    }

    public function testOtherFieldsAreCarriedOver(): void
    {
        $article = (new Sanitiser())->sanitiseRecord(self::record());

        self::assertSame('my-article', $article->record->urlSlug);
        self::assertSame('My Article', $article->record->title);
        self::assertSame(['banner' => 'https://example.org/a.png', 'author' => 'Sam'], $article->record->attributes);
    }

    public function testTheHashIsStableForTheSameContent(): void
    {
        $sanitiser = new Sanitiser();

        self::assertSame(
            $sanitiser->sanitiseRecord(self::record())->contentHash(),
            $sanitiser->sanitiseRecord(self::record())->contentHash(),
        );
    }

    public function testTheHashIgnoresAttributeOrder(): void
    {
        $sanitiser = new Sanitiser();
        $a = $sanitiser->sanitiseRecord(self::record(['attributes' => ['banner' => 'x', 'author' => 'y']]));
        $b = $sanitiser->sanitiseRecord(self::record(['attributes' => ['author' => 'y', 'banner' => 'x']]));

        self::assertSame($a->contentHash(), $b->contentHash());
    }

    public function testTheHashIgnoresWhatSanitisingRemoves(): void
    {
        $sanitiser = new Sanitiser();
        $a = $sanitiser->sanitiseRecord(self::record(['body_html' => [['block_type' => 'content', 'data' => '<p>Same</p>']]]));
        $b = $sanitiser->sanitiseRecord(self::record(['body_html' => [['block_type' => 'content', 'data' => '<p class="x">Same</p><script>1</script>']]]));

        self::assertSame($a->contentHash(), $b->contentHash());
    }

    public function testTheHashChangesWhenAnythingOnThePageWouldChange(): void
    {
        $sanitiser = new Sanitiser();
        $base = $sanitiser->sanitiseRecord(self::record())->contentHash();

        $changes = [
            'title' => ['title' => 'Other'],
            'description' => ['description' => 'Other'],
            'date' => ['published_date' => '2025-03-15'],
            'meta description' => ['meta_description' => 'Other'],
            'meta keywords' => ['meta_keywords' => 'a, b'],
            'attributes' => ['attributes' => ['banner' => 'https://example.org/b.png', 'author' => 'Sam']],
            'body' => ['body_html' => [['block_type' => 'content', 'data' => '<p>Different</p>']]],
        ];

        foreach ($changes as $name => $override) {
            self::assertNotSame($base, $sanitiser->sanitiseRecord(self::record($override))->contentHash(), $name);
        }
    }

    public function testTheHashHasTheFormatOfASha256(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (new Sanitiser())->sanitiseRecord(self::record())->contentHash());
    }
}
