<?php
/** Resolve location-specific WordPress objects before approving a shared layout. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-elementor-reusable.php';

final class Nova_Bridge_Suite_Shared_Layout_Dependencies {
    private const FORMAT = 'nova.shared-dependencies/v1';
    private const MAX_REFERENCES = 128;
    private $expected;
    private $remaps;
    private $entries = [];
    private $targets = [];
    private $issues = [];
    private $locations = [];
    private $inspect_only = false;

    private function __construct( ?array $expected, array $remaps = [] ) {
        $this->expected = $expected;
        $this->remaps = $remaps;
    }

    public static function supports_builder( string $builder ): bool {
        return in_array( $builder, [ 'core', 'gutenberg', 'elementor', 'flatsome', 'enfold' ], true );
    }

    /** Only public media-library filenames and fingerprints travel with the layout. */
    public static function capture( array $record ) {
        $scan = new self( null );
        $result = $scan->process( $record );
        return is_wp_error( $result ) ? $result : [ 'format' => self::FORMAT, 'entries' => $scan->entries ];
    }

    public static function resolve( array $record, array $manifest, array $remaps = [] ) {
        if ( ( $manifest['format'] ?? '' ) !== self::FORMAT || ! is_array( $manifest['entries'] ?? null ) || count( $manifest['entries'] ) > self::MAX_REFERENCES ) {
            return new WP_Error( 'nova_shared_layout_manifest', 'Publish this shared revision again with its complete dependency manifest.' );
        }
        $scan = new self( $manifest['entries'], $remaps );
        return $scan->process( $record );
    }

    private function process( array $record ) {
        $builder = $record['recipe']['builder'] ?? '';
        if ( ! is_array( $record['recipe']['blueprint'] ?? null ) || ! is_string( $builder ) || ! empty( $record['recipe']['regions'] ) ) {
            return new WP_Error( 'nova_shared_layout_manifest', 'Choose a saved fixed layout with a complete native blueprint.' );
        }
        $blueprint =& $record['recipe']['blueprint'];
        if ( ! self::supports_builder( $builder ) ) {
            $this->unsupported( $builder, 'This builder has no portable layout importer.' );
        } else {
            $this->page_template( $blueprint['template'] ?? '', $record['resource_id'] ?? '' );
            $this->walk( $blueprint, $builder, '/blueprint' );
        }
        if ( null !== $this->expected && ( array_diff_key( $this->expected, $this->entries ) || array_diff_key( $this->remaps, $this->entries ) ) ) {
            $this->unsupported( 'manifest', 'The supplied dependencies or replacements do not belong to this exact layout. Download this revision again.' );
        }
        if ( $this->issues ) {
            foreach ( $this->issues as $key => &$issue ) {
                $issue['locations'] = array_values( array_unique( $this->locations[$key] ?? [] ) );
                if ( $issue['locations'] ) { $issue['label'] .= ' · ' . implode( ', ', $issue['locations'] ); $issue['message'] .= ' Used in: ' . implode( ', ', $issue['locations'] ) . '.'; }
            }
            unset( $issue );
            return new WP_Error( 'nova_shared_layout_dependencies', reset( $this->issues )['message'], [ 'status' => 409, 'issues' => array_values( $this->issues ) ] );
        }
        ksort( $this->entries );
        return $record;
    }

    private function issue( string $key, string $type, string $label, int $source, string $message, bool $remappable = false, ?int $suggestion = null ): void {
        $this->issues[$key] = [ 'key' => $key, 'type' => $type, 'label' => $label, 'source_id' => $source, 'message' => $message, 'remappable' => $remappable, 'suggested_id' => $suggestion ];
    }

    private function unsupported( string $path, string $message ): void {
        $this->issue( 'unsupported:' . substr( hash( 'sha256', $path ), 0, 16 ), 'unsupported', $path, 0, $message . ' (' . $path . ')' );
    }

    private static function positive( $value ): bool {
        return ( is_int( $value ) || is_string( $value ) ) && (string) (int) $value === (string) $value && (int) $value > 0;
    }

    /** WordPress accepts Query Loop filters as lists, comma-separated IDs, or lists per taxonomy. Zero can mean root. */
    private static function selects_object( $value ): bool {
        if ( is_array( $value ) ) {
            foreach ( $value as $selection ) { if ( self::selects_object( $selection ) ) { return true; } }
        } elseif ( is_string( $value ) || is_int( $value ) ) {
            foreach ( explode( ',', (string) $value ) as $id ) { if ( (int) $id !== 0 ) { return true; } }
        }
        return false;
    }

    private static function local_media_url( string $text ): bool {
        $base = function_exists( 'wp_get_upload_dir' ) ? ( wp_get_upload_dir()['baseurl'] ?? '' ) : '';
        return ( '' !== $base && str_contains( $text, rtrim( $base, '/' ) . '/' ) ) || (bool) preg_match( '~/wp-content/(?:blogs\.dir/[^/]+/files|uploads)/~i', $text );
    }

    private static function location( string $path ): string {
        $path = preg_replace_callback( '~/(?:document|elements|children)/(\d+)~', static fn( $m ) => ' / element ' . ( 1 + (int) $m[1] ), $path );
        return trim( str_replace( [ '/blueprint', '/settings/', '_' ], [ '', ' / ', ' ' ], $path ), ' /' );
    }

    private function page_template( $slug, string $resource ): void {
        if ( ! $slug || 'default' === $slug ) { return; }
        if ( ! is_string( $slug ) || ! str_starts_with( $resource, 'post_type:' ) || ! function_exists( 'wp_get_theme' ) ) {
            $this->unsupported( 'Page template', 'The native theme template cannot be verified on this installation.' );
            return;
        }
        $theme = wp_get_theme();
        $templates = $theme->get_page_templates( null, substr( $resource, 10 ) );
        if ( ! array_key_exists( $slug, $templates ) ) {
            $this->unsupported( 'Page template', 'Install or enable the native page template ' . $slug . ' for this destination before importing.' );
        }
    }

    private function walk( &$value, string $builder, string $path ): void {
        if ( 'elementor' === $builder && is_array( $value ) && isset( $value['elType'] ) ) {
            $ref = Nova_Bridge_Suite_Elementor_Reusable::reference( $value );
            if ( $ref ) {
                $id = $ref['id']; $unused = null;
                $this->replace( 'global' === $ref['kind'] ? 'elementor_global_widget' : 'elementor_template', $id, $unused, $path );
                if ( 'global' === $ref['kind'] ) { $value['templateID'] = $id; }
                elseif ( 'template' === $ref['kind'] ) { $value['settings']['template_id'] = $id; }
                else { $value['settings']['shortcode'] = '[elementor-template id="' . $id . '"]'; }
                // A global widget reads its content from the verified library record; cached instance values are not authority.
                if ( 'global' !== $ref['kind'] ) {
                    $settings = $value['settings']; unset( $settings['template_id'], $settings['shortcode'] );
                    $this->walk( $settings, $builder, $path . '/settings' );
                    $value['settings'] = array_replace( $value['settings'], $settings );
                }
                return;
            }
        }
        if ( ! is_array( $value ) ) {
            if ( is_string( $value ) && ( self::local_media_url( $value ) || isset( $this->expected['attachment-url:' . hash( 'sha256', $value )] ) ) ) {
                if ( ! preg_match( '~^(?:https?://|/)[^\s"\'<>]+$~iD', $value ) ) {
                    $this->unsupported( $path, 'A WordPress media URL is embedded in markup or CSS. Select that image in a native image control before sharing.' );
                } else {
                    $this->url_attachment( $value, $path );
                }
                return;
            }
            if ( is_string( $value ) && preg_match( '~\bwp-image-\d+\b|[?&](?:page_id|attachment_id|p)=\d+\b|\[/?(?:gallery|playlist|elementor-template|wp_block|block)\b|wp:(?:block|navigation)\s+\{|(?:src|srcset)\s*=\s*["\'][^"\']*/uploads/~i', $value ) ) {
                $this->unsupported( $path, 'This embedded WordPress reference cannot be safely transferred. Use a native image control or inline the linked layout before sharing.' );
            }
            if ( is_string( $value ) && preg_match( '~^globals/(colors|typography)\?id=([a-z0-9_-]+)$~iD', $value, $m ) ) {
                $this->global_style( $m[1], $m[2], $path );
            }
            return;
        }
        // Frozen UX Block snapshots retain their original linked node solely for history; rendering uses their children.
        if ( 'flatsome' === $builder && 'block_snapshot' === ( $value['kind'] ?? '' ) ) {
            if ( is_array( $value['children'] ?? null ) ) { $this->walk( $value['children'], $builder, $path . '/children' ); }
            return;
        }
        if ( in_array( $builder, [ 'flatsome', 'enfold' ], true ) && 'shortcode' === ( $value['kind'] ?? '' ) ) {
            $this->shortcode( $value, $builder, $path );
            return;
        }
        if ( 'elementor' === $builder && array_key_exists( 'id', $value ) && array_key_exists( 'url', $value ) ) {
            if ( self::positive( $value['id'] ) ) {
                $this->replace( 'attachment', $value['id'], $value['url'], $path );
                return;
            }
            if ( is_string( $value['url'] ) && ( self::local_media_url( $value['url'] ) || isset( $this->expected['attachment-url:' . hash( 'sha256', $value['url'] )] ) ) ) {
                $local = $this->url_attachment( $value['url'], $path );
                if ( $local && null !== $this->expected ) { $value['id'] = $local; }
                return;
            }
            if ( ! in_array( $value['id'], [ '', '0', 0, null ], true ) ) {
                $this->unsupported( $path, 'Select a native media attachment or a fixed external URL before sharing.' );
            }
        }
        if ( 'elementor' === $builder && in_array( $value['widgetType'] ?? '', [ 'loop-carousel', 'loop-grid' ], true ) && ! empty( $value['settings']['template_id'] ) ) {
            $this->replace( 'elementor_loop', $value['settings']['template_id'], $unused, $path . '/settings/template_id' );
            // Avoid scanning the already verified template ID as an opaque setting.
            $settings = $value['settings'];
            unset( $settings['template_id'] );
            $this->walk( $settings, $builder, $path . '/settings' );
            $value['settings'] = [ 'template_id' => $value['settings']['template_id'] ] + $settings;
            if ( isset( $value['elements'] ) ) { $this->walk( $value['elements'], $builder, $path . '/elements' ); }
            return;
        }
        if ( 'gutenberg' === $builder && ! empty( $value['blockName'] ) ) {
            // Synced blocks, template parts, navigation and media can duplicate their IDs in serialized HTML.
            if ( in_array( $value['blockName'], [ 'core/block', 'core/template-part', 'core/pattern', 'core/shortcode' ], true ) ) {
                $this->unsupported( $path, 'Detach this reusable block or shortcode before sharing its saved layout.' );
                return;
            }
            $attrs = $value['attrs'] ?? [];
            if ( 'core/query' === $value['blockName'] ) {
                foreach ( [ 'taxQuery', 'categoryIds', 'tagIds', 'author', 'parents', 'exclude' ] as $filter ) {
                    if ( self::selects_object( $attrs['query'][$filter] ?? null ) ) {
                        $this->unsupported( $path . '/attrs/query/' . $filter, 'This Query Loop filters by records on the source installation. Remove the selected terms, authors or posts before sharing.' );
                        return;
                    }
                }
            }
            $filters = [ 'core/latest-posts' => [ 'categories', 'selectedAuthor' ], 'core/avatar' => [ 'userId' ], 'core/page-list' => [ 'parentPageID' ] ];
            foreach ( $filters[$value['blockName']] ?? [] as $filter ) {
                if ( self::selects_object( $attrs[$filter] ?? null ) ) {
                    $this->unsupported( $path . '/attrs/' . $filter, 'This block selects a record on the source installation. Clear that selection before sharing.' );
                    return;
                }
            }
            if ( ! empty( $value['attrs']['id'] ) || ! empty( $value['attrs']['ids'] ) || ! empty( $value['attrs']['ref'] ) || ! empty( $value['attrs']['mediaId'] ) || ! empty( $value['attrs']['postId'] ) ) {
                $this->unsupported( $path, 'This block embeds a site-specific object. Remove the embedded object or use an independent block before sharing.' );
                return;
            }
        }
        foreach ( $value as $name => &$child ) {
            if ( 'elementor' === $builder && Nova_Bridge_Suite_Elementor_Reusable::COPY === $name ) { continue; }
            $location = $path . '/' . $name;
            if ( '__dynamic__' === $name && is_array( $child ) ) {
                foreach ( $child as $tag ) {
                    if ( ! is_string( $tag ) || ! preg_match( '/\bname=["\'](?:post-title|post-excerpt|post-url|post-featured-image|featured-image|site-title|site-url)["\']/', $tag ) || ! preg_match( '~\bsettings=["\'](?:%7B%7D|\{\}|)["\']~i', $tag ) ) {
                        $this->unsupported( $location, 'This dynamic control can reference an object on the authoring installation. Use a static value or a current-post dynamic control without custom settings.' );
                    }
                }
                continue;
            }
            if ( '__globals__' === $name && is_array( $child ) ) {
                foreach ( $child as $global ) {
                    if ( $global && ( ! is_string( $global ) || ! preg_match( '~^globals/(?:colors|typography)\?id=[a-z0-9_-]+$~iD', $global ) ) ) {
                        $this->unsupported( $location, 'Choose a standard Elementor color or typography token, or use a local literal.' );
                    }
                }
            }
            // Numeric editor node IDs, spacing and counts are not database IDs.
            if ( is_string( $name ) && preg_match( '/(?:^|_)(?:(?:attachment|media|image|gallery|post|term|template|menu|popup|product|category)(?:_?ids?)|include_ids|exclude_ids|selected_ids)$/iD', $name ) && ! empty( $child ) ) {
                $this->unsupported( $location, 'This object selector needs a portable native control. Remove the hard-coded object selection before sharing.' );
                continue;
            }
            if ( 'elementor' === $builder && in_array( $name, [ 'menu', 'selected_terms', 'include_term_ids', 'exclude_term_ids', 'query_include', 'query_exclude' ], true ) && ! empty( $child ) ) {
                $this->unsupported( $location, 'This Elementor menu or query refers to content on the primary installation. Clear the saved selection or use a current-post query before sharing.' );
                continue;
            }
            $this->walk( $child, $builder, $location );
        }
        unset( $child );
    }

    private function shortcode( array &$node, string $builder, string $path ): void {
        $tag = $node['tag'] ?? '';
        if ( ! empty( $node['definition']['protected'] ) || in_array( $tag, [ 'block', 'ux_block' ], true ) ) {
            $this->unsupported( $path, 'This shortcode depends on a linked block or external widget. Expand the reusable block into the saved layout or replace this shortcode.' );
            return;
        }
        $attributes =& $node['attributes'];
        if ( ! is_array( $attributes ) ) { $this->unsupported( $path, 'The shortcode attributes are incomplete.' ); return; }
        $handled = [];
        foreach ( $node['definition']['fields'] ?? [] as $name => $field ) {
            if ( 'flatsome' === $builder && ! empty( $field['image'] ) && ! empty( $attributes[$name] ) ) {
                $this->replace( 'attachment', $attributes[$name], $unused, $path . '/' . $name );
                $handled[$name] = true;
            }
            if ( 'enfold' === $builder && 'image_url' === ( $field['kind'] ?? '' ) && ! empty( $attributes['attachment'] ) ) {
                $this->replace( 'attachment', $attributes['attachment'], $attributes[$name], $path . '/attachment' );
                $handled['attachment'] = $handled[$name] = true;
            }
        }
        foreach ( $attributes as $name => $attr ) {
            if ( isset( $handled[$name] ) ) { continue; }
            if ( is_string( $attr ) && self::local_media_url( $attr ) ) {
                $this->walk( $attributes[$name], $builder, $path . '/' . $name );
                continue;
            }
            if ( ! empty( $attr ) && ( preg_match( '/^(?:ids|gallery|attachment|bg_image|element_template|one_element_template|categories|cat|posts|products|menu|id)$/iD', (string) $name ) && ( 'id' !== $name || self::positive( $attr ) ) || preg_match( '/^(?:page|post|cat|term|product|attachment),\d+$/iD', (string) $attr ) ) ) {
                $this->unsupported( $path . '/' . $name, 'This shortcode refers to an object on its authoring site. Inline the content or remove the local ID before sharing.' );
            }
        }
        foreach ( [ 'content', 'children' ] as $key ) { if ( isset( $node[$key] ) ) { $this->walk( $node[$key], $builder, $path . '/' . $key ); } }
    }

    private function global_style( string $type, string $id, string $path ): void {
        $key = 'elementor_global:' . $type . ':' . $id;
        if ( array_key_exists( $key, $this->entries ) ) { return; }
        $this->entries[$key] = [];
        if ( $this->inspect_only ) { return; }
        $kit = (int) get_option( 'elementor_active_kit' );
        $settings = $kit ? get_post_meta( $kit, '_elementor_page_settings', true ) : [];
        // The native kit includes built-in system defaults which may not yet be saved as metadata.
        $manager = class_exists( '\Elementor\Plugin' ) ? ( \Elementor\Plugin::instance()->kits_manager ?? null ) : null;
        $document = $manager && is_callable( [ $manager, 'get_active_kit' ] ) ? $manager->get_active_kit() : null;
        foreach ( [ 'system_', 'custom_' ] as $prefix ) {
            $name = $prefix . $type;
            $styles = $document && is_callable( [ $document, 'get_settings' ] ) ? $document->get_settings( $name ) : ( $settings[$name] ?? [] );
            foreach ( (array) $styles as $style ) {
                if ( ( $style['_id'] ?? '' ) !== $id ) { continue; }
                unset( $style['title'] );
                $this->entries[$key] = [ 'digest' => self::digest( $style ) ];
                if ( null !== $this->expected && ( $this->expected[$key] ?? null ) !== $this->entries[$key] ) {
                    $this->unsupported( $path, 'Set the matching Elementor global style on this installation or use a fixed value on the primary layout.' );
                }
                return;
            }
        }
        $this->unsupported( $path, 'The active Elementor kit does not define the selected global style. Save that style in the kit or use a fixed value.' );
    }

    /** A URL-only native media control still needs a verified local file on the follower. */
    private function url_attachment( string &$url, string $path ): int {
        $key = 'attachment-url:' . hash( 'sha256', $url );
        $source = null === $this->expected ? ( function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $url ) : 0 ) : ( $this->expected[$key]['source_id'] ?? 0 );
        if ( ! self::positive( $source ) ) {
            $filename = basename( (string) parse_url( $url, PHP_URL_PATH ) );
            $this->unsupported( $path, 'WordPress could not identify the attachment for ' . $filename . '. Re-select the original media file in the image control before sharing.' );
            return 0;
        }
        $this->replace( 'attachment', $source, $url, $path, $key );
        return (int) $source;
    }

    private function replace( string $type, &$id, &$url, string $path, string $key = '' ): void {
        if ( ! self::positive( $id ) ) { $this->unsupported( $path, 'This native object reference is invalid.' ); return; }
        $source = (int) $id;
        $key = $key ?: $type . ':' . $source;
        $this->locations[$key][] = self::location( $path );
        if ( ! isset( $this->entries[$key] ) ) {
            if ( count( $this->entries ) >= self::MAX_REFERENCES ) { $this->unsupported( $path, 'The layout has too many distinct site objects to share.' ); return; }
            $this->entries[$key] = [];
            if ( $this->inspect_only ) { return; }
            $label = ( 'attachment' === $type ? 'Media attachment ' : ( 'elementor_loop' === $type ? 'Elementor Loop Item ' : 'Shared Elementor component ' ) ) . $source;
            if ( null === $this->expected ) {
                $descriptor = $this->describe( $type, $source );
                if ( ! $descriptor ) {
                    $this->issue( $key, $type, $label, $source, $label . ' could not be verified on the primary site. Use readable media or a published, supported Elementor component and share the revision again.' );
                    return;
                }
                $this->entries[$key] = $descriptor + [ 'source_id' => $source ];
            } else {
                $descriptor = $this->expected[$key] ?? [];
                if ( ! is_array( $descriptor ) || ( $descriptor['source_id'] ?? null ) !== $source || ! is_string( $descriptor['digest'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $descriptor['digest'] ) || ! is_string( $descriptor['slug'] ?? null ) || ! is_string( $descriptor['label'] ?? null ) ) {
                    $this->unsupported( $path, 'The dependency manifest is incomplete. Publish the shared revision again.' );
                    return;
                }
                $this->entries[$key] = $descriptor;
                $target = $this->find( $type, $descriptor, $this->remaps[$key] ?? null );
                if ( ! $target ) {
                    $instruction = 'attachment' === $type ? 'Upload the original file to this installation’s Media Library' : 'Install the matching published Elementor library component on this installation';
                    $this->issue( $key, $type, $descriptor['label'], $source, $descriptor['label'] . ' (primary ID ' . $source . ') has no unique local match. ' . $instruction . ', then retry or enter its local ID.', true );
                    return;
                }
                $this->targets[$key] = $target;
            }
        }
        if ( isset( $this->targets[$key] ) ) {
            $target = $this->targets[$key];
            $id = is_string( $id ) ? (string) $target : $target;
            if ( 'attachment' === $type && null !== $url ) {
                $url = wp_get_attachment_url( $target );
                if ( ! is_string( $url ) || '' === $url ) { $this->unsupported( $path, 'The selected local media file has no readable attachment URL.' ); }
            }
        }
    }

    private static function digest( array $data ): string {
        // Sort objects, preserving numerical list order.
        $sort = static function ( array &$value ) use ( &$sort ) {
            foreach ( $value as &$v ) { if ( is_array( $v ) ) { $sort( $v ); } } unset( $v );
            if ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value ); }
        };
        $sort( $data );
        return hash( 'sha256', (string) wp_json_encode( $data ) );
    }

    private function describe( string $type, int $id ): ?array {
        if ( in_array( $type, [ 'elementor_global_widget', 'elementor_template' ], true ) ) { return $this->component_descriptor( $type, $id ); }
        $post = get_post( $id );
        $post_type = 'attachment' === $type ? 'attachment' : 'elementor_library';
        if ( ! $post || $post_type !== $post->post_type || ! empty( $post->post_password ) ) { return null; }
        $slug = (string) $post->post_name;
        if ( ! $slug ) { return null; }
        $descriptor = [ 'slug' => $slug, 'label' => ( 'attachment' === $type ? 'media attachment ' : 'Elementor Loop Item ' ) . $slug ];
        if ( 'attachment' === $type ) {
            if ( ! in_array( $post->post_status, [ 'inherit', 'publish' ], true ) ) { return null; }
            $uploads = wp_get_upload_dir();
            $base = realpath( $uploads['basedir'] ?? '' );
            $file = get_attached_file( $id, true );
            $file = is_string( $file ) && $file !== '' && ! str_contains( $file, '://' ) ? realpath( $file ) : false;
            if ( ! $base || ! $file || ! str_starts_with( $file, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR ) || ! is_file( $file ) || ! is_readable( $file ) ) { return null; }
            $size = filesize( $file );
            if ( ! $size || $size > 32 * 1024 * 1024 ) { return null; }
            $hash = hash_file( 'sha256', $file );
            if ( ! $hash ) { return null; }
            $descriptor['digest'] = $hash;
            $descriptor['mime'] = $post->post_mime_type;
            $descriptor['relative'] = (string) get_post_meta( $id, '_wp_attached_file', true );
            $descriptor['label'] = 'Media Library file ' . $descriptor['relative'];
            if ( ! $descriptor['relative'] || str_starts_with( $descriptor['relative'], '/' ) || preg_match( '~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)|^[a-z]+:~i', $descriptor['relative'] ) ) { return null; }
        } else {
            if ( 'publish' !== $post->post_status || 'loop-item' !== get_post_meta( $id, '_elementor_template_type', true ) ) { return null; }
            $raw = get_post_meta( $id, '_elementor_data', true );
            $nodes = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
            if ( ! is_array( $nodes ) || ! $nodes ) { return null; }
            $layout = [ 'document' => $nodes, 'settings' => get_post_meta( $id, '_elementor_page_settings', true ), 'template' => get_page_template_slug( $id ) ];
            // A matching template shell cannot vouch for nested, site-specific references.
            $nested = new self( null );
            $nested->inspect_only = true;
            $nested->walk( $layout, 'elementor', 'Loop Item' );
            if ( $nested->entries || $nested->issues ) { return null; }
            $descriptor['digest'] = self::digest( $layout );
        }
        return $descriptor;
    }

    /** Compare rendered dependencies by content, never by the numeric IDs of another installation. */
    private function component_descriptor( string $type, int $id ): ?array {
        try {
            $ref = [ 'kind' => 'elementor_global_widget' === $type ? 'global' : 'template', 'id' => $id ];
            $library = Nova_Bridge_Suite_Elementor_Reusable::library( $ref );
            Nova_Bridge_Suite_Elementor_Reusable::validation_blueprint( [ 'document' => $library['nodes'] ] );
            $post = get_post( $id );
            $layout = [ 'document' => $library['nodes'], 'settings' => get_post_meta( $id, '_elementor_page_settings', true ), 'kind' => $library['kind'] ];
            $nested = new self( null ); $nested->inspect_only = $this->inspect_only;
            $nested->walk( $layout, 'elementor', 'Shared component' );
            if ( $nested->issues ) { return null; }
            $ids = []; $collect = static function ( array $nodes ) use ( &$collect, &$ids ): void { foreach ( $nodes as $node ) { $ids[$node['id']] = 'node' . count( $ids ); $collect( $node['elements'] ?? [] ); } }; $collect( $layout['document'] );
            $normalize = static function ( $value ) use ( &$normalize, $nested, $ids ) {
                if ( ! is_array( $value ) ) {
                    if ( is_string( $value ) ) { foreach ( $ids as $old => $new ) { $value = str_replace( 'elementor-element-' . $old, 'elementor-element-' . $new, $value ); } }
                    return $value;
                }
                unset( $value[Nova_Bridge_Suite_Elementor_Reusable::COPY] );
                $reference = isset( $value['elType'] ) ? Nova_Bridge_Suite_Elementor_Reusable::reference( $value ) : null;
                if ( $reference ) {
                    $key = ( 'global' === $reference['kind'] ? 'elementor_global_widget:' : 'elementor_template:' ) . $reference['id'];
                    $digest = $nested->entries[$key]['digest'] ?? null;
                    if ( ! $digest ) { throw new InvalidArgumentException( 'Unresolved nested component.' ); }
                    unset( $value['templateID'], $value['originalWidgetType'] );
                    if ( 'global' === $reference['kind'] ) { $value['settings'] = []; }
                    else { unset( $value['settings']['template_id'], $value['settings']['shortcode'] ); }
                    $value['shared_content'] = $digest;
                }
                if ( isset( $value['elType'], $value['id'] ) ) { $value['id'] = $ids[$value['id']] ?? $value['id']; }
                elseif ( isset( $value['id'], $value['url'] ) && isset( $nested->entries['attachment:' . $value['id']]['digest'] ) ) {
                    $value['id'] = $nested->entries['attachment:' . $value['id']]['digest']; $value['url'] = 'verified-media';
                }
                foreach ( $value as &$child ) { $child = $normalize( $child ); } unset( $child ); return $value;
            };
            $styles = array_filter( $nested->entries, static fn( $key ) => str_starts_with( $key, 'elementor_global:' ), ARRAY_FILTER_USE_KEY );
            return [ 'slug' => $post->post_name, 'label' => ( 'global' === $ref['kind'] ? 'Global widget ' : 'Saved template ' ) . $library['label'], 'digest' => self::digest( [ 'layout' => $normalize( $layout ), 'global_styles' => $styles ] ) ];
        } catch ( InvalidArgumentException $error ) { return null; }
    }

    private function find( string $type, array $expected, $override ): int {
        if ( null !== $override ) {
            $ids = self::positive( $override ) ? [ (int) $override ] : [];
        } else {
            $query = [ 'post_type' => 'attachment' === $type ? 'attachment' : 'elementor_library', 'post_status' => 'attachment' === $type ? [ 'inherit', 'publish' ] : 'publish', 'name' => $expected['slug'], 'numberposts' => 11, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ];
            $ids = get_posts( $query );
            if ( 'attachment' === $type && ! empty( $expected['relative'] ) ) {
                unset( $query['name'] );
                $ids = array_unique( array_merge( $ids, get_posts( $query + [ 'meta_key' => '_wp_attached_file', 'meta_value' => $expected['relative'] ] ) ) );
            }
        }
        if ( count( $ids ) > 10 ) { return 0; }
        $matching = [];
        foreach ( $ids as $candidate ) {
            $local = $this->describe( $type, (int) $candidate );
            if ( $local && hash_equals( $expected['digest'], $local['digest'] ) && ( $expected['mime'] ?? null ) === ( $local['mime'] ?? null ) ) { $matching[] = (int) $candidate; }
        }
        return 1 === count( $matching ) ? $matching[0] : 0;
    }
}
