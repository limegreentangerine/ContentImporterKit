<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use DOMXPath;
use DOMDocument;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ContentImporterKit\Scrape\SelectorTranslator;
use ContentImporterKit\Scrape\InvalidSelectorException;

final class SelectorTranslatorTest extends TestCase
{
    private const HTML = <<<'HTML'
        <html><body>
          <h1 id="title">Page title</h1>
          <div class="page_banner_wrap"><div class="image_container"><div class="image_wrap"><img src="/b.png"></div></div></div>
          <div class="full_width single_post extra">
            <div class="two_thirds"><p class="lead intro">Lead</p><p>Second</p><span data-kind="note">N</span></div>
            <div class="one_third"><p>Sidebar</p></div>
          </div>
          <div class="full_width other"><div class="two_thirds"><p>Not the post</p></div></div>
          <a href="https://example.org/x">x</a><a>no href</a>
          <p data-quote="it's">quote</p>
        </body></html>
        HTML;

    private static function countMatches(string $css): int
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8">' . self::HTML);
        $xpath = (new SelectorTranslator())->toXPath($css);

        return (int) (new DOMXPath($doc))->query($xpath)->length;
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function selectorsAndMatchCounts(): iterable
    {
        yield 'tag' => ['h1', 1];
        yield 'any tag' => ['*', 20];
        yield 'class' => ['.lead', 1];
        yield 'two classes on one element' => ['.full_width.single_post', 1];
        yield 'class among several' => ['.extra', 1];
        yield 'id' => ['#title', 1];
        yield 'tag with id' => ['h1#title', 1];
        yield 'tag with class' => ['p.lead', 1];
        yield 'descendant' => ['.single_post .two_thirds', 1];
        yield 'real adferiad article selector' => ['.full_width.single_post .two_thirds', 1];
        yield 'real adferiad banner selector' => ['.page_banner_wrap .image_container .image_wrap', 1];
        yield 'child combinator' => ['.two_thirds > p', 3];
        yield 'child does not skip levels' => ['.single_post > p', 0];
        yield 'child with spaces' => ['.two_thirds>p', 3];
        yield 'group' => ['h1, .lead', 2];
        yield 'group without space' => ['h1,.lead', 2];
        yield 'attribute present' => ['a[href]', 1];
        yield 'attribute equals bare' => ['span[data-kind=note]', 1];
        yield 'attribute equals double quoted' => ['span[data-kind="note"]', 1];
        yield 'attribute equals single quoted' => ["span[data-kind='note']", 1];
        yield 'attribute value with apostrophe' => ['p[data-quote="it\'s"]', 1];
        yield 'class does not match a longer name' => ['.lea', 0];
        yield 'no match' => ['.nope', 0];
        yield 'surrounding whitespace' => ['  h1  ', 1];
    }

    #[DataProvider('selectorsAndMatchCounts')]
    public function testSelectorMatchesTheRightElements(string $css, int $expected): void
    {
        self::assertSame($expected, self::countMatches($css));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupported(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'too long' => [str_repeat('a', 501)];
        yield 'pseudo class' => ['p:first-child'];
        yield 'pseudo element' => ['p::before'];
        yield 'adjacent sibling' => ['h1 + p'];
        yield 'general sibling' => ['h1 ~ p'];
        yield 'attribute prefix operator' => ['a[href^=http]'];
        yield 'attribute contains operator' => ['a[href*=example]'];
        yield 'trailing combinator' => ['div >'];
        yield 'leading combinator' => ['> div'];
        yield 'empty group' => ['h1,,p'];
        yield 'trailing comma' => ['h1,'];
        yield 'bad class' => ['.'];
        yield 'bad id' => ['#1abc'];
        yield 'unclosed attribute' => ['a[href'];
        yield 'xpath injection attempt' => ["a[href='x'] or 1=1"];
        yield 'xpath axis attempt' => ['//div'];
        yield 'function attempt' => ['div)(script'];
        yield 'quote in class name' => ['.a"b'];
    }

    #[DataProvider('unsupported')]
    public function testUnsupportedSyntaxIsRejected(string $css): void
    {
        $this->expectException(InvalidSelectorException::class);

        (new SelectorTranslator())->toXPath($css);
    }

    public function testValuesAreQuotedAsXPathLiterals(): void
    {
        $xpath = (new SelectorTranslator())->toXPath('p[data-quote="it\'s"]');

        self::assertStringContainsString('"it\'s"', $xpath);
    }
}
