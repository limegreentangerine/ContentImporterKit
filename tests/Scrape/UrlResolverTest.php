<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Scrape;

use PHPUnit\Framework\TestCase;
use ContentImporterKit\Scrape\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;

final class UrlResolverTest extends TestCase
{
    private const BASE = 'https://adferiad.org/cym/news/my-post/?page=2';

    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function references(): iterable
    {
        yield 'absolute https' => [self::BASE, 'https://cdn.example.org/a.png', 'https://cdn.example.org/a.png'];
        yield 'absolute http' => [self::BASE, 'http://example.org/a.png', 'http://example.org/a.png'];
        yield 'protocol relative' => [self::BASE, '//cdn.example.org/a.png', 'https://cdn.example.org/a.png'];
        yield 'protocol relative follows base scheme' => ['http://example.org/', '//cdn.example.org/a.png', 'http://cdn.example.org/a.png'];
        yield 'root relative' => [self::BASE, '/wp-content/uploads/a.png', 'https://adferiad.org/wp-content/uploads/a.png'];
        yield 'relative to a directory' => [self::BASE, 'a.png', 'https://adferiad.org/cym/news/my-post/a.png'];
        yield 'relative to a file' => ['https://example.org/dir/page.html', 'a.png', 'https://example.org/dir/a.png'];
        yield 'dot segment' => [self::BASE, './a.png', 'https://adferiad.org/cym/news/my-post/a.png'];
        yield 'parent segment' => [self::BASE, '../a.png', 'https://adferiad.org/cym/news/a.png'];
        yield 'two parent segments' => [self::BASE, '../../a.png', 'https://adferiad.org/cym/a.png'];
        yield 'parent past the root stays at the root' => ['https://example.org/a/', '../../../x.png', 'https://example.org/x.png'];
        yield 'dots inside a root path' => [self::BASE, '/a/./b/../c.png', 'https://adferiad.org/a/c.png'];
        yield 'query kept' => [self::BASE, 'a.png?w=100', 'https://adferiad.org/cym/news/my-post/a.png?w=100'];
        yield 'query only' => [self::BASE, '?page=3', 'https://adferiad.org/cym/news/my-post/?page=3'];
        yield 'fragment only' => [self::BASE, '#section', 'https://adferiad.org/cym/news/my-post/?page=2#section'];
        yield 'fragment kept' => [self::BASE, 'a.html#top', 'https://adferiad.org/cym/news/my-post/a.html#top'];
        yield 'base port kept' => ['http://localhost:8080/a/b', '/x.png', 'http://localhost:8080/x.png'];
        yield 'base without a path' => ['https://example.org', 'a.png', 'https://example.org/a.png'];
        yield 'surrounding whitespace' => [self::BASE, '  /a.png  ', 'https://adferiad.org/a.png'];

        yield 'empty' => [self::BASE, '', null];
        yield 'whitespace only' => [self::BASE, '   ', null];
        yield 'javascript' => [self::BASE, 'javascript:alert(1)', null];
        yield 'javascript mixed case' => [self::BASE, 'JaVaScRiPt:alert(1)', null];
        yield 'data' => [self::BASE, 'data:image/png;base64,AAAA', null];
        yield 'mailto' => [self::BASE, 'mailto:hello@example.org', null];
        yield 'tel' => [self::BASE, 'tel:+441234', null];
        yield 'ftp' => [self::BASE, 'ftp://example.org/a.png', null];
        yield 'file' => [self::BASE, 'file:///etc/passwd', null];
        yield 'control character' => [self::BASE, "/a.png\r\nHost: evil", null];
        yield 'embedded space' => [self::BASE, '/a b.png', null];
        yield 'http without a host' => [self::BASE, 'http:///a.png', null];
        yield 'protocol relative without a host' => [self::BASE, '///a.png', null];
        yield 'base is not http' => ['ftp://example.org/', 'a.png', null];
        yield 'base is not a url' => ['not a url', 'a.png', null];
    }

    #[DataProvider('references')]
    public function testResolve(string $base, string $reference, ?string $expected): void
    {
        self::assertSame($expected, (new UrlResolver())->resolve($base, $reference));
    }
}
