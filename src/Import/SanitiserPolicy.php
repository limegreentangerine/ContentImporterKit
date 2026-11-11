<?php

declare(strict_types=1);

namespace ContentImporterKit\Import;

/**
 * What imported article HTML is allowed to contain. Everything else is removed, not escaped.
 *
 * This is the one place to loosen or tighten the output. Keep it as small as the content needs: every tag or
 * attribute added here is something an untrusted source can put on a public page.
 */
final class SanitiserPolicy
{
    /**
     * HTMLPurifier "HTML.Allowed" syntax: tag, or tag[attribute|attribute].
     *
     * h1 is left out on purpose: the page title is the h1, so an imported h1 would give the page two.
     * No class, id or style attributes: they let a source restyle or script-target the host theme.
     */
    public const ALLOWED_HTML = 'p,br,hr,h2,h3,h4,h5,h6,strong,b,em,i,u,s,sub,sup,blockquote,pre,code,'
        . 'ul,ol,li,'
        . 'a[href|title],'
        . 'img[src|alt|title|width|height],'
        . 'figure,figcaption,'
        . 'table,caption,thead,tbody,tfoot,tr,th[colspan|rowspan|scope],td[colspan|rowspan]';

    /**
     * URL schemes allowed in href and src. Relative URLs are kept. No data:, javascript: or vbscript:.
     *
     * @var list<string>
     */
    public const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];
}
