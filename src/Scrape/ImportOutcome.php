<?php

declare(strict_types=1);

namespace ContentImporterKit\Scrape;

/**
 * How one article of a scrape run ended.
 */
enum ImportOutcome: string
{
    /** A new page was created. */
    case Imported = 'imported';

    /** An existing page got a new version. */
    case Updated = 'updated';

    /** Nothing on the page needed to change. */
    case Unchanged = 'unchanged';

    /** The article could not be imported. The reason is recorded on its ItemState. */
    case Failed = 'failed';
}
