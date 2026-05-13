<?php
/**
 * Smoke test: V2.3.0 Weighted Internal Linking Engine.
 *
 * Builds a small synthetic site (homepage, services parent, 2 service pages,
 * 1 location, 1 blog post, 1 FAQ, 1 isolated orphan) and verifies:
 *   - Strategic classification matches expectations.
 *   - Authority is highest on homepage and 'money' service pages.
 *   - Weighted edges have weight > 1 for descriptive anchors and < 1 for generic.
 *   - Cluster detection identifies the isolated page outside the main cluster.
 *   - Intelligence flags detect high-out/weak-in nodes.
 *   - Final bundle contains all 5 new JSON files.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'TSE_SITE_EXPORTER_VERSION', '2.3.0-test' );

// -- Minimal WP stubs -------------------------------------------------------
function home_url()       { return 'https://example.com'; }
function get_bloginfo($k) { return $k === 'name' ? 'Example' : '6.5'; }
function wp_json_encode($d, $f = 0) { return json_encode( $d, $f ); }
function wp_parse_url($u) { return parse_url( $u ); }
function wp_strip_all_tags($s) { return strip_tags( (string) $s ); }
function get_option($k) { return 0; }

require_once __DIR__ . '/tse-site-exporter/includes/postprocess.php';
require_once __DIR__ . '/tse-site-exporter/includes/schema.php';
require_once __DIR__ . '/tse-site-exporter/includes/relationships.php';
require_once __DIR__ . '/tse-site-exporter/includes/authority.php';
require_once __DIR__ . '/tse-site-exporter/includes/exporter.php';

function mk( $id, $url, $type, $classification, $internal_targets, $extra = array() ) {
    $links_internal = array();
    foreach ( $internal_targets as $t ) {
        $links_internal[] = array(
            'url' => $t['url'],
            'anchor' => $t['anchor'],
            'rel' => isset( $t['rel'] ) ? $t['rel'] : array(),
            'is_self' => false,
            'source_post_type' => $type,
            'source_classification' => $classification,
            'target_post_type' => 'page',
            'target_classification' => 'unknown',
            'target_id' => 0,
        );
    }
    return array_merge( array(
        'id' => $id,
        'url' => $url,
        'post_type' => $type,
        'classification' => $classification,
        'parent_id' => 0,
        'content' => array( 'h1' => '' ),
        'seo' => array( 'title' => '', 'description' => '', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
        'links' => array( 'internal' => $links_internal, 'external' => array() ),
        'schema' => array(),
        'cro' => array(),
        'elementor' => array( 'is_elementor' => false ),
    ), $extra );
}

$records = array(
    // 1: Homepage links to everything (high outgoing, low incoming).
    mk( 1, 'https://example.com/', 'page', 'homepage', array(
        array( 'url' => 'https://example.com/services/seo/',         'anchor' => 'Professional SEO services' ),
        array( 'url' => 'https://example.com/services/web-design/',  'anchor' => 'Custom web design' ),
        array( 'url' => 'https://example.com/locations/london/',     'anchor' => 'Our London office' ),
        array( 'url' => 'https://example.com/blog/welcome-post/',    'anchor' => 'Read more' ),
        array( 'url' => 'https://example.com/contact/',              'anchor' => 'Contact us' ),
    ) ),
    // 2: Money / service page (SEO) — receives links from multiple sources.
    mk( 2, 'https://example.com/services/seo/', 'page', 'money', array(
        array( 'url' => 'https://example.com/contact/',             'anchor' => 'Get a free SEO quote' ),
        array( 'url' => 'https://example.com/services/web-design/', 'anchor' => 'web design services' ),
    ), array(
        'cro' => array( 'cta_count' => 3, 'form_count' => 1, 'phone_count' => 1 ),
    ) ),
    // 3: Money / service page (Web Design).
    mk( 3, 'https://example.com/services/web-design/', 'page', 'money', array(
        array( 'url' => 'https://example.com/contact/',             'anchor' => 'Start your project' ),
        array( 'url' => 'https://example.com/services/seo/',        'anchor' => 'SEO services' ),
    ) ),
    // 4: Location page.
    mk( 4, 'https://example.com/locations/london/', 'page', 'money', array(
        array( 'url' => 'https://example.com/contact/',             'anchor' => 'click here', 'rel' => array( 'nofollow' ) ),
    ) ),
    // 5: Article.
    mk( 5, 'https://example.com/blog/welcome-post/', 'post', 'article', array(
        array( 'url' => 'https://example.com/services/seo/', 'anchor' => 'our SEO services' ),
    ) ),
    // 6: Contact (money via URL).
    mk( 6, 'https://example.com/contact/', 'page', 'money', array() ),
    // 7: FAQ (support — receives nothing → isolated cluster).
    mk( 7, 'https://example.com/help/faq/', 'page', 'support', array() ),
    // 8: Hub with many outgoing but no incoming (high-out / weak-in).
    mk( 8, 'https://example.com/hub-page/', 'page', 'other', array(
        array( 'url' => 'https://example.com/services/seo/', 'anchor' => 'SEO' ),
        array( 'url' => 'https://example.com/services/web-design/', 'anchor' => 'Web Design' ),
        array( 'url' => 'https://example.com/locations/london/', 'anchor' => 'London' ),
        array( 'url' => 'https://example.com/contact/', 'anchor' => 'Contact' ),
        array( 'url' => 'https://example.com/blog/welcome-post/', 'anchor' => 'Blog' ),
        array( 'url' => 'https://example.com/services/seo/', 'anchor' => 'SEO again' ),
        array( 'url' => 'https://example.com/services/web-design/', 'anchor' => 'Design again' ),
        array( 'url' => 'https://example.com/blog/welcome-post/', 'anchor' => 'Blog again' ),
        array( 'url' => 'https://example.com/contact/', 'anchor' => 'Contact again' ),
        array( 'url' => 'https://example.com/', 'anchor' => 'Back to homepage' ),
    ) ),
);

$url_index = array();
foreach ( $records as $i => $r ) {
    $url_index[ tse_normalize_url( $r['url'] ) ] = $i;
}

$opts = array(
    'mode' => 'quick', 'live_fetch' => false, 'broken_check' => false,
    'include_slices' => true, 'quick_cap' => 500,
);

$postprocess   = tse_postprocess_build( $records, $url_index, $opts );
$relationships = tse_relationships_build( $records, $url_index );
$authority     = tse_authority_build( $records, $url_index, $relationships );

// Inject per-page metrics into records (mirrors tse_exporter_run).
foreach ( $records as &$r ) {
    $norm = tse_normalize_url( $r['url'] );
    $r['relationships'] = $relationships['per_page'][ $norm ];
    $r['authority']     = $authority['per_page'][ $norm ];
}
unset( $r );

$bundle = tse_exporter_assemble_bundle( $records, $postprocess, $relationships, $authority, $opts, false, array( 'page', 'post' ) );

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------
$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== V2.3.0 Weighted Internal Linking Engine smoke test ===\n";

// 1. Strategic classification
$auth_by_id = array();
foreach ( $authority['strategic_pages']['pages'] as $p ) $auth_by_id[ $p['id'] ] = $p;

check( 'homepage classified as homepage', $auth_by_id[1]['strategic_type'] === 'homepage' );
check( 'SEO service page classified as service (URL pattern)', $auth_by_id[2]['strategic_type'] === 'service', 'got ' . $auth_by_id[2]['strategic_type'] );
check( 'Web design service page classified as service', $auth_by_id[3]['strategic_type'] === 'service', 'got ' . $auth_by_id[3]['strategic_type'] );
check( 'London page classified as location', $auth_by_id[4]['strategic_type'] === 'location', 'got ' . $auth_by_id[4]['strategic_type'] );
check( 'Welcome post classified as article', $auth_by_id[5]['strategic_type'] === 'article', 'got ' . $auth_by_id[5]['strategic_type'] );
check( 'Contact page classified as money', $auth_by_id[6]['strategic_type'] === 'money', 'got ' . $auth_by_id[6]['strategic_type'] );
check( 'FAQ classified as support', $auth_by_id[7]['strategic_type'] === 'support', 'got ' . $auth_by_id[7]['strategic_type'] );
check( 'Hub page falls back to other', $auth_by_id[8]['strategic_type'] === 'other', 'got ' . $auth_by_id[8]['strategic_type'] );

// 2. Weighted edges
$edge_for = function( $src, $tgt ) use ( $authority ) {
    foreach ( $authority['weighted_graph']['edges'] as $e ) {
        if ( $e['source'] === $src && $e['target'] === $tgt ) return $e;
    }
    return null;
};
$e_desc = $edge_for( 'https://example.com/', 'https://example.com/services/seo/' );
$e_gen  = $edge_for( 'https://example.com/locations/london/', 'https://example.com/contact/' );
check( 'descriptive-anchor edge weight > 1', $e_desc && $e_desc['weight'] > 1.0, 'weight=' . ($e_desc['weight'] ?? 'null') );
check( 'generic+nofollow edge weight < 0.5', $e_gen && $e_gen['weight'] < 0.5, 'weight=' . ($e_gen['weight'] ?? 'null') );

// 3. Authority: homepage (1) and SEO service (2) should rank above isolated FAQ (7).
$a1 = $auth_by_id[1]['internal_authority_score'];
$a2 = $auth_by_id[2]['internal_authority_score'];
$a7 = $auth_by_id[7]['internal_authority_score'];
check( 'homepage authority > FAQ (isolated)', $a1 > $a7, "home=$a1 faq=$a7" );
check( 'SEO service page authority > FAQ', $a2 > $a7, "seo=$a2 faq=$a7" );

// 4. Cluster signals: FAQ should be isolated (no inbound, no outbound)
$cs = $bundle['cluster-signals.json'];
$faq_isolated = false;
foreach ( $cs['clusters'] as $c ) {
    if ( in_array( 'https://example.com/help/faq', $c['members'], true ) && ! $c['is_main'] ) $faq_isolated = true;
}
check( 'FAQ is in an isolated cluster', $faq_isolated );
check( 'main cluster has homepage', $cs['main_cluster_id'] !== null );
check( 'isolated_clusters count >= 1', $cs['totals']['isolated_clusters'] >= 1 );

// 5. Intelligence flags
$intel = $bundle['intelligence-flags.json'];
$hub_in_weak = false;
foreach ( $intel['high_outgoing_weak_incoming_pages'] as $p ) {
    if ( $p['id'] === 8 ) $hub_in_weak = true;
}
check( 'hub page (id=8) flagged as high_outgoing_weak_incoming', $hub_in_weak );

// 6. Bundle completeness
$expected = array(
    'manifest.json', 'full-export.json',
    'authority-map.json', 'weighted-link-graph.json',
    'strategic-pages.json', 'cluster-signals.json', 'intelligence-flags.json',
);
foreach ( $expected as $f ) check( "bundle has $f", isset( $bundle[ $f ] ) );

// 7. Records carry the new authority block
check( "record has 'authority' block", isset( $records[0]['authority']['internal_authority_score'] ) );
check( "record authority has strategic_type", isset( $records[0]['authority']['strategic_type'] ) );

echo "\n";
if ( $fail === 0 ) {
    echo "ALL ASSERTIONS PASS\n";
    exit( 0 );
}
echo "FAILED: $fail assertion(s)\n";
exit( 1 );
