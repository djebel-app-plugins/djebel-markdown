# djebel-markdown Plugin

## What This Plugin Does

Converts markdown to HTML and parses YAML frontmatter from content files. Built on Parsedown v1.8.0.

**This plugin is a dependency of `djebel-static-content` and `djebel-faq`.**

## Capabilities

### 1. Markdown-to-HTML Conversion
- Standard markdown syntax (headings, lists, bold, italic, links, code blocks, etc.)
- Safe mode ON: raw HTML tags are escaped (security)
- Line breaks: newlines are converted to `<br>`
- Filter hook: `app.plugins.markdown.convert_markdown`

### 2. Frontmatter Parsing
- Extracts YAML-like metadata from between `---` delimiters at the start of files
- Returns a `Dj_App_Result` object with `->meta` (parsed fields) and `->content` (body)
- Default buffer: reads first 2KB for frontmatter (configurable)
- Can read full file with `$ctx['full'] => true`
- Filter hook: `app.plugins.markdown.parse_front_matter`

### 3. Auto-Detection
- Detects markdown by file extension (`.md`)
- Also detects by content: starts with `#` or `---`

### 4. Title Extraction
- Automatically extracts H1 (`# Title`) from content body
- Removes the H1 from body to prevent duplication when theme renders it separately

### 5. Content Linking
Special syntax for linking between content items:

```markdown
(@dj:abc123def456)              <- Auto-titled (uses target's title)
[](@dj:abc123def456)            <- Auto-titled (empty brackets)
[Custom Text](@dj:abc123def456) <- Custom link text
```

Hash must be 10-15 alphanumeric characters. Resolved via `app.plugins.markdown.resolve_content_reference` hook.

## Filter Hooks

| Hook | Description |
|------|-------------|
| `app.plugins.markdown.convert_markdown` | Convert markdown to HTML |
| `app.plugins.markdown.parse_front_matter` | Parse frontmatter from file/string |
| `app.plugins.markdown.pre_parse.content` | Before processing starts |
| `app.plugins.markdown.pre_process_content` | Before Parsedown conversion |
| `app.plugins.markdown.post_process_content` | After Parsedown (receives HTML) |
| `app.plugins.markdown.parser_init_obj` | Customize Parsedown instance |
| `app.plugins.markdown.parse_front_matter_buff_size` | Override buffer size |
| `app.plugins.markdown.parse_front_matter_title` | Process extracted titles |
| `app.plugins.markdown.resolve_content_reference` | Resolve `(@dj:hash)` links |

## Parsedown Configuration

- Safe mode: ON (raw HTML escaped, prevents XSS)
- Breaks enabled: ON (newlines become `<br>`)
- Markup escaped: ON (HTML tags in markdown are escaped)

This means you cannot embed raw HTML in markdown files. All HTML tags will be escaped and shown as text.

## Guide

- [markdown-format.md](markdown-format.md) - Complete frontmatter format reference with examples
