# TSE Site Exporter — Product Requirements

## Original Problem Statement
Build a WordPress plugin "TSE Site Exporter" that produces an AI-ready structured website intelligence JSON package: SEO, content hierarchy, links, media, CRO heuristics, Elementor structure, schema, relationship graph, and weighted internal-authority intelligence.

## Architecture
- WordPress plugin (PHP 8.x, native WP APIs only, no external services).
- Modules under `tse-site-exporter/includes/`:
  - `exporter.php` — orchestration, extraction, bundle assembly.
  - `postprocess.php` — hierarchy, anchor frequency, orphans, broken-link check.
  - `schema.php` — JSON-LD extraction & rollup.
  - `relationships.php` — directed internal-link graph + per-page metrics.
  - `authority.php` — weighted edges, PageRank-like authority, strategic classification, clusters, intelligence (V2.3.0).
- Output: ZIP of JSON files (manifest + full export + slices).

## Implemented (Changelog)

### V1.0.0 — Raw content export.
### V2.0.0 — AI-ready structured export (SEO, content, CRO, links, Elementor parsing).
### V2.1.0–2.1.3 — Schema engine (JSON-LD parsing, live HTML), SEO/H1 stabilisation, heading dedupe, content cleanup.
### V2.2.0 — Internal Link Relationship Engine: `internal-link-graph.json`, `orphan-pages.json`, `weak-pages.json`, `relationship-summary.json`. Per-page `relationships` block injected into each PageRecord.

### V2.2.0 hotfix (2026-02)
- Fixed `ArgumentCountError` in `tse_exporter_run` — relationships engine was added but never called/passed through. Wired up + injected per-page metrics into PageRecord.

### V2.3.0 — Weighted Internal Linking Engine (2026-02)
- `authority.php` module.
- Strategic page classifier → money / support / article / service / location / product / category / homepage / other (URL patterns + post-type + schema + CRO + FAQ signals).
- Weighted edge graph: descriptive anchor bonus, high-value-source bonus, generic-anchor penalty, nofollow x0.2.
- PageRank-like internal authority (damping 0.85, 30 iters, no dangling-mass redistribution → isolated pages stay at base).
- Composite scores per page (all 0..100): internal_authority_score, relationship_strength_score, contextual_support_score, incoming_link_quality_score.
- Cluster detection via union-find on undirected graph → main vs isolated clusters.
- Intelligence flags: overlinked (>=p95 incoming AND >=10), under-supported important (strategic ∈ money/service/location/product/category AND authority < median), high-outgoing-weak-incoming.
- New bundle files: `authority-map.json`, `weighted-link-graph.json`, `strategic-pages.json`, `cluster-signals.json`, `intelligence-flags.json`.
- Per-PageRecord `authority` block.

## Backlog / Roadmap
- **P1** AI Recommendations & Analysis Layer — content gap analysis, optimisation suggestions using LLM over the structured datasets.
- **P1** Local SEO analysis — NAP consistency, LocalBusiness completeness, geo-signal scoring.
- **P2** Website replication / asset deployment workflows.
- **P2** Dashboard / UI (post-backend phases).

## Testing
- Manual PHP CLI smoke tests under `/app/smoke_*.php` (WP function stubs + assertions). Run with `php /app/smoke_authority.php` etc.
- Latest passing: `smoke_run_fix.php` (V2.2 regression), `smoke_authority.php` (V2.3 full).
