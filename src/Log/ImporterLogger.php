<?php

namespace ContentImporterKit\Log;

use ClassKit\Log\Logger;

/**
 * The logger for everything this package does. Entries are written to the "content_importer" channel, so they can be
 * filtered in Dashboard > Reports > Logs.
 *
 * Use it as Core::make(ImporterLogger::class)->getLogger(). Do not log through Concrete's Log facade, error_log() or
 * a logger of another channel anywhere in this package.
 */
class ImporterLogger extends Logger
{
    public const CHANNEL = 'content_importer';

    public function __construct()
    {
        parent::__construct(self::CHANNEL);
    }
}
