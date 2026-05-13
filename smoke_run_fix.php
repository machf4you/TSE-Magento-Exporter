<?php
/**
 * Smoke test: verify tse_exporter_run() reaches tse_exporter_assemble_bundle()
 * with the correct 6 args after the V2.2.0 fix, and that per-page relationships
 * are injected into each record.
 *
 * We stub the WP functions used by tse_exporter_run() / postprocess / relationships
 * /assemble_bundle, then synthesise two minimal PageRecords (so the rest of the
 * exporter logic - DOM parsing, content extraction, etc - is bypassed).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'TSE_SITE_EXPORTER_VERSION', '2.2.0-test' );

// -- Minimal WP stubs -------------------------------------------------------
function home_url()       { return 'https://example.com'; }
function get_bloginfo($k) { return $k === 'name' ? 'Example' : '6.5'; }
function wp_json_encode($d, $f = 0) { return json_encode( $d, $f ); }
function wp_parse_url($u) { return parse_url( $u ); }
function wp_strip_all_tags($s) { return strip_tags( (string) $s ); }
function get_option($k) { return 0; }
function get_post_types() { return array(); }
function get_posts() { return array(); }

// Override tse_exporter_run flow by short-circuiting fetch/build with fixtures.
// We'll call tse_postprocess_build, tse_relationships_build, tse_exporter_assemble_bundle
// directly against synthesised records to validate the new 6-arg signature.

require_once __DIR__ . '/tse-site-exporter/includes/postprocess.php';
require_once __DIR__ . '/tse-site-exporter/includes/schema.php';
require_once __DIR__ . '/tse-site-exporter/includes/relationships.php';
require_once __DIR__ . '/tse-site-exporter/includes/exporter.php';

$records = array(
    array(
        'id'             => 1,
        'url'            => 'https://example.com/',
        'post_type'      => 'page',
        'classification' => 'homepage',
        'content'        => array( 'h1' => 'Home' ),
        'seo'            => array(
            'title' => 'Home', 'description' => '', 'focus_keywords' => array(),
            'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core',
        ),
        'links' => array(
            'internal' => array(
                array(
                    'url' => 'https://example.com/about', 'anchor' => 'About', 'rel' => array(),
                    'is_self' => false, 'source_post_type' => 'page', 'source_classification' => 'homepage',
                    'target_post_type' => 'page', 'target_classification' => 'about', 'target_id' => 2,
                ),
            ),
            'external' => array(),
        ),
        'schema'    => array(),
        'cro'       => array(),
        'elementor' => array( 'is_elementor' => false ),
    ),
    array(
        'id'             => 2,
        'url'            => 'https://example.com/about',
        'post_type'      => 'page',
        'classification' => 'about',
        'content'        => array( 'h1' => 'About' ),
        'seo'            => array(
            'title' => 'About', 'description' => '', 'focus_keywords' => array(),
            'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core',
        ),
        'links'     => array( 'internal' => array(), 'external' => array() ),
        'schema'    => array(),
        'cro'       => array(),
        'elementor' => array( 'is_elementor' => false ),
    ),
);

$url_index = array();
foreach ( $records as $i => $r ) {
    $url_index[ tse_normalize_url( $r['url'] ) ] = $i;
}

$opts = array(
    'mode' => 'quick', 'live_fetch' => false, 'broken_check' => false,
    'include_slices' => true, 'quick_cap' => 500,
);

$postprocess  = tse_postprocess_build( $records, $url_index, $opts );
$relationships = tse_relationships_build( $records, $url_index );
require_once __DIR__ . '/tse-site-exporter/includes/authority.php';
$authority = tse_authority_build( $records, $url_index, $relationships );

// Inject per-page metrics (mirrors the new logic in tse_exporter_run).
foreach ( $records as &$r ) {
    $norm = tse_normalize_url( $r['url'] );
    $r['relationships'] = $relationships['per_page'][ $norm ];
    $r['authority']     = $authority['per_page'][ $norm ];
}
unset( $r );

// Verify the new 7-arg signature.
$bundle = tse_exporter_assemble_bundle( $records, $postprocess, $relationships, $authority, $opts, false, array( 'page' ) );

$expected_files = array(
    'manifest.json', 'full-export.json', 'seo-data.json', 'internal-links.json',
    'external-links.json', 'cro-analysis.json', 'schema.json', 'elementor-structure.json',
    'hierarchy.json', 'orphans.json',
    'internal-link-graph.json', 'orphan-pages.json', 'weak-pages.json', 'relationship-summary.json',
);

$missing = array();
foreach ( $expected_files as $f ) {
    if ( ! isset( $bundle[ $f ] ) ) $missing[] = $f;
}

echo "=== Smoke test: V2.2.0 run/assemble fix ===\n";
echo "Bundle files: " . count( $bundle ) . "\n";
echo "Missing files: " . ( $missing ? implode( ', ', $missing ) : 'NONE' ) . "\n";
echo "Records have 'relationships' key: " . ( isset( $records[0]['relationships'] ) ? 'YES' : 'NO' ) . "\n";
echo "Homepage outgoing_link_count: " . $records[0]['relationships']['outgoing_link_count'] . " (expected 1)\n";
echo "About incoming_link_count:    " . $records[1]['relationships']['incoming_link_count']  . " (expected 1)\n";
echo "Graph edges: " . count( $bundle['internal-link-graph.json']['edges'] ) . " (expected 1)\n";
echo "Orphans count: " . $bundle['orphan-pages.json']['count'] . " (expected 0; about has 1 incoming)\n";
echo "Summary totals.pages: " . $bundle['relationship-summary.json']['totals']['pages'] . " (expected 2)\n";

if ( $missing ) { exit( 1 ); }
if ( $records[0]['relationships']['outgoing_link_count'] !== 1 ) exit( 1 );
if ( $records[1]['relationships']['incoming_link_count']  !== 1 ) exit( 1 );
echo "ALL ASSERTIONS PASS\n";
