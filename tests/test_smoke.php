<?php
/**
 * Minimal PHP CLI smoke test stubbing WordPress functions for wp-apify-connector.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'AUTH_KEY', 'test-auth-key-32-bytes-minimum-seed' );
define( 'SECURE_AUTH_SALT', 'test-secure-salt' );

$GLOBALS['__wp_options']    = array();
$GLOBALS['__wp_transients'] = array();
$GLOBALS['__wp_shortcodes'] = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function add_action( $hook, $fn, $p = 10, $args = 1 ) {}
function add_shortcode( $tag, $fn ) { $GLOBALS['__wp_shortcodes'][ $tag ] = $fn; }
function get_option( $k, $default = false ) { return $GLOBALS['__wp_options'][ $k ] ?? $default; }
function get_transient( $k ) { return $GLOBALS['__wp_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['__wp_transients'][ $k ] = $v; return true; }
function absint( $n ) { return abs( (int) $n ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_URL ) ?: ''; }
function __( $s, $d = 'default' ) { return $s; }
function esc_html__( $s, $d = 'default' ) { return esc_html( $s ); }
function esc_attr__( $s, $d = 'default' ) { return esc_attr( $s ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function wp_remote_get( $url, $args = array() ) { return new WP_Error( 'stub', 'stubbed' ); }
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return '[]'; }

require_once dirname( __DIR__ ) . '/wp-apify-connector.php';

// 1. Encryption round-trip
$secret = 'apify_api_secret_token_12345';
$enc    = wp_apify_connector_encrypt_token( $secret );
assert( 0 === strpos( $enc, 'gcm:' ), 'Token should encrypt with AES-256-GCM' );
assert( wp_apify_connector_decrypt_token( $enc ) === $secret, 'Token round-trip must match' );

// 2. Sanitization preserves encrypted token unless replaced or cleared
$sanitized = wp_apify_connector_sanitize_options(
	array(
		'api_token'           => $secret,
		'default_source_id'   => 'brasil-api-lcm/apify-brazil-cvm',
		'default_source_type' => 'actor',
		'default_cache_ttl'   => 15, // below min 60 -> clamped
		'publish_trigger_on'  => 1,
	)
);
assert( 60 === $sanitized['default_cache_ttl'], 'Cache TTL must clamp at minimum 60s' );
assert( wp_apify_connector_decrypt_token( $sanitized['api_token_enc'] ) === $secret, 'Sanitized token decrypts' );

// 3. URL builder
$task_url = wp_apify_connector_build_url( 'brasil-api-lcm/brazil-fii-reits-dividends-equity', 'task', 25 );
assert( false !== strpos( $task_url, 'v2/actor-tasks/brasil-api-lcm~brazil-fii-reits-dividends-equity/runs/last/dataset/items' ), 'Task URL normalized slash to tilde' );

// 4. HTML table & XSS escaping
$html = wp_apify_connector_render_items_html(
	array(
		array(
			'name'      => '<script>alert(1)</script>BTGP LOGISTICA',
			'sourceUrl' => 'https://dados.cvm.gov.br/',
		),
	),
	array( 'name', 'sourceUrl' ),
	'table',
	'CVM Sample'
);
assert( false === strpos( $html, '<script>' ), 'XSS script tag must be escaped' );
assert( false !== strpos( $html, 'https://dados.cvm.gov.br/' ), 'URL rendered as safe link' );

echo "wp-apify-connector smoke tests: 4/4 OK\n";
