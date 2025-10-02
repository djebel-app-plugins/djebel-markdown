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
Dj_App_Hooks::addFilter('app.content.markdown', [ $obj, 'processMarkdown' ] );

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
            } else { // custom class prefixed
                require_once __DIR__ . '/shared/parsedown/Parsedown.php';
                $this->parser = new Djebel_Plugin_Markdown_Shared_Parsedown();
            }

            $this->parser->setSafeMode(true);
        }

        if (empty($this->parser)) {
            return $content;
        }

        $content = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.pre_process', $content, $ctx );
        $markdown_content = $this->parser->text($content);
        $markdown_content = Dj_App_Hooks::applyFilter( 'app.plugins.markdown.post_process', $markdown_content, $ctx );

        return $markdown_content;
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