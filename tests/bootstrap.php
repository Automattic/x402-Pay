<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! defined( 'X402_PAY_FILE' ) ) {
	define( 'X402_PAY_FILE', __DIR__ . '/../x402-pay.php' );
}
if ( ! defined( 'X402_PAY_VERSION' ) ) {
	define( 'X402_PAY_VERSION', '0.0.0-test' );
}
if ( ! defined( 'X402_PAY_DIR' ) ) {
	define( 'X402_PAY_DIR', dirname( __DIR__ ) . '/' );
}
// WordPress time constants — defined globally in wp-includes/default-constants.php
// at runtime, but not present in the unit-test bootstrap.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) define( 'MINUTE_IN_SECONDS', 60 );
if ( ! defined( 'HOUR_IN_SECONDS' ) )   define( 'HOUR_IN_SECONDS', 3600 );
if ( ! defined( 'DAY_IN_SECONDS' ) )    define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'WEEK_IN_SECONDS' ) )   define( 'WEEK_IN_SECONDS', 604800 );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param string|array $value Value to unslash.
	 * @return string|array
	 */
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0 ): string|false {
		return json_encode( $data, $options );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $s ): string {
		return rtrim( $s, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['__x402_pay_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['__x402_pay_options'][ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $name ): bool {
		unset( $GLOBALS['__x402_pay_options'][ $name ] );
		return true;
	}
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $timestamp = null ): string {
		return gmdate( $format, $timestamp ?? time() );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['__x402_pay_http'] = array( 'url' => $url, 'args' => $args );
		if ( ! empty( $GLOBALS['__x402_pay_http_queue'] ) ) {
			return array_shift( $GLOBALS['__x402_pay_http_queue'] );
		}
		$next = $GLOBALS['__x402_pay_http_next'] ?? null;
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		return $next ?? array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
}
if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( string $url, array $args = array() ) {
		$GLOBALS['__x402_pay_http'] = array( 'url' => $url, 'args' => $args, 'method' => 'HEAD' );
		if ( ! empty( $GLOBALS['__x402_pay_http_queue'] ) ) {
			return array_shift( $GLOBALS['__x402_pay_http_queue'] );
		}
		$next = $GLOBALS['__x402_pay_http_next'] ?? null;
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		return $next ?? array( 'response' => array( 'code' => 200 ), 'body' => '' );
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ) {
		$GLOBALS['__x402_pay_http'] = array(
			'url'    => $url,
			'args'   => $args,
			'method' => $args['method'] ?? 'GET',
		);
		if ( ! empty( $GLOBALS['__x402_pay_http_queue'] ) ) {
			return array_shift( $GLOBALS['__x402_pay_http_queue'] );
		}
		$next = $GLOBALS['__x402_pay_http_next'] ?? null;
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		return $next ?? array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['__x402_pay_filters'][ $hook ] ?? array() as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['__x402_pay_filters'][ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'has_term' ) ) {
	/**
	 * @param string|int $term Term name or id.
	 */
	function has_term( $term, string $taxonomy, int $post_id ): bool {
		return in_array( array( $term, $taxonomy, $post_id ), $GLOBALS['__x402_pay_terms'] ?? array(), true );
	}
}
if ( ! function_exists( 'term_exists' ) ) {
	/**
	 * @param string|int $term Term name or id.
	 */
	function term_exists( $term, string $taxonomy ) {
		$is_id = is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) );
		foreach ( $GLOBALS['__x402_pay_existing_terms'] ?? array() as $row ) {
			if ( $row['taxonomy'] !== $taxonomy ) {
				continue;
			}
			$matches = $is_id
				? (int) $row['term_id'] === (int) $term
				: $row['name'] === $term;
			if ( $matches ) {
				return array( 'term_id' => $row['term_id'] );
			}
		}
		return null;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( int $term_id, string $taxonomy = '' ) {
		foreach ( $GLOBALS['__x402_pay_existing_terms'] ?? array() as $row ) {
			if ( $row['term_id'] === $term_id
				&& ( '' === $taxonomy || $row['taxonomy'] === $taxonomy )
			) {
				$term           = new \stdClass();
				$term->term_id  = $row['term_id'];
				$term->name     = $row['name'];
				$term->taxonomy = $row['taxonomy'];
				$term->count    = (int) ( $row['count'] ?? 0 );
				return $term;
			}
		}
		return null;
	}
}
if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( string $term, string $taxonomy ) {
		$term_id = count( $GLOBALS['__x402_pay_existing_terms'] ?? array() ) + 1;
		$row     = array(
			'term_id'  => $term_id,
			'name'     => $term,
			'taxonomy' => $taxonomy,
		);
		$GLOBALS['__x402_pay_existing_terms'][] = $row;
		$GLOBALS['__x402_pay_inserted_terms'][] = $row;
		return array( 'term_id' => $term_id );
	}
}
if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( int $term_id, string $taxonomy, array $args = array() ) {
		foreach ( $GLOBALS['__x402_pay_existing_terms'] as $idx => $row ) {
			if ( $row['term_id'] === $term_id && $row['taxonomy'] === $taxonomy ) {
				if ( isset( $args['name'] ) ) {
					$GLOBALS['__x402_pay_existing_terms'][ $idx ]['name'] = (string) $args['name'];
				}
				return array( 'term_id' => $term_id );
			}
		}
		return new \WP_Error( 'invalid_term', 'Term not found.' );
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id ): string|false {
		return $GLOBALS['__x402_pay_posts'][ $post_id ]['post_type'] ?? false;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post_id ): string|false {
		return $GLOBALS['__x402_pay_posts'][ $post_id ]['post_status'] ?? false;
	}
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! function_exists( 'get_post' ) ) {
	/**
	 * @param int|\WP_Post|null $post Post ID or object.
	 */
	function get_post( $post = null, $output = OBJECT, $filter = 'raw' ) {
		if ( is_object( $post ) && isset( $post->ID ) ) {
			return $post;
		}
		$post_id = (int) $post;
		if ( $post_id <= 0 ) {
			return null;
		}
		$row = $GLOBALS['__x402_pay_posts'][ $post_id ] ?? null;
		if ( ! is_array( $row ) ) {
			return null;
		}
		$obj = new \stdClass();
		$obj->ID = $post_id;
		foreach ( $row as $key => $value ) {
			$obj->{$key} = $value;
		}
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $key ) {
			if ( ! isset( $obj->{$key} ) ) {
				$obj->{$key} = '';
			}
		}
		return $obj;
	}
}
if ( ! function_exists( 'get_site_icon_url' ) ) {
	function get_site_icon_url( int $size = 512, string $url = '', int $blog_id = 0 ): string {
		return (string) ( $GLOBALS['__x402_pay_site_icon_url'] ?? '' );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * @param string $show   Blog info key (e.g. 'name').
	 * @param string $filter Same contract as core: 'raw' | 'display' | …
	 */
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		return (string) ( $GLOBALS['__x402_pay_bloginfo'][ $show ] ?? '' );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $string, bool $remove_breaks = false ): string {
		return trim( strip_tags( $string ) );
	}
}
if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( string $text, int $num_words = 55, ?string $more = null ): string {
		if ( '' === $text ) {
			return '';
		}
		$suffix = null !== $more ? $more : '…';
		if ( preg_match_all( '/\S+/u', $text, $matches ) ) {
			$words = $matches[0];
			if ( count( $words ) <= $num_words ) {
				return $text;
			}
			return implode( ' ', array_slice( $words, 0, $num_words ) ) . $suffix;
		}
		return $text;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		$entry = $GLOBALS['__x402_pay_transients'][ $key ] ?? null;
		if ( null === $entry ) {
			return false;
		}
		if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
			unset( $GLOBALS['__x402_pay_transients'][ $key ] );
			return false;
		}
		return $entry['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['__x402_pay_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $ttl > 0 ? time() + $ttl : 0,
		);
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', ?string $scheme = null ): string {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void {
		$GLOBALS['x402_pay_response']['status'] = $code;
	}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {}
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( string $group, string $option, array $args = array() ): void {
		$GLOBALS['__x402_pay_registered_settings'][ $group ][ $option ] = $args;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		$caps = $GLOBALS['__x402_pay_current_user_caps'] ?? null;
		if ( is_array( $caps ) ) {
			return in_array( $cap, $caps, true );
		}
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['__x402_pay_current_user_id'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action = '-1' ): string {
		$uid = get_current_user_id();
		return hash_hmac( 'sha256', $action . '|' . $uid, 'x402-pay-test-nonce-secret' );
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): int|false {
		$expected = wp_create_nonce( $action );
		return hash_equals( $expected, $nonce ) ? 1 : false;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * @param array<string,mixed> $args
	 * @return array<int,\WP_Post|int>
	 */
	function get_posts( array $args = array() ): array {
		return $GLOBALS['__x402_pay_get_posts_return'] ?? array();
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * @param int|\WP_Post $post Post ID or object.
	 */
	function get_permalink( $post = 0, bool $leavename = false ): string|false {
		if ( is_object( $post ) ) {
			$post = (int) $post->ID;
		}
		$post = (int) $post;
		return 'https://example.test/p/' . $post . '/';
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( $action = -1, $query_arg = false, $stop = true ): int|false {
		return 1;
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( $data = null ): void {
		$GLOBALS['__x402_pay_json_success'] = $data;
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( $data = null, int $status_code = 0 ): void {
		$GLOBALS['__x402_pay_json_error']             = $data;
		$GLOBALS['__x402_pay_json_error_status_code'] = $status_code;
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( string $text ): string {
		return str_replace(
			array( '\\', "'", '"', "\n", "\r" ),
			array( '\\\\', "\\'", '\\"', '\\n', '\\r' ),
			$text
		);
	}
}
if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( string $group ): void {}
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button(): void {}
}
if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['__x402_pay_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}
if ( ! function_exists( 'settings_errors' ) ) {
	function settings_errors( string $setting = '' ): void {
		$GLOBALS['__x402_pay_settings_errors_rendered'] = true;
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, bool $echo = true ): string {
		$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( $disabled, $current = true, bool $echo = true ): string {
		$out = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
}
if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'https://example.test/wp-content/plugins/x402-pay/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, $in_footer = false ): bool {
		$registered = $GLOBALS['__x402_pay_registered_scripts'][ $handle ] ?? array();
		$GLOBALS['__x402_pay_enqueued_scripts'][ $handle ] = array(
			'src'       => $src,
			'deps'      => $deps,
			'ver'       => $ver,
			'in_footer' => $in_footer,
		) + $registered;
		if ( '' === $src && isset( $registered['src'] ) ) {
			$GLOBALS['__x402_pay_enqueued_scripts'][ $handle ]['src'] = $registered['src'];
		}
		return true;
	}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( string $handle, string $src, array $deps = array(), $ver = false, $args = array() ): bool {
		$GLOBALS['__x402_pay_registered_scripts'][ $handle ] = array(
			'src'  => $src,
			'deps' => $deps,
			'ver'  => $ver,
			'args' => $args,
		);
		return true;
	}
}
if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		$position = 'before' === $position ? 'before' : 'after';
		$GLOBALS['__x402_pay_inline_scripts'][ $handle ][ $position ][] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_print_scripts' ) ) {
	function wp_print_scripts( $handles = false ): array {
		$handles = false === $handles ? array_keys( $GLOBALS['__x402_pay_enqueued_scripts'] ?? array() ) : (array) $handles;
		foreach ( $handles as $handle ) {
			$script = $GLOBALS['__x402_pay_enqueued_scripts'][ $handle ]
				?? $GLOBALS['__x402_pay_registered_scripts'][ $handle ]
				?? null;
			if ( ! is_array( $script ) ) {
				continue;
			}
			foreach ( $GLOBALS['__x402_pay_inline_scripts'][ $handle ]['before'] ?? array() as $inline ) {
				echo '<script>' . $inline . '</script>';
			}
			$defer = isset( $script['args']['strategy'] ) && 'defer' === $script['args']['strategy'] ? ' defer' : '';
			echo '<script src="' . esc_url( (string) $script['src'] ) . '"' . $defer . '></script>';
			foreach ( $GLOBALS['__x402_pay_inline_scripts'][ $handle ]['after'] ?? array() as $inline ) {
				echo '<script>' . $inline . '</script>';
			}
		}
		return $handles;
	}
}
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( string $handle, string $object_name, array $data ): bool {
		$GLOBALS['__x402_pay_localized_data'][ $handle ][ $object_name ] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false, string $media = 'all' ): bool {
		$registered = $GLOBALS['__x402_pay_registered_styles'][ $handle ] ?? array();
		$GLOBALS['__x402_pay_enqueued_styles'][ $handle ] = array(
			'src'   => $src,
			'deps'  => $deps,
			'ver'   => $ver,
			'media' => $media,
		) + $registered;
		if ( '' === $src && isset( $registered['src'] ) ) {
			$GLOBALS['__x402_pay_enqueued_styles'][ $handle ]['src'] = $registered['src'];
		}
		return true;
	}
}
if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( string $handle, $src = '', array $deps = array(), $ver = false, string $media = 'all' ): bool {
		$GLOBALS['__x402_pay_registered_styles'][ $handle ] = array(
			'src'   => $src,
			'deps'  => $deps,
			'ver'   => $ver,
			'media' => $media,
		);
		return true;
	}
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( string $handle, string $data ): bool {
		$GLOBALS['__x402_pay_inline_styles'][ $handle ][] = $data;
		return true;
	}
}
if ( ! function_exists( 'wp_print_styles' ) ) {
	function wp_print_styles( $handles = false ): array {
		$handles = false === $handles ? array_keys( $GLOBALS['__x402_pay_enqueued_styles'] ?? array() ) : (array) $handles;
		foreach ( $handles as $handle ) {
			$style = $GLOBALS['__x402_pay_enqueued_styles'][ $handle ]
				?? $GLOBALS['__x402_pay_registered_styles'][ $handle ]
				?? null;
			if ( ! is_array( $style ) ) {
				continue;
			}
			if ( ! empty( $style['src'] ) ) {
				echo '<link rel="stylesheet" href="' . esc_url( (string) $style['src'] ) . '">';
			}
			foreach ( $GLOBALS['__x402_pay_inline_styles'][ $handle ] ?? array() as $inline ) {
				echo '<style>' . $inline . '</style>';
			}
		}
		return $handles;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( array $args = array() ): array {
		$taxonomy = (string) ( $args['taxonomy'] ?? 'category' );
		$out      = array();
		foreach ( $GLOBALS['__x402_pay_existing_terms'] ?? array() as $row ) {
			if ( ( $row['taxonomy'] ?? '' ) !== $taxonomy ) {
				continue;
			}
			$term           = new \stdClass();
			$term->term_id  = (int) $row['term_id'];
			$term->name     = (string) $row['name'];
			$term->taxonomy = (string) $row['taxonomy'];
			$out[]          = $term;
		}
		return $out;
	}
}
if ( ! function_exists( 'wp_dropdown_categories' ) ) {
	function wp_dropdown_categories( array $args = array() ) {
		$name     = (string) ( $args['name'] ?? 'cat' );
		$id       = (string) ( $args['id'] ?? '' );
		$selected = (int) ( $args['selected'] ?? 0 );
		$taxonomy = (string) ( $args['taxonomy'] ?? 'category' );
		$echo     = ! empty( $args['echo'] );

		$options = '';
		foreach ( $GLOBALS['__x402_pay_existing_terms'] ?? array() as $row ) {
			if ( ( $row['taxonomy'] ?? '' ) !== $taxonomy ) {
				continue;
			}
			$term_id = (int) $row['term_id'];
			$is_sel  = $term_id === $selected ? ' selected="selected"' : '';
			$options .= sprintf(
				'<option value="%d"%s>%s</option>',
				$term_id,
				$is_sel,
				htmlspecialchars( (string) $row['name'], ENT_QUOTES, 'UTF-8' )
			);
		}
		$html = sprintf(
			'<select name="%s" id="%s">%s</select>',
			htmlspecialchars( $name, ENT_QUOTES, 'UTF-8' ),
			htmlspecialchars( $id, ENT_QUOTES, 'UTF-8' ),
			$options
		);
		if ( $echo ) {
			echo $html;
			return '';
		}
		return $html;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return (bool) ( $GLOBALS['__x402_pay_is_admin'] ?? false );
	}
}
if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen(): ?object {
		$id = $GLOBALS['__x402_pay_current_screen_id'] ?? null;
		return null === $id ? null : (object) array( 'id' => (string) $id );
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ): bool {
		return (bool) ( $GLOBALS['__x402_pay_is_singular'] ?? false );
	}
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id(): int {
		return (int) ( $GLOBALS['__x402_pay_queried_object_id'] ?? 0 );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args = array() ): string {
		return (string) ( $GLOBALS['__x402_pay_request_uri'] ?? '/' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['__x402_pay_actions'][ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		foreach ( $GLOBALS['__x402_pay_actions'][ $hook ] ?? array() as $cb ) {
			$cb( ...$args );
		}
	}
}
if ( ! class_exists( 'WP_Admin_Bar' ) ) {
	class WP_Admin_Bar {
		/** @var array<int,array<string,mixed>> */
		public array $nodes = array();

		/** @param array<string,mixed> $args */
		public function add_node( array $args ): void {
			$this->nodes[] = $args;
		}
	}
}
if ( ! class_exists( 'WP_Connector_Registry' ) ) {
	/**
	 * Stand-in for WordPress 7.0's WP_Connector_Registry.
	 *
	 * Mirrors the public surface at
	 * https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/.
	 */
	class WP_Connector_Registry {
		public function register( string $id, array $args ): bool {
			$GLOBALS['__x402_pay_connectors'][ $id ] = $args;
			return true;
		}

		public function unregister( string $id ): ?array {
			$prev = $GLOBALS['__x402_pay_connectors'][ $id ] ?? null;
			unset( $GLOBALS['__x402_pay_connectors'][ $id ] );
			return $prev;
		}

		public function is_registered( string $id ): bool {
			return isset( $GLOBALS['__x402_pay_connectors'][ $id ] );
		}

		public function get_registered( string $id ): ?array {
			return $GLOBALS['__x402_pay_connectors'][ $id ] ?? null;
		}

		/** @return array<string,array> */
		public function get_all_registered(): array {
			return $GLOBALS['__x402_pay_connectors'] ?? array();
		}
	}
}
if ( ! function_exists( 'wp_get_connectors' ) ) {
	function wp_get_connectors(): array {
		return $GLOBALS['__x402_pay_connectors'] ?? array();
	}
}
if ( ! function_exists( 'wp_get_connector' ) ) {
	function wp_get_connector( string $id ): ?array {
		return $GLOBALS['__x402_pay_connectors'][ $id ] ?? null;
	}
}
if ( ! function_exists( 'wp_is_connector_registered' ) ) {
	function wp_is_connector_registered( string $id ): bool {
		return isset( $GLOBALS['__x402_pay_connectors'][ $id ] );
	}
}
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id   = 0;
		public string $name   = '';
		public string $taxonomy = 'category';
		public int $count     = 0;

		public function __construct( int $term_id, string $name, string $taxonomy = 'category', int $count = 0 ) {
			$this->term_id  = $term_id;
			$this->name     = $name;
			$this->taxonomy = $taxonomy;
			$this->count    = $count;
		}
	}
}
$GLOBALS['__x402_pay_terms']           = array();
$GLOBALS['__x402_pay_posts']           = array();
$GLOBALS['__x402_pay_bloginfo']       = array();
$GLOBALS['__x402_pay_existing_terms']  = array();
$GLOBALS['__x402_pay_inserted_terms']  = array();
$GLOBALS['__x402_pay_settings_errors'] = array();
$GLOBALS['__x402_pay_registered_scripts'] = array();
$GLOBALS['__x402_pay_enqueued_scripts']   = array();
$GLOBALS['__x402_pay_inline_scripts']     = array();
$GLOBALS['__x402_pay_registered_styles']  = array();
$GLOBALS['__x402_pay_enqueued_styles']    = array();
$GLOBALS['__x402_pay_inline_styles']      = array();
$GLOBALS['__x402_pay_localized_data']     = array();
$GLOBALS['x402_pay_response'] = array(
	'status'  => 200,
	'headers' => array(),
	'body'    => null,
	'exited'  => false,
);

// Reset global state between tests.
$GLOBALS['__x402_pay_options']     = array();
$GLOBALS['__x402_pay_transients'] = array();
$GLOBALS['__x402_pay_bloginfo']   = array();
$GLOBALS['__x402_pay_filters']    = array();
$GLOBALS['__x402_pay_actions']    = array();
$GLOBALS['__x402_pay_http']       = null;
$GLOBALS['__x402_pay_connectors'] = array();
$GLOBALS['__x402_pay_jp']         = null;
$GLOBALS['__x402_pay_jp_next']    = null;
