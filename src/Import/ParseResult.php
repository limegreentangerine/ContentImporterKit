<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * The outcome of reading an import file: the valid records, and why each rejected record was rejected.
 */
final readonly class ParseResult
{
    /**
     * @param list<ArticleRecord> $records
     * @param array<int, string>  $errors  reason per record, keyed by its position in the file
     */
    public function __construct(
        public array $records,
        public array $errors,
    ) {}

    /**
     * Every attribute key used by any valid record, in first-seen order, for the mapping screen.
     *
     * @return list<string>
     */
    public function attributeKeys(): array
    {
        $keys = [];
        foreach ($this->records as $record) {
            foreach (array_keys($record->attributes) as $key) {
                $keys[(string) $key] = true;
            }
        }

        return array_keys($keys);
    }
}
