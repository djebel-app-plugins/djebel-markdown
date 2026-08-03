<?php
/*
plugin_name: Djebel Markdown
plugin_uri: https://djebel.com/plugins/djebel-markdown
description: Provides filters for markdown syntax
version: 1.0.0
load_priority:20
tags: markdown
stable_version: 1.0.0
min_php_ver: 5.6
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Svetoslav Marinov (Slavi)
company_name: Orbisius
author_uri: https://orbisius.com
text_domain: djebel-markdown
license: gpl2
*/

$obj = Djebel_App_Plugin_Markdown::getInstance();

Dj_App_Hooks::addFilter('app.plugins.markdown.convert_markdown', [ $obj, 'processMarkdown' ] );
Dj_App_Hooks::addFilter('app.plugins.markdown.parse_front_matter', [ $obj, 'parseFrontMatter' ] );
Dj_App_Hooks::addFilter('app.page.content', [ $obj, 'filterPageContent' ], 10);

class Djebel_App_Plugin_Markdown {
    private $parser = null;

    /**
     * @desc when we read the frontmatter/header of a markdown we read it partially.
     */
    private $buffer_size = 2048;

    private $frontmatter_delimiter = '---';

    /**
     * @param string $content
     * @param array $ctx
     * @return string
     * @throws Exception
     */
    public function processMarkdown( $content, $ctx = [] ) {
        // Load on demand. Guarded on the parser itself, not on the class: every assignment
        // below lives in this block, so keying it on class_exists() meant a pre-defined
        // override class skipped the block entirely and left the parser null — which returns
        // the content unconverted a few lines down.
        if (empty($this->parser)) {
            if (class_exists('Djebel_Plugin_Markdown_Shared_Parsedown')) {
                $this->parser = new Djebel_Plugin_Markdown_Shared_Parsedown(); // pre-defined override
            } elseif (class_exists('Parsedown')) {
                $this->parser = new Parsedown(); // loaded by the user.
            } else { // custom prefixed class
                require_once __DIR__ . '/shared/parsedown/Parsedown.php';
                $this->parser = new Djebel_Plugin_Markdown_Shared_Parsedown();
            }

            // Prevents raw HTML in Markdown from being rendered
            $this->parser->setSafeMode(true);

            // new lines to br
            $this->parser->setBreaksEnabled(true);

            // Escapes all raw HTML tags instead of rendering them.
            $this->parser->setMarkupEscaped(true);

            $this->parser = Dj_App_Hooks::applyFilter( 'app.plugin.markdown.parser_init_obj', $this->parser, $ctx );
        }

        if (empty($this->parser)) {
            return $content;
        }

        $content = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.pre_parse.content', $content, $ctx );
        $content = Dj_App_String_Util::trim($content);
        $first_char = Dj_App_String_Util::getFirstChar($content);

        // Skip the front matter block if present, to speed things up. It must OPEN with the
        // delimiter at offset 0 — a bare leading '-' is a list bullet, and treating that as
        // front matter discarded every line up to the next --- (a horizontal rule).
        $delimiter_len = strlen($this->frontmatter_delimiter);
        $opens_with_delimiter = strpos($content, $this->frontmatter_delimiter) === 0;

        if ($first_char == '-' && $opens_with_delimiter) {
            // Search for the CLOSING delimiter past the opening one.
            $end_str_pos = strpos($content, $this->frontmatter_delimiter, $delimiter_len);

            if ($end_str_pos !== false) {
                // Resume after the closing delimiter's whole LINE, so extra dashes (----) don't
                // survive and a body that starts with a list keeps its bullet.
                $line_end_pos = strpos($content, "\n", $end_str_pos);

                if ($line_end_pos === false) {
                    $content = '';
                } else {
                    $content = substr($content, $line_end_pos + 1);
                }

                $content = Dj_App_String_Util::trim($content);
            }
        }

        $markdown_content = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.pre_process_content', $content, $ctx );

        if (method_exists($this->parser, 'text')) { // jic
            $markdown_content = $this->parser->text($markdown_content);
            $markdown_content = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.post_process_content', $markdown_content, $ctx );
        }

        return $markdown_content;
    }

    /**
     * Filter page content - convert markdown if ext is 'md' or starts with ---
     * Hooks into app.page.content with high priority for early processing
     *
     * @param string $content
     * @param array $ctx
     * @return string
     */
    public function filterPageContent($content, $ctx = [])
    {
        if (empty($content)) {
            return $content;
        }

        $first_char = $content[0];

        // HTML content - skip markdown processing
        if ($first_char === '<') {
            return $content;
        }

        $ext = empty($ctx['ext']) ? '' : $ctx['ext'];
        $is_markdown = $ext === 'md';

        // Detect markdown by first char(s)
        if (!$is_markdown) {
            if ($first_char === '-' && substr($content, 1, 2) === '--') {
                $is_markdown = true;
            } elseif ($first_char === '#') {
                $is_markdown = true;
            }
        }

        if (!$is_markdown) {
            return $content;
        }

        $content = $this->processMarkdown($content, $ctx);

        return $content;
    }

    /**
     * Parses frontmatter from markdown content or reads from a file.
     * Extracts metadata between --- delimiters and returns parsed data.
     *
     * @param string $content Full markdown content with frontmatter
     * @param array $ctx Context information
     * @return Dj_App_Result
     */
    public function parseFrontMatter($content, $ctx = [])
    {
        $res_obj = new Dj_App_Result();

        try {
            $res_obj->meta = [];
            $res_obj->content = '';

            $content = Dj_App_String_Util::trim($content);
            $buffer_size = $this->buffer_size;
            $buffer_size = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.parse_front_matter_buff_size', $buffer_size, $ctx );
            $read_full_content = empty($ctx['full']) ? false : $ctx['full'];

            if (empty($content)) {
                if (empty($ctx['file'])) {
                    throw new Dj_App_Exception('Empty content');
                }

                if ($read_full_content) {
                    $buffer_size = 5 * 1024 * 1024; // 5MB
                }

                $file = $ctx['file'];

                // Kept in its OWN variable: assigning the file read to $res_obj replaced the
                // result this method returns, so its success status came from the read rather
                // than from the parse — and a parse failure could still report success.
                $read_res = Dj_App_File_Util::readPartially($file, $buffer_size);

                if ($read_res->isError()) {
                    throw new Dj_App_Exception('Error reading file', [
                        'code' => 'markdown.front_matter.read_failed',
                        'file' => $file,
                        'res_obj' => $read_res,
                    ]);
                }

                $content = $read_res->output;
                $content = Dj_App_String_Util::trim($content);

                if (empty($content)) {
                    throw new Dj_App_Exception('Empty content', [ 'file' => $file ]);
                }
            }
            
            $res_obj->content = $content;
            $first_char = Dj_App_String_Util::getFirstChar($content);

            // A file may legitimately carry NO front matter — that is not an error. Its whole
            // body is the content, meta stays empty, and the h1 title extraction below still
            // runs, so such a file behaves like every other one. A block that OPENS but never
            // closes is the one hard failure: see the throw below for why it is not repaired.
            $meta = [];
            $remaining_content = $content;
            $frontmatter_text = '';

            // Front matter must OPEN with the delimiter at offset 0. Testing only for a
            // leading '-' matched a list bullet, and the scan below then treated the next
            // --- (a horizontal rule) as the closing delimiter — silently swallowing every
            // line before it as if it were metadata.
            $has_front_matter = $first_char == '-' && strpos($content, $this->frontmatter_delimiter) === 0;

            if ($has_front_matter) {
                $delimiter_len = strlen($this->frontmatter_delimiter);
                $small_content = Dj_App_String_Util::cut($content, $buffer_size);

                // Search for the CLOSING delimiter PAST the opening one, on the untouched
                // string. Trimming '-' off the front first also stripped whitespace, so an
                // empty "---\n---" block lost BOTH delimiters and looked unterminated.
                $closing_delimiter_pos = strpos($small_content, $this->frontmatter_delimiter, $delimiter_len);

                // Opened but never closed is an authoring error, not content. It is NOT
                // repaired: every heuristic (stop at the first blank line, consume lines that
                // look like key: value) silently eats the opening lines of a document that
                // legitimately starts with a horizontal rule followed by "Note: ...". Failing
                // loudly beats mis-parsing quietly.
                if ($closing_delimiter_pos === false) {
                    throw new Dj_App_Exception('Front matter opened with --- but never closed', [
                        'code' => 'markdown.front_matter.unclosed',
                        'file' => empty($ctx['file']) ? '' : $ctx['file'],
                    ]);
                }

                // Extract frontmatter text: everything between the two delimiters.
                $frontmatter_len = $closing_delimiter_pos - $delimiter_len;
                $frontmatter_text = substr($small_content, $delimiter_len, $frontmatter_len);
                $frontmatter_text = Dj_App_String_Util::trim($frontmatter_text);

                // skip header
                if ($read_full_content) {
                    // Resume after the closing delimiter's whole LINE, so extra dashes (----)
                    // don't survive and a body starting with a list keeps its bullet.
                    $line_end_pos = strpos($content, "\n", $closing_delimiter_pos);

                    if ($line_end_pos === false) {
                        $remaining_content = '';
                    } else {
                        $remaining_content = substr($content, $line_end_pos + 1);
                    }

                    $remaining_content = Dj_App_String_Util::trim($remaining_content);
                    $res_obj->content = $remaining_content;
                }
            }

            if (!empty($frontmatter_text)) {
                // Use existing utility to parse metadata
                $meta_res = Dj_App_Util::extractMetaInfo($frontmatter_text);

                if ($meta_res->isSuccess()) {
                    $meta = $meta_res->data();
                    $meta = empty($meta) || !is_array($meta) ? [] : $meta;
                }
            }

            // Set defaults for common fields
            $defaults = [
                'title' => '',
                'summary' => '',
                'creation_date' => '',
                'last_modified' => '',
                'publish_date' => '',
                'sort_order' => 0,
                'category' => '',
                'tags' => [],
                'author' => '',
                'slug' => '',
            ];

            foreach ($defaults as $key => $default_value) {
                if (empty($meta[$key])) {
                    $meta[$key] = $default_value;
                }
            }

            // Title extraction: content h1 is source of truth
            // If content starts with # Title, extract it to meta and remove from content
            // This prevents duplication when rendering and ensures content title overrides frontmatter
            if ($read_full_content && !empty($remaining_content) && $remaining_content[0] === '#') {
                // Exactly one # is an h1. Counted on the raw content BEFORE any copying or
                // normalizing, so ## and deeper bail out having done no work at all.
                $hash_count = strspn($remaining_content, '#');

                if ($hash_count === 1) {
                    // Normalize once, then find the end of the first line in the FULL content.
                    // A fixed-size search window used to fall short of a long heading and cut
                    // it mid-line, truncating the title and leaking its tail into the body.
                    $content_normalized = Dj_App_String_Util::normalizeNewLines($remaining_content);
                    $newline_pos = strpos($content_normalized, "\n");

                    if ($newline_pos === false) {
                        $title_line = $content_normalized;
                        $line_end = strlen($content_normalized);
                    } else {
                        // substr is safe here: $newline_pos came from strpos() on an ASCII
                        // delimiter, so it always lands on a character boundary.
                        $title_line = substr($content_normalized, 0, $newline_pos);
                        $line_end = $newline_pos + 1;
                    }

                    // Drop the leading # only, by byte offset: it is ASCII and exactly one
                    // char here, so the cut lands before any multi-byte text and leaves it
                    // whole. Trimming '#' from BOTH ends ate the trailing character of a
                    // title like "Learn C#".
                    $title = substr($title_line, $hash_count);
                    $title = Dj_App_String_Util::trim($title);

                    // A bare '#' line carries no title. Leave whatever the front matter
                    // declared instead of blanking it, and leave the line in the content.
                    if (!empty($title)) {
                        $meta['title'] = $title;

                        // Remove the heading line from the content.
                        $remaining_content = substr($content_normalized, $line_end);
                        $remaining_content = Dj_App_String_Util::trim($remaining_content);
                        $res_obj->content = $remaining_content;

                        $ctx['title_extracted_from_content'] = true;
                    }
                }

                $meta = Dj_App_Hooks::applyFilter('app.plugins.markdown.parse_front_matter_title', $meta, $ctx);
            }

            // Process tags: convert string to array
            if (is_string($meta['tags'])) {
                $meta['tags'] = explode(',', $meta['tags']);
                $meta['tags'] = Dj_App_String_Util::trim($meta['tags']);
                $meta['tags'] = array_filter($meta['tags']);
            }

            // Fallback for publish_date: creation_date -> file mtime
            if (empty($meta['publish_date'])) {
                if (!empty($meta['creation_date'])) {
                    $meta['publish_date'] = $meta['creation_date'];
                } elseif (!empty($ctx['file'])) {
                    $file = $ctx['file'];
                    $file_mtime = filemtime($file);

                    if ($file_mtime) {
                        $meta['publish_date'] = date('Y-m-d H:i:s', $file_mtime);
                    }
                }
            }

            $res_obj->meta = $meta;
            $res_obj->status(true);
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
        }

        // Ensure meta is always an array even on exception
        if (!isset($res_obj->meta) || !is_array($res_obj->meta)) {
            $res_obj->meta = [];
        }

        return $res_obj;
    }

    /**
     * Parse markdown file: extract front matter, clean content, and handle title
     * @param string $content Raw markdown file content
     * @param array $ctx Context information
     * @return Dj_App_Result Object with header, meta, and content fields
     */
    public function parseMarkdown($content, $ctx = [])
    {
        $res_obj = new Dj_App_Result();

        $delimiter_pos = strpos($content, "\n---\n");
        $delimiter_len = 5;

        if ($delimiter_pos === false) {
            $delimiter_pos = strpos($content, "\n+++\n");
        }

        if ($delimiter_pos === false) {
            $res_obj->header = '';
            $res_obj->meta = [];
            $res_obj->content = $content;
            $res_obj->status(1);
            return $res_obj;
        }

        $header = substr($content, 0, $delimiter_pos + $delimiter_len);
        $meta_result = Dj_App_Util::extractMetaInfo($header);

        if ($meta_result->isError()) {
            $meta = [];
        } else {
            $meta = $meta_result->data();
        }

        $clean_start = $delimiter_pos + $delimiter_len;
        $clean_content = substr($content, $clean_start);

        if (empty($meta['title'])) {
            $content_len = strlen($clean_content);

            if ($content_len > 0) {
                $first_char = substr($clean_content, 0, 1);
                $has_newline_hash = strpos($clean_content, "\n#");

                if ($first_char === '#' || $has_newline_hash !== false) {
                    $search_len = min(200, $content_len);
                    $search_buffer = substr($clean_content, 0, $search_len);

                    $hash_pos = strpos($search_buffer, "\n#");

                    if ($hash_pos === false) {
                        if ($first_char === '#') {
                            $hash_pos = 0;
                        }
                    } else {
                        $hash_pos++;
                    }

                    if ($hash_pos !== false) {
                        $line_end = strpos($search_buffer, "\n", $hash_pos);

                        if ($line_end === false) {
                            $title_line = substr($search_buffer, $hash_pos);
                            $line_end = strlen($search_buffer);
                        } else {
                            $line_len = $line_end - $hash_pos;
                            $title_line = substr($search_buffer, $hash_pos, $line_len);
                        }

                        $hash_count = strspn($title_line, '#');

                        if ($hash_count === 1) {
                            $without_hash = substr($title_line, 1);
                            $title = ltrim($without_hash);
                            $meta['title'] = $title;

                            $before = substr($clean_content, 0, $hash_pos);
                            $after = substr($clean_content, $line_end);
                            $clean_content = $before . $after;
                        }
                    }
                }
            }
        }

        $res_obj->header = $header;
        $res_obj->meta = $meta;
        $res_obj->content = $clean_content;
        $res_obj->status(1);

        return $res_obj;
    }

    /**
     * Singleton pattern i.e. we have only one instance of this obj
     * @staticvar static $instance
     * @return static
     */
    public static function getInstance() {
        static $instance = null;

        // This will make the calling class to be instantiated.
        // no need each sub class to define this method.
        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }
}