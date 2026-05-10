# PRD — TSE Site Exporter (WordPress Plugin)

## Original Problem Statement
Build a WordPress plugin called "TSE Site Exporter".
- Adds an admin page under Tools with one button: "Export Site Data".
- On click, exports all public pages, posts, products and custom post types into a structured JSON file, compresses it into a ZIP, and lets the ZIP be downloaded.
- Focus only on clean data extraction. No dashboard, no charts, no AI features, no frontend output.

## Architecture
- Single PHP plugin file (`tse-site-exporter.php`) + `README.md`.
- Hooks:
  - `admin_menu` → adds Tools → TSE Site Exporter page.
  - `admin_post_tse_site_exporter_export` → form handler that builds JSON, packages with `ZipArchive`, streams ZIP as download.
- Capability gate: `manage_options`.
- Nonce-protected form (`tse_site_exporter_export`).

## User Personas
- **Site Administrator**: needs a one-click structured export of all content for migration, backup, audit, or piping into another system.

## Core Requirements (static)
1. Tools admin page with a single button.
2. Export covers all public post types (posts, pages, products, public CPTs); excludes `attachment`.
3. Only `publish` status.
4. Each post: core fields + post meta + taxonomies + featured image URL.
5. JSON wrapped in a ZIP, downloaded directly.
6. No frontend output, no dashboards/charts/AI.

## What's Implemented (2026-01)
- Admin menu under Tools.
- Single "Export Site Data" button (with `data-testid="tse-export-site-data-button"`).
- Server-side handler: capability + nonce check, paginated `WP_Query` per public post type, filtered to `publish`.
- Per-post payload: id, post_type, slug, title, status, permalink, dates, menu_order, parent, comment/ping status, author info, excerpt, content, featured_image URL, taxonomies (all terms grouped), meta (public meta, plus `_thumbnail_id` and `_wp_page_template`).
- Top-level `meta` block: plugin info, site URL/name, WP version, export timestamp, list of post types, status filter.
- ZIP creation via `ZipArchive` in uploads dir, streamed as `application/zip` download, then deleted.
- README with install + usage + output schema.
- Distributable: `/app/tse-site-exporter.zip`.

## Validation
- `php -l` clean on `tse-site-exporter.php`.
- Smoke test verified `ZipArchive` + JSON encode/decode round-trip.
- (Note: Full end-to-end test requires a live WordPress instance; not part of this environment.)

## Backlog / Future
- P1: Chunked / background export via Action Scheduler for very large sites.
- P1: Optional inclusion of media files (attachments) and users/comments.
- P2: CSV / NDJSON output formats.
- P2: Filter by date range or specific post types via UI.
- P2: WP-CLI command (`wp tse-export`).

## Next Action Items
- User installs `/app/tse-site-exporter.zip` on a WordPress site and runs an export to verify against real content.
