<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Import;

use PHPUnit\Framework\TestCase;
use ContentImporterKit\Import\Sanitiser;
use PHPUnit\Framework\Attributes\DataProvider;

final class SanitiserTest extends TestCase
{
    private static ?Sanitiser $sanitiser = null;

    private static function sanitiser(): Sanitiser
    {
        return self::$sanitiser ??= new Sanitiser();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function attacks(): iterable
    {
        yield 'script tag' => ['<p>x</p><script>alert(1)</script>'];
        yield 'script with split tag' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'];
        yield 'img onerror' => ['<img src=x onerror=alert(1)>'];
        yield 'img onerror quoted' => ['<img src="x" onerror="alert(1)">'];
        yield 'img javascript src' => ['<img src="javascript:alert(1)">'];
        yield 'img data src' => ['<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">'];
        yield 'a javascript' => ['<a href="javascript:alert(1)">x</a>'];
        yield 'a javascript mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>'];
        yield 'a javascript leading space' => ['<a href="  javascript:alert(1)">x</a>'];
        yield 'a javascript entity' => ['<a href="&#106;avascript:alert(1)">x</a>'];
        yield 'a javascript tab' => ["<a href=\"java\tscript:alert(1)\">x</a>"];
        yield 'a vbscript' => ['<a href="vbscript:msgbox(1)">x</a>'];
        yield 'a data html' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'];
        yield 'a onclick' => ['<a href="https://example.org/" onclick="alert(1)">x</a>'];
        yield 'iframe' => ['<iframe src="https://evil.example/"></iframe>'];
        yield 'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'];
        yield 'object' => ['<object data="https://evil.example/x.swf"></object>'];
        yield 'embed' => ['<embed src="https://evil.example/x.swf">'];
        yield 'svg onload' => ['<svg onload=alert(1)></svg>'];
        yield 'svg script' => ['<svg><script>alert(1)</script></svg>'];
        yield 'math' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'];
        yield 'form' => ['<form action="https://evil.example/"><input name="x"></form>'];
        yield 'button formaction' => ['<button formaction="javascript:alert(1)">x</button>'];
        yield 'base' => ['<base href="https://evil.example/">'];
        yield 'link stylesheet' => ['<link rel="stylesheet" href="https://evil.example/x.css">'];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example/">'];
        yield 'style tag' => ['<style>body{background:url(javascript:alert(1))}</style>'];
        yield 'style attribute' => ['<p style="background:url(javascript:alert(1))">x</p>'];
        yield 'div mouseover' => ['<div onmouseover="alert(1)">x</div>'];
        yield 'body onload' => ['<body onload=alert(1)>'];
        yield 'noscript mutation' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'];
        yield 'comment trick' => ['<!--><img src=x onerror=alert(1)>-->'];
        yield 'unclosed tag' => ['<img src="x" onerror="alert(1)"'];
        yield 'null byte' => ["<scr\x00ipt>alert(1)</scr\x00ipt>"];
    }

    #[DataProvider('attacks')]
    public function testAttackPayloadsAreNeutralised(string $payload): void
    {
        $clean = self::sanitiser()->sanitise($payload);

        self::assertDoesNotMatchRegularExpression(
            '/<\s*\/?\s*(script|iframe|object|embed|svg|math|form|input|button|base|link|meta|style|body|noscript)\b/i',
            $clean,
        );
        self::assertDoesNotMatchRegularExpression('/\bon[a-z]+\s*=/i', $clean);
        self::assertDoesNotMatchRegularExpression('/\bstyle\s*=/i', $clean);
        self::assertDoesNotMatchRegularExpression('/(javascript|vbscript|data)\s*:/i', $clean);
    }

    public function testNormalArticleHtmlSurvives(): void
    {
        $html = '<h2>Teitl</h2><p>Mae <strong>Adferiad</strong> yn <em>falch</em> o\'r '
            . '<a href="https://adferiad.org/cym/" title="Adferiad">wefan</a>.</p>'
            . '<ul><li>Un</li><li>Dau</li></ul>'
            . '<p><img src="https://adferiad.org/a.png" alt="Logo" width="10" height="10"></p>'
            . '<table><thead><tr><th colspan="2">H</th></tr></thead><tbody><tr><td>1</td><td>2</td></tr></tbody></table>';

        $clean = self::sanitiser()->sanitise($html);

        self::assertStringContainsString('<h2>Teitl</h2>', $clean);
        self::assertStringContainsString('<strong>Adferiad</strong>', $clean);
        self::assertStringContainsString('href="https://adferiad.org/cym/"', $clean);
        self::assertStringContainsString('<li>Dau</li>', $clean);
        self::assertStringContainsString('src="https://adferiad.org/a.png"', $clean);
        self::assertStringContainsString('alt="Logo"', $clean);
        self::assertStringContainsString('colspan="2"', $clean);
        self::assertStringContainsString('<td>2</td>', $clean);
    }

    public function testFigureAndCaptionAreKept(): void
    {
        $clean = self::sanitiser()->sanitise(
            '<figure><img src="https://example.org/a.png" alt="A"><figcaption>Caption</figcaption></figure>',
        );

        self::assertStringContainsString('<figure>', $clean);
        self::assertStringContainsString('<figcaption>Caption</figcaption>', $clean);
    }

    public function testWelshCharactersAreKept(): void
    {
        self::assertStringContainsString('Ŵyl ŷ â ê î ô û', self::sanitiser()->sanitise('<p>Ŵyl ŷ â ê î ô û</p>'));
    }

    public function testMailtoLinkIsKept(): void
    {
        self::assertStringContainsString(
            'href="mailto:hello@example.org"',
            self::sanitiser()->sanitise('<a href="mailto:hello@example.org">Email</a>'),
        );
    }

    public function testH1IsDroppedButItsTextIsKept(): void
    {
        $clean = self::sanitiser()->sanitise('<h1>Page title</h1><p>Body</p>');

        self::assertStringNotContainsString('<h1', $clean);
        self::assertStringContainsString('Page title', $clean);
    }

    public function testClassIdAndStyleAreRemoved(): void
    {
        $clean = self::sanitiser()->sanitise('<p class="a" id="b" style="color:red">x</p>');

        self::assertSame('<p>x</p>', $clean);
    }

    public function testScriptContentIsRemovedNotJustTheTag(): void
    {
        self::assertStringNotContainsString('alert', self::sanitiser()->sanitise('<p>a</p><script>alert(1)</script>'));
    }

    public function testEmptyElementsAreRemoved(): void
    {
        self::assertSame('<p>x</p>', self::sanitiser()->sanitise('<p></p><p>x</p><p> </p>'));
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', self::sanitiser()->sanitise(''));
    }

    public function testCacheDirectoryIsCreated(): void
    {
        $path = sys_get_temp_dir() . '/cik-purifier-' . bin2hex(random_bytes(4));

        try {
            $clean = (new Sanitiser($path))->sanitise('<p>x</p>');

            self::assertSame('<p>x</p>', $clean);
            self::assertDirectoryExists($path);
        } finally {
            foreach (glob($path . '/*/*') ?: [] as $file) {
                @unlink($file);
            }
            foreach (glob($path . '/*') ?: [] as $dir) {
                @rmdir($dir);
            }
            @rmdir($path);
        }
    }
}
