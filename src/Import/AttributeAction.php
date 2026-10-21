<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * What the importer does with one value that is mapped to one page attribute.
 */
enum AttributeAction
{
    /** Store the value as it is. */
    case StoreText;

    /** Download the image the value points to and store the file. */
    case DownloadImage;

    /** Leave the attribute empty. */
    case Skip;
}
