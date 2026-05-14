<?php
/**
 * Smoke test: V2.5.0 AI Analysis Execution Layer.
 *
 * Validates:
 *  - Provider abstraction (a fake provider returning canned items).
 *  - Runner produces 4 expected output files with the documented schema.
 *  - Error path (WP_Error from provider) is wrapped as status='error'.
 *  - Real provider classes implement the slug/default_model/complete contract.
 *  - HTTP request shape: with wp_remote_post mocked, each provider posts to the
 *    right URL with the right headers + a JSON body containing the right model.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'TSE_SITE_EXPORTER_VERSION', '2.5.0-test' );

// -- Minimal WP stubs --------------------------------------------------------
function home_url()       { return 'https://example.com'; }
function get_bloginfo($k) { return $k === 'name' ? 'Example' : '6.5'; }
function wp_json_encode($d, $f = 0) { return json_encode( $d, $f ); }
function wp_parse_url($u) { return parse_url( $u ); }
function wp_strip_all_tags($s) { return strip_tags( (string) $s ); }
function get_option($k, $d = false) { return $d; }
function update_option($k, $v) { return true; }
function sanitize_text_field($s) { return is_string($s) ? trim( strip_tags( $s ) ) : ''; }

class WP_Error {
    public $code; public $message; public $data;
    function __construct( $code = '', $message = '', $data = null ) {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
    function get_error_message() { return $this->message; }
    function get_error_code()    { return $this->code; }
    function get_error_data()    { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

// Captures the last wp_remote_post call so we can assert on URL/headers/body.
$GLOBALS['tse_test_last_post']  = null;
$GLOBALS['tse_test_canned_resp']= null;
function wp_remote_post( $url, $args = array() ) {
    $GLOBALS['tse_test_last_post'] = array( 'url' => $url, 'args' => $args );
    return $GLOBALS['tse_test_canned_resp'];
}
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : 0; }
function wp_remote_retrieve_body( $r )          { return isset( $r['body'] ) ? $r['body'] : ''; }

require_once __DIR__ . '/tse-site-exporter/includes/ai_settings.php';
require_once __DIR__ . '/tse-site-exporter/includes/ai_provider.php';
require_once __DIR__ . '/tse-site-exporter/includes/ai_runner.php';

// ---------------------------------------------------------------------------
// Fake provider for runner-level tests (returns canned JSON).
// ---------------------------------------------------------------------------
class TSE_AI_Provider_Fake extends TSE_AI_Provider_Base {
    public $canned = array();
    public $calls  = array();
    public $force_error = null;
    public function slug() { return 'fake'; }
    public function default_model() { return 'fake-model-1'; }
    public function get_key() { return 'test-key'; }
    public function complete( $system, $user_payload, $opts = array() ) {
        $this->calls[] = array( 'system' => $system, 'payload' => $user_payload, 'opts' => $opts );
        if ( $this->force_error ) return $this->force_error;
        $canned = array_shift( $this->canned );
        if ( null === $canned ) $canned = array( 'items' => array() );
        return $canned;
    }
}

$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== V2.5.0 AI Analysis Execution Layer smoke test ===\n";

// ---------------------------------------------------------------------------
// 1. Provider factory + supported list
// ---------------------------------------------------------------------------
$supported = tse_ai_supported_providers();
check( 'factory: 3 providers supported', count( $supported ) === 3 );
check( 'factory: openai returns OpenAI instance', tse_ai_get_provider( 'openai' ) instanceof TSE_AI_Provider_OpenAI );
check( 'factory: anthropic returns Anthropic instance', tse_ai_get_provider( 'anthropic' ) instanceof TSE_AI_Provider_Anthropic );
check( 'factory: gemini returns Gemini instance', tse_ai_get_provider( 'gemini' ) instanceof TSE_AI_Provider_Gemini );
check( 'factory: unknown returns WP_Error', is_wp_error( tse_ai_get_provider( 'bogus' ) ) );

// ---------------------------------------------------------------------------
// 2. Default models match the spec
// ---------------------------------------------------------------------------
define( 'TSE_OPENAI_KEY', 'sk-test-openai' );
define( 'TSE_ANTHROPIC_KEY', 'sk-test-anthropic' );
define( 'TSE_GEMINI_KEY', 'gemini-test-key' );

$op = tse_ai_get_provider( 'openai' );
$an = tse_ai_get_provider( 'anthropic' );
$ge = tse_ai_get_provider( 'gemini' );
check( 'openai default_model is gpt-5.2', $op->default_model() === 'gpt-5.2' );
check( 'anthropic default_model is claude-sonnet-4-5', $an->default_model() === 'claude-sonnet-4-5' );
check( 'gemini default_model is gemini-3-pro', $ge->default_model() === 'gemini-3-pro' );

// ---------------------------------------------------------------------------
// 3. HTTP request shape per provider
// ---------------------------------------------------------------------------
// OpenAI
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => '{"items":[{"issue":"x","recommendation":"y","priority":"high","affected_pages":["u"],"confidence_score":0.8}]}' ) ) ) ) ),
);
$out = $op->complete( 'sys', array( 'k' => 'v' ) );
$last = $GLOBALS['tse_test_last_post'];
check( 'openai POST to /v1/chat/completions', strpos( $last['url'], 'api.openai.com/v1/chat/completions' ) !== false, $last['url'] );
check( 'openai Authorization Bearer header set', isset( $last['args']['headers']['Authorization'] ) && strpos( $last['args']['headers']['Authorization'], 'Bearer sk-test-openai' ) === 0 );
check( 'openai body contains model=gpt-5.2', strpos( $last['args']['body'], '"model":"gpt-5.2"' ) !== false );
check( 'openai body contains response_format=json_object', strpos( $last['args']['body'], '"response_format":{"type":"json_object"}' ) !== false );
check( 'openai parsed items present', is_array( $out ) && isset( $out['items'] ) && $out['items'][0]['issue'] === 'x' );

// Anthropic
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'content' => array( array( 'type' => 'text', 'text' => '{"items":[{"issue":"a"}]}' ) ) ) ),
);
$an->complete( 'sys', array( 'k' => 'v' ) );
$last = $GLOBALS['tse_test_last_post'];
check( 'anthropic POST to /v1/messages', strpos( $last['url'], 'api.anthropic.com/v1/messages' ) !== false );
check( 'anthropic x-api-key header set', isset( $last['args']['headers']['x-api-key'] ) && $last['args']['headers']['x-api-key'] === 'sk-test-anthropic' );
check( 'anthropic-version header is 2023-06-01', isset( $last['args']['headers']['anthropic-version'] ) && $last['args']['headers']['anthropic-version'] === '2023-06-01' );
check( 'anthropic body contains model=claude-sonnet-4-5', strpos( $last['args']['body'], '"model":"claude-sonnet-4-5"' ) !== false );

// Gemini
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => '{"items":[]}' ) ) ) ) ) ) ),
);
$ge->complete( 'sys', array( 'k' => 'v' ) );
$last = $GLOBALS['tse_test_last_post'];
check( 'gemini POST to generateContent', strpos( $last['url'], 'generativelanguage.googleapis.com/v1beta/models/gemini-3-pro:generateContent' ) !== false, $last['url'] );
check( 'gemini x-goog-api-key set', isset( $last['args']['headers']['x-goog-api-key'] ) && $last['args']['headers']['x-goog-api-key'] === 'gemini-test-key' );
check( 'gemini body has response_mime_type=application/json', strpos( $last['args']['body'], '"response_mime_type":"application\/json"' ) !== false || strpos( $last['args']['body'], '"response_mime_type":"application/json"' ) !== false );

// ---------------------------------------------------------------------------
// 4. parse_json: handles markdown fences
// ---------------------------------------------------------------------------
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => "```json\n{\"items\":[{\"issue\":\"fenced\"}]}\n```" ) ) ) ) ),
);
$out = $op->complete( 'sys', array() );
check( 'markdown-fenced JSON parsed', is_array( $out ) && isset( $out['items'][0]['issue'] ) && $out['items'][0]['issue'] === 'fenced' );

// ---------------------------------------------------------------------------
// 5. HTTP error path returns WP_Error
// ---------------------------------------------------------------------------
$GLOBALS['tse_test_canned_resp'] = array( 'response' => array( 'code' => 401 ), 'body' => '{"error":"bad key"}' );
$out = $op->complete( 'sys', array() );
check( 'HTTP 401 maps to WP_Error', is_wp_error( $out ) );

// ---------------------------------------------------------------------------
// 6. Runner: 4 outputs, schema, error wrapping
// ---------------------------------------------------------------------------
$fake = new TSE_AI_Provider_Fake();
$fake->canned = array(
    array( 'items' => array( array( 'priority' => 'high', 'issue' => 'Authority gap', 'affected_pages' => array( 'https://example.com/services/seo/' ), 'recommendation' => 'Link homepage → SEO with descriptive anchor', 'confidence_score' => 0.9, 'category' => 'linking' ) ) ),
    array( 'items' => array( array( 'priority' => 'high', 'issue' => 'Web design under-supported', 'affected_pages' => array( 'https://example.com/services/seo/', 'https://example.com/services/web-design/' ), 'recommendation' => 'Add a link in SEO page', 'confidence_score' => 0.85, 'source_url' => 'https://example.com/services/seo/', 'target_url' => 'https://example.com/services/web-design/', 'suggested_anchor' => 'custom web design services', 'reason' => 'raises authority' ) ) ),
    array( 'items' => array( array( 'priority' => 'medium', 'cluster_id' => 2, 'issue' => 'FAQ isolated', 'affected_pages' => array( 'https://example.com/help/faq/' ), 'recommendation' => 'Bridge from homepage', 'confidence_score' => 0.95, 'finding_type' => 'isolated' ) ) ),
    array( 'items' => array( array( 'priority' => 'low', 'issue' => 'No comparison content', 'affected_pages' => array( 'https://example.com/services/seo/' ), 'recommendation' => 'Add SEO vs PPC support article', 'confidence_score' => 0.6, 'gap_type' => 'missing_support' ) ) ),
);
$inputs = array(
    'site'    => array( 'totals' => array( 'pages' => 7 ), 'distribution' => array( 'by_strategic_type' => array( 'money' => 2 ) ), 'top_authorities' => array() ),
    'pages'   => array(
        array( 'url' => 'https://example.com/services/seo/', 'title' => 'SEO', 'meta_title' => 'SEO', 'meta_description' => '', 'strategic_type' => 'service', 'classification' => 'money', 'h1' => 'SEO', 'h2' => array(), 'word_count' => 1200, 'internal_authority_score' => 70, 'incoming_link_count' => 2, 'outgoing_link_count' => 1, 'top_inbound_anchors' => array(), 'issues' => array() ),
        array( 'url' => 'https://example.com/services/web-design/', 'title' => 'Web Design', 'meta_title' => 'Web Design', 'meta_description' => '', 'strategic_type' => 'service', 'classification' => 'money', 'h1' => 'Web Design', 'h2' => array(), 'word_count' => 100, 'internal_authority_score' => 20, 'incoming_link_count' => 1, 'outgoing_link_count' => 1, 'top_inbound_anchors' => array(), 'issues' => array( 'thin_content' ) ),
    ),
    'linking' => array(
        'linking_opportunities' => array( array( 'source_url' => 'https://example.com/services/seo/', 'target_url' => 'https://example.com/services/web-design/' ) ),
        'weak_money_pages'      => array(),
        'orphan_pages'          => array(),
        'near_orphan_pages'     => array(),
        'duplicate_meta_titles' => array(),
        'duplicate_meta_descriptions' => array(),
    ),
    'cluster' => array( 'totals' => array( 'clusters' => 2 ), 'clusters' => array() ),
);
$out = tse_ai_runner_execute( $fake, $inputs );

check( 'runner: manifest.json present', isset( $out['manifest.json'] ) );
check( 'runner: manifest has provider+model', isset( $out['manifest.json']['provider'] ) && isset( $out['manifest.json']['model'] ) && $out['manifest.json']['provider'] === 'fake' );
foreach ( array( 'ai-recommendations.json', 'ai-internal-link-opportunities.json', 'ai-cluster-analysis.json', 'ai-content-gap-signals.json' ) as $f ) {
    check( "runner: $f present", isset( $out[ $f ] ) );
    check( "runner: $f status=ok", isset( $out[ $f ]['status'] ) && $out[ $f ]['status'] === 'ok' );
    check( "runner: $f items array present + count>=0", isset( $out[ $f ]['items'] ) && is_array( $out[ $f ]['items'] ) );
}
check( 'runner: recommendations item carries required fields',
    isset( $out['ai-recommendations.json']['items'][0]['priority'], $out['ai-recommendations.json']['items'][0]['issue'], $out['ai-recommendations.json']['items'][0]['affected_pages'], $out['ai-recommendations.json']['items'][0]['recommendation'], $out['ai-recommendations.json']['items'][0]['confidence_score'] ) );
check( 'runner: 4 LLM calls executed', count( $fake->calls ) === 4 );

// Verify each prompt mentions the strict JSON schema directive.
foreach ( $fake->calls as $i => $c ) {
    check( "runner: call $i system prompt forbids prose", stripos( $c['system'], 'no prose' ) !== false );
}

// Verify link-opportunities prompt slimmed page context (no plain_text leak).
$lo_payload = $fake->calls[1]['payload'];
check( 'link opps: payload has pre_computed_opportunities', isset( $lo_payload['pre_computed_opportunities'] ) );
check( 'link opps: page_context entries have no plain_text', isset( $lo_payload['page_context'][0] ) && ! array_key_exists( 'plain_text', $lo_payload['page_context'][0] ) );

// ---------------------------------------------------------------------------
// 7. Error path wrapping
// ---------------------------------------------------------------------------
$fake2 = new TSE_AI_Provider_Fake();
$fake2->force_error = new WP_Error( 'tse_ai_http_429', 'rate limited' );
$out2 = tse_ai_runner_execute( $fake2, $inputs );
check( 'runner: error path returns status=error', $out2['ai-recommendations.json']['status'] === 'error' );
check( 'runner: error path carries error message', $out2['ai-recommendations.json']['error'] === 'rate limited' );

// ---------------------------------------------------------------------------
// 8. GPT-5.x compatibility: max_completion_tokens swap + temperature dropped
// ---------------------------------------------------------------------------
$GLOBALS['tse_test_canned_resp'] = array(
    'response' => array( 'code' => 200 ),
    'body'     => json_encode( array( 'choices' => array( array( 'message' => array( 'content' => '{"items":[]}' ) ) ) ) ),
);
$op->complete( 'sys', array( 'k' => 'v' ), array( 'temperature' => 0.2, 'max_tokens' => 2048 ) );
$body_gpt5 = $GLOBALS['tse_test_last_post']['args']['body'];
check( 'gpt-5.2 uses max_completion_tokens, not max_tokens',
    strpos( $body_gpt5, '"max_completion_tokens":2048' ) !== false
    && strpos( $body_gpt5, '"max_tokens"' ) === false, substr( $body_gpt5, 0, 200 ) );
check( 'gpt-5.2 body omits temperature (only default supported)',
    strpos( $body_gpt5, '"temperature"' ) === false );

// Force an older model and verify it still uses max_tokens + accepts temperature.
require_once __DIR__ . '/tse-site-exporter/includes/ai_provider.php';
class TSE_AI_OpenAI_Legacy_For_Test extends TSE_AI_Provider_OpenAI {
    public function get_model() { return 'gpt-4o-mini'; }
    public function get_key()   { return 'sk-legacy-test'; }
}
$legacy = new TSE_AI_OpenAI_Legacy_For_Test();
$legacy->complete( 'sys', array( 'k' => 'v' ), array( 'temperature' => 0.3, 'max_tokens' => 1024 ) );
$body_legacy = $GLOBALS['tse_test_last_post']['args']['body'];
check( 'gpt-4o-mini still uses max_tokens (legacy path)',
    strpos( $body_legacy, '"max_tokens":1024' ) !== false
    && strpos( $body_legacy, '"max_completion_tokens"' ) === false );
check( 'gpt-4o-mini accepts temperature', strpos( $body_legacy, '"temperature":0.3' ) !== false );

// ---------------------------------------------------------------------------
// 9. HTML reports: structure, escaping, priority ordering
// ---------------------------------------------------------------------------
require_once __DIR__ . '/tse-site-exporter/includes/ai_report.php';

$runner_output = $out;
$reports = tse_ai_report_build( $runner_output );

foreach ( array( 'ai-report.html', 'internal-link-report.html', 'cluster-report.html' ) as $f ) {
    check( "reports: $f generated", isset( $reports[ $f ] ) && is_string( $reports[ $f ] ) );
    $h = $reports[ $f ];
    check( "reports: $f is valid HTML doc", strpos( $h, '<!doctype html>' ) === 0 );
    check( "reports: $f has table or empty card", strpos( $h, '<table class="tse"' ) !== false || strpos( $h, 'class="empty"' ) !== false );
    check( "reports: $f references provider+model", strpos( $h, 'fake' ) !== false && strpos( $h, 'fake-model-1' ) !== false );
}

// XSS-escaping check
$evil = '<script>alert(1)</script>';
$fake3 = new TSE_AI_Provider_Fake();
$fake3->canned = array(
    array( 'items' => array( array( 'priority' => 'high', 'issue' => $evil, 'affected_pages' => array( 'https://example.com/' . $evil ), 'recommendation' => $evil, 'confidence_score' => 0.5 ) ) ),
    array( 'items' => array() ),
    array( 'items' => array() ),
    array( 'items' => array() ),
);
$evil_out = tse_ai_runner_execute( $fake3, $inputs );
$evil_reports = tse_ai_report_build( $evil_out );
check( 'reports: <script> tag from LLM is escaped in HTML output',
    strpos( $evil_reports['ai-report.html'], '<script>alert(1)</script>' ) === false
    && strpos( $evil_reports['ai-report.html'], '&lt;script&gt;' ) !== false );

// Priority ordering
$fake4 = new TSE_AI_Provider_Fake();
$fake4->canned = array(
    array( 'items' => array(
        array( 'priority' => 'low',    'issue' => 'L', 'affected_pages' => array(), 'recommendation' => 'r', 'confidence_score' => 0.9 ),
        array( 'priority' => 'high',   'issue' => 'H', 'affected_pages' => array(), 'recommendation' => 'r', 'confidence_score' => 0.5 ),
        array( 'priority' => 'medium', 'issue' => 'M', 'affected_pages' => array(), 'recommendation' => 'r', 'confidence_score' => 0.5 ),
    ) ),
    array( 'items' => array() ), array( 'items' => array() ), array( 'items' => array() ),
);
$ord_out = tse_ai_runner_execute( $fake4, $inputs );
$ord_reports = tse_ai_report_build( $ord_out );
$h_pos = strpos( $ord_reports['ai-report.html'], '<td>H</td>' );
$m_pos = strpos( $ord_reports['ai-report.html'], '<td>M</td>' );
$l_pos = strpos( $ord_reports['ai-report.html'], '<td>L</td>' );
check( 'reports: items sorted high → medium → low', $h_pos !== false && $m_pos !== false && $l_pos !== false && $h_pos < $m_pos && $m_pos < $l_pos );

// Error wrapping in HTML
$fake5 = new TSE_AI_Provider_Fake();
$fake5->force_error = new WP_Error( 'tse_ai_http_429', 'rate limited' );
$err_out = tse_ai_runner_execute( $fake5, $inputs );
$err_reports = tse_ai_report_build( $err_out );
check( 'reports: ai-report.html renders provider error banner',
    strpos( $err_reports['ai-report.html'], 'Analysis failed' ) !== false
    && strpos( $err_reports['ai-report.html'], 'rate limited' ) !== false );

// ---------------------------------------------------------------------------
// 10. V2.6.0 report readability: title-over-path cells, page-type pills,
//     executive summary, quick wins, renamed column header.
// ---------------------------------------------------------------------------
$fake6 = new TSE_AI_Provider_Fake();
$fake6->canned = array(
    // recommendations (1 high, 1 medium)
    array( 'items' => array(
        array( 'priority' => 'high',   'issue' => 'London under-supported', 'affected_pages' => array( 'https://example.com/locations/london/' ), 'recommendation' => 'Add link from homepage', 'confidence_score' => 0.9, 'category' => 'linking' ),
        array( 'priority' => 'medium', 'issue' => 'Web design thin',         'affected_pages' => array( 'https://example.com/services/web-design/' ), 'recommendation' => 'Expand content', 'confidence_score' => 0.7, 'category' => 'content' ),
    ) ),
    // link opportunities
    array( 'items' => array(
        array( 'priority' => 'high', 'source_url' => 'https://example.com/services/seo/', 'target_url' => 'https://example.com/services/web-design/', 'suggested_anchor' => 'custom web design services', 'reason' => 'Lifts authority', 'confidence_score' => 0.85 ),
    ) ),
    // cluster
    array( 'items' => array() ),
    // content gaps (cannibalisation example)
    array( 'items' => array(
        array( 'priority' => 'medium', 'issue' => 'Topic overlap', 'affected_pages' => array( 'https://example.com/services/seo/' ), 'recommendation' => 'Merge', 'confidence_score' => 0.65, 'gap_type' => 'cannibalisation' ),
    ) ),
);

// Context mirrors what the plugin handler passes (linking.near_orphan_pages etc.)
$rich_inputs = array(
    'pages' => array(
        array( 'url' => 'https://example.com/locations/london/', 'title' => 'Bathroom Renovations London', 'strategic_type' => 'location', 'classification' => 'money', 'issues' => array( 'near_orphan' ), 'incoming_link_count' => 1, 'outgoing_link_count' => 0 ),
        array( 'url' => 'https://example.com/services/web-design/', 'title' => 'Custom Web Design', 'strategic_type' => 'service', 'classification' => 'money', 'issues' => array( 'thin_content', 'missing_meta_description' ), 'incoming_link_count' => 1, 'outgoing_link_count' => 1 ),
        array( 'url' => 'https://example.com/services/seo/', 'title' => 'SEO Services', 'strategic_type' => 'service', 'classification' => 'money', 'issues' => array(), 'incoming_link_count' => 3, 'outgoing_link_count' => 2 ),
        array( 'url' => 'https://example.com/legacy/printable/', 'title' => 'Printable version', 'strategic_type' => 'other', 'classification' => 'other', 'issues' => array( 'thin_content' ), 'incoming_link_count' => 0, 'outgoing_link_count' => 0 ),
    ),
    'linking' => array(
        'linking_opportunities' => array(),
        'weak_money_pages'      => array( array( 'url' => 'https://example.com/services/web-design/' ) ),
        'orphan_pages'          => array(),
        'near_orphan_pages'     => array( array( 'url' => 'https://example.com/locations/london/' ) ),
        'duplicate_meta_titles'       => array( array( 'meta_title' => 'home', 'count' => 2, 'urls' => array( 'https://example.com/a/', 'https://example.com/b/' ) ) ),
        'duplicate_meta_descriptions' => array(),
    ),
    'site'    => array( 'totals' => array( 'pages' => 4 ) ),
    'cluster' => array( 'clusters' => array() ),
);

$rich_out     = tse_ai_runner_execute( $fake6, $rich_inputs );
$rich_reports = tse_ai_report_build( $rich_out, $rich_inputs );

$ai_html = $rich_reports['ai-report.html'];

// (a) Title-over-path rendering
check( '[v2.6] affected page shows title (Bathroom Renovations London)', strpos( $ai_html, 'Bathroom Renovations London' ) !== false );
check( '[v2.6] affected page shows path /locations/london/', strpos( $ai_html, '/locations/london/' ) !== false );

// (b) Column header renamed
check( '[v2.6] column header "Type / Confidence" present', strpos( $ai_html, 'Type / Confidence' ) !== false );
check( '[v2.6] old header "Category / Conf." removed', strpos( $ai_html, 'Category / Conf.' ) === false );

// (c) Executive summary cards
check( '[v2.6] exec summary heading rendered', strpos( $ai_html, 'Executive summary' ) !== false );
check( '[v2.6] exec card: High Priority Issues', strpos( $ai_html, 'High Priority Issues' ) !== false );
check( '[v2.6] exec card: Near-Orphan Pages', strpos( $ai_html, 'Near-Orphan Pages' ) !== false );
check( '[v2.6] exec card: Weak Money Pages', strpos( $ai_html, 'Weak Money Pages' ) !== false );
check( '[v2.6] exec card: Cannibalisation Risks', strpos( $ai_html, 'Cannibalisation Risks' ) !== false );
check( '[v2.6] exec card: Thin Content Signals', strpos( $ai_html, 'Thin Content Signals' ) !== false );

// (d) Quick Wins block
check( '[v2.6] quick wins heading rendered', strpos( $ai_html, 'Quick wins' ) !== false );
check( '[v2.6] quick win: add internal link from LLM opportunities', strpos( $ai_html, 'Add internal link' ) !== false );
check( '[v2.6] quick win: fix duplicate meta titles', strpos( $ai_html, 'Fix duplicate meta titles' ) !== false );
check( '[v2.6] quick win: meta description gap on money/service page', strpos( $ai_html, 'meta descriptions for high-value pages' ) !== false );
check( '[v2.6] quick win: noindex utility/thin pages', strpos( $ai_html, 'noindex' ) !== false );

// (e) Page-type pill present somewhere (Service Page / Location Page)
check( '[v2.6] page-type pill "Location Page" appears',
    strpos( $rich_reports['internal-link-report.html'], 'Service Page' ) !== false
    || strpos( $rich_reports['internal-link-report.html'], 'Location Page' ) !== false );

// (f) "Why this matters" helper text
check( '[v2.6] why-this-matters helper present',
    strpos( $ai_html, 'preserves authority where it matters most' ) !== false
    || strpos( $ai_html, 'closes topical gaps' ) !== false
    || strpos( $ai_html, 'easiest/highest impact' ) !== false
    || strpos( $ai_html, 'Quick snapshot' ) !== false
    || strpos( $ai_html, 'authority where it matters' ) !== false
    || strpos( $ai_html, 'Closing topical gaps' ) !== false );

// (g) Wider affected-pages column declared (28% colgroup now V2.7)
check( '[v2.6] affected-pages column ~28% width', strpos( $ai_html, 'style="width:28%"' ) !== false );

// ---------------------------------------------------------------------------
// 11. V2.7.0 layout/usability: width, sticky headers, collapse, grouping,
//     impact badges, priority order, export metrics.
// ---------------------------------------------------------------------------
check( '[v2.7] wider layout: max-width 1600px', strpos( $ai_html, 'max-width: 1600px' ) !== false );
check( '[v2.7] sticky table thead CSS present', strpos( $ai_html, 'position: sticky' ) !== false );

// Collapsible affected pages: feed an item with >3 affected pages.
$fake7 = new TSE_AI_Provider_Fake();
$fake7->canned = array(
    array( 'items' => array(
        array(
            'priority' => 'high', 'issue' => 'Many pages affected', 'recommendation' => 'do x',
            'confidence_score' => 0.9, 'category' => 'metadata',
            'affected_pages' => array(
                'https://example.com/p1/', 'https://example.com/p2/', 'https://example.com/p3/',
                'https://example.com/p4/', 'https://example.com/p5/', 'https://example.com/p6/',
            ),
        ),
        array(
            'priority' => 'medium', 'issue' => 'Linking gap', 'recommendation' => 'add link',
            'confidence_score' => 0.7, 'category' => 'linking',
            'affected_pages' => array( 'https://example.com/services/seo/' ),
        ),
        array(
            'priority' => 'low', 'issue' => 'Cluster fragment', 'recommendation' => 'bridge',
            'confidence_score' => 0.5, 'category' => 'cluster',
            'affected_pages' => array( 'https://example.com/help/faq/' ),
        ),
    ) ),
    array( 'items' => array() ),
    array( 'items' => array() ),
    array( 'items' => array(
        array( 'priority' => 'medium', 'issue' => 'Cannibal', 'recommendation' => 'merge', 'gap_type' => 'cannibalisation', 'affected_pages' => array( 'https://example.com/a/' ), 'confidence_score' => 0.8 ),
        array( 'priority' => 'low',    'issue' => 'Thin', 'recommendation' => 'expand', 'gap_type' => 'thin_content', 'affected_pages' => array( 'https://example.com/services/web-design/' ), 'confidence_score' => 0.6 ),
    ) ),
);
$big_inputs = $rich_inputs;
// Add page metadata for p1..p6 so titles resolve.
for ( $i = 1; $i <= 6; $i++ ) {
    $big_inputs['pages'][] = array(
        'url' => 'https://example.com/p' . $i . '/', 'title' => 'Page ' . $i,
        'strategic_type' => 'other', 'classification' => 'other', 'issues' => array(), 'incoming_link_count' => 1, 'outgoing_link_count' => 1,
    );
}
$big_out     = tse_ai_runner_execute( $fake7, $big_inputs );
$big_reports = tse_ai_report_build( $big_out, $big_inputs );
$big_html    = $big_reports['ai-report.html'];

check( '[v2.7] >3 pages → <details> collapse rendered', strpos( $big_html, '<details>' ) !== false && strpos( $big_html, 'Show 3 more pages' ) !== false );
check( '[v2.7] grouping: Metadata subsection', strpos( $big_html, '>Metadata<' ) !== false );
check( '[v2.7] grouping: Linking subsection', strpos( $big_html, '>Linking<' ) !== false );
check( '[v2.7] grouping: Cluster / Architecture subsection', strpos( $big_html, 'Cluster / Architecture' ) !== false );
check( '[v2.7] grouping: Cannibalisation subsection from gap_type', strpos( $big_html, '>Cannibalisation<' ) !== false );
check( '[v2.7] grouping: Thin Content subsection from gap_type', strpos( $big_html, '>Thin Content<' ) !== false );

// Impact column + badges
check( '[v2.7] Impact column header present', strpos( $big_html, '<th>Impact</th>' ) !== false );
check( '[v2.7] High SEO Impact badge rendered', strpos( $big_html, 'High SEO Impact' ) !== false );
check( '[v2.7] Medium SEO Impact badge rendered', strpos( $big_html, 'Medium SEO Impact' ) !== false );
check( '[v2.7] Low SEO Impact badge rendered', strpos( $big_html, 'Low SEO Impact' ) !== false );

// Suggested priority order
check( '[v2.7] "Suggested priority order" section', strpos( $big_html, 'Suggested priority order' ) !== false );
check( '[v2.7] priority order col: Fix first', strpos( $big_html, 'Fix first' ) !== false );
check( '[v2.7] priority order col: Fix next',  strpos( $big_html, 'Fix next' ) !== false );
check( '[v2.7] priority order col: Fix later', strpos( $big_html, 'Fix later' ) !== false );

// Export summary metrics
check( '[v2.7] Export summary metrics section', strpos( $big_html, 'Export summary metrics' ) !== false );
check( '[v2.7] metric: Pages analysed', strpos( $big_html, 'Pages analysed' ) !== false );
check( '[v2.7] metric: Money pages', strpos( $big_html, 'Money pages' ) !== false );
check( '[v2.7] metric: Location pages', strpos( $big_html, 'Location pages' ) !== false );
check( '[v2.7] metric: Support pages', strpos( $big_html, 'Support pages' ) !== false );
check( '[v2.7] metric: Orphan pages', strpos( $big_html, 'Orphan pages' ) !== false );
check( '[v2.7] metric: Near-orphan pages', strpos( $big_html, 'Near-orphan pages' ) !== false );
check( '[v2.7] metric: Weak money pages', strpos( $big_html, 'Weak money pages' ) !== false );
check( '[v2.7] metric: Link opportunities', strpos( $big_html, 'Link opportunities' ) !== false );

echo "\n";
if ( $fail === 0 ) { echo "ALL ASSERTIONS PASS\n"; exit( 0 ); }
echo "FAILED: $fail assertion(s)\n"; exit( 1 );
