<?php
// file generated with AI assistance: Claude Code - 2026-10-07 22:10:00 UTC

namespace dmstr\knowledgeLibrary\helpers;

use HTMLPurifier_Config;
use Yii;
use yii\helpers\FileHelper;
use yii\helpers\HtmlPurifier;

/**
 * Renders the Markdown text of versions.
 */
class MarkdownHelper
{
    private static ?SafeGithubMarkdown $parser = null;

    /**
     * Renders the text as GitHub flavored Markdown.
     *
     * HTML written into the text is shown as text and never interpreted
     * (see SafeGithubMarkdown); only the Markdown syntax is rendered. Links
     * and images keep only safe URL schemes (`http`, `https`, `mailto`,
     * ...), a `javascript:` URL is dropped.
     *
     * @return string the HTML, an empty string for null or an empty text
     */
    public static function render(?string $markdown): string
    {
        if ($markdown === null || $markdown === '') {
            return '';
        }

        if (self::$parser === null) {
            self::$parser = new SafeGithubMarkdown();
            self::$parser->html5 = true;
        }
        $html = self::$parser->parse($markdown);

        // The Markdown parser passes any URL through, including
        // `javascript:`; the purifier keeps only safe schemes and
        // well-formed markup. Its definition cache lives in a directory of
        // its own below the runtime path, created on first use.
        return HtmlPurifier::process($html, static function (HTMLPurifier_Config $config): void {
            $path = Yii::$app->getRuntimePath() . '/html-purifier';
            FileHelper::createDirectory($path);
            $config->set('Cache.SerializerPath', $path);
        });
    }
}
