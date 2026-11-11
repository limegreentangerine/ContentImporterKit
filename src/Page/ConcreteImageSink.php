<?php

declare(strict_types=1);

namespace ContentImporterKit\Page;

use RuntimeException;
use Concrete\Core\File\Importer;
use Concrete\Core\Entity\File\Version;
use Concrete\Core\File\Set\Set as FileSet;

/**
 * Puts downloaded images in Concrete's file manager, and in a file set when one is given.
 */
final class ConcreteImageSink implements ImageSink
{
    public function __construct(
        private readonly Importer $importer,
        private readonly ?FileSet $fileSet = null,
    ) {}

    public function store(string $localPath): int
    {
        $version = $this->importer->import($localPath, basename($localPath));

        if (!$version instanceof Version) {
            throw new RuntimeException(sprintf('The file manager refused the image: %s', $this->importer->getErrorMessage($version)));
        }

        $this->fileSet?->addFileToSet($version);

        return (int) $version->getFile()->getFileID();
    }
}
