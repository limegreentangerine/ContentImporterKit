<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * Decides, from the value and the type of the attribute it is mapped to, what the importer does with it.
 *
 * - A value that is not a URL is stored as it is, whatever the attribute type.
 * - Only http(s) URLs are ever acted on: PHP's FILTER_VALIDATE_URL also accepts javascript://, and an attribute
 *   value may end up in an href in the theme.
 * - A jpg or png URL is downloaded when the attribute holds a file, and stored as text when it holds text.
 * - Any other URL is stored as text when the attribute holds text, and skipped otherwise.
 */
final class AttributeValueResolver
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png'];

    /** @var list<string> attribute type handles that can hold a URL as text */
    private const TEXT_TYPES = ['text', 'textarea', 'url'];

    private const FILE_TYPE = 'image_file';

    public function decide(string $value, string $attributeTypeHandle): AttributeAction
    {
        // FILTER_VALIDATE_URL rejects "javascript:alert(1)" and "data:text/html,..." (no "//"), so they would pass as
        // plain text. Browsers ignore whitespace and control characters inside a scheme, so those are removed first.
        // A data: URL must carry a media type or start with ; or , which keeps text like "data: 42 items" usable.
        $compact = (string) preg_replace('/[\x00-\x20]+/', '', $value);
        if (preg_match('/^(?:javascript|vbscript):|^data:(?:[a-z0-9.+-]*\/|[;,])/i', $compact) === 1) {
            return AttributeAction::Skip;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return AttributeAction::StoreText;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return AttributeAction::Skip;
        }

        $extension = strtolower(pathinfo((string) parse_url($value, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (in_array($extension, self::IMAGE_EXTENSIONS, true) && $attributeTypeHandle === self::FILE_TYPE) {
            return AttributeAction::DownloadImage;
        }

        return in_array($attributeTypeHandle, self::TEXT_TYPES, true)
            ? AttributeAction::StoreText
            : AttributeAction::Skip;
    }
}
