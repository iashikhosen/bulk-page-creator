# Bulk Page Creation

A simple WordPress plugin for creating pages and unlimited nested parent/child page structures from a plain-text hierarchy.

## Features

* Create multiple WordPress pages at once
* Supports unlimited nested page levels
* Uses a simple dash-based hierarchy
* Preserves the order of pages
* Preview the page structure before creating
* Reuses existing pages when the parent relationship matches
* Shows created, existing, and error results
* Built-in prompt for generating the required format
* One-click **Copy Prompt** button
* Clean, lightweight WordPress admin interface
* No external dependencies

## Format

Use one page per line.

A page without a dash is a top-level page:

```text
Work
About
Contact
```

A single dash creates a child page:

```text
Work
- Portfolio
- Case Studies
```

Multiple dashes create deeper levels:

```text
Work
- Portfolio
-- WordPress
--- Elementor
--- Bricks
-- LMS
- Case Studies
-- LearnDash
-- Membership
About
Contact
```

The hierarchy is interpreted as:

```text
Work
├── Portfolio
│   ├── WordPress
│   │   ├── Elementor
│   │   └── Bricks
│   └── LMS
│
├── Case Studies
│   ├── LearnDash
│   └── Membership
│
├── About
└── Contact
```

You can continue nesting with as many levels as needed.

## How It Works

1. Open **Tools → Bulk Page Creation**.
2. Enter your page hierarchy in the text editor.
3. Click **Preview** to review the structure.
4. Click **Create Pages**.
5. The plugin creates the pages using WordPress's native Pages system.

The order you provide is preserved during creation.

## Using the Built-in Prompt

The **How it works** section includes a **Copy Prompt** button.

Click it to copy a ready-to-use generic prompt. You can paste that prompt into any AI assistant along with your page requirements.

The prompt instructs the assistant to return only the required hierarchy format, for example:

```text
Work
- Portfolio
-- WordPress
--- Elementor
--- Bricks
- Case Studies
About
Contact
```

Copy the generated hierarchy and paste it directly into Bulk Page Creation.

## Existing Pages

If a page already exists with the same title and the correct parent, the plugin reuses it instead of creating a duplicate.

If the same title exists but has a different parent, the plugin creates the required page structure separately.

## Requirements

* WordPress 5.8+
* PHP 7.4+
* User capability: `manage_options`

## Installation

### WordPress Admin

1. Download the plugin.
2. Go to **Plugins → Add New → Upload Plugin**.
3. Upload the plugin ZIP.
4. Activate **Bulk Page Creation**.
5. Go to **Tools → Bulk Page Creation**.

### Manual Installation

Copy the plugin folder into:

```text
/wp-content/plugins/
```

Then activate it from:

**WordPress → Plugins**

## Plugin Structure

This plugin is intentionally lightweight and currently uses a single PHP file.

```text
bulk-page-creation/
└── bulk-page-creation.php
```

## License

GPL-2.0-or-later

## Author

**Ashik Hosen**

https://ashikhosen.com
