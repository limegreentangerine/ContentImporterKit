<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

use DateTimeZone;
use JsonException;

/**
 * Reads an import file into validated records. A bad record is reported and skipped; it never aborts the file.
 */
final class ArticleFileParser
{
    public const MAX_RECORDS = 10000;

    /**
     * @throws InvalidArticleException when the file as a whole is unusable
     */
    public function parse(string $json, ?DateTimeZone $timezone = null): ParseResult
    {
        try {
            $data = json_decode(ltrim($json, "\xEF\xBB\xBF"), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArticleException('The file is not valid JSON.');
        }

        if (!is_array($data) || !array_is_list($data)) {
            throw new InvalidArticleException('The file must contain a list of articles.');
        }
        if (count($data) > self::MAX_RECORDS) {
            throw new InvalidArticleException('The file contains too many articles.');
        }

        $records = [];
        $errors = [];
        $seen = [];
        foreach ($data as $position => $raw) {
            try {
                $record = ArticleRecord::fromArray($raw, $timezone);
            } catch (InvalidArticleException $e) {
                $errors[$position] = $e->getMessage();
                continue;
            }

            if (isset($seen[$record->urlSlug])) {
                $errors[$position] = 'Another record in the file uses the same "urlSlug".';
                continue;
            }
            $seen[$record->urlSlug] = true;
            $records[] = $record;
        }

        return new ParseResult($records, $errors);
    }
}
