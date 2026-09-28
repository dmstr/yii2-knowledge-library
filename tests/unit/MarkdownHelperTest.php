<?php

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
        $this->assertStringContainsString('&lt;img src=&quot;x&quot; onerror=&quot;alert(1)&quot;&gt;', $html);
    }

    public function testEmptyTextRendersNothing(): void
    {
        $this->assertSame('', MarkdownHelper::render(null));
        $this->assertSame('', MarkdownHelper::render(''));
    }
}
