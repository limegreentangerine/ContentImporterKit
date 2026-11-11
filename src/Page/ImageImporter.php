<?php

declare(strict_types=1);

namespace ContentImporterKit\Page;

use Throwable;
use Psr\Log\LoggerInterface;
use ContentImporterKit\Scrape\Fetcher;

/**
 * Downloads the images that imported articles point to, and hands them to the file manager.
 *
 * - Every request goes through the Fetcher, so the address checks, redirect checks and size limit apply.
 * - The file is judged by its content, not its name: only real JPEG and PNG images are kept, and the file gets the
 *   extension of what it really is.
 * - An address is downloaded once per instance, however many articles use it, and a failure is remembered too.
 *
 * A failed image is logged and reported as null: an article is never held up by its picture.
 */
final class ImageImporter
{
    /** @var array<int, string> */
    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
    ];

    /** @var array<string, int|null> */
    private array $imported = [];

    public function __construct(
        private readonly Fetcher $fetcher,
        private readonly ImageSink $sink,
        private readonly LoggerInterface $logger,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    private function makeDirectory(): string
    {
        $base = rtrim($this->temporaryDirectory ?? sys_get_temp_dir(), '/');
        $directory = $base . '/cik-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('A temporary folder could not be created.');
        }

        return $directory;
    }

    /**
     * The file's name from the address, kept to safe characters, with the extension of the real image type.
     */
    private function filename(string $url, string $extension): string
    {
        $name = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
        $name = (string) preg_replace(['/[^A-Za-z0-9._-]+/', '/\.{2,}/'], ['-', '.'], $name);
        $name = trim($name, '-.');
        $name = substr($name, 0, 100);

        return ($name !== '' ? $name : 'image-' . substr(sha1($url), 0, 12)) . '.' . $extension;
    }

    private function printable(string $value): string
    {
        return substr((string) preg_replace('/[\x00-\x1f\x7f]+/', '', $value), 0, 300);
    }

    /**
     * @return int|null the file ID, or null when the image could not be imported
     */
    public function import(string $url): ?int
    {
        if (array_key_exists($url, $this->imported)) {
            return $this->imported[$url];
        }
        $this->imported[$url] = null;

        $directory = null;
        $path = null;

        try {
            $body = $this->fetcher->get($url)->body;

            $info = @getimagesizefromstring($body);
            if ($info === false || !isset(self::TYPES[$info[2]])) {
                $this->logger->warning(sprintf('Image skipped (%s): the file is not a JPEG or PNG image.', $this->printable($url)));

                return null;
            }

            $directory = $this->makeDirectory();
            $path = $directory . '/' . $this->filename($url, self::TYPES[$info[2]]);
            if (file_put_contents($path, $body) === false) {
                throw new \RuntimeException('The image could not be written to disk.');
            }

            $stored = $this->sink->store($path);
            return $this->imported[$url] = $stored;
        } catch (Throwable $e) {
            $this->logger->warning(sprintf('Image skipped (%s): %s', $this->printable($url), $e->getMessage()));

            return null;
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            if ($directory !== null) {
                @rmdir($directory);
            }
        }
    }
}
