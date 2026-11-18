<?php
/**
 * Plugin Name:       WP Apify Connector
 * Plugin URI:        https://github.com/lucas0maia/wp-apify-connector
 * Description:       Embed live Apify Actor & Task datasets in WordPress with Transient caching and trigger Apify workflows automatically on post publish.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Lucas Maia
 * Author URI:        https://apify.com/brasil-api-lcm
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-apify-connector
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_APIFY_CONNECTOR_VERSION', '1.0.0' );
define( 'WP_APIFY_CONNECTOR_OPTION', 'wp_apify_connector_options' );
define( 'WP_APIFY_CONNECTOR_PREFIX', 'wp_apify_ds_' );

/**
 * Derive a deterministic site-specific encryption key for credential storage.
 *
 * @return string Binary 32-byte key.
 */
function wp_apify_connector_derive_key() {
	$material = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : (string) get_option( 'siteurl', 'wp-apify-default' );
	$salt     = defined( 'SECURE_AUTH_SALT' ) && SECURE_AUTH_SALT ? SECURE_AUTH_SALT : 'wp-apify-connector';
	return hash( 'sha256', $material . '|' . $salt, true );
}

/**
 * Encrypt sensitive token before saving into wp_options.
 *
 * @param string $plaintext Plain API token.
 * @return string Prefixed ciphertext or empty string.
 */
function wp_apify_connector_encrypt_token( $plaintext ) {
	$plaintext = trim( (string) $plaintext );
	if ( '' === $plaintext ) {
		return '';
	}
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return 'b64:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}
	$key = wp_apify_connector_derive_key();
	$iv  = random_bytes( 12 );
	$tag = '';
	$enc = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
	if ( false === $enc ) {
		return '';
	}
	return 'gcm:' . base64_encode( $iv . $tag . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Decrypt stored token from wp_options.
 *
 * @param string $stored Encrypted token string.
 * @return string Decrypted token or empty string.
 */
function wp_apify_connector_decrypt_token( $stored ) {
	$stored = (string) $stored;
	if ( '' === $stored ) {
		return '';
	}
	if ( 0 === strpos( $stored, 'b64:' ) ) {
		$decoded = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false !== $decoded ? $decoded : '';
	}
	if ( 0 !== strpos( $stored, 'gcm:' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( false === $raw || strlen( $raw ) <= 28 ) {
		return '';
	}
	$iv  = substr( $raw, 0, 12 );
	$tag = substr( $raw, 12, 16 );
	$enc = substr( $raw, 28 );
	$dec = openssl_decrypt( $enc, 'aes-256-gcm', wp_apify_connector_derive_key(), OPENSSL_RAW_DATA, $iv, $tag );
	return false !== $dec ? $dec : '';
}

/**
 * Retrieve plugin options with safe defaults.
 *
 * @return array<string,mixed>
 */
function wp_apify_connector_get_options() {
	$defaults = array(
		'api_token_enc'      => '',
		'default_source_id'  => '',
		'default_source_type'=> 'dataset',
		'default_cache_ttl'  => 3600,
		'publish_actor_id'   => '',
		'publish_trigger_on' => 0,
	);
	$saved    = get_option( WP_APIFY_CONNECTOR_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( $defaults, $saved );
}

/**
 * Sanitize settings form input.
 *
 * @param array<string,mixed> $input Raw POST input.
 * @return array<string,mixed>
 */
function wp_apify_connector_sanitize_options( $input ) {
	$existing = wp_apify_connector_get_options();
	$output   = $existing;

	if ( ! is_array( $input ) ) {
		return $output;
	}

	if ( ! empty( $input['clear_token'] ) ) {
		$output['api_token_enc'] = '';
	} elseif ( isset( $input['api_token'] ) && '' !== trim( (string) $input['api_token'] ) ) {
		$token = sanitize_text_field( wp_unslash( (string) $input['api_token'] ) );
		$output['api_token_enc'] = wp_apify_connector_encrypt_token( $token );
	}

	$output['default_source_id']   = isset( $input['default_source_id'] )
		? sanitize_text_field( wp_unslash( (string) $input['default_source_id'] ) )
		: '';
	$source_type                   = isset( $input['default_source_type'] )
		? sanitize_key( (string) $input['default_source_type'] )
		: 'dataset';
	$output['default_source_type'] = in_array( $source_type, array( 'dataset', 'task', 'actor' ), true ) ? $source_type : 'dataset';

	$ttl = isset( $input['default_cache_ttl'] ) ? absint( $input['default_cache_ttl'] ) : 3600;
	$output['default_cache_ttl'] = max( 60, min( 86400, $ttl ) );

	$output['publish_actor_id']   = isset( $input['publish_actor_id'] )
		? sanitize_text_field( wp_unslash( (string) $input['publish_actor_id'] ) )
		: '';
	$output['publish_trigger_on'] = ! empty( $input['publish_trigger_on'] ) ? 1 : 0;

	return $output;
}

/**
 * Register admin settings page.
 */
function wp_apify_connector_admin_menu() {
	add_options_page(
		__( 'Apify Connector', 'wp-apify-connector' ),
		__( 'Apify Connector', 'wp-apify-connector' ),
		'manage_options',
		'wp-apify-connector',
		'wp_apify_connector_render_settings_page'
	);
}
add_action( 'admin_menu', 'wp_apify_connector_admin_menu' );

/**
 * Register plugin settings.
 */
function wp_apify_connector_register_settings() {
	register_setting(
		'wp_apify_connector_group',
		WP_APIFY_CONNECTOR_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'wp_apify_connector_sanitize_options',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'wp_apify_connector_register_settings' );

/**
 * Handle manual transient cache purge from Settings page.
 */
function wp_apify_connector_handle_clear_cache() {
	if ( ! isset( $_POST['wp_apify_clear_cache_nonce'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'wp_apify_clear_cache_action', 'wp_apify_clear_cache_nonce' );

	global $wpdb;
	$like_transient = $wpdb->esc_like( '_transient_' . WP_APIFY_CONNECTOR_PREFIX ) . '%';
	$like_timeout   = $wpdb->esc_like( '_transient_timeout_' . WP_APIFY_CONNECTOR_PREFIX ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_transient, $like_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	add_settings_error(
		'wp_apify_connector_messages',
		'cache_cleared',
		__( 'Apify dataset cache cleared.', 'wp-apify-connector' ),
		'updated'
	);
}
add_action( 'admin_init', 'wp_apify_connector_handle_clear_cache' );

/**
 * Render admin settings page.
 */
function wp_apify_connector_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opts      = wp_apify_connector_get_options();
	$has_token = '' !== wp_apify_connector_decrypt_token( $opts['api_token_enc'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'WP Apify Connector', 'wp-apify-connector' ); ?></h1>
		<?php settings_errors( 'wp_apify_connector_messages' ); ?>
		<p>
			<?php esc_html_e( 'Embed live Apify Actor or Task datasets using the shortcode:', 'wp-apify-connector' ); ?>
			<code>[apify_dataset id="YOUR_DATASET_OR_TASK_ID" type="dataset" limit="20" cache="3600"]</code>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'wp_apify_connector_group' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wp_apify_token"><?php esc_html_e( 'Apify API Token (optional for public datasets)', 'wp-apify-connector' ); ?></label></th>
					<td>
						<input type="password" id="wp_apify_token" name="<?php echo esc_attr( WP_APIFY_CONNECTOR_OPTION ); ?>[api_token]" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_token ? esc_attr__( 'Token saved (enter new to replace)', 'wp-apify-connector' ) : esc_attr__( 'apify_api_...', 'wp-apify-connector' ); ?>" />
						<?php if ( $has_token ) : ?>
							<label style="margin-left:10px;">
								<input type="checkbox" name="<?php echo esc_attr( WP_APIFY_CONNECTOR_OPTION ); ?>[clear_token]" value="1" />
								<?php esc_html_e( 'Remove saved token', 'wp-apify-connector' ); ?>
							</label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Stored encrypted with AES-256-GCM using your site auth keys. Required for private datasets or post-publish triggers.', 'wp-apify-connector' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wp_apify_default_id"><?php esc_html_e( 'Default Dataset / Task / Actor ID', 'wp-apify-connector' ); ?></label></th>
					<td>
						<input type="text" id="wp_apify_default_id" name="<?php echo esc_attr( WP_APIFY_CONNECTOR_OPTION ); ?>[default_source_id]" value="<?php echo esc_attr( (string) $opts['default_source_id'] ); ?>" class="regular-text" placeholder="brasil-api-lcm~brazil-fii-reits-dividends-equity" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wp_apify_default_type"><?php esc_html_e( 'Default Source Type', 'wp-apify-connector' ); ?></label></th>
					<td>
						<select id="wp_apify_default_type" name="<?php echo esc_attr( WP_APIFY_CONNECTOR_OPTION ); ?>[default_source_type]">
							<option value="dataset" <?php selected( $opts['default_source_type'], 'dataset' ); ?>><?php esc_html_e( 'Dataset ID', 'wp-apify-connector' ); ?></option>
							<option value="task" <?php selected( $opts['default_source_type'], 'task' ); ?>><?php esc_html_e( 'Task ID / slug (last run)', 'wp-apify-connector' ); ?></option>
							<option value="actor" <?php selected( $opts['default_source_type'], 'actor' ); ?>><?php esc_html_e( 'Actor ID / slug (last run)', 'wp-apify-connector' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wp_apify_ttl"><?php esc_html_e( 'Default Cache TTL (seconds)', 'wp-apify-connector' ); ?></label></th>
					<td>
						<input type="number" min="60" max="86400" id="wp_apify_ttl" name="<?php echo esc_attr( WP_APIFY_CONNECTOR_OPTION ); ?>[default_cache_ttl]" value="<?php echo esc_attr( (string) absint( $opts['default_cache_ttl'] ) ); ?>" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wp_apify_publish_actor"><?php esc_html_e( 'On-Publish Apify Actor / Task ID', 'wp-apify-connector' ); ?></label></th>
					<td>
						<input type="text" id="wp_apify_publish_actor" name="<?php echo esc_attr( WP_APIFY_CONNECTOR_OPTION ); ?>[publish_actor_id]" value="<?php echo esc_attr( (string) $opts['publish_actor_id'] ); ?>" class="regular-text" placeholder="username~actor-name" />
						<label style="display:block;margin-top:6px;">
							<input type="checkbox" name="<?php echo esc_attr( WP_APIFY_CONNECTOR_OPTION ); ?>[publish_trigger_on]" value="1" <?php checked( 1, absint( $opts['publish_trigger_on'] ) ); ?> />
							<?php esc_html_e( 'Trigger non-blocking Apify run when a new post is published', 'wp-apify-connector' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr />
		<form method="post">
			<?php wp_nonce_field( 'wp_apify_clear_cache_action', 'wp_apify_clear_cache_nonce' ); ?>
			<?php submit_button( __( 'Clear Cached Apify Datasets', 'wp-apify-connector' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/**
 * Build Apify API endpoint URL for dataset items.
 *
 * @param string $id    Dataset, Task, or Actor identifier (e.g. "abc123" or "user~name").
 * @param string $type  One of dataset|task|actor.
 * @param int    $limit Max rows (1-100).
 * @return string
 */
function wp_apify_connector_build_url( $id, $type, $limit ) {
	$clean_id = rawurlencode( str_replace( '/', '~', trim( (string) $id ) ) );
	$limit    = max( 1, min( 100, absint( $limit ) ) );
	$query    = '?clean=true&format=json&limit=' . $limit;

	if ( 'task' === $type ) {
		return 'https://api.apify.com/v2/actor-tasks/' . $clean_id . '/runs/last/dataset/items' . $query;
	}
	if ( 'actor' === $type ) {
		return 'https://api.apify.com/v2/acts/' . $clean_id . '/runs/last/dataset/items' . $query;
	}
	return 'https://api.apify.com/v2/datasets/' . $clean_id . '/items' . $query;
}

/**
 * Fetch and cache dataset rows from Apify API v2.
 *
 * @param string $id    Source ID.
 * @param string $type  Source type (dataset|task|actor).
 * @param int    $limit Max rows.
 * @param int    $ttl   Transient TTL in seconds.
 * @return array<int,array<string,mixed>>|WP_Error
 */
function wp_apify_connector_fetch_items( $id, $type, $limit, $ttl ) {
	$cache_key = WP_APIFY_CONNECTOR_PREFIX . md5( $type . '|' . $id . '|' . $limit );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$opts    = wp_apify_connector_get_options();
	$token   = wp_apify_connector_decrypt_token( $opts['api_token_enc'] );
	$headers = array( 'Accept' => 'application/json' );
	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	$url      = wp_apify_connector_build_url( $id, $type, $limit );
	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 12,
			'headers' => $headers,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = wp_remote_retrieve_body( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error(
			'apify_http_error',
			sprintf( 'Apify API returned HTTP %d.', $status )
		);
	}

	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'apify_json_error', 'Invalid JSON returned by Apify API.' );
	}

	$items = array_values( array_filter( $decoded, 'is_array' ) );
	set_transient( $cache_key, $items, max( 60, absint( $ttl ) ) );
	return $items;
}

/**
 * Format scalar or nested cell value safely for HTML output.
 *
 * @param mixed $val Cell value.
 * @return string Safe HTML string.
 */
function wp_apify_connector_format_cell( $val ) {
	if ( is_null( $val ) ) {
		return '';
	}
	if ( is_bool( $val ) ) {
		return $val ? 'true' : 'false';
	}
	if ( is_array( $val ) ) {
		$json = wp_json_encode( $val );
		return esc_html( is_string( $json ) ? $json : '' );
	}
	$str = (string) $val;
	if ( preg_match( '#^https?://#i', $str ) ) {
		return '<a href="' . esc_url( $str ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $str ) . '</a>';
	}
	return esc_html( $str );
}

/**
 * Render Apify dataset items into clean responsive HTML.
 *
 * @param array<int,array<string,mixed>> $items   Dataset rows.
 * @param array<int,string>              $columns Ordered field names to show.
 * @param string                         $format  table|cards.
 * @param string                         $title   Optional heading.
 * @return string
 */
function wp_apify_connector_render_items_html( $items, $columns, $format, $title ) {
	if ( empty( $items ) ) {
		return '<p class="wp-apify-empty">' . esc_html__( 'No dataset records available.', 'wp-apify-connector' ) . '</p>';
	}

	if ( empty( $columns ) ) {
		$columns = array_slice( array_keys( $items[0] ), 0, 8 );
	}

	$html = '<div class="wp-apify-connector-wrap">';
	if ( '' !== $title ) {
		$html .= '<h3 class="wp-apify-title">' . esc_html( $title ) . '</h3>';
	}

	if ( 'cards' === $format ) {
		$html .= '<div class="wp-apify-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">';
		foreach ( $items as $row ) {
			$html .= '<div class="wp-apify-card" style="border:1px solid #dcdcde;border-radius:8px;padding:12px;background:#fff;">';
			foreach ( $columns as $col ) {
				if ( ! array_key_exists( $col, $row ) ) {
					continue;
				}
				$html .= '<div style="margin-bottom:6px;"><strong>' . esc_html( $col ) . ':</strong> ' . wp_apify_connector_format_cell( $row[ $col ] ) . '</div>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';
	} else {
		$html .= '<div style="overflow-x:auto;"><table class="wp-apify-table" style="width:100%;border-collapse:collapse;"><thead><tr>';
		foreach ( $columns as $col ) {
			$html .= '<th style="text-align:left;padding:8px;border-bottom:2px solid #dcdcde;">' . esc_html( $col ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $items as $row ) {
			$html .= '<tr>';
			foreach ( $columns as $col ) {
				$val   = array_key_exists( $col, $row ) ? $row[ $col ] : '';
				$html .= '<td style="padding:8px;border-bottom:1px solid #eee;">' . wp_apify_connector_format_cell( $val ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
	}

	$html .= '</div>';
	return $html;
}

/**
 * Shortcode handler: [apify_dataset].
 *
 * @param array<string,mixed>|string $atts Shortcode attributes.
 * @return string
 */
function wp_apify_connector_shortcode( $atts ) {
	$opts = wp_apify_connector_get_options();
	$a    = shortcode_atts(
		array(
			'id'     => (string) $opts['default_source_id'],
			'type'   => (string) $opts['default_source_type'],
			'limit'  => 20,
			'cache'  => (int) $opts['default_cache_ttl'],
			'fields' => '',
			'format' => 'table',
			'title'  => '',
		),
		is_array( $atts ) ? $atts : array(),
		'apify_dataset'
	);

	$id = sanitize_text_field( (string) $a['id'] );
	if ( '' === $id ) {
		return '<p class="wp-apify-error">' . esc_html__( 'WP Apify Connector: missing dataset, task, or actor id.', 'wp-apify-connector' ) . '</p>';
	}

	$type   = in_array( $a['type'], array( 'dataset', 'task', 'actor' ), true ) ? $a['type'] : 'dataset';
	$limit  = max( 1, min( 100, absint( $a['limit'] ) ) );
	$ttl    = max( 60, min( 86400, absint( $a['cache'] ) ) );
	$format = in_array( $a['format'], array( 'table', 'cards' ), true ) ? $a['format'] : 'table';
	$title  = sanitize_text_field( (string) $a['title'] );

	$columns = array();
	if ( '' !== trim( (string) $a['fields'] ) ) {
		$columns = array_values( array_filter( array_map( 'trim', explode( ',', (string) $a['fields'] ) ) ) );
	}

	$items = wp_apify_connector_fetch_items( $id, $type, $limit, $ttl );
	if ( is_wp_error( $items ) ) {
		return '<p class="wp-apify-error">' . esc_html( $items->get_error_message() ) . '</p>';
	}

	return wp_apify_connector_render_items_html( $items, $columns, $format, $title );
}
add_shortcode( 'apify_dataset', 'wp_apify_connector_shortcode' );

/**
 * Trigger configured Apify Actor on post publish.
 *
 * @param string  $new_status New post status.
 * @param string  $old_status Previous post status.
 * @param WP_Post $post       Post object.
 */
function wp_apify_connector_on_publish( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( ! $post || ! isset( $post->post_type ) || 'post' !== $post->post_type ) {
		return;
	}
	if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return;
	}

	$opts = wp_apify_connector_get_options();
	if ( empty( $opts['publish_trigger_on'] ) || '' === trim( (string) $opts['publish_actor_id'] ) ) {
		return;
	}
	$token = wp_apify_connector_decrypt_token( $opts['api_token_enc'] );
	if ( '' === $token ) {
		return;
	}

	$actor_id = rawurlencode( str_replace( '/', '~', trim( (string) $opts['publish_actor_id'] ) ) );
	$payload  = array(
		'postId'    => (int) $post->ID,
		'title'     => (string) get_the_title( $post->ID ),
		'url'       => (string) get_permalink( $post->ID ),
		'published' => (string) get_post_time( 'c', true, $post->ID ),
	);

	wp_remote_post(
		'https://api.apify.com/v2/acts/' . $actor_id . '/runs',
		array(
			'timeout'  => 2,
			'blocking' => false,
			'headers'  => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'     => wp_json_encode( $payload ),
		)
	);
}
add_action( 'transition_post_status', 'wp_apify_connector_on_publish', 10, 3 );
