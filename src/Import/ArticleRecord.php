<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

use DateTimeZone;
use DateTimeImmutable;

/**
 * One validated article, as produced by the scraper or read from an uploaded JSON file.
 *
 * Validation covers shape, types, lengths and formats. It does not sanitise HTML: body blocks are passed through
 * the Sanitiser before they are stored.
 */
final readonly class ArticleRecord
{
    private const MAX_SLUG = 128;
    private const MAX_TITLE = 255;
    private const MAX_DESCRIPTION = 2000;
    private const MAX_ATTRIBUTE_VALUE = 2000;
    private const MAX_ATTRIBUTES = 100;
    private const MAX_BLOCKS = 200;
    private const MAX_BLOCK_DATA = 1_000_000;

    /**
     * @param array<string, string>                         $attributes
     * @param list<array{block_type: string, data: string}> $bodyBlocks
     */
    public function __construct(
        public string $urlSlug,
        public string $title,
        public string $description,
        public DateTimeImmutable $publishedAt,
        public ?string $metaDescription,
        public ?string $metaKeywords,
        public array $attributes,
        public array $bodyBlocks,
    ) {}

    /**
     * @param array<mixed> $raw
     */
    private static function text(array $raw, string $key, int $max, bool $required): ?string
    {
        $value = $raw[$key] ?? null;
        if ($value === null) {
            if ($required) {
                throw new InvalidArticleException(sprintf('"%s" is required.', $key));
            }

            return null;
        }

        return self::checkedString($value, $key, $max, $required);
    }

    private static function checkedString(mixed $value, string $field, int $max, bool $required): string
    {
        if (!is_string($value)) {
            throw new InvalidArticleException(sprintf('"%s" must be a string.', $field));
        }
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArticleException(sprintf('"%s" contains invalid characters.', $field));
        }
        $value = trim($value);
        if ($required && $value === '') {
            throw new InvalidArticleException(sprintf('"%s" is required.', $field));
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArticleException(sprintf('"%s" is too long.', $field));
        }

        return $value;
    }

    private static function date(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        // Fixed formats only: DateTime would also accept relative text such as "tomorrow".
        foreach ([DATE_ATOM, 'Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || $errors['warning_count'] + $errors['error_count'] === 0)) {
                return $date;
            }
        }

        throw new InvalidArticleException('"published_date" is not a valid date.');
    }

    /**
     * @return array<string, string>
     */
    private static function attributes(mixed $raw): array
    {
        if (!is_array($raw) || array_is_list($raw) && $raw !== []) {
            throw new InvalidArticleException('"attributes" must be an object.');
        }
        if (count($raw) > self::MAX_ATTRIBUTES) {
            throw new InvalidArticleException('"attributes" has too many entries.');
        }

        $attributes = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) !== 1) {
                throw new InvalidArticleException('"attributes" contains an invalid key.');
            }
            if ($value === null) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $value = (string) $value;
            }
            $attributes[$key] = self::checkedString($value, 'attributes.' . $key, self::MAX_ATTRIBUTE_VALUE, false);
        }

        return $attributes;
    }

    /**
     * @return list<array{block_type: string, data: string}>
     */
    private static function blocks(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new InvalidArticleException('"body_html" must be a list.');
        }
        if (count($raw) > self::MAX_BLOCKS) {
            throw new InvalidArticleException('"body_html" has too many blocks.');
        }

        $blocks = [];
        foreach ($raw as $block) {
            if (!is_array($block)) {
                throw new InvalidArticleException('"body_html" blocks must be objects.');
            }
            $type = self::text($block, 'block_type', 64, true);
            if (preg_match('/^[a-z0-9_]+$/', (string) $type) !== 1) {
                throw new InvalidArticleException('"block_type" is not valid.');
            }
            $blocks[] = [
                'block_type' => (string) $type,
                'data' => (string) self::text($block, 'data', self::MAX_BLOCK_DATA, true),
            ];
        }

        return $blocks;
    }

    /**
     * A copy of this record with different body blocks. Used by the Sanitiser, which is the only place a record's
     * HTML is rewritten.
     *
     * @param list<array{block_type: string, data: string}> $bodyBlocks
     */
    public function withBodyBlocks(array $bodyBlocks): self
    {
        return new self(
            $this->urlSlug,
            $this->title,
            $this->description,
            $this->publishedAt,
            $this->metaDescription,
            $this->metaKeywords,
            $this->attributes,
            $bodyBlocks,
        );
    }

    /**
     * @param DateTimeZone|null $timezone applied to dates that carry no offset of their own (UTC when omitted)
     *
     * @throws InvalidArticleException
     */
    public static function fromArray(mixed $raw, ?DateTimeZone $timezone = null): self
    {
        if (!is_array($raw) || array_is_list($raw) && $raw !== []) {
            throw new InvalidArticleException('The record must be an object.');
        }

        $slug = strtolower((string) self::text($raw, 'urlSlug', self::MAX_SLUG, true));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            throw new InvalidArticleException('"urlSlug" may only contain letters, numbers and single hyphens.');
        }

        return new self(
            $slug,
            (string) self::text($raw, 'title', self::MAX_TITLE, true),
            (string) self::text($raw, 'description', self::MAX_DESCRIPTION, false),
            self::date((string) self::text($raw, 'published_date', 64, true), $timezone ?? new DateTimeZone('UTC')),
            self::text($raw, 'meta_description', self::MAX_DESCRIPTION, false),
            self::text($raw, 'meta_keywords', self::MAX_DESCRIPTION, false),
            self::attributes($raw['attributes'] ?? []),
            self::blocks($raw['body_html'] ?? []),
        );
    }
}
