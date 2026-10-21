<?php

declare(strict_types=1);

namespace ContentImporterKit\Security;

/**
 * A URL host that has been checked by UrlGuard, together with the public address the request must be pinned to.
 */
final readonly class ValidatedTarget
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /**
     * The value for CURLOPT_RESOLVE, so the request connects to the address that was validated.
     */
    public function curlResolveEntry(): string
    {
        $ip = str_contains($this->ip, ':') ? '[' . $this->ip . ']' : $this->ip;

        return sprintf('%s:%d:%s', $this->host, $this->port, $ip);
    }
}
