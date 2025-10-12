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

Dj_App_Hooks::addFilter('app.plugins.markdown.parse_markdown', [ $obj, 'processMarkdown' ] );
Dj_App_Hooks::addFilter('app.plugins.markdown.parse_front_matter', [ $obj, 'parseFrontMatter' ] );

class Djebel_App_Plugin_Markdown {
    private $parser = null;

    /**
     * @desc when we read the frontmatter/header of a markdown we read it partially.
     */
    private $buffer_size = 512;

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
            $end_str = '---';
            $end_str_pos = strpos($content, $end_str);

            if ($end_str_pos !== false) {
                $offset = $end_str_pos + strlen($end_str);
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

            // Find closing ---
            $end_pos = strpos($small_content, '---');

            if ($end_pos === false) {
                throw new Dj_App_Exception('Missing closing ---');
            }

            // Extract frontmatter text
            $frontmatter_text = substr($small_content, 0, $end_pos);
            $frontmatter_text = Dj_App_String_Util::trim($frontmatter_text, '-'); // in case there are more -

            if (empty($frontmatter_text)) {
                return $res_obj;
            }

            // skip header
            if ($read_full_content) {
                $frontmatter_len = strlen($frontmatter_text);
                $content = substr($content, $frontmatter_len);
                $res_obj->content = $content;
            }

            // Use existing utility to parse metadata
            $meta_res = Dj_App_Util::extractMetaInfo($frontmatter_text);

            if ($meta_res->isError()) {
                return $res_obj;
            }

            $res_obj->status(true);            
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
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