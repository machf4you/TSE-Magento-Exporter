<?php
/**
 * TSE Site Exporter — Static HTML reports (V2.6.0).
 *
 * Self-contained, framework-free HTML reports rendered from the AI runner
 * outputs PLUS the deterministic AI-summary inputs (page titles, page types,
 * linking signals) so each affected page renders with a readable label, not
 * a raw URL.
 *
 * Three reports are produced:
 *   - ai-report.html              — exec summary + quick wins + recs + gaps
 *   - internal-link-report.html   — refined LLM link opportunities
 *   - cluster-report.html         — findings grouped by cluster_id
 *
 * No JavaScript, no external CSS, no images. Every report is a single file.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry. Returns array of filename => HTML string.
 *
 * @param array $runner_output The {filename => payload} map from the runner.
 * @param array $context       Optional. Keys: pages, linking, site, cluster (the
 *                             deterministic AI summary slices). Enables page
 *                             labels + page-type pills + exec summary + quick
 *                             wins.
 */
function tse_ai_report_build( $runner_output, $context = array() ) {
    $meta = isset( $runner_output['manifest.json'] ) ? $runner_output['manifest.json'] : array();

    $recs     = isset( $runner_output['ai-recommendations.json'] ) ? $runner_output['ai-recommendations.json'] : array( 'items' => array() );
    $links    = isset( $runner_output['ai-internal-link-opportunities.json'] ) ? $runner_output['ai-internal-link-opportunities.json'] : array( 'items' => array() );
    $clusters = isset( $runner_output['ai-cluster-analysis.json'] ) ? $runner_output['ai-cluster-analysis.json'] : array( 'items' => array() );
    $gaps     = isset( $runner_output['ai-content-gap-signals.json'] ) ? $runner_output['ai-content-gap-signals.json'] : array( 'items' => array() );

    $page_index = tse_ai_report_build_page_index( $context );

    return array(
        'ai-report.html'            => tse_ai_report_main( $meta, $recs, $gaps, $links, $context, $page_index ),
        'internal-link-report.html' => tse_ai_report_links( $meta, $links, $page_index ),
        'cluster-report.html'       => tse_ai_report_clusters( $meta, $clusters, $page_index ),
    );
}

/* -------------------------------------------------------------------------
 * Context normalisation: build URL → {title, page_type, path, ...}.
 * ---------------------------------------------------------------------- */
function tse_ai_report_build_page_index( $context ) {
    $idx   = array();
    if ( empty( $context['pages'] ) || ! is_array( $context['pages'] ) ) return $idx;

    $type_label = array(
        'money'    => 'Money Page',
        'service'  => 'Service Page',
        'location' => 'Location Page',
        'product'  => 'Product Page',
        'category' => 'Category Page',
        'article'  => 'Support Article',
        'support'  => 'Support Page',
        'homepage' => 'Homepage',
        'other'    => 'Page',
    );
    $type_color = array(
        'money'    => 'money',
        'service'  => 'service',
        'location' => 'location',
        'product'  => 'product',
        'category' => 'product',
        'article'  => 'article',
        'support'  => 'support',
        'homepage' => 'home',
        'other'    => 'neutral',
    );

    foreach ( $context['pages'] as $p ) {
        $url   = isset( $p['url'] )   ? (string) $p['url']   : '';
        if ( '' === $url ) continue;
        $title = isset( $p['title'] ) ? trim( (string) $p['title'] ) : '';
        $st    = isset( $p['strategic_type'] ) ? (string) $p['strategic_type'] : 'other';
        $parts = wp_parse_url( $url );
        $path  = '/';
        if ( isset( $parts['path'] ) && '' !== $parts['path'] ) $path = rtrim( $parts['path'], '/' ) . '/';
        if ( '' === $path ) $path = '/';

        $idx[ $url ] = array(
            'title'           => '' !== $title ? $title : null,
            'path'            => $path,
            'strategic_type'  => $st,
            'page_type_label' => isset( $type_label[ $st ] ) ? $type_label[ $st ] : $type_label['other'],
            'page_type_class' => isset( $type_color[ $st ] ) ? $type_color[ $st ] : 'neutral',
        );
        // Also index by trailing-slash-normalised variant for resilience.
        $alt = rtrim( $url, '/' );
        if ( $alt !== $url ) $idx[ $alt ] = $idx[ $url ];
        $alt2 = $url . ( substr( $url, -1 ) === '/' ? '' : '/' );
        if ( $alt2 !== $url ) $idx[ $alt2 ] = $idx[ $url ];
    }
    return $idx;
}

function tse_ai_report_lookup_page( $url, $page_index ) {
    if ( isset( $page_index[ $url ] ) ) return $page_index[ $url ];
    $alt = rtrim( $url, '/' );
    if ( isset( $page_index[ $alt ] ) ) return $page_index[ $alt ];
    $alt2 = $url . '/';
    if ( isset( $page_index[ $alt2 ] ) ) return $page_index[ $alt2 ];
    return null;
}

/* -------------------------------------------------------------------------
 * Shared chrome (CSS, header, footer)
 * ---------------------------------------------------------------------- */
function tse_ai_report_css() {
    return <<<CSS
:root { color-scheme: light; }
*,*:before,*:after { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111827; background: #f9fafb; line-height: 1.5; }
.wrap { max-width: 1240px; margin: 0 auto; padding: 32px 24px 64px; }
header.tse-h { background: #111827; color: #f9fafb; padding: 28px 24px; }
header.tse-h .inner { max-width: 1240px; margin: 0 auto; }
header.tse-h h1 { margin: 0 0 6px; font-size: 22px; font-weight: 600; letter-spacing: -0.01em; }
header.tse-h .meta { font-size: 13px; color: #9ca3af; display: flex; flex-wrap: wrap; gap: 16px; margin-top: 8px; }
header.tse-h .meta span strong { color: #e5e7eb; font-weight: 500; }
h2.section { font-size: 16px; font-weight: 600; margin: 32px 0 8px; color: #111827; letter-spacing: -0.005em; display:flex; align-items:center; gap:10px; }
h2.section .count { font-size: 12px; font-weight: 500; color: #6b7280; background: #f3f4f6; padding: 2px 8px; border-radius: 999px; }
.why { font-size: 13px; color: #6b7280; font-style: italic; margin: -2px 0 12px; }
.empty { background: #ffffff; border: 1px dashed #d1d5db; border-radius: 8px; padding: 20px; color: #6b7280; font-size: 14px; }
table.tse { width: 100%; border-collapse: collapse; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; font-size: 14px; table-layout: fixed; }
table.tse th, table.tse td { padding: 12px 14px; text-align: left; vertical-align: top; border-bottom: 1px solid #f3f4f6; word-wrap: break-word; }
table.tse th { background: #f9fafb; font-weight: 600; color: #374151; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
table.tse tr:last-child td { border-bottom: 0; }
table.tse td a { color: #2563eb; text-decoration: none; word-break: break-all; }
table.tse td a:hover { text-decoration: underline; }
.badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; line-height: 1.4; white-space: nowrap; }
.badge.high { background: #fee2e2; color: #991b1b; }
.badge.medium { background: #fef3c7; color: #92400e; }
.badge.low { background: #dcfce7; color: #166534; }
.badge.kind { background: #e0e7ff; color: #3730a3; }
.badge.confidence { background: #f3f4f6; color: #374151; font-variant-numeric: tabular-nums; }
.pt { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; line-height: 1.4; }
.pt.money    { background: #fee2e2; color: #991b1b; }
.pt.service  { background: #ffedd5; color: #9a3412; }
.pt.location { background: #cffafe; color: #155e75; }
.pt.product  { background: #f3e8ff; color: #6b21a8; }
.pt.article  { background: #e0e7ff; color: #3730a3; }
.pt.support  { background: #dcfce7; color: #166534; }
.pt.home     { background: #fef9c3; color: #854d0e; }
.pt.neutral  { background: #f3f4f6; color: #4b5563; }
.pages { display: flex; flex-direction: column; gap: 10px; max-height: 240px; overflow-y: auto; }
.page-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.page-cell .ptitle { font-weight: 500; color: #111827; font-size: 14px; word-break: break-word; }
.page-cell .ppath { font-size: 12px; color: #6b7280; font-family: ui-monospace, SFMono-Regular, monospace; word-break: break-all; }
.page-cell .ppath a { color: #6b7280; }
.page-cell .ppath a:hover { color: #2563eb; }
.page-cell .pmeta { margin-top: 3px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.recommendation { color: #374151; }
.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px 16px; border-radius: 8px; font-size: 14px; margin: 12px 0; }
.error code { background: #fff1f2; padding: 2px 5px; border-radius: 4px; font-size: 12px; }
.anchor-suggest { display: inline-block; background: #ecfdf5; color: #065f46; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-family: ui-monospace, SFMono-Regular, monospace; }
footer.tse-f { color: #6b7280; font-size: 12px; text-align: center; margin: 32px 0 24px; }
.exec { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 12px 0 8px; }
.exec .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; }
.exec .card .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }
.exec .card .num { font-size: 26px; font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; line-height: 1; margin-top: 4px; }
.exec .card.h .num { color: #b91c1c; }
.exec .card.m .num { color: #b45309; }
.exec .card.l .num { color: #166534; }
.exec .card .sub { font-size: 12px; color: #6b7280; }
.qw { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 4px 0; margin-top: 4px; }
.qw .qw-row { display: grid; grid-template-columns: 28px 1fr auto; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #f3f4f6; align-items: flex-start; }
.qw .qw-row:last-child { border-bottom: 0; }
.qw .num-circle { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #111827; color: #ffffff; font-size: 12px; font-weight: 600; margin-top: 2px; }
.qw .qw-row .title { font-weight: 500; }
.qw .qw-row .desc { font-size: 13px; color: #6b7280; margin-top: 3px; }
.qw .qw-row .qw-pages { font-size: 13px; margin-top: 6px; display: flex; flex-direction: column; gap: 4px; }
.qw .qw-row .qw-pages a { color: #2563eb; text-decoration: none; word-break: break-all; }
.qw .qw-row .qw-pages a:hover { text-decoration: underline; }
CSS;
}

function tse_ai_report_header( $title, $meta ) {
    $provider = isset( $meta['provider'] ) ? $meta['provider'] : '';
    $model    = isset( $meta['model'] )    ? $meta['model']    : '';
    $site_url = isset( $meta['site_url'] ) ? $meta['site_url'] : '';
    $site     = isset( $meta['site_name'] )? $meta['site_name']: '';
    $when     = isset( $meta['generated_at'] ) ? $meta['generated_at'] : '';

    $css = tse_ai_report_css();
    $h   = htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' );
    return "<!doctype html>\n"
        . "<html lang=\"en\"><head><meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>" . $h . "</title>\n"
        . "<style>" . $css . "</style>\n"
        . "</head><body>\n"
        . "<header class=\"tse-h\"><div class=\"inner\">\n"
        . "<h1>" . $h . "</h1>\n"
        . "<div class=\"meta\">"
        . "<span><strong>" . htmlspecialchars( (string) $site, ENT_QUOTES, 'UTF-8' ) . "</strong></span>"
        . ( $site_url ? "<span>" . htmlspecialchars( (string) $site_url, ENT_QUOTES, 'UTF-8' ) . "</span>" : "" )
        . ( $provider ? "<span>Provider: <strong>" . htmlspecialchars( (string) $provider, ENT_QUOTES, 'UTF-8' ) . "</strong></span>" : "" )
        . ( $model    ? "<span>Model: <strong>"    . htmlspecialchars( (string) $model,    ENT_QUOTES, 'UTF-8' ) . "</strong></span>" : "" )
        . ( $when     ? "<span>" . htmlspecialchars( (string) $when, ENT_QUOTES, 'UTF-8' ) . "</span>" : "" )
        . "</div></div></header>\n"
        . "<div class=\"wrap\">\n";
}

function tse_ai_report_footer() {
    return "</div>\n<footer class=\"tse-f\">Generated by TSE Site Exporter. All findings are LLM-generated and should be reviewed by a human.</footer>\n"
        . "</body></html>\n";
}

/* -------------------------------------------------------------------------
 * Page cell renderer (title over path + page-type pill)
 * ---------------------------------------------------------------------- */
function tse_ai_report_page_cell( $url, $page_index, $show_pill = true ) {
    $url_safe = htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
    $hit = tse_ai_report_lookup_page( $url, $page_index );
    $title = $hit && ! empty( $hit['title'] ) ? $hit['title'] : '';
    $path  = $hit && ! empty( $hit['path'] )  ? $hit['path']  : $url;
    $path_safe  = htmlspecialchars( (string) $path,  ENT_QUOTES, 'UTF-8' );

    $out = '<div class="page-cell">';
    if ( '' !== $title ) {
        $out .= '<span class="ptitle">' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</span>';
        $out .= '<span class="ppath"><a href="' . $url_safe . '" target="_blank" rel="noopener">' . $path_safe . '</a></span>';
    } else {
        // Fall back to the URL itself as the visible link.
        $out .= '<span class="ptitle"><a href="' . $url_safe . '" target="_blank" rel="noopener">' . $url_safe . '</a></span>';
    }
    if ( $show_pill && $hit ) {
        $out .= '<div class="pmeta"><span class="pt ' . htmlspecialchars( $hit['page_type_class'], ENT_QUOTES, 'UTF-8' ) . '">'
              . htmlspecialchars( $hit['page_type_label'], ENT_QUOTES, 'UTF-8' ) . '</span></div>';
    }
    return $out . '</div>';
}

function tse_ai_report_pages_cell( $urls, $page_index ) {
    if ( empty( $urls ) || ! is_array( $urls ) ) return '<span style="color:#9ca3af">—</span>';
    $out = '<div class="pages">';
    foreach ( $urls as $u ) {
        $out .= tse_ai_report_page_cell( $u, $page_index, false );
    }
    return $out . '</div>';
}

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */
function tse_ai_report_priority_class( $p ) {
    $p = strtolower( (string) $p );
    if ( in_array( $p, array( 'high', 'medium', 'low' ), true ) ) return $p;
    return 'low';
}

function tse_ai_report_priority_rank( $p ) {
    $map = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
    $p   = strtolower( (string) $p );
    return isset( $map[ $p ] ) ? $map[ $p ] : 3;
}

function tse_ai_report_sort_by_priority( $items ) {
    usort( $items, function( $a, $b ) {
        $pa = tse_ai_report_priority_rank( isset( $a['priority'] ) ? $a['priority'] : '' );
        $pb = tse_ai_report_priority_rank( isset( $b['priority'] ) ? $b['priority'] : '' );
        if ( $pa === $pb ) {
            $ca = isset( $a['confidence_score'] ) ? (float) $a['confidence_score'] : 0;
            $cb = isset( $b['confidence_score'] ) ? (float) $b['confidence_score'] : 0;
            return $ca === $cb ? 0 : ( $ca < $cb ? 1 : -1 );
        }
        return $pa < $pb ? -1 : 1;
    } );
    return $items;
}

function tse_ai_report_badge( $text, $class = '' ) {
    return '<span class="badge ' . $class . '">' . htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) . '</span>';
}

function tse_ai_report_confidence_badge( $score ) {
    if ( ! is_numeric( $score ) ) return '';
    $pct = (int) round( ( (float) $score ) * 100 );
    return '<span class="badge confidence">' . $pct . '%</span>';
}

function tse_ai_report_render_error( $data ) {
    if ( ! isset( $data['status'] ) || 'error' !== $data['status'] ) return '';
    $msg  = isset( $data['error'] ) ? $data['error'] : 'Unknown error.';
    $code = isset( $data['error_code'] ) ? $data['error_code'] : '';
    $html = '<div class="error">Analysis failed';
    if ( $code ) $html .= ' (<code>' . htmlspecialchars( (string) $code, ENT_QUOTES, 'UTF-8' ) . '</code>)';
    $html .= ': ' . htmlspecialchars( (string) $msg, ENT_QUOTES, 'UTF-8' ) . '</div>';
    return $html;
}

function tse_ai_report_section_heading( $label, $count ) {
    return '<h2 class="section">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
        . '<span class="count">' . (int) $count . '</span></h2>';
}

function tse_ai_report_why( $text ) {
    return '<p class="why">' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</p>';
}

/* -------------------------------------------------------------------------
 * Executive Summary
 * ---------------------------------------------------------------------- */
function tse_ai_report_executive_summary( $recs, $gaps, $context ) {
    $rec_items = isset( $recs['items'] ) ? $recs['items'] : array();
    $gap_items = isset( $gaps['items'] ) ? $gaps['items'] : array();

    $high = 0; $med = 0;
    foreach ( $rec_items as $it ) {
        $p = strtolower( isset( $it['priority'] ) ? (string) $it['priority'] : '' );
        if ( 'high'   === $p ) $high++;
        if ( 'medium' === $p ) $med++;
    }

    $near_orphans = 0; $weak_money = 0;
    if ( ! empty( $context['linking']['near_orphan_pages'] ) ) $near_orphans = count( $context['linking']['near_orphan_pages'] );
    if ( ! empty( $context['linking']['weak_money_pages'] ) )  $weak_money   = count( $context['linking']['weak_money_pages'] );

    $cannibal = 0;
    foreach ( $gap_items as $g ) {
        $gt = strtolower( isset( $g['gap_type'] ) ? (string) $g['gap_type'] : '' );
        if ( 'cannibalisation' === $gt || 'cannibalization' === $gt || 'topic_overlap' === $gt ) $cannibal++;
    }
    // Also count deterministic duplicate metadata as cannibalisation risks.
    if ( ! empty( $context['linking']['duplicate_meta_titles'] ) )       $cannibal += count( $context['linking']['duplicate_meta_titles'] );
    if ( ! empty( $context['linking']['duplicate_meta_descriptions'] ) ) $cannibal += count( $context['linking']['duplicate_meta_descriptions'] );

    $thin = 0;
    if ( ! empty( $context['pages'] ) ) {
        foreach ( $context['pages'] as $p ) {
            if ( isset( $p['issues'] ) && is_array( $p['issues'] ) && in_array( 'thin_content', $p['issues'], true ) ) $thin++;
        }
    }

    $cards = array(
        array( 'lbl' => 'High Priority Issues',    'num' => $high,         'cls' => 'h', 'sub' => 'From AI recommendations' ),
        array( 'lbl' => 'Medium Priority Issues',  'num' => $med,          'cls' => 'm', 'sub' => 'From AI recommendations' ),
        array( 'lbl' => 'Near-Orphan Pages',       'num' => $near_orphans, 'cls' => 'm', 'sub' => 'Pages with only 1 inbound link' ),
        array( 'lbl' => 'Weak Money Pages',        'num' => $weak_money,   'cls' => 'h', 'sub' => 'Below-median authority' ),
        array( 'lbl' => 'Cannibalisation Risks',   'num' => $cannibal,     'cls' => 'm', 'sub' => 'Duplicate / overlapping signals' ),
        array( 'lbl' => 'Thin Content Signals',    'num' => $thin,         'cls' => 'l', 'sub' => 'Pages under 300 words' ),
    );

    $html  = tse_ai_report_section_heading( 'Executive summary', count( $cards ) );
    $html .= tse_ai_report_why( 'A quick snapshot of where the site is bleeding authority right now.' );
    $html .= '<div class="exec">';
    foreach ( $cards as $c ) {
        $html .= '<div class="card ' . $c['cls'] . '">';
        $html .= '<span class="lbl">' . htmlspecialchars( $c['lbl'], ENT_QUOTES, 'UTF-8' ) . '</span>';
        $html .= '<span class="num">' . (int) $c['num'] . '</span>';
        $html .= '<span class="sub">' . htmlspecialchars( $c['sub'], ENT_QUOTES, 'UTF-8' ) . '</span>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/* -------------------------------------------------------------------------
 * Quick Wins (deterministic, computed from inputs + LLM outputs)
 * ---------------------------------------------------------------------- */
function tse_ai_report_quick_wins( $links, $context, $page_index ) {
    $wins = array();

    // 1. Internal-link wins from the LLM (top 3 high-priority).
    $link_items = isset( $links['items'] ) ? $links['items'] : array();
    $link_items = tse_ai_report_sort_by_priority( $link_items );
    $taken = 0;
    foreach ( $link_items as $it ) {
        if ( $taken >= 3 ) break;
        $src = isset( $it['source_url'] ) ? $it['source_url'] : '';
        $tgt = isset( $it['target_url'] ) ? $it['target_url'] : '';
        if ( ! $src || ! $tgt ) continue;
        $anchor = isset( $it['suggested_anchor'] ) ? $it['suggested_anchor'] : '';
        $wins[] = array(
            'title' => 'Add internal link' . ( $anchor ? ' (anchor: ' . $anchor . ')' : '' ),
            'desc'  => isset( $it['reason'] ) ? $it['reason'] : ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ),
            'pages' => array( $src, $tgt ),
        );
        $taken++;
    }

    // 2. Duplicate meta titles → fix uniqueness.
    if ( ! empty( $context['linking']['duplicate_meta_titles'] ) ) {
        foreach ( array_slice( $context['linking']['duplicate_meta_titles'], 0, 2 ) as $d ) {
            $wins[] = array(
                'title' => 'Fix duplicate meta titles',
                'desc'  => 'Multiple pages share the same meta title — rewrite each to be unique and intent-specific.',
                'pages' => isset( $d['urls'] ) ? $d['urls'] : array(),
            );
        }
    }
    // 3. Missing meta descriptions on important pages.
    if ( ! empty( $context['pages'] ) ) {
        $missing = array();
        foreach ( $context['pages'] as $p ) {
            $issues = isset( $p['issues'] ) && is_array( $p['issues'] ) ? $p['issues'] : array();
            if ( in_array( 'missing_meta_description', $issues, true )
              && in_array( ( isset( $p['strategic_type'] ) ? $p['strategic_type'] : '' ), array( 'money', 'service', 'location', 'product' ), true ) ) {
                $missing[] = $p['url'];
            }
            if ( count( $missing ) >= 5 ) break;
        }
        if ( ! empty( $missing ) ) {
            $wins[] = array(
                'title' => 'Write meta descriptions for high-value pages',
                'desc'  => 'These conversion-focused pages have no meta description — write one with a clear value proposition + call to action.',
                'pages' => $missing,
            );
        }
    }

    // 4. Noindex candidates: thin + non-strategic + low/no incoming.
    if ( ! empty( $context['pages'] ) ) {
        $noindex = array();
        foreach ( $context['pages'] as $p ) {
            $issues = isset( $p['issues'] ) && is_array( $p['issues'] ) ? $p['issues'] : array();
            $st     = isset( $p['strategic_type'] ) ? $p['strategic_type'] : 'other';
            $in_ct  = isset( $p['incoming_link_count'] ) ? (int) $p['incoming_link_count'] : 0;
            if ( 'other' === $st && in_array( 'thin_content', $issues, true ) && $in_ct <= 1 ) {
                $noindex[] = $p['url'];
            }
            if ( count( $noindex ) >= 5 ) break;
        }
        if ( ! empty( $noindex ) ) {
            $wins[] = array(
                'title' => 'Consider noindex on low-value utility pages',
                'desc'  => 'These pages are thin, non-strategic, and barely linked internally — noindexing them concentrates crawl + authority on pages that matter.',
                'pages' => $noindex,
            );
        }
    }

    $html  = tse_ai_report_section_heading( 'Quick wins', count( $wins ) );
    $html .= tse_ai_report_why( 'High-impact, low-effort actions you can ship this week.' );
    if ( empty( $wins ) ) {
        return $html . '<div class="empty">No quick wins detected.</div>';
    }
    $html .= '<div class="qw">';
    $n = 0;
    foreach ( $wins as $w ) {
        $n++;
        $html .= '<div class="qw-row">';
        $html .= '<span class="num-circle">' . $n . '</span>';
        $html .= '<div><div class="title">' . htmlspecialchars( $w['title'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        if ( ! empty( $w['desc'] ) ) {
            $html .= '<div class="desc">' . htmlspecialchars( $w['desc'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        }
        if ( ! empty( $w['pages'] ) ) {
            $html .= '<div class="qw-pages">';
            foreach ( $w['pages'] as $u ) {
                $html .= tse_ai_report_page_cell( $u, $page_index, false );
            }
            $html .= '</div>';
        }
        $html .= '</div><div></div></div>';
    }
    $html .= '</div>';
    return $html;
}

/* -------------------------------------------------------------------------
 * ai-report.html — exec summary + quick wins + recs + gaps
 * ---------------------------------------------------------------------- */
function tse_ai_report_main( $meta, $recs, $gaps, $links, $context, $page_index ) {
    $html  = tse_ai_report_header( 'AI Site Analysis Report', $meta );
    $html .= tse_ai_report_render_error( $recs );

    $html .= tse_ai_report_executive_summary( $recs, $gaps, $context );
    $html .= tse_ai_report_quick_wins( $links, $context, $page_index );

    // Prioritised recommendations
    $rec_items = tse_ai_report_sort_by_priority( isset( $recs['items'] ) ? $recs['items'] : array() );
    $html .= tse_ai_report_section_heading( 'Prioritised recommendations', count( $rec_items ) );
    $html .= tse_ai_report_why( 'Acting on high-priority items first preserves authority where it matters most.' );
    if ( empty( $rec_items ) ) {
        $html .= '<div class="empty">No recommendations were returned.</div>';
    } else {
        $html .= '<table class="tse"><colgroup>'
              . '<col style="width:80px"><col style="width:24%"><col style="width:32%"><col><col style="width:130px">'
              . '</colgroup><thead><tr>'
              . '<th>Priority</th>'
              . '<th>Issue</th>'
              . '<th>Affected pages</th>'
              . '<th>Recommendation</th>'
              . '<th>Type / Confidence</th>'
              . '</tr></thead><tbody>';
        foreach ( $rec_items as $it ) {
            $pr     = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
            $issue  = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
            $rec_t  = htmlspecialchars( (string) ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ), ENT_QUOTES, 'UTF-8' );
            $cat    = isset( $it['category'] ) ? $it['category'] : '';
            $html .= '<tr>'
                  . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
                  . '<td>' . $issue . '</td>'
                  . '<td>' . tse_ai_report_pages_cell( isset( $it['affected_pages'] ) ? $it['affected_pages'] : array(), $page_index ) . '</td>'
                  . '<td class="recommendation">' . $rec_t . '</td>'
                  . '<td>'
                  . ( $cat ? tse_ai_report_badge( $cat, 'kind' ) . '<br>' : '' )
                  . tse_ai_report_confidence_badge( isset( $it['confidence_score'] ) ? $it['confidence_score'] : null )
                  . '</td>'
                  . '</tr>';
        }
        $html .= '</tbody></table>';
    }

    // Content gap signals
    $html .= tse_ai_report_render_error( $gaps );
    $gap_items = tse_ai_report_sort_by_priority( isset( $gaps['items'] ) ? $gaps['items'] : array() );
    $html .= tse_ai_report_section_heading( 'Content gap signals', count( $gap_items ) );
    $html .= tse_ai_report_why( 'Closing topical gaps reduces cannibalisation and lifts under-supported strategic pages.' );
    if ( empty( $gap_items ) ) {
        $html .= '<div class="empty">No content gap signals were returned.</div>';
    } else {
        $html .= '<table class="tse"><colgroup>'
              . '<col style="width:80px"><col style="width:24%"><col style="width:32%"><col><col style="width:130px">'
              . '</colgroup><thead><tr>'
              . '<th>Priority</th>'
              . '<th>Issue</th>'
              . '<th>Affected pages</th>'
              . '<th>Recommendation</th>'
              . '<th>Type / Confidence</th>'
              . '</tr></thead><tbody>';
        foreach ( $gap_items as $it ) {
            $pr    = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
            $issue = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
            $rec_t = htmlspecialchars( (string) ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ), ENT_QUOTES, 'UTF-8' );
            $gtype = isset( $it['gap_type'] ) ? $it['gap_type'] : '';
            $html .= '<tr>'
                  . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
                  . '<td>' . $issue . '</td>'
                  . '<td>' . tse_ai_report_pages_cell( isset( $it['affected_pages'] ) ? $it['affected_pages'] : array(), $page_index ) . '</td>'
                  . '<td class="recommendation">' . $rec_t . '</td>'
                  . '<td>'
                  . ( $gtype ? tse_ai_report_badge( $gtype, 'kind' ) . '<br>' : '' )
                  . tse_ai_report_confidence_badge( isset( $it['confidence_score'] ) ? $it['confidence_score'] : null )
                  . '</td>'
                  . '</tr>';
        }
        $html .= '</tbody></table>';
    }

    return $html . tse_ai_report_footer();
}

/* -------------------------------------------------------------------------
 * internal-link-report.html
 * ---------------------------------------------------------------------- */
function tse_ai_report_links( $meta, $links, $page_index ) {
    $html = tse_ai_report_header( 'Internal-Link Opportunities (LLM)', $meta );
    $html .= tse_ai_report_render_error( $links );

    $items = tse_ai_report_sort_by_priority( isset( $links['items'] ) ? $links['items'] : array() );
    $html .= tse_ai_report_section_heading( 'Refined link opportunities', count( $items ) );
    $html .= tse_ai_report_why( 'Strong internal linking signals page importance to search engines and AI crawlers.' );
    if ( empty( $items ) ) {
        return $html . '<div class="empty">No internal-link opportunities were returned.</div>' . tse_ai_report_footer();
    }

    $html .= '<table class="tse"><colgroup>'
          . '<col style="width:80px"><col style="width:42%"><col style="width:200px"><col><col style="width:90px">'
          . '</colgroup><thead><tr>'
          . '<th>Priority</th>'
          . '<th>Source → Target</th>'
          . '<th>Suggested anchor</th>'
          . '<th>Reason</th>'
          . '<th>Conf.</th>'
          . '</tr></thead><tbody>';
    foreach ( $items as $it ) {
        $pr     = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
        $src    = isset( $it['source_url'] ) ? (string) $it['source_url'] : ( isset( $it['affected_pages'][0] ) ? $it['affected_pages'][0] : '' );
        $tgt    = isset( $it['target_url'] ) ? (string) $it['target_url'] : ( isset( $it['affected_pages'][1] ) ? $it['affected_pages'][1] : '' );
        $anchor = htmlspecialchars( (string) ( isset( $it['suggested_anchor'] ) ? $it['suggested_anchor'] : '' ), ENT_QUOTES, 'UTF-8' );
        $reason = htmlspecialchars( (string) ( isset( $it['reason'] ) ? $it['reason'] : ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ) ), ENT_QUOTES, 'UTF-8' );

        $html .= '<tr>'
              . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
              . '<td>'
              . '<div style="font-size:11px;color:#6b7280;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;margin-bottom:4px">SOURCE</div>'
              . ( $src ? tse_ai_report_page_cell( $src, $page_index, true ) : '<span style="color:#9ca3af">—</span>' )
              . '<div style="font-size:11px;color:#6b7280;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;margin:10px 0 4px">TARGET</div>'
              . ( $tgt ? tse_ai_report_page_cell( $tgt, $page_index, true ) : '<span style="color:#9ca3af">—</span>' )
              . '</td>'
              . '<td>' . ( $anchor ? '<span class="anchor-suggest">' . $anchor . '</span>' : '<span style="color:#9ca3af">—</span>' ) . '</td>'
              . '<td class="recommendation">' . $reason . '</td>'
              . '<td>' . tse_ai_report_confidence_badge( isset( $it['confidence_score'] ) ? $it['confidence_score'] : null ) . '</td>'
              . '</tr>';
    }
    $html .= '</tbody></table>';

    return $html . tse_ai_report_footer();
}

/* -------------------------------------------------------------------------
 * cluster-report.html
 * ---------------------------------------------------------------------- */
function tse_ai_report_clusters( $meta, $clusters, $page_index ) {
    $html = tse_ai_report_header( 'Cluster Analysis (LLM)', $meta );
    $html .= tse_ai_report_render_error( $clusters );

    $items = isset( $clusters['items'] ) ? $clusters['items'] : array();
    $by_cluster = array();
    foreach ( $items as $it ) {
        $cid = isset( $it['cluster_id'] ) ? (int) $it['cluster_id'] : -1;
        $by_cluster[ $cid ][] = $it;
    }
    ksort( $by_cluster );

    $html .= tse_ai_report_section_heading( 'Findings', count( $items ) );
    $html .= tse_ai_report_why( 'Isolated clusters miss out on authority distribution from the main site graph.' );
    if ( empty( $items ) ) {
        return $html . '<div class="empty">No cluster findings were returned.</div>' . tse_ai_report_footer();
    }

    foreach ( $by_cluster as $cid => $cluster_items ) {
        $cluster_items = tse_ai_report_sort_by_priority( $cluster_items );
        $label = $cid >= 0 ? ( 'Cluster #' . $cid ) : 'Unclustered findings';
        $html .= '<h2 class="section" style="margin-top:24px">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
              . '<span class="count">' . count( $cluster_items ) . '</span></h2>';
        $html .= '<table class="tse"><colgroup>'
              . '<col style="width:80px"><col style="width:24%"><col style="width:32%"><col><col style="width:130px">'
              . '</colgroup><thead><tr>'
              . '<th>Priority</th>'
              . '<th>Issue</th>'
              . '<th>Affected pages</th>'
              . '<th>Recommendation</th>'
              . '<th>Type / Confidence</th>'
              . '</tr></thead><tbody>';
        foreach ( $cluster_items as $it ) {
            $pr     = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
            $issue  = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
            $rec_t  = htmlspecialchars( (string) ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ), ENT_QUOTES, 'UTF-8' );
            $ftype  = isset( $it['finding_type'] ) ? $it['finding_type'] : '';
            $html  .= '<tr>'
                   . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
                   . '<td>' . $issue . '</td>'
                   . '<td>' . tse_ai_report_pages_cell( isset( $it['affected_pages'] ) ? $it['affected_pages'] : array(), $page_index ) . '</td>'
                   . '<td class="recommendation">' . $rec_t . '</td>'
                   . '<td>'
                   . ( $ftype ? tse_ai_report_badge( $ftype, 'kind' ) . '<br>' : '' )
                   . tse_ai_report_confidence_badge( isset( $it['confidence_score'] ) ? $it['confidence_score'] : null )
                   . '</td>'
                   . '</tr>';
        }
        $html .= '</tbody></table>';
    }

    return $html . tse_ai_report_footer();
}
