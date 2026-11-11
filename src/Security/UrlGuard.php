<?php

declare(strict_types=1);

namespace ContentImporterKit\Security;

use Closure;
use IPLib\Address\IPv6;
use IPLib\Factory as IPFactory;
use IPLib\Range\Type as IPRangeType;

/**
 * Decides whether a URL may be fetched server-side (SSRF protection).
 *
 * Only http(s) URLs on the allowed ports whose host resolves exclusively to public addresses pass. The caller must
 * pin the request to ValidatedTarget::$ip, otherwise DNS could change between this check and the fetch. Redirects
 * must not be followed, or be validated again hop by hop.
 */
final class UrlGuard
{
    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /**
     * @param list<int>                            $allowedPorts
     * @param (Closure(string): list<string>)|null $resolver     returns the addresses a host name resolves to
     */
    public function __construct(
        private readonly array $allowedPorts = [80, 443],
        ?Closure $resolver = null,
    ) {
        // gethostbynamel() goes through the system resolver, like curl does. dns_get_record() talks to the DNS
        // library directly and took ~60s per lookup under the web server on a dev machine, so it is only the
        // fallback for IPv6-only hosts. This is safe because the request is pinned to the validated address.
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = gethostbynamel($host) ?: [];
            if ($ips === []) {
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    $ips[] = (string) ($record['ipv6'] ?? '');
                }
            }

            return $ips;
        };
    }

    private function assertPublicAddress(string $address): void
    {
        $ip = IPFactory::addressFromString($address, false, false);
        if ($ip === null) {
            throw new UnsafeUrlException('The URL host resolved to an invalid address.');
        }

        // ::ffff:127.0.0.1 is loopback, whatever the IPv6 notation says.
        if ($ip instanceof IPv6 && $ip->toIPv4() !== null) {
            $ip = $ip->toIPv4();
        }

        if ($ip->getRangeType() !== IPRangeType::T_PUBLIC) {
            throw new UnsafeUrlException('The URL points to a private or reserved address.');
        }
    }

    /**
     * @throws UnsafeUrlException
     */
    public function assertPublic(string $url): ValidatedTarget
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new UnsafeUrlException('The URL is not valid.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrlException('The URL is not valid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Only http and https URLs are allowed.');
        }

        // user:pass@host is a common way to make a URL look like it points somewhere else.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URLs with credentials are not allowed.');
        }

        $host = rtrim(strtolower(trim($parts['host'], '[]')), '.');
        if ($host === '' || $host === '0' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new UnsafeUrlException('The URL points to a local address.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, $this->allowedPorts, true)) {
            throw new UnsafeUrlException('The URL uses a port that is not allowed.');
        }

        $candidates = IPFactory::addressFromString($host, false, false) !== null
            ? [$host]
            : ($this->resolver)($host);

        // A host that does not resolve is rejected rather than waved through.
        if ($candidates === []) {
            throw new UnsafeUrlException('The URL host could not be resolved.');
        }

        foreach ($candidates as $candidate) {
            $this->assertPublicAddress($candidate);
        }

        return new ValidatedTarget($scheme, $host, $port, $candidates[0]);
    }

    public function isPublic(string $url): bool
    {
        try {
            $this->assertPublic($url);
        } catch (UnsafeUrlException) {
            return false;
        }

        return true;
    }
}
