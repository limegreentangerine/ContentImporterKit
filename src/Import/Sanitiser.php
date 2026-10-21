<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

use HTMLPurifier;
use RuntimeException;
use HTMLPurifier_Config;

/**
 * Reduces untrusted HTML to the SanitiserPolicy allowlist (XSS protection for imported content).
 *
 * Build one instance per run and reuse it: setting up the purifier is the expensive part, cleaning a block is cheap.
 */
final class Sanitiser
{
    private HTMLPurifier $purifier;

    /**
     * @param string|null $cachePath writable directory for HTMLPurifier's compiled definitions; without one they
     *                               are rebuilt on every instance, which is fine for tests but slow in production
     */
    public function __construct(?string $cachePath = null)
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', SanitiserPolicy::ALLOWED_HTML);
        $config->set('URI.AllowedSchemes', array_fill_keys(SanitiserPolicy::ALLOWED_SCHEMES, true));
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('HTML.SafeIframe', false);

        if ($cachePath === null) {
            $config->set('Cache.DefinitionImpl', null);
        } else {
            if (!is_dir($cachePath) && !@mkdir($cachePath, 0775, true) && !is_dir($cachePath)) {
                throw new RuntimeException('The sanitiser cache directory could not be created.');
            }
            $config->set('Cache.SerializerPath', $cachePath);
        }

        // HTMLPurifier only knows HTML 4, so the HTML5 elements in the policy have to be declared. Raise the
        // revision whenever this block changes, so cached definitions are rebuilt. This finalizes the config, so
        // every directive must be set before it.
        $config->set('HTML.DefinitionID', 'content-importer-kit');
        $config->set('HTML.DefinitionRev', 1);
        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addElement('figure', 'Block', 'Flow', 'Common');
            $definition->addElement('figcaption', 'Inline', 'Flow', 'Common');
        }

        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitise(string $html): string
    {
        return $this->purifier->purify($html);
    }

    /**
     * Sanitises every body block of a record. Only "content" blocks are HTML this class knows how to make safe, so
     * any other block type is dropped, and so are blocks that are empty once cleaned.
     */
    public function sanitiseRecord(ArticleRecord $record): SanitisedArticle
    {
        $blocks = [];
        foreach ($record->bodyBlocks as $block) {
            if ($block['block_type'] !== 'content') {
                continue;
            }
            $html = $this->sanitise($block['data']);
            if (trim($html) !== '') {
                $blocks[] = ['block_type' => 'content', 'data' => $html];
            }
        }

        return new SanitisedArticle($record->withBodyBlocks($blocks));
    }
}
