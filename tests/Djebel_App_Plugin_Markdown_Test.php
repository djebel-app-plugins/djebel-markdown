<?php
/**
 * Unit tests for Djebel_App_Plugin_Markdown. The framework + plugin are loaded by
 * the test bootstrap (see the plugin readme) — this file expects the bootstrap to
 * be done for it and loads nothing.
 */

use PHPUnit\Framework\TestCase;

class Djebel_App_Plugin_Markdown_Test extends TestCase
{
    private $plugin_obj;
    private $test_dir;

    protected function setUp(): void
    {
        $this->plugin_obj = Djebel_App_Plugin_Markdown::getInstance();
        $this->test_dir = sys_get_temp_dir() . '/djebel_markdown_test_' . uniqid();

        $mkdir_res = Dj_App_File_Util::mkdir($this->test_dir);
        $this->assertTrue($mkdir_res->isSuccess(), 'Failed to create the test dir');
    }

    protected function tearDown(): void
    {
        $files = glob($this->test_dir . '/*');

        if (!empty($files)) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        if (is_dir($this->test_dir)) {
            rmdir($this->test_dir);
        }
    }

    /**
     * Writes a fixture file then parses it the way the static content plugin does
     * (full read, so the body plus the h1 title extraction are both exercised).
     * @param string $content
     * @return Dj_App_Result
     */
    private function parseFixture($content)
    {
        $file = $this->test_dir . '/fixture.md';
        $write_res = file_put_contents($file, $content);
        $this->assertNotFalse($write_res, 'Failed to write the markdown fixture');

        $ctx = [
            'file' => $file,
            'full' => 1,
        ];

        return $this->plugin_obj->parseFrontMatter('', $ctx);
    }

    // ---------------------------------------------------------------- front matter

    public function testFrontMatterIsStrippedFromContent()
    {
        $res_obj = $this->parseFixture("---\ntitle: My Title\nstatus: published\n---\n\nBody text.\n");

        $this->assertTrue($res_obj->isSuccess());
        $this->assertEquals('My Title', $res_obj->meta['title']);
        $this->assertEquals('Body text.', $res_obj->content);
        $this->assertStringNotContainsString('status:', $res_obj->content);
    }

    /**
     * A file with no front matter is ordinary content, not an error — it must still
     * parse, keep its whole body, then take its title from the h1.
     */
    public function testFileWithoutFrontMatterStillSucceeds()
    {
        $res_obj = $this->parseFixture("# Plain Heading\n\nBody text.\n");

        $this->assertTrue($res_obj->isSuccess());
        $this->assertEquals('Plain Heading', $res_obj->meta['title']);
        $this->assertEquals('Body text.', $res_obj->content);
    }

    public function testPlainTextWithoutFrontMatterSucceeds()
    {
        $res_obj = $this->parseFixture("Just a sentence.\n");

        $this->assertTrue($res_obj->isSuccess());
        $this->assertEmpty($res_obj->meta['title']);
        $this->assertEquals('Just a sentence.', $res_obj->content);
    }

    /**
     * Opened with --- but never closed is an authoring error. It is reported, never
     * repaired — see the throw in parseFrontMatter for why.
     */
    public function testUnclosedFrontMatterIsReportedAsAnError()
    {
        $res_obj = $this->parseFixture("---\ntitle: Never closed\n\nBody text.\n");

        $this->assertFalse($res_obj->isSuccess());
        $this->assertStringContainsString('never closed', $res_obj->msg);
    }

    public function testEmptyFrontMatterBlockSucceedsWithNoMeta()
    {
        $res_obj = $this->parseFixture("---\n---\n\nBody text.\n");

        $this->assertTrue($res_obj->isSuccess());
        $this->assertEmpty($res_obj->meta['title']);
        $this->assertEquals('Body text.', $res_obj->content);
    }

    // ---------------------------------------------------------------- content loss

    /**
     * A body opening with a list bullet must not be mistaken for a front matter
     * delimiter — the next --- (a horizontal rule) used to be treated as the closing
     * delimiter, discarding every line before it.
     */
    public function testListFollowedByHorizontalRuleKeepsAllContent()
    {
        $res_obj = $this->parseFixture("- item one\n- item two\n\n---\n\ntrailing paragraph\n");

        $this->assertTrue($res_obj->isSuccess());
        $this->assertStringContainsString('item one', $res_obj->content);
        $this->assertStringContainsString('item two', $res_obj->content);
        $this->assertStringContainsString('trailing paragraph', $res_obj->content);
    }

    public function testFrontMatterWithListThenHorizontalRuleKeepsAllContent()
    {
        $res_obj = $this->parseFixture("---\ntitle: T\n---\n\n- item one\n- item two\n\n---\n\ntrailing paragraph\n");

        $this->assertTrue($res_obj->isSuccess());
        $this->assertEquals('T', $res_obj->meta['title']);
        $this->assertStringContainsString('item one', $res_obj->content);
        $this->assertStringContainsString('trailing paragraph', $res_obj->content);
    }

    // ---------------------------------------------------------------- h1 title

    public function testContentH1OverridesFrontMatterTitle()
    {
        $res_obj = $this->parseFixture("---\ntitle: From Front Matter\n---\n\n# From Content\n\nBody.\n");

        $this->assertEquals('From Content', $res_obj->meta['title']);
        $this->assertStringNotContainsString('From Content', $res_obj->content);
    }

    public function testH2IsNotTreatedAsATitle()
    {
        $res_obj = $this->parseFixture("---\ntitle: Kept\n---\n\n## Subheading\n\nBody.\n");

        $this->assertEquals('Kept', $res_obj->meta['title']);
        $this->assertStringContainsString('## Subheading', $res_obj->content);
    }

    /**
     * The heading line used to be located inside a fixed 150-char window; a longer
     * title was cut mid-line, leaking its tail into the body.
     */
    public function testVeryLongH1IsNotTruncated()
    {
        $long_title = str_repeat('W', 200) . ' END';
        $res_obj = $this->parseFixture("---\nstatus: published\n---\n\n# {$long_title}\n\nBody paragraph.\n");

        $this->assertEquals($long_title, $res_obj->meta['title']);
        $this->assertStringNotContainsString('END', $res_obj->content);
        $this->assertEquals('Body paragraph.', $res_obj->content);
    }

    /**
     * Trimming '#' from BOTH ends ate the trailing character of a title like "Learn C#".
     */
    public function testH1KeepsATrailingHashInTheTitle()
    {
        $res_obj = $this->parseFixture("---\nstatus: published\n---\n\n# Learn C#\n\nBody.\n");

        $this->assertEquals('Learn C#', $res_obj->meta['title']);
    }

    public function testCyrillicH1IsNotSplit()
    {
        $res_obj = $this->parseFixture("---\nstatus: published\n---\n\n# Светослав Маринов\n\nТяло.\n");

        $this->assertEquals('Светослав Маринов', $res_obj->meta['title']);
        $this->assertNotFalse(mb_check_encoding($res_obj->meta['title'], 'UTF-8'));
    }

    // ---------------------------------------------------------------- conversion

    public function testProcessMarkdownConvertsMarkdownSyntax()
    {
        $html = $this->plugin_obj->processMarkdown("## Sub\n\n- one\n- two\n");

        $this->assertStringContainsString('<h2>Sub</h2>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
    }

    /**
     * processMarkdown() strips a front matter block when handed a whole file, but a
     * leading list bullet is not a delimiter so it must survive.
     */
    public function testProcessMarkdownKeepsAListThatPrecedesAHorizontalRule()
    {
        $html = $this->plugin_obj->processMarkdown("- item one\n- item two\n\n---\n\ntrailing\n");

        $this->assertStringContainsString('item one', $html);
        $this->assertStringContainsString('item two', $html);
        $this->assertStringContainsString('trailing', $html);
    }

    public function testProcessMarkdownStripsFrontMatterBlock()
    {
        $html = $this->plugin_obj->processMarkdown("---\ntitle: T\nstatus: published\n---\n\nBody text.\n");

        $this->assertStringNotContainsString('status:', $html);
        $this->assertStringContainsString('Body text.', $html);
    }

    public function testProcessMarkdownEscapesRawHtml()
    {
        $html = $this->plugin_obj->processMarkdown("<script>alert(1)</script>\n");

        $this->assertStringNotContainsString('<script>', $html);
    }
}
