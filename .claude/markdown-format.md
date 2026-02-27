# Markdown Format Reference

This is the definitive format reference for markdown content files processed by the `djebel-markdown` plugin.

## File Structure

Every markdown content file has two parts:

```
---
YAML frontmatter (metadata)
---

Markdown body (content)
```

## Frontmatter Specification

Frontmatter is enclosed between two `---` lines at the very start of the file. Fields use `key: value` syntax.

### Field Reference

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `title` | string | Yes | — | Article title |
| `summary` | string | Yes | — | 1-2 sentence description |
| `author` | string | Yes | — | Author name |
| `creation_date` | string | Yes | file mtime | Format: `YYYY-MM-DD HH:MM:SS` |
| `category` | string | Yes | — | Single category name |
| `tags` | string/array | Yes | `[]` | Comma-separated or YAML array |
| `status` | string | Yes | — | `published` or `draft` |
| `hash` | string | Recommended | from filename | 12-char hex ID for URLs |
| `publish_date` | string | No | `creation_date` | Scheduled publication date |
| `slug` | string | No | from filename | Custom URL-friendly slug |
| `sort_order` | integer | No | `0` | Numeric sort position |
| `meta_title` | string | No | `title` | SEO title override |
| `meta_keywords` | string | No | — | SEO keywords |
| `meta_description` | string | No | — | SEO description |

### Tags Format

Both formats are accepted:

```yaml
# Comma-separated string (converted to array internally)
tags: plugins, development, hooks, tutorial

# YAML array
tags: [plugins, development, hooks, tutorial]
```

### Date Format

Always use: `YYYY-MM-DD HH:MM:SS`

```yaml
creation_date: 2026-02-24 10:00:00
publish_date: 2026-02-24 10:00:00
```

### Status Values

- `published` - Content is live and visible
- `draft` - Content is hidden from listings and not served

### Hash / ID Field

The hash uniquely identifies content for URL generation and cross-linking. It can also appear as `hash_id` or `id` in frontmatter (checked in that order).

Generate a new hash:
```bash
openssl rand -hex 6
```

## Full Example: Blog Post

```markdown
---
title: Building Plugins with Djebel
summary: A practical guide to creating your first Djebel plugin, from file structure to hooks and filters.
author: Djebel Team
creation_date: 2026-02-24 10:00:00
category: Development
tags: plugins, development, hooks, tutorial
status: published
hash: 7a3f9b2c1d4e
---

# Building Plugins with Djebel

Djebel's plugin system is designed to be simple. No complex patterns to learn.

---

## Plugin File Structure

Every plugin needs a `plugin.php` file in its directory.

---

## Registering Hooks

Use hooks to extend the framework. See (@dj:abc123def456) for the hooks reference.

---

## Your Next Step

Create your first plugin directory and start building.
```

## Full Example: Documentation Page

```markdown
---
title: Introduction to Djebel
slug: introduction
summary: Getting started with the Djebel framework.
author: Documentation Team
creation_date: 2025-01-10
publish_date: 2025-01-10
category: Documentation
tags: [intro, getting-started, framework]
status: published
---

# Introduction to Djebel

Welcome to the Djebel framework documentation.

## What is Djebel?

Djebel is a lightweight PHP framework designed for building web applications.

## Key Features

- Plugin-based architecture
- Theme support
- Markdown support
- Multi-language support
```

## Content Linking

Link to other content items using the `(@dj:hash_id)` syntax. The hash must be 10-15 alphanumeric characters.

### Three Link Forms

```markdown
# 1. Bare - auto-uses target's title as link text
Check out (@dj:abc123def456)

# 2. Empty brackets - also auto-titled
See also [](@dj:abc123def456)

# 3. Custom text
Read the [Getting Started Guide](@dj:abc123def456)
```

These are processed before markdown conversion. The plugin resolves the hash to a URL and title via the `app.plugins.markdown.resolve_content_reference` hook (handled by `djebel-static-content`).

## Safe Mode

The markdown parser runs in safe mode. This means:

- Raw HTML tags in markdown content are **escaped** (shown as text, not rendered)
- You cannot embed `<div>`, `<span>`, `<script>`, or any HTML tags
- Use markdown syntax only for formatting
- This is a security feature to prevent XSS

## Line Breaks

Newlines in markdown are converted to `<br>` tags. A single newline creates a line break (unlike standard markdown which requires two spaces or a blank line).

## Markdown Syntax Quick Reference

```markdown
# H1 Heading
## H2 Heading
### H3 Heading

**Bold text**
*Italic text*

- Bullet list item
- Another item

1. Numbered list
2. Second item

[Link text](https://example.com)

`inline code`

\`\`\`
code block
\`\`\`

> Blockquote

--- (horizontal rule)
```
