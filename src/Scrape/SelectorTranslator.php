<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * Translates the small subset of CSS selectors the scraper needs into XPath.
 *
 * Supported: tag names and *, .class, #id, [attr] and [attr=value] (value bare or quoted), descendant (space) and
 * child (>) combinators, and comma-separated groups. Anything else (pseudo-classes, sibling combinators, attribute
 * operators other than =) is rejected, never guessed at. Every name is checked against a strict pattern and every
 * value is quoted as an XPath literal, so a selector can not inject XPath.
 */
final class SelectorTranslator
{
    private const MAX_LENGTH = 500;
    private const NAME = '[A-Za-z_][A-Za-z0-9_-]*';

    /**
     * Splits on commas that are not inside quotes or brackets.
     *
     * @return list<string>
     */
    private function splitGroups(string $selector): array
    {
        $groups = [];
        $current = '';
        $quote = null;
        $depth = 0;
        foreach (str_split($selector) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '[') {
                ++$depth;
            } elseif ($char === ']') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $groups[] = $current;
                $current = '';

                continue;
            }
            $current .= $char;
        }
        $groups[] = $current;

        return $groups;
    }

    private function translateGroup(string $group): string
    {
        $group = trim($group);
        if ($group === '') {
            throw new InvalidSelectorException('The selector contains an empty group.');
        }

        $xpath = '';
        $combinator = '//';
        $offset = 0;
        $length = strlen($group);

        while ($offset < $length) {
            [$compound, $offset] = $this->readCompound($group, $offset);
            $xpath .= $combinator . $compound;

            // Whatever follows a compound is a combinator or the end.
            $whitespace = strspn($group, " \t\r\n", $offset);
            $offset += $whitespace;
            if ($offset >= $length) {
                break;
            }
            if ($group[$offset] === '>') {
                $combinator = '/';
                ++$offset;
                $offset += strspn($group, " \t\r\n", $offset);
            } elseif ($whitespace > 0) {
                $combinator = '//';
            } else {
                throw new InvalidSelectorException(sprintf('Unsupported selector syntax near "%s".', substr($group, $offset, 10)));
            }
            if ($offset >= $length) {
                throw new InvalidSelectorException('The selector ends with a combinator.');
            }
        }

        return $xpath;
    }

    /**
     * @return array{string, int} the XPath step and the offset after the compound selector
     */
    private function readCompound(string $group, int $offset): array
    {
        $tag = '*';
        $predicates = [];
        $length = strlen($group);
        $start = $offset;

        if ($group[$offset] === '*') {
            ++$offset;
        } elseif (preg_match('/\G' . self::NAME . '/', $group, $match, 0, $offset) === 1) {
            $tag = $match[0];
            $offset += strlen($match[0]);
        }

        while ($offset < $length) {
            $char = $group[$offset];

            if ($char === '.' || $char === '#') {
                if (preg_match('/\G[.#](' . self::NAME . ')/', $group, $match, 0, $offset) !== 1) {
                    throw new InvalidSelectorException(sprintf('Unsupported selector syntax near "%s".', substr($group, $offset, 10)));
                }
                $predicates[] = $char === '.'
                    ? sprintf("contains(concat(' ', normalize-space(@class), ' '), ' %s ')", $match[1])
                    : sprintf('@id=%s', $this->literal($match[1]));
                $offset += strlen($match[0]);

                continue;
            }

            if ($char === '[') {
                $pattern = '/\G\[\s*(' . self::NAME . ')\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([A-Za-z0-9_.-]+))\s*)?\]/';
                if (preg_match($pattern, $group, $match, 0, $offset) !== 1) {
                    throw new InvalidSelectorException(sprintf('Unsupported selector syntax near "%s".', substr($group, $offset, 10)));
                }
                $value = $match[2] ?? '';
                $value = ($match[3] ?? '') !== '' ? $match[3] : $value;
                $value = ($match[4] ?? '') !== '' ? $match[4] : $value;
                $hasValue = str_contains($match[0], '=');
                $predicates[] = $hasValue
                    ? sprintf('@%s=%s', $match[1], $this->literal($value))
                    : sprintf('@%s', $match[1]);
                $offset += strlen($match[0]);

                continue;
            }

            break;
        }

        if ($offset === $start) {
            throw new InvalidSelectorException(sprintf('Unsupported selector syntax near "%s".', substr($group, $offset, 10)));
        }

        return [$tag . ($predicates === [] ? '' : '[' . implode('][', $predicates) . ']'), $offset];
    }

    private function literal(string $value): string
    {
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        $parts = array_map(static fn(string $part): string => "'" . $part . "'", explode("'", $value));

        return 'concat(' . implode(', "\'", ', $parts) . ')';
    }

    /**
     * @throws InvalidSelectorException
     */
    public function toXPath(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '' || strlen($selector) > self::MAX_LENGTH) {
            throw new InvalidSelectorException('The selector is empty or too long.');
        }

        $alternatives = [];
        foreach ($this->splitGroups($selector) as $group) {
            $alternatives[] = $this->translateGroup($group);
        }

        return implode(' | ', $alternatives);
    }
}
