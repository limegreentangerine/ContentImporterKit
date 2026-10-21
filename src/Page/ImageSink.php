<?php

declare(strict_types=1);

namespace ContentImporterKit\Page;

/**
 * Takes a downloaded image and puts it in the site's file manager.
 */
interface ImageSink
{
    /**
     * @param string $localPath a file on disk, named as the file should be named. It is removed afterwards.
     *
     * @return int the ID of the file in the file manager
     *
     * @throws \RuntimeException when the file manager refuses the file
     */
    public function store(string $localPath): int;
}
