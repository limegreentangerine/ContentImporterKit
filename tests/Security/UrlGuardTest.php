<?php

declare(strict_types=1);

namespace ContentImporterKit\Tests\Security;

use Closure;
use PHPUnit\Framework\TestCase;
use ContentImporterKit\Security\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use ContentImporterKit\Security\UnsafeUrlException;

final class UrlGuardTest extends TestCase
{
    /**
     * @param array<string, list<string>> $dns
     */
    private function guard(array $dns = []): UrlGuard
    {
        return new UrlGuard([80, 443], Closure::fromCallable(static fn(string $host): array => $dns[$host] ?? []));
    }

    public function testPublicHostIsAcceptedAndPinned(): void
    {
        $target = $this->guard(['example.org' => ['93.184.216.34']])->assertPublic('https://Example.org/img/a.jpg');

        self::assertSame('https', $target->scheme);
        self::assertSame('example.org', $target->host);
        self::assertSame(443, $target->port);
        self::assertSame('93.184.216.34', $target->ip);
        self::assertSame('example.org:443:93.184.216.34', $target->curlResolveEntry());
    }

    public function testIpv6PinIsBracketed(): void
    {
        $target = $this->guard(['example.org' => ['2606:4700::1111']])->assertPublic('http://example.org/');

        self::assertSame('example.org:80:[2606:4700::1111]', $target->curlResolveEntry());
    }

    public function testPublicIpLiteralNeedsNoDns(): void
    {
        self::assertSame('8.8.8.8', $this->guard()->assertPublic('https://8.8.8.8/')->ip);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'not a url' => ['not a url'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'gopher scheme' => ['gopher://example.org/'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'protocol relative' => ['//example.org/a.jpg'];
        yield 'credentials' => ['https://example.org@127.0.0.1/'];
        yield 'credentials with password' => ['https://user:pass@example.org/'];
        yield 'localhost' => ['http://localhost/'];
        yield 'localhost trailing dot' => ['http://localhost./'];
        yield 'localhost subdomain' => ['http://app.localhost/'];
        yield 'zero host' => ['http://0/'];
        yield 'loopback' => ['http://127.0.0.1/'];
        yield 'loopback ipv6' => ['http://[::1]/'];
        yield 'ipv4 mapped loopback' => ['http://[::ffff:127.0.0.1]/'];
        yield 'rfc1918 10' => ['http://10.0.0.5/'];
        yield 'rfc1918 172' => ['http://172.16.0.1/'];
        yield 'rfc1918 192' => ['http://192.168.1.1/'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'carrier grade nat' => ['http://100.64.0.1/'];
        yield 'unspecified' => ['http://0.0.0.0/'];
        yield 'unique local ipv6' => ['http://[fc00::1]/'];
        yield 'link local ipv6' => ['http://[fe80::1]/'];
        yield 'bad port' => ['http://8.8.8.8:6379/'];
        yield 'control character' => ["http://8.8.8.8/\r\nHost: evil"];
        yield 'space' => ['http://8.8.8.8/ x'];
        yield 'unresolvable host' => ['https://does-not-resolve.invalid/'];
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlsAreRejected(string $url): void
    {
        $this->expectException(UnsafeUrlException::class);

        $this->guard()->assertPublic($url);
    }

    public function testHostResolvingToAnyPrivateAddressIsRejected(): void
    {
        $guard = $this->guard(['mixed.example.org' => ['93.184.216.34', '10.0.0.5']]);

        $this->expectException(UnsafeUrlException::class);

        $guard->assertPublic('https://mixed.example.org/');
    }

    public function testHostResolvingToMappedLoopbackIsRejected(): void
    {
        $guard = $this->guard(['sneaky.example.org' => ['::ffff:7f00:1']]);

        $this->expectException(UnsafeUrlException::class);

        $guard->assertPublic('https://sneaky.example.org/');
    }

    public function testResolverGarbageIsRejected(): void
    {
        $guard = $this->guard(['odd.example.org' => ['']]);

        $this->expectException(UnsafeUrlException::class);

        $guard->assertPublic('https://odd.example.org/');
    }

    public function testExceptionMessageDoesNotEchoTheUrl(): void
    {
        try {
            $this->guard()->assertPublic('http://127.0.0.1/<script>alert(1)</script>');
            self::fail('Expected an UnsafeUrlException.');
        } catch (UnsafeUrlException $e) {
            self::assertStringNotContainsString('script', $e->getMessage());
        }
    }

    public function testIsPublic(): void
    {
        $guard = $this->guard(['example.org' => ['93.184.216.34']]);

        self::assertTrue($guard->isPublic('https://example.org/'));
        self::assertFalse($guard->isPublic('http://10.0.0.1/'));
    }
}
