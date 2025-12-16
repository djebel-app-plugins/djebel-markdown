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
        // Load on demand
        if (!class_exists('Djebel_Plugin_Markdown_Shared_Parsedown')) {
            if (class_exists('Parsedown')) {
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

        // ? header Skip frontmatter if present to speed things up.
        if ($first_char == '-') {
            $content = Dj_App_String_Util::trim($content, '-'); // trim the first few chars so we can search for the second ---

            // we're searching for the second ---
            $end_str_pos = strpos($content, $this->frontmatter_delimiter);

            if ($end_str_pos !== false) {
                $offset = $end_str_pos + strlen($this->frontmatter_delimiter);
                $content = substr($content, $offset); // until end of time
                $content = Dj_App_String_Util::trim($content, '-'); // could there be more dashes than 3 --- ?
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
     * Filter page content - convert markdown if ext is 'md'
     * Hooks into app.page.content with high priority for early processing
     *
     * @param string $content
     * @param array $ctx
     * @return string
     */
    public function filterPageContent($content, $ctx = [])
    {
        $ext = empty($ctx['ext']) ? '' : $ctx['ext'];

        if ($ext !== 'md') {
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
                $res_obj = Dj_App_File_Util::readPartially($file, $buffer_size);

                if ($res_obj->isError()) {
                    throw new Dj_App_Exception('Error reading file', [ 'file' => $file ]);
                }

                $content = $res_obj->output;
                $content = Dj_App_String_Util::trim($content);

                if (empty($content)) {
                    throw new Dj_App_Exception('Empty content', [ 'file' => $file ]);
                }
            }
            
            $res_obj->content = $content;
            $first_char = Dj_App_String_Util::getFirstChar($content);

            // no header
            if (empty($first_char) || $first_char != '-') {
                throw new Dj_App_Exception('Missing header');
            }

            $small_content = Dj_App_String_Util::cut($content, $buffer_size);
            $small_content = Dj_App_String_Util::trim($small_content, '-');

            // Find closing ---
            $closing_delimiter_pos = strpos($small_content, $this->frontmatter_delimiter);

            if ($closing_delimiter_pos === false) {
                throw new Dj_App_Exception('Missing closing ---');
            }

            // Extract frontmatter text
            $frontmatter_text = substr($small_content, 0, $closing_delimiter_pos);
            $frontmatter_text = Dj_App_String_Util::trim($frontmatter_text, '-'); // in case there are more -

            if (empty($frontmatter_text)) {
                return $res_obj;
            }

            // skip header
            if ($read_full_content) {
                $content_trimmed = Dj_App_String_Util::trim($content, '-');
                $offset = $closing_delimiter_pos + strlen($this->frontmatter_delimiter);
                $remaining_content = substr($content_trimmed, $offset);
                $remaining_content = Dj_App_String_Util::trim($remaining_content);
                $res_obj->content = $remaining_content;
            }

            // Use existing utility to parse metadata
            $meta_res = Dj_App_Util::extractMetaInfo($frontmatter_text);

            if ($meta_res->isError()) {
                return $res_obj;
            }

            $meta = $meta_res->data();
            $meta = empty($meta) || !is_array($meta) ? [] : $meta;

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
                // Performance: check first char before cutting/normalizing (early exit for 99% of files)
                $search_buffer = Dj_App_String_Util::cut($remaining_content, 150);
                $search_buffer = Dj_App_String_Util::normalizeNewLines($search_buffer);

                // Find end of first line
                $newline_pos = strpos($search_buffer, "\n");
                $title_line = ($newline_pos !== false) ? substr($search_buffer, 0, $newline_pos) : $search_buffer;

                // Check if h1 (single # only)
                if (strspn($title_line, '#') === 1) {
                    // Extract and set title (trim removes # and whitespace)
                    $meta['title'] = Dj_App_String_Util::trim($title_line, '#');

                    // Remove from content (normalize full content only when needed)
                    $line_end = ($newline_pos !== false) ? $newline_pos + 1 : strlen($search_buffer);
                    $content_normalized = Dj_App_String_Util::normalizeNewLines($remaining_content);
                    $remaining_content = substr($content_normalized, $line_end);
                    $remaining_content = Dj_App_String_Util::trim($remaining_content);
                    $res_obj->content = $remaining_content;

                    $ctx['title_extracted_from_content'] = true;
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