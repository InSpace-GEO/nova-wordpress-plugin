<?php
/** Authenticated frontend rendering for the mapping inspector. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Mapping_Preview {
	private static $canvas = null;
	private static $canvas_nonce = '';
	private static $parent_origin = '';
	public static function bootstrap(): void {
		add_action( 'init', [ __CLASS__, 'no_cache' ], 0 );
		add_action( 'wp_loaded', [ __CLASS__, 'prepare' ] );
		add_action( 'template_redirect', [ __CLASS__, 'render' ], -100 );
	}
	public static function url( array $entity ): string {
		$url = $entity['url'];
		if ( 'post' === $entity['reference_type'] && 'publish' !== get_post_status( $entity['reference_id'] ) ) { $url = get_preview_post_link( $entity['reference_id'] ); }
		if ( ! is_string( $url ) || ! $url ) { return ''; }
		// Keep www/non-www and scheme variants on the admin origin so session cookies reach the iframe.
		$admin = wp_parse_url( admin_url() ); $front = wp_parse_url( $url );
		if ( ! empty( $admin['host'] ) && ! empty( $front['host'] ) && preg_replace( '/^www\./i', '', $admin['host'] ) === preg_replace( '/^www\./i', '', $front['host'] ) ) {
			$url = $admin['scheme'] . '://' . $admin['host'] . ( isset( $admin['port'] ) ? ':' . $admin['port'] : '' ) . ( $front['path'] ?? '/' ) . ( isset( $front['query'] ) ? '?' . $front['query'] : '' );
		} elseif ( 'post' === $entity['reference_type'] ) {
			$id = (int) $entity['reference_id'];
			$post_type = get_post_type( $id );
			if ( ! $post_type || ! self::wpml_domain_language( $id, $post_type, $url ) ) { return ''; }
			$query = 'page' === $post_type ? [ 'page_id' => $id ] : [ 'p' => $id, 'post_type' => $post_type ];
			$query['nova_wpml_domain_preview'] = '1';
			$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$url = add_query_arg( $query, self::admin_origin() . '/' . ltrim( $home_path, '/' ) );
		}
		return add_query_arg( [ 'nova_preview_request' => wp_generate_uuid4(), 'nova_mapping_preview' => $entity['reference_type'], 'nova_reference' => $entity['reference_id'], '_nova_nonce' => wp_create_nonce( 'nova_mapping_preview:' . $entity['reference_type'] . ':' . $entity['reference_id'] ) ], $url );
	}
	public static function no_cache(): void {
		if ( ! isset( $_GET['nova_mapping_preview'] ) ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		nocache_headers();
	}
	public static function prepare(): void {
		if ( ! isset( $_GET['nova_mapping_preview'] ) ) { return; }
		self::$canvas = null; self::$canvas_nonce = '';
		$type = is_string( $_GET['nova_mapping_preview'] ) ? sanitize_key( wp_unslash( $_GET['nova_mapping_preview'] ) ) : '';
		$id = isset( $_GET['nova_reference'] ) && is_scalar( $_GET['nova_reference'] ) ? absint( $_GET['nova_reference'] ) : 0;
		$nonce = isset( $_GET['_nova_nonce'] ) && is_string( $_GET['_nova_nonce'] ) ? wp_unslash( $_GET['_nova_nonce'] ) : '';
		if ( ! is_user_logged_in() ) { wp_die( 'Sign in to WordPress on this domain, then use Refresh preview. The preview did not receive your login session.', 'Mapping preview', [ 'response' => 401 ] ); }
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Your account cannot open mapping previews. Administrator access is required.', 'Mapping preview', [ 'response' => 403 ] ); }
		if ( ! wp_verify_nonce( $nonce, 'nova_mapping_preview:' . $type . ':' . $id ) ) { wp_die( 'This preview link has expired. Use Refresh preview to request a new link.', 'Mapping preview', [ 'response' => 403 ] ); }
		$canvas_requested = isset( $_GET['nova_recipe_canvas'] ) || isset( $_GET['nova_recipe_digest'] );
		if ( $canvas_requested ) {
			$session = isset( $_GET['nova_recipe_canvas'] ) && is_string( $_GET['nova_recipe_canvas'] ) ? wp_unslash( $_GET['nova_recipe_canvas'] ) : '';
			$digest = isset( $_GET['nova_recipe_digest'] ) && is_string( $_GET['nova_recipe_digest'] ) ? wp_unslash( $_GET['nova_recipe_digest'] ) : '';
			if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', $session ) || ! preg_match( '/^[a-f0-9]{64}$/iD', $digest ) ) { wp_die( 'This recipe canvas request is invalid. Read the native design again.', 'Recipe canvas', [ 'response' => 400 ] ); }
		}
		$entity = in_array( $type, [ 'post', 'term' ], true ) ? Nova_Bridge_Suite_Strategy::entity( $type, $id ) : null;
		if ( $canvas_requested && 'term' === $type && current_user_can( 'edit_term', $id ) ) {
			$term = get_term( $id ); $taxonomy = $term && ! is_wp_error( $term ) ? get_taxonomy( $term->taxonomy ) : null;
			$entity = $taxonomy && ! empty( $taxonomy->public ) && ! empty( $taxonomy->show_ui ) ? [ 'post_type' => $term->taxonomy ] : null;
		}
		if ( ! $entity ) { wp_die( 'This reference is unavailable or cannot be edited by your account.', 'Mapping preview', [ 'response' => 403 ] ); }
		self::$parent_origin = self::admin_origin();
		if ( isset( $_GET['nova_wpml_domain_preview'] ) ) {
			$url = 'post' === $type && 'publish' !== get_post_status( $id ) ? get_preview_post_link( $id ) : ( $entity['url'] ?? '' );
			$language = 'post' === $type ? self::wpml_domain_language( $id, $entity['post_type'], (string) $url ) : '';
			if ( ! $language ) { wp_die( 'The language domain for this reference has changed. Refresh the preview.', 'Mapping preview', [ 'response' => 409 ] ); }
			// The public WPML switch action also changes the language cookie. Only this request should switch.
			$GLOBALS['sitepress']->switch_lang( $language, false );
		}
		if ( $canvas_requested ) {
			self::$canvas_nonce = base64_encode( random_bytes( 18 ) );
			self::$canvas = [ 'referenceId' => $id, 'referenceType' => $type, 'sessionId' => $session, 'digest' => strtolower( $digest ), 'taxonomy' => 'term' === $type ? $entity['post_type'] : '' ];
			header( 'Content-Security-Policy: ' . self::canvas_policy() );
			add_filter( 'wp_script_attributes', [ __CLASS__, 'canvas_script_attributes' ] );
			add_filter( 'wp_inline_script_attributes', [ __CLASS__, 'canvas_script_attributes' ] );
		}

		remove_action( 'template_redirect', 'redirect_canonical' );
		add_filter( 'show_admin_bar', '__return_false' );
	}
	public static function render(): void {
		if ( ! isset( $_GET['nova_mapping_preview'] ) ) { return; }
		$type = sanitize_key( wp_unslash( $_GET['nova_mapping_preview'] ) );
		$id = absint( $_GET['nova_reference'] );
		$taxonomy = self::$canvas && 'term' === $type ? self::$canvas['taxonomy'] : 'product_cat';
		$term_query = 'term' === $type && ( is_tax( $taxonomy ) || ( 'category' === $taxonomy && is_category() ) || ( 'post_tag' === $taxonomy && is_tag() ) );
		if ( get_queried_object_id() !== $id || ( 'post' === $type ? ! is_singular() : ! $term_query ) ) {
			wp_die( 'WordPress rendered a different reference. Open the correct page before mapping.', 'Mapping preview', [ 'response' => 409 ] );
		}
		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: no-referrer' );
		if ( self::$canvas ) {
			if ( 'post' === $type ) {
				add_filter( 'the_content', [ __CLASS__, 'mark_custom_html' ], 8 );
				add_filter( 'the_content', [ __CLASS__, 'mark_shortcodes' ], 9 );
				require_once __DIR__ . '/class-nova-bridge-suite-shortcode-preview.php';
				add_filter( 'do_shortcode_tag', [ 'Nova_Bridge_Suite_Shortcode_Preview', 'tag_output' ], PHP_INT_MAX, 3 );
			}
			header( 'Content-Security-Policy: ' . self::canvas_policy() );
			$script = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'assets/recipe-canvas.js';
			// Release ZIPs retain deterministic timestamps. Key the preview asset to its
			// contents so a previous release cannot supply stale instrumentation.
			$version = substr( hash_file( 'sha256', $script ), 0, 16 );
			wp_enqueue_script( 'nova-recipe-canvas', NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL . 'assets/recipe-canvas.js', [], $version, false );
			$config = self::$canvas; unset( $config['taxonomy'] ); $config['parentOrigin'] = self::$parent_origin;
			wp_add_inline_script( 'nova-recipe-canvas', 'window.NovaRecipeCanvas=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
			require_once __DIR__ . '/class-nova-bridge-suite-preview-bootstrap.php';
			Nova_Bridge_Suite_Preview_Bootstrap::start( $config, add_query_arg( [ 'ver' => $version ], NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL . 'assets/recipe-canvas.js' ), self::$canvas_nonce );
			return;
		}
		$script = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'assets/mapping-preview.js';
		wp_enqueue_script( 'nova-mapping-preview', NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL . 'assets/mapping-preview.js', [], (string) filemtime( $script ), false );
		wp_add_inline_script( 'nova-mapping-preview', 'window.NovaMappingPreview=' . wp_json_encode( [ 'referenceId' => $id, 'referenceType' => $type, 'parentOrigin' => self::$parent_origin ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
	public static function mark_custom_html( string $content ): string {
		if ( ! self::$canvas || (int) get_the_ID() !== self::$canvas['referenceId'] || ! str_contains( $content, '<!-- wp:html' ) ) { return $content; }
		require_once dirname( __DIR__ ) . '/adapters/gutenberg-html.php';
		return serialize_blocks( Nova_Mapping_Gutenberg_HTML::preview_blocks( parse_blocks( $content ) ) );
	}
	public static function mark_shortcodes( string $content ): string {
		if ( ! self::$canvas || (int) get_the_ID() !== self::$canvas['referenceId'] ) { return $content; }
		require_once __DIR__ . '/class-nova-bridge-suite-shortcode-preview.php';
		return Nova_Bridge_Suite_Shortcode_Preview::mark_content( $content );
	}
	private static function wpml_domain_language( int $id, string $post_type, string $url ): string {
		if ( ! is_callable( [ $GLOBALS['sitepress'] ?? null, 'switch_lang' ] ) || 2 !== (int) apply_filters( 'wpml_setting', null, 'language_negotiation_type' ) ) { return ''; }
		$language = apply_filters( 'wpml_element_language_code', null, [ 'element_id' => $id, 'element_type' => 'post_' . $post_type ] );
		if ( ! is_string( $language ) || '' === $language ) { return ''; }
		// Ask WPML itself for the configured language home, including the default language.
		$home = wp_parse_url( apply_filters( 'wpml_permalink', home_url( '/' ), $language ) );
		$front = wp_parse_url( $url );
		if ( empty( $home['host'] ) || empty( $front['host'] ) || isset( $front['user'] ) || isset( $front['pass'] ) || ! in_array( $front['scheme'] ?? '', [ 'http', 'https' ], true ) ) { return ''; }
		return 0 === strcasecmp( $home['host'], $front['host'] ) && ( $home['port'] ?? null ) === ( $front['port'] ?? null ) ? $language : '';
	}
	private static function admin_origin(): string { $url = wp_parse_url( admin_url() ); return ( $url['scheme'] ?? ( is_ssl() ? 'https' : 'http' ) ) . '://' . $url['host'] . ( isset( $url['port'] ) ? ':' . $url['port'] : '' ); }
	private static function canvas_policy(): string {
		return "default-src 'none'; script-src 'nonce-" . self::$canvas_nonce . "'; script-src-attr 'none'; connect-src 'none'; object-src 'none'; frame-src 'none'; child-src 'none'; worker-src 'none'; form-action 'none'; base-uri 'none'; frame-ancestors 'self'; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; font-src 'self' data: https:";
	}
	public static function canvas_script_attributes( array $attributes ): array {
		if ( self::$canvas && in_array( $attributes['id'] ?? '', [ 'nova-recipe-canvas-js', 'nova-recipe-canvas-js-before' ], true ) ) { $attributes['nonce'] = self::$canvas_nonce; }
		return $attributes;
	}
}
