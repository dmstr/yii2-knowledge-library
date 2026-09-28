<?php

namespace dmstr\knowledgeLibrary\helpers;

use yii\helpers\Html;
use yii\helpers\Markdown;

/**
 * Renders the Markdown text of versions.
 */
class MarkdownHelper
{
    /**
     * Renders the text as GitHub flavored Markdown.
     *
     * The text is HTML-encoded first, so HTML written into the text is shown
     * as text and never interpreted; only the Markdown syntax is rendered.
     *
     * @return string the HTML, an empty string for null or an empty text
     */
    public static function render(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }

        return Markdown::process(Html::encode($markdown), 'gfm');
    }
}
