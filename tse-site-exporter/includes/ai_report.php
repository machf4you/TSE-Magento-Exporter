<?php
/**
 * TSE Site Exporter — Static HTML reports (V2.5.1).
 *
 * Renders the AI runner outputs as self-contained, framework-free HTML reports
 * grouped by priority/type with colour-coded severity and clickable URLs.
 * No JavaScript, no external CSS, no images. Every report is a single file.
 *
 * Three reports are produced:
 *   - ai-report.html              — master: recommendations + content-gap signals.
 *   - internal-link-report.html   — refined LLM link opportunities.
 *   - cluster-report.html         — per-cluster findings.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry. Returns array of filename => HTML string.
 */
function tse_ai_report_build( $runner_output ) {
    $meta = isset( $runner_output['manifest.json'] ) ? $runner_output['manifest.json'] : array();

    $recs     = isset( $runner_output['ai-recommendations.json'] ) ? $runner_output['ai-recommendations.json'] : array( 'items' => array() );
    $links    = isset( $runner_output['ai-internal-link-opportunities.json'] ) ? $runner_output['ai-internal-link-opportunities.json'] : array( 'items' => array() );
    $clusters = isset( $runner_output['ai-cluster-analysis.json'] ) ? $runner_output['ai-cluster-analysis.json'] : array( 'items' => array() );
    $gaps     = isset( $runner_output['ai-content-gap-signals.json'] ) ? $runner_output['ai-content-gap-signals.json'] : array( 'items' => array() );

    return array(
        'ai-report.html'            => tse_ai_report_main( $meta, $recs, $gaps ),
        'internal-link-report.html' => tse_ai_report_links( $meta, $links ),
        'cluster-report.html'       => tse_ai_report_clusters( $meta, $clusters ),
    );
}

/* -------------------------------------------------------------------------
 * Shared chrome (CSS, header, footer)
 * ---------------------------------------------------------------------- */
function tse_ai_report_css() {
    return <<<CSS
:root { color-scheme: light; }
*,*:before,*:after { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111827; background: #f9fafb; line-height: 1.5; }
.wrap { max-width: 1100px; margin: 0 auto; padding: 32px 24px 64px; }
header.tse-h { background: #111827; color: #f9fafb; padding: 28px 24px; }
header.tse-h .inner { max-width: 1100px; margin: 0 auto; }
header.tse-h h1 { margin: 0 0 6px; font-size: 22px; font-weight: 600; letter-spacing: -0.01em; }
header.tse-h .meta { font-size: 13px; color: #9ca3af; display: flex; flex-wrap: wrap; gap: 16px; margin-top: 8px; }
header.tse-h .meta span strong { color: #e5e7eb; font-weight: 500; }
h2.section { font-size: 16px; font-weight: 600; margin: 32px 0 12px; color: #111827; letter-spacing: -0.005em; display:flex; align-items:center; gap:10px; }
h2.section .count { font-size: 12px; font-weight: 500; color: #6b7280; background: #f3f4f6; padding: 2px 8px; border-radius: 999px; }
.empty { background: #ffffff; border: 1px dashed #d1d5db; border-radius: 8px; padding: 20px; color: #6b7280; font-size: 14px; }
table.tse { width: 100%; border-collapse: collapse; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; font-size: 14px; }
table.tse th, table.tse td { padding: 12px 14px; text-align: left; vertical-align: top; border-bottom: 1px solid #f3f4f6; }
table.tse th { background: #f9fafb; font-weight: 600; color: #374151; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
table.tse tr:last-child td { border-bottom: 0; }
table.tse td a { color: #2563eb; text-decoration: none; word-break: break-all; }
table.tse td a:hover { text-decoration: underline; }
.badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; line-height: 1.4; }
.badge.high { background: #fee2e2; color: #991b1b; }
.badge.medium { background: #fef3c7; color: #92400e; }
.badge.low { background: #dcfce7; color: #166534; }
.badge.kind { background: #e0e7ff; color: #3730a3; }
.badge.confidence { background: #f3f4f6; color: #374151; font-variant-numeric: tabular-nums; }
.urls { display: flex; flex-direction: column; gap: 4px; max-height: 110px; overflow-y: auto; font-size: 13px; }
.recommendation { color: #374151; }
.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px 16px; border-radius: 8px; font-size: 14px; margin: 12px 0; }
.error code { background: #fff1f2; padding: 2px 5px; border-radius: 4px; font-size: 12px; }
.anchor-suggest { display: inline-block; background: #ecfdf5; color: #065f46; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-family: ui-monospace, SFMono-Regular, monospace; }
footer.tse-f { color: #6b7280; font-size: 12px; text-align: center; margin: 32px 0 24px; }
CSS;
}

function tse_ai_report_header( $title, $meta ) {
    $provider = isset( $meta['provider'] ) ? $meta['provider'] : '';
    $model    = isset( $meta['model'] )    ? $meta['model']    : '';
    $site_url = isset( $meta['site_url'] ) ? $meta['site_url'] : '';
    $site     = isset( $meta['site_name'] )? $meta['site_name']: '';
    $when     = isset( $meta['generated_at'] ) ? $meta['generated_at'] : '';
    $ver      = isset( $meta['plugin_version'] ) ? $meta['plugin_version'] : '';

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
        . "<div class=\"wrap\">\n"
        . tse_ai_report_version_note( $ver );
}

function tse_ai_report_version_note( $ver ) {
    if ( ! $ver ) return '';
    return '';
}

function tse_ai_report_footer() {
    return "</div>\n<footer class=\"tse-f\">Generated by TSE Site Exporter. All findings are LLM-generated and should be reviewed by a human.</footer>\n"
        . "</body></html>\n";
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

function tse_ai_report_urls_cell( $urls ) {
    if ( empty( $urls ) || ! is_array( $urls ) ) return '<span style="color:#9ca3af">—</span>';
    $out = '<div class="urls">';
    foreach ( $urls as $u ) {
        $u_safe = htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' );
        $out .= '<a href="' . $u_safe . '" target="_blank" rel="noopener">' . $u_safe . '</a>';
    }
    return $out . '</div>';
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

/* -------------------------------------------------------------------------
 * ai-report.html — recommendations + content gap signals
 * ---------------------------------------------------------------------- */
function tse_ai_report_main( $meta, $recs, $gaps ) {
    $html  = tse_ai_report_header( 'AI Site Analysis Report', $meta );
    $html .= tse_ai_report_render_error( $recs );

    $rec_items = tse_ai_report_sort_by_priority( isset( $recs['items'] ) ? $recs['items'] : array() );
    $html .= tse_ai_report_section_heading( 'Prioritised recommendations', count( $rec_items ) );
    if ( empty( $rec_items ) ) {
        $html .= '<div class="empty">No recommendations were returned.</div>';
    } else {
        $html .= '<table class="tse"><thead><tr>'
              . '<th style="width:90px">Priority</th>'
              . '<th>Issue</th>'
              . '<th>Affected pages</th>'
              . '<th>Recommendation</th>'
              . '<th style="width:140px">Category / Conf.</th>'
              . '</tr></thead><tbody>';
        foreach ( $rec_items as $it ) {
            $pr   = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
            $issue = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
            $rec  = htmlspecialchars( (string) ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ), ENT_QUOTES, 'UTF-8' );
            $cat  = isset( $it['category'] ) ? $it['category'] : '';
            $html .= '<tr>'
                  . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
                  . '<td>' . $issue . '</td>'
                  . '<td>' . tse_ai_report_urls_cell( isset( $it['affected_pages'] ) ? $it['affected_pages'] : array() ) . '</td>'
                  . '<td class="recommendation">' . $rec . '</td>'
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
    if ( empty( $gap_items ) ) {
        $html .= '<div class="empty">No content gap signals were returned.</div>';
    } else {
        $html .= '<table class="tse"><thead><tr>'
              . '<th style="width:90px">Priority</th>'
              . '<th>Issue</th>'
              . '<th>Affected pages</th>'
              . '<th>Recommendation</th>'
              . '<th style="width:140px">Type / Conf.</th>'
              . '</tr></thead><tbody>';
        foreach ( $gap_items as $it ) {
            $pr   = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
            $issue = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
            $rec  = htmlspecialchars( (string) ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ), ENT_QUOTES, 'UTF-8' );
            $gtype = isset( $it['gap_type'] ) ? $it['gap_type'] : '';
            $html .= '<tr>'
                  . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
                  . '<td>' . $issue . '</td>'
                  . '<td>' . tse_ai_report_urls_cell( isset( $it['affected_pages'] ) ? $it['affected_pages'] : array() ) . '</td>'
                  . '<td class="recommendation">' . $rec . '</td>'
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
function tse_ai_report_links( $meta, $links ) {
    $html = tse_ai_report_header( 'Internal-Link Opportunities (LLM)', $meta );
    $html .= tse_ai_report_render_error( $links );

    $items = tse_ai_report_sort_by_priority( isset( $links['items'] ) ? $links['items'] : array() );
    $html .= tse_ai_report_section_heading( 'Refined link opportunities', count( $items ) );
    if ( empty( $items ) ) {
        return $html . '<div class="empty">No internal-link opportunities were returned.</div>' . tse_ai_report_footer();
    }

    $html .= '<table class="tse"><thead><tr>'
          . '<th style="width:90px">Priority</th>'
          . '<th>Source → Target</th>'
          . '<th>Suggested anchor</th>'
          . '<th>Reason</th>'
          . '<th style="width:90px">Conf.</th>'
          . '</tr></thead><tbody>';
    foreach ( $items as $it ) {
        $pr      = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
        $src     = isset( $it['source_url'] ) ? (string) $it['source_url'] : ( isset( $it['affected_pages'][0] ) ? $it['affected_pages'][0] : '' );
        $tgt     = isset( $it['target_url'] ) ? (string) $it['target_url'] : ( isset( $it['affected_pages'][1] ) ? $it['affected_pages'][1] : '' );
        $anchor  = htmlspecialchars( (string) ( isset( $it['suggested_anchor'] ) ? $it['suggested_anchor'] : '' ), ENT_QUOTES, 'UTF-8' );
        $reason  = htmlspecialchars( (string) ( isset( $it['reason'] ) ? $it['reason'] : ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ) ), ENT_QUOTES, 'UTF-8' );
        $src_h   = htmlspecialchars( $src, ENT_QUOTES, 'UTF-8' );
        $tgt_h   = htmlspecialchars( $tgt, ENT_QUOTES, 'UTF-8' );

        $html .= '<tr>'
              . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
              . '<td><div style="font-size:12px;color:#6b7280;margin-bottom:4px">SOURCE</div>'
              . ( $src ? '<a href="' . $src_h . '" target="_blank" rel="noopener">' . $src_h . '</a>' : '—' )
              . '<div style="font-size:12px;color:#6b7280;margin:8px 0 4px">TARGET</div>'
              . ( $tgt ? '<a href="' . $tgt_h . '" target="_blank" rel="noopener">' . $tgt_h . '</a>' : '—' )
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
function tse_ai_report_clusters( $meta, $clusters ) {
    $html = tse_ai_report_header( 'Cluster Analysis (LLM)', $meta );
    $html .= tse_ai_report_render_error( $clusters );

    $items = isset( $clusters['items'] ) ? $clusters['items'] : array();
    // Group by cluster_id; within a group sort by priority.
    $by_cluster = array();
    foreach ( $items as $it ) {
        $cid = isset( $it['cluster_id'] ) ? (int) $it['cluster_id'] : -1;
        $by_cluster[ $cid ][] = $it;
    }
    ksort( $by_cluster );

    $html .= tse_ai_report_section_heading( 'Findings', count( $items ) );
    if ( empty( $items ) ) {
        return $html . '<div class="empty">No cluster findings were returned.</div>' . tse_ai_report_footer();
    }

    foreach ( $by_cluster as $cid => $cluster_items ) {
        $cluster_items = tse_ai_report_sort_by_priority( $cluster_items );
        $label = $cid >= 0 ? ( 'Cluster #' . $cid ) : 'Unclustered findings';
        $html .= '<h2 class="section" style="margin-top:24px">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
              . '<span class="count">' . count( $cluster_items ) . '</span></h2>';
        $html .= '<table class="tse"><thead><tr>'
              . '<th style="width:90px">Priority</th>'
              . '<th>Issue</th>'
              . '<th>Affected pages</th>'
              . '<th>Recommendation</th>'
              . '<th style="width:140px">Type / Conf.</th>'
              . '</tr></thead><tbody>';
        foreach ( $cluster_items as $it ) {
            $pr     = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
            $issue  = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
            $rec    = htmlspecialchars( (string) ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ), ENT_QUOTES, 'UTF-8' );
            $ftype  = isset( $it['finding_type'] ) ? $it['finding_type'] : '';
            $html  .= '<tr>'
                   . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
                   . '<td>' . $issue . '</td>'
                   . '<td>' . tse_ai_report_urls_cell( isset( $it['affected_pages'] ) ? $it['affected_pages'] : array() ) . '</td>'
                   . '<td class="recommendation">' . $rec . '</td>'
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
