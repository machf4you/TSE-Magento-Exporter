# TSE Site Exporter

A lightweight WordPress plugin that adds a single button under **Tools → TSE Site Exporter** to export all public pages, posts, products (WooCommerce) and any registered public custom post types into a structured JSON file packaged as a downloadable ZIP.

No dashboards, no charts, no AI, no frontend output — just clean data extraction.

## Features

- Single admin page under **Tools → TSE Site Exporter**.
- One button: **Export Site Data**.
- Exports every **public** post type (posts, pages, WooCommerce products, custom post types). `attachment` is excluded.
- Only posts with status `publish` are included.
- Each post entry includes:
  - Core fields: `id`, `post_type`, `slug`, `title`, `status`, `permalink`, `date_gmt`, `modified_gmt`, `menu_order`, `parent`, `comment_status`, `ping_status`, `excerpt`, `content`.
  - `author` (id, login, display name).
  - `featured_image` URL.
  - `taxonomies` — all terms grouped by taxonomy.
  - `meta` — public post meta (internal keys starting with `_` are skipped, except common useful ones like `_thumbnail_id`, `_wp_page_template`).
- Output is wrapped in a top-level `meta` block with site/plugin/export details.
- File is packaged as ZIP using PHP's `ZipArchive`.

## Installation

### Option 1 — Upload via WordPress admin
1. Download the plugin ZIP (`tse-site-exporter.zip`).
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Choose `tse-site-exporter.zip` and click **Install Now**.
4. Click **Activate Plugin**.

### Option 2 — Manual install
1. Unzip `tse-site-exporter.zip`.
2. Upload the `tse-site-exporter/` folder to `/wp-content/plugins/` on your server.
3. In WordPress admin go to **Plugins** and activate **TSE Site Exporter**.

## Usage

1. Go to **Tools → TSE Site Exporter**.
2. Click **Export Site Data**.
3. Your browser will download `tse-site-export-<site-slug>-<timestamp>.zip`.
4. Unzip it to find `tse-site-export-<site-slug>-<timestamp>.json`.

## Output structure

```json
{
  "meta": {
    "plugin": "TSE Site Exporter",
    "plugin_version": "1.0.0",
    "site_url": "https://example.com",
    "site_name": "Example",
    "wp_version": "6.x",
    "exported_at": "2026-01-15T10:00:00+00:00",
    "post_types": ["post", "page", "product", "..."],
    "status_filter": "publish"
  },
  "content": {
    "post":    [ { ...post... }, ... ],
    "page":    [ { ...page... }, ... ],
    "product": [ { ...product... }, ... ],
    "custom":  [ { ...cpt... }, ... ]
  }
}
```

## Requirements

- WordPress 5.0+
- PHP 7.2+
- PHP `zip` extension (`ZipArchive` class).

## Permissions

Only users with the `manage_options` capability (typically Administrators) can access the page and trigger the export. The export form is protected with a WordPress nonce.

## Notes

- For very large sites the export runs in a single request; ensure your PHP `max_execution_time` and `memory_limit` are reasonable. The plugin calls `set_time_limit(0)` to avoid timeouts where allowed.
- The export only includes post-type content. Users, comments, options, menus, widgets, theme settings, etc. are intentionally out of scope.
