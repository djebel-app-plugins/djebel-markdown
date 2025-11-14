# Djebel Markdown Plugin

Provides filters for markdown syntax conversion using Parsedown library.

## Features

- Converts markdown to HTML
- Parses frontmatter metadata from markdown files
- Supports content linking between markdown documents
- Safe mode enabled (prevents raw HTML injection)
- Automatic line break conversion
- HTML markup escaping for security

## Content Linking

The plugin supports linking between content using the `(@dj:hash_id)` syntax. This allows you to reference other content by its hash_id without needing to know the full URL.

### Syntax Options

**1. Bare syntax (auto-title):**
```markdown
Check out (@dj:abc123def456)
```
Automatically inserts the referenced content's title as the link text.

**2. Empty brackets (auto-title):**
```markdown
See also [](@dj:abc123def456)
```
Same as bare syntax - automatically uses the title from the referenced content.

**3. Custom link text:**
```markdown
Read the [Getting Started Guide](@dj:abc123def456)
```
Uses your custom text while linking to the content.

### How It Works

1. The plugin scans markdown content for `(@dj:hash_id)` patterns
2. Calls filter `app.plugins.markdown.resolve_content_reference` to resolve the hash_id
3. Other plugins (like djebel-static-content) provide the URL and title
4. Replaces the pattern with standard markdown links: `[title](url)`
5. Parsedown then converts to HTML

### Performance

- Uses efficient `strpos()` scanning (no heavy regex)
- Only backtracks up to 200 characters when looking for brackets
- Hash_id validation: 10-15 alphanumeric characters
- Relies on cached content data (no file I/O during conversion)

## Filters

### app.plugins.markdown.convert_markdown
Converts markdown text to HTML.

### app.plugins.markdown.parse_front_matter
Parses frontmatter from markdown files with `---` delimiters.

### app.plugins.markdown.resolve_content_reference
Resolves content references in `(@dj:hash_id)` syntax. Other plugins can hook into this to provide resolution logic.

**Expected format:**
```php
$reference_data = [
    'hash_id' => 'abc123def456',  // The content hash to resolve
    'link_text' => 'Custom text',  // Empty string for auto-title
    'url' => '',                   // Plugin should fill this
    'title' => '',                 // Plugin should fill this
];
```

### app.plugins.markdown.pre_parse.content
Fires before markdown processing. Use for content preprocessing.

### app.plugins.markdown.pre_process_content
Fires before Parsedown conversion. Content links are processed here.

### app.plugins.markdown.post_process_content
Fires after Parsedown conversion, receives HTML output.

## Requirements

- PHP 7.4+
- Djebel App 1.0.0+
- Parsedown library (bundled in shared/ directory)

## Version

1.0.0
