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
Dj_App_Hooks::addFilter('app.plugins.markdown.parse_markdown_front_matter', [ $obj, 'parseFrontMatter' ] );

class Djebel_App_Plugin_Markdown {
    private $parser = null;

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

            $this->parser->setSafeMode(true);
        }

        if (empty($this->parser)) {
            return $content;
        }

        $content = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.pre_process_content', $content, $ctx );
        $markdown_content = $this->parser->text($content);
        $markdown_content = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.post_process_content', $markdown_content, $ctx );

        return $markdown_content;
    }

    /**
     * Parses frontmatter from markdown content.
     * Extracts metadata between --- delimiters and returns parsed data.
     *
     * @param string $content Full markdown content with frontmatter
     * @param array $ctx Context information
     * @return array Parsed frontmatter data
     */
    public function parseFrontMatter($content, $ctx = [])
    {
        $data = [];

        if (empty($content)) {
            return $data;
        }

        // Split on --- delimiters
        $parts = explode('---', $content, 3);

        if (count($parts) < 3) {
            return $data;
        }

        // Parse frontmatter (between first and second ---)
        $frontmatter_text = trim($parts[1]);

        if (empty($frontmatter_text)) {
            return $data;
        }

        $lines = explode("\n", $frontmatter_text);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $colon_pos = strpos($line, ':');

            if ($colon_pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $colon_pos));
            $value = trim(substr($line, $colon_pos + 1));

            if (empty($key)) {
                continue;
            }

            // Handle array notation: [item1, item2, item3]
            if (!empty($value) && $value[0] === '[' && substr($value, -1) === ']') {
                $array_content = substr($value, 1, -1);
                $items = explode(',', $array_content);
                $items = Dj_App_String_Util::trim($items);
                $parsed_items = [];

                foreach ($items as $item) {
                    if (!empty($item)) {
                        $parsed_items[] = $item;
                    }
                }

                $data[$key] = $parsed_items;
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
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