<?php
// file generated with AI assistance: Claude Code - 2026-10-07 22:10:00 UTC

namespace dmstr\knowledgeLibrary\tests\unit;

use dmstr\knowledgeLibrary\helpers\MarkdownHelper;
use dmstr\knowledgeLibrary\tests\TestCase;

class MarkdownHelperTest extends TestCase
{
    public function testRendersMarkdown(): void
    {
        $html = MarkdownHelper::render("# Title\n\nSome **bold** text.\n\n- one\n- two");

        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
    }

    public function testRendersBlockquotesLinkTitlesAndAutolinks(): void
    {
        $html = MarkdownHelper::render("> quoted\n\nSee [docs](https://example.org/ \"Docs\") and <https://auto.example.org/>.");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<p>quoted</p>', $html);
        $this->assertStringContainsString('<a href="https://example.org/" title="Docs">docs</a>', $html);
        $this->assertStringContainsString('<a href="https://auto.example.org/">https://auto.example.org/</a>', $html);
        $this->assertStringNotContainsString('&gt; quoted', $html);
    }

    public function testCodeBlocksKeepAngleBracketsAndAmpersands(): void
    {
        $html = MarkdownHelper::render("```\nif (a > b && c < d) {}\n```");

        $this->assertStringContainsString('<pre><code>if (a &gt; b &amp;&amp; c &lt; d) {}', $html);
        $this->assertStringNotContainsString('&amp;gt;', $html);
    }

    public function testScriptIsShownAsText(): void
    {
        $html = MarkdownHelper::render("Before <script>alert(1)</script> after");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testImageWithEventHandlerIsShownAsText(): void
    {
        $html = MarkdownHelper::render('<img src="x" onerror="alert(1)">');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src="x" onerror="alert(1)"&gt;', $html);
    }

    public function testBlockHtmlIsShownAsText(): void
    {
        $html = MarkdownHelper::render("<div onclick=\"alert(1)\">\n<p>inner</p>\n</div>\n\n<!-- a comment -->");

        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringNotContainsString('<!--', $html);
        $this->assertStringContainsString('&lt;div onclick="alert(1)"&gt;', $html);
        $this->assertStringContainsString('&lt;!-- a comment --&gt;', $html);
    }

    public function testEntitiesAndBareAmpersandsAreKept(): void
    {
        $html = MarkdownHelper::render('Copyright &copy; Fish & Chips');

        // The purifier normalizes entities to characters.
        $this->assertStringContainsString('© Fish &amp; Chips', $html);
    }

    public function testJavascriptUrlsAreDropped(): void
    {
        $html = MarkdownHelper::render('[click](javascript:alert(1)) and ![x](javascript:alert(2)) and [ok](https://example.org/)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<a href="https://example.org/">ok</a>', $html);
    }

    public function testEmptyTextRendersNothing(): void
    {
        $this->assertSame('', MarkdownHelper::render(null));
        $this->assertSame('', MarkdownHelper::render(''));
    }
}
