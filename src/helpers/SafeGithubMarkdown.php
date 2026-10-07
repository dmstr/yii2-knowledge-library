<?php
// file generated with AI assistance: Claude Code - 2026-10-07 22:10:00 UTC

namespace dmstr\knowledgeLibrary\helpers;

use cebe\markdown\GithubMarkdown;

/**
 * GitHub flavored Markdown parser that shows HTML written into the text as
 * text instead of passing it through.
 *
 * Block-level HTML becomes a paragraph with the escaped source, inline tags
 * and comments are escaped in place. Entities such as `&copy;` are kept, as
 * they cannot carry markup. Everything else renders like GithubMarkdown.
 */
class SafeGithubMarkdown extends GithubMarkdown
{
    protected function renderHtml($block)
    {
        return '<p>' . static::escape($block['content']) . "</p>\n";
    }

    protected function renderInlineHtml($block)
    {
        // parseEntity() yields inlineHtml blocks for entities, which are
        // safe; parseInlineHtml() yields them for tags and comments.
        return $block[1][0] === '&' ? $block[1] : static::escape($block[1]);
    }

    private static function escape(string $html): string
    {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
