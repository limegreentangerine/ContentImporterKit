<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use ContentImporterKit\Security\UrlGuard;
use GuzzleHttp\Exception\GuzzleException;
use ContentImporterKit\Security\UnsafeUrlException;

/**
 * Fetches pages for the scraper without being talked into reaching internal addresses.
 *
 * Every request, including each redirect hop, is checked by UrlGuard and pinned to the address it validated. Redirects
 * are followed here, not by the HTTP client, so each hop gets that check. Responses are size-limited while they are
 * read, and failures are reported as FetchException with a message that is safe to show.
 */
final class Fetcher
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlGuard $guard,
        private readonly UrlResolver $resolver = new UrlResolver(),
        private readonly int $maxBytes = 5_000_000,
        private readonly int $maxRedirects = 3,
        private readonly string $userAgent = 'ContentImporterKit/1.0',
    ) {}

    private function header(ResponseInterface $response, string $name): ?string
    {
        $value = trim($response->getHeaderLine($name));

        return $value === '' ? null : $value;
    }

    private function readBody(ResponseInterface $response): string
    {
        $declared = $response->getHeaderLine('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $this->maxBytes) {
            throw new FetchException('The response is too large.');
        }

        $stream = $response->getBody();
        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(8192);
            if (strlen($body) > $this->maxBytes) {
                throw new FetchException('The response is too large.');
            }
        }

        return $body;
    }

    /**
     * @param array<string, string> $headers for example If-None-Match and If-Modified-Since for a conditional request
     *
     * @throws FetchException
     */
    public function get(string $url, array $headers = []): FetchResult
    {
        $current = $url;

        for ($hop = 0; $hop <= $this->maxRedirects; ++$hop) {
            try {
                $target = $this->guard->assertPublic($current);
            } catch (UnsafeUrlException $e) {
                throw new FetchException($e->getMessage(), 0, $e);
            }

            try {
                $response = $this->client->request('GET', $current, [
                    'allow_redirects' => false,
                    'http_errors' => false,
                    // Never add 'stream' => true here. It makes Guzzle use PHP's stream wrapper instead of cURL,
                    // which ignores the CURLOPT_RESOLVE pin below (so the address check would no longer hold)
                    // and waits for the connection to close on chunked responses (2 to 3 seconds a page).
                    'connect_timeout' => 5,
                    'timeout' => 20,
                    // Stops the download as soon as it grows past the limit, instead of after it has finished.
                    'progress' => function (int $downloadTotal, int $downloaded): void {
                        if ($downloadTotal > $this->maxBytes || $downloaded > $this->maxBytes) {
                            throw new FetchException('The response is too large.');
                        }
                    },
                    'headers' => $headers + ['User-Agent' => $this->userAgent],
                    'curl' => [
                        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                        CURLOPT_RESOLVE => [$target->curlResolveEntry()],
                    ],
                ]);
            } catch (GuzzleException $e) {
                throw new FetchException('The request failed.', 0, $e);
            }

            $status = $response->getStatusCode();

            if (in_array($status, self::REDIRECT_STATUSES, true)) {
                $next = $this->resolver->resolve($current, $response->getHeaderLine('Location'));
                if ($next === null) {
                    throw new FetchException('The server redirected to an address that can not be used.', $status);
                }
                $current = $next;

                continue;
            }

            if ($status === 304) {
                return new FetchResult($current, 304, '', $this->header($response, 'ETag'), $this->header($response, 'Last-Modified'), true);
            }

            if ($status < 200 || $status >= 300) {
                throw new FetchException(sprintf('The server answered with HTTP %d.', $status), $status);
            }

            return new FetchResult(
                $current,
                $status,
                $this->readBody($response),
                $this->header($response, 'ETag'),
                $this->header($response, 'Last-Modified'),
                false,
            );
        }

        throw new FetchException('The server redirected too many times.');
    }
}
