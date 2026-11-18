<?php
/** Opt-in conversion of an article's HTML into editable WordPress core blocks. */
defined( 'ABSPATH' ) || exit;

final class Nova_Mapping_Gutenberg_Article {
    private const LIMIT = 1000;
    private $rules;
    private $count = 0;
    private $static_body = false;
    private $warnings = [];

    public static function defaults(): array {
        return [ 'version' => 1, 'target' => '/document/0', 'theme_displays_title' => true, 'rules' => [
            'paragraph' => [ 'class_name' => '' ],
            'heading' => [ 'class_name' => '', 'level' => 'preserve' ],
            'list' => [ 'class_name' => '' ],
            'table' => [ 'class_name' => '', 'style' => 'default', 'fixed_layout' => false ],
            'quote' => [ 'class_name' => '' ],
            'image' => [ 'class_name' => '' ],
            'separator' => [ 'class_name' => '' ],
            'fallback' => 'html',
        ] ];
    }

    public static function sample(): string {
        return '<p>An introduction with <strong>important context</strong>.</p><h2>Compare the options</h2>'
            . '<table><thead><tr><th>Option</th><th>Benefit</th></tr></thead><tbody><tr><td>First</td><td>Flexible</td></tr><tr><td>Second</td><td>Predictable</td></tr></tbody></table>'
            . '<h3>What to consider</h3><ul><li>Your priorities</li><li>The available choices</li></ul><p>A practical conclusion.</p>';
    }

    /** Keep text in nested list items and quote citations in the owned output too. */
    public static function ownership_fields( array $blueprint, string $target ): array {
        $fields = [];
        $walk = static function ( array $block, string $path ) use ( &$walk, &$fields ) {
            foreach ( $block['innerContent'] as $index => $fragment ) {
                if ( ! is_string( $fragment ) || ( trim( strip_tags( $fragment ) ) === '' && ! preg_match( '/<(?:img|a)\b/i', $fragment ) ) ) { continue; }
                $fields[] = [ 'binding' => [ 'target' => $path . '/innerContent/' . $index, 'format' => 'html', 'article_fragment' => true ], 'value' => $fragment ];
            }
            foreach ( $block['innerBlocks'] as $index => $child ) { $walk( $child, $path . '/innerBlocks/' . $index ); }
        };
        $walk( Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $target ), $target );
        return $fields;
    }

    /** Translation markers are applied only to a rendered copy of the block tree. */
    public static function bind_owned_fragment( array $blueprint, array $binding, string $value ): array {
        Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $binding['target'], $value );
        $path = substr( $binding['target'], 0, strrpos( $binding['target'], '/innerContent/' ) );
        $block = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $path );
        $block['innerHTML'] = implode( '', array_filter( $block['innerContent'], 'is_string' ) );
        Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $path, $block );
        return $blueprint;
    }

    /** Configuration stays on the immutable recipe, outside the source design. */
    public static function validate( array $article, array $blueprint, array $bindings, array $regions ): void {
        $defaults = self::defaults();
        if ( array_diff( [ 'version', 'target', 'rules' ], array_keys( $article ) ) || array_diff( array_keys( $article ), array_merge( array_keys( $defaults ), [ 'sources' ] ) ) ) { throw new InvalidArgumentException( 'Supply the complete article rendering configuration.' ); }
        if ( array_key_exists( 'theme_displays_title', $article ) && ! is_bool( $article['theme_displays_title'] ) ) { throw new InvalidArgumentException( 'Choose whether the theme displays the page title.' ); }
        $sources = $article['sources'] ?? [];
        if ( ! is_array( $sources ) || count( $sources ) > 67 ) { throw new InvalidArgumentException( 'Choose a bounded set of article field sources.' ); }
        foreach ( $sources as $path => $source ) {
            if ( ! is_string( $path ) || ! preg_match( '~^/(?:native_post/(?:title|excerpt|featured_image)|@nova/custom-fields/(?:acf|meta|halo)/[A-Za-z0-9_-]+)$~D', $path )
                || ! in_array( $source, [ 'h1', 'title', 'meta_description', 'content', 'top_content', 'bottom_content', 'image_url', 'image_alt', 'url', 'language', 'page_type_frontend' ], true ) ) {
                throw new InvalidArgumentException( 'Choose an existing NOVA source for each additional article field.' );
            }
        }
        if ( ( $article['version'] ?? null ) !== 1 || ! is_string( $article['target'] ?? null ) || ! is_array( $article['rules'] ?? null ) ) { throw new InvalidArgumentException( 'The article renderer configuration is invalid.' ); }
        $rules = $article['rules'];
        if ( count( $rules ) !== count( $defaults['rules'] ) || array_diff( array_keys( $rules ), array_keys( $defaults['rules'] ) ) ) { throw new InvalidArgumentException( 'Use the supported article element rules.' ); }
        foreach ( $defaults['rules'] as $kind => $default ) {
            if ( 'fallback' === $kind ) { continue; }
            $rule = $rules[$kind] ?? null;
            if ( ! is_array( $rule ) || count( $rule ) !== count( $default ) || array_diff( array_keys( $rule ), array_keys( $default ) )
                || ! is_string( $rule['class_name'] ?? null ) || strlen( $rule['class_name'] ) > 300
                || ! preg_match( '/^(?:[a-zA-Z_][a-zA-Z0-9_-]*(?: +[a-zA-Z_][a-zA-Z0-9_-]*)*)?$/D', $rule['class_name'] ) ) { throw new InvalidArgumentException( 'Element classes must be space-separated CSS class names.' ); }
        }
        if ( ! in_array( $rules['heading']['level'], [ 'preserve', 1, 2, 3, 4, 5, 6 ], true )
            || ! in_array( $rules['table']['style'], [ 'default', 'stripes' ], true ) || ! is_bool( $rules['table']['fixed_layout'] )
            || ! in_array( $rules['fallback'], [ 'html', 'reject' ], true ) ) { throw new InvalidArgumentException( 'Choose supported heading, table and fallback rules.' ); }
        $target = $article['target'];
        if ( ! preg_match( '~^/document/[0-9]+(?:/innerBlocks/[0-9]+)*$~D', $target ) || $regions ) { throw new InvalidArgumentException( 'An article occupies one block in a fixed layout without repeated regions.' ); }
        $block = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $target );
        if ( ( $block['blockName'] ?? '' ) !== 'core/html' || ! empty( $block['innerBlocks'] ) || isset( $block['nova_html_slots'] ) ) { throw new InvalidArgumentException( 'Choose a whole Custom HTML block for the article region.' ); }
        $body = array_values( array_filter( $bindings, static function ( $binding ) use ( $target ) { return ( $binding['target'] ?? '' ) === $target . '/innerHTML'; } ) );
        if ( count( $body ) !== 1 || ( $body[0]['format'] ?? '' ) !== 'html' || empty( $body[0]['required'] ) ) { throw new InvalidArgumentException( 'The article region requires one complete rich-text content binding.' ); }
    }

    public static function after_omissions( array $article, array $removed ): array {
        $targets = Nova_Bridge_Suite_Recipe_Engine::shift_pointers( [ $article['target'] => true ], $removed );
        if ( ! $targets ) { throw new InvalidArgumentException( 'The required article region cannot be removed.' ); }
        $article['target'] = array_key_first( $targets );
        return $article;
    }

    public static function render( array $blueprint, array $article, string $title = '' ): array {
        $block = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $article['target'] );
        $renderer = new self();
        $renderer->rules = $article['rules'];
        $blocks = $renderer->convert( $block['innerHTML'], ! empty( $article['theme_displays_title'] ) ? $title : null );
        // A group gives the article stable ownership and leaves sibling paths intact.
        $group = self::block( 'core/group', [ 'layout' => [ 'type' => 'default' ] ], '<div class="wp-block-group">', $blocks, '</div>' );
        Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $article['target'], $group );
        return [ 'blueprint' => $blueprint, 'warnings' => array_values( array_unique( $renderer->warnings ) ) ];
    }

    /** A single replacement group keeps surrounding binding, media and omission paths stable. */
    public static function paragraph_body( array $paragraph, string $html ): array {
        $renderer = new self();
        $renderer->rules = self::defaults()['rules'];
        $renderer->static_body = true;
        $children = $renderer->convert( $html, null );
        $attrs = array_intersect_key( $paragraph['attrs'], array_flip( [ 'anchor', 'className', 'style', 'backgroundColor', 'textColor', 'fontSize', 'fontFamily', 'gradient', 'lock' ] ) );
        $attrs['layout'] = [ 'type' => 'default' ];
        $opening = '<div class="wp-block-group">';
        if ( preg_match( '/^\s*<p\b([^>]*)>/i', $paragraph['innerHTML'], $match ) ) {
            $tag = new WP_HTML_Tag_Processor( '<div' . $match[1] . '>' );
            $tag->next_tag();
            $tag->add_class( 'wp-block-group' );
            $opening = $tag->get_updated_html();
        }
        return self::block( 'core/group', $attrs, $opening, $children, '</div>' );
    }

    private static function block( string $name, array $attrs, string $opening, array $children = [], string $closing = '' ): array {
        return [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => $opening . $closing,
            'innerContent' => array_merge( [ $opening ], array_fill( 0, count( $children ), null ), $closing === '' ? [] : [ $closing ] ) ];
    }

    private function convert( string $html, ?string $theme_title ): array {
        if ( trim( $html ) === '' ) { throw new InvalidArgumentException( 'Supply complete article HTML before publishing.' ); }
        if ( strlen( $html ) > 2097152 || ! class_exists( 'DOMDocument' ) || ! preg_match( '//u', $html ) ) { throw new InvalidArgumentException( 'Article HTML must be valid UTF-8 within 2 MiB and requires the DOM extension.' ); }
        $shortcode = preg_match( '/\[\/?(?:vc_|us_|et_pb_|fusion_|av_|cs_|ux_)[a-zA-Z0-9_-]*\b/', $html );
        if ( function_exists( 'get_shortcode_regex' ) && ! empty( $GLOBALS['shortcode_tags'] ) ) { $shortcode = $shortcode || preg_match( '/' . get_shortcode_regex() . '/s', $html ); }
        if ( wp_kses_post( $html ) !== $html || preg_match( '/<!--\s*\/?wp:/', $html ) || $shortcode ) { throw new InvalidArgumentException( 'Article content must contain permitted HTML without block comments or executable shortcodes.' ); }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        try { $loaded = $document->loadHTML( '<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>', LIBXML_NONET ); }
        finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
        if ( ! $loaded ) { throw new InvalidArgumentException( 'The article HTML could not be parsed.' ); }
        $body = $document->getElementsByTagName( 'body' )->item( 0 );
        if ( null !== $theme_title ) { $this->omit_duplicate_title( $body, $theme_title ); }
        return $this->children( $body, 0 );
    }

    private static function title_text( string $html ): string {
        $text = html_entity_decode( strip_tags( preg_replace( '~<br\b[^>]*>~i', ' ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/[\s\p{Z}]+/u', ' ', $text ) );
    }

    private function omit_duplicate_title( DOMNode $body, string $title ): void {
        foreach ( $body->childNodes as $node ) {
            if ( $node instanceof DOMComment || ( $node instanceof DOMText && preg_match( '/^[\s\p{Z}]*$/u', $node->textContent ) ) ) { continue; }
            if ( $node instanceof DOMElement && strtolower( $node->tagName ) === 'h1' ) {
                $expected = self::title_text( $title );
                $html = $this->inner( $node );
                $has_media = preg_match( '~<(?:img|video|audio|svg|iframe|object|embed|input)\b~i', $html );
                if ( '' !== $expected && ! $has_media && self::title_text( $html ) === $expected ) { $body->removeChild( $node ); }
                else { $this->warnings[] = 'The opening H1 could not be matched to the page title and was kept. Review the article title.'; }
            }
            break;
        }
    }

    private function children( DOMNode $parent, int $depth ): array {
        if ( $depth > 24 ) { throw new InvalidArgumentException( 'Article nesting exceeds 24 levels.' ); }
        $blocks = []; $inline = '';
        foreach ( $parent->childNodes as $node ) {
            if ( $node instanceof DOMText || $node instanceof DOMElement && in_array( strtolower( $node->tagName ), [ 'a', 'abbr', 'b', 'br', 'code', 'del', 'em', 'i', 's', 'small', 'span', 'strong', 'sub', 'sup', 'u' ], true ) && ! $this->has_block_children( $node ) ) {
                $inline .= $parent->ownerDocument->saveHTML( $node );
                continue;
            }
            if ( trim( $inline ) !== '' ) { $blocks[] = $this->paragraph( $inline ); } $inline = '';
            if ( $node instanceof DOMComment ) { $blocks[] = $this->fallback( $node ); continue; }
            if ( ! $node instanceof DOMElement ) { continue; }
            $blocks[] = $this->element( $node, $depth );
        }
        if ( trim( $inline ) !== '' ) { $blocks[] = $this->paragraph( $inline ); }
        return $blocks;
    }

    private function counted( array $block ): array {
        if ( ++$this->count > self::LIMIT ) { throw new InvalidArgumentException( 'The article exceeds 1000 blocks.' ); }
        return $block;
    }

    private function inner( DOMNode $node ): string {
        $html = ''; foreach ( $node->childNodes as $child ) { $html .= $node->ownerDocument->saveHTML( $child ); } return $html;
    }

    private function has_attributes( DOMElement $node, array $allowed ): bool {
        foreach ( $node->attributes as $attribute ) { if ( ! in_array( $attribute->name, $allowed, true ) ) { return true; } } return false;
    }

    private function without_class( string $classes, string $remove ): string {
        return implode( ' ', array_diff( preg_split( '/\s+/', trim( $classes ) ), explode( ' ', $remove ) ) );
    }

    private function classes( string $kind, string $source = '', string $base = '' ): array {
        $custom = trim( $source . ' ' . $this->rules[$kind]['class_name'] );
        $custom = implode( ' ', array_unique( array_filter( preg_split( '/\s+/', $custom ) ) ) );
        $attrs = $custom === '' ? [] : [ 'className' => $custom ];
        $class = trim( $base . ' ' . $custom );
        return [ $attrs, $class === '' ? '' : ' class="' . esc_attr( $class ) . '"' ];
    }

    private function paragraph( string $html, string $source_class = '' ): array {
        [ $attrs, $class ] = $this->classes( 'paragraph', $source_class );
        return $this->counted( self::block( 'core/paragraph', $attrs, '<p' . $class . '>' . $html . '</p>' ) );
    }

    private function element( DOMElement $node, int $depth ): array {
        $tag = strtolower( $node->tagName );
        if ( $this->static_body && in_array( $tag, [ 'section', 'article', 'div' ], true ) && ! $this->has_attributes( $node, [ 'class', 'id' ] ) ) {
            [ $attrs, $class ] = $this->classes( 'paragraph', $node->getAttribute( 'class' ), 'wp-block-group' );
            $attrs['layout'] = [ 'type' => 'default' ];
            if ( 'div' !== $tag ) { $attrs['tagName'] = $tag; }
            $id = $node->getAttribute( 'id' );
            if ( '' !== $id ) { $attrs['anchor'] = $id; }
            return $this->counted( self::block( 'core/group', $attrs, '<' . $tag . $class . ( '' === $id ? '' : ' id="' . esc_attr( $id ) . '"' ) . '>', $this->children( $node, $depth + 1 ), '</' . $tag . '>' ) );
        }
        if ( 'p' === $tag && ! $this->has_attributes( $node, [ 'class' ] ) && ! $this->has_block_children( $node ) ) { return $this->paragraph( $this->inner( $node ), $node->getAttribute( 'class' ) ); }
        if ( preg_match( '/^h([1-6])$/D', $tag, $match ) && ! $this->has_attributes( $node, [ 'class', 'id' ] ) && ! $this->has_block_children( $node ) ) {
            $level = $this->rules['heading']['level'] === 'preserve' ? (int) $match[1] : $this->rules['heading']['level'];
            [ $attrs, $class ] = $this->classes( 'heading', $this->without_class( $node->getAttribute( 'class' ), 'wp-block-heading' ), 'wp-block-heading' );
            $attrs['level'] = $level;
            $anchor = $node->getAttribute( 'id' ); if ( $anchor !== '' ) { $attrs['anchor'] = $anchor; }
            return $this->counted( self::block( 'core/heading', $attrs, '<h' . $level . $class . ( $anchor === '' ? '' : ' id="' . esc_attr( $anchor ) . '"' ) . '>' . $this->inner( $node ) . '</h' . $level . '>' ) );
        }
        if ( in_array( $tag, [ 'ul', 'ol' ], true ) ) { return $this->list_block( $node, $depth ); }
        if ( 'table' === $tag ) { return $this->table( $node ); }
        if ( 'blockquote' === $tag && ! $this->has_attributes( $node, [ 'class' ] ) ) {
            [ $attrs, $class ] = $this->classes( 'quote', $this->without_class( $node->getAttribute( 'class' ), 'wp-block-quote' ), 'wp-block-quote' );
            $citation = ''; $clone = $node->cloneNode( true );
            foreach ( iterator_to_array( $clone->childNodes ) as $child ) { if ( $child instanceof DOMElement && strtolower( $child->tagName ) === 'cite' ) { if ( $citation !== '' || $child->attributes->length ) { return $this->fallback( $node ); } $citation = '<cite>' . $this->inner( $child ) . '</cite>'; $clone->removeChild( $child ); } }
            return $this->counted( self::block( 'core/quote', $attrs, '<blockquote' . $class . '>', $this->children( $clone, $depth + 1 ), $citation . '</blockquote>' ) );
        }
        if ( 'img' === $tag ) { return $this->image( $node ); }
        if ( 'figure' === $tag && ! $this->has_attributes( $node, [ 'class' ] ) ) { return $this->figure( $node, $depth ); }
        if ( 'hr' === $tag && ! $this->has_attributes( $node, [ 'class' ] ) ) {
            [ $attrs, $class ] = $this->classes( 'separator', $this->without_class( $node->getAttribute( 'class' ), 'wp-block-separator has-alpha-channel-opacity' ), 'wp-block-separator has-alpha-channel-opacity' );
            return $this->counted( self::block( 'core/separator', $attrs, '<hr' . $class . '/>' ) );
        }
        return $this->fallback( $node );
    }

    private function has_block_children( DOMNode $node ): bool {
        foreach ( $node->childNodes as $child ) {
            if ( ! $child instanceof DOMElement ) { continue; }
            if ( in_array( strtolower( $child->tagName ), [ 'p', 'div', 'section', 'table', 'ul', 'ol', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) || $this->has_block_children( $child ) ) { return true; }
        }
        return false;
    }

    private function list_block( DOMElement $node, int $depth ): array {
        if ( $depth > 24 || $this->has_attributes( $node, [ 'class', 'start', 'reversed' ] ) ) { return $this->fallback( $node ); }
        $ordered = strtolower( $node->tagName ) === 'ol';
        if ( ! $ordered && ( $node->hasAttribute( 'start' ) || $node->hasAttribute( 'reversed' ) ) ) { return $this->fallback( $node ); }
        $start = $node->getAttribute( 'start' );
        if ( $start !== '' && ! preg_match( '/^-?[0-9]{1,8}$/D', $start ) ) { return $this->fallback( $node ); }
        [ $attrs, $class ] = $this->classes( 'list', $this->without_class( $node->getAttribute( 'class' ), 'wp-block-list' ), 'wp-block-list' );
        $attrs['ordered'] = $ordered;
        if ( $start !== '' ) { $attrs['start'] = (int) $start; }
        if ( $node->hasAttribute( 'reversed' ) ) { $attrs['reversed'] = true; }
        $items = [];
        foreach ( $node->childNodes as $item ) {
            if ( $item instanceof DOMText && trim( $item->textContent ) === '' ) { continue; }
            if ( ! $item instanceof DOMElement || strtolower( $item->tagName ) !== 'li' || $item->attributes->length ) { return $this->fallback( $node ); }
            $content = ''; $nested = []; $seen_list = false;
            foreach ( $item->childNodes as $child ) {
                if ( $child instanceof DOMElement && in_array( strtolower( $child->tagName ), [ 'ul', 'ol' ], true ) ) {
                    $seen_list = true; $list = $this->list_block( $child, $depth + 1 );
                    if ( 'core/list' !== $list['blockName'] ) { return $this->fallback( $node ); }
                    $nested[] = $list;
                }
                elseif ( $seen_list && ( $child instanceof DOMElement || trim( $child->textContent ) !== '' ) || $child instanceof DOMElement && in_array( strtolower( $child->tagName ), [ 'p', 'div', 'table', 'blockquote' ], true ) ) { return $this->fallback( $node ); }
                else { $content .= $node->ownerDocument->saveHTML( $child ); }
            }
            $items[] = $this->counted( self::block( 'core/list-item', [], '<li>' . $content, $nested, '</li>' ) );
        }
        $tag = $ordered ? 'ol' : 'ul';
        $opening = '<' . $tag . $class . ( $start === '' || (int) $start === 1 ? '' : ' start="' . (int) $start . '"' ) . ( $node->hasAttribute( 'reversed' ) ? ' reversed' : '' ) . '>';
        return $this->counted( self::block( 'core/list', $attrs, $opening, $items, '</' . $tag . '>' ) );
    }

    private function table( DOMElement $node, string $caption = '', string $figure_class = '' ): array {
        if ( $this->has_attributes( $node, [ 'class' ] ) ) { return $this->fallback( $node ); }
        $sections = [ 'thead' => '', 'tbody' => '', 'tfoot' => '' ];
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMText && trim( $child->textContent ) === '' ) { continue; }
            if ( ! $child instanceof DOMElement ) { return $this->fallback( $node ); }
            $tag = strtolower( $child->tagName );
            if ( 'caption' === $tag && $caption === '' && ! $child->attributes->length ) { $caption = $this->inner( $child ); continue; }
            if ( 'tr' === $tag ) { $rows = [ $child ]; $section = 'tbody'; }
            elseif ( isset( $sections[$tag] ) && ! $child->attributes->length ) { $rows = iterator_to_array( $child->childNodes ); $section = $tag; }
            else { return $this->fallback( $node ); }
            foreach ( $rows as $row ) {
                if ( $row instanceof DOMText && trim( $row->textContent ) === '' ) { continue; }
                if ( ! $row instanceof DOMElement || strtolower( $row->tagName ) !== 'tr' || $row->attributes->length ) { return $this->fallback( $node ); }
                $cells = '';
                foreach ( $row->childNodes as $cell ) {
                    if ( $cell instanceof DOMText && trim( $cell->textContent ) === '' ) { continue; }
                    if ( ! $cell instanceof DOMElement || ! in_array( strtolower( $cell->tagName ), [ 'td', 'th' ], true ) || $this->has_attributes( $cell, [ 'scope', 'colspan', 'rowspan', 'data-align' ] ) || $this->has_block_children( $cell ) ) { return $this->fallback( $node ); }
                    $tag = strtolower( $cell->tagName ); $attributes = '';
                    if ( $cell->hasAttribute( 'data-align' ) ) {
                        $alignment = $cell->getAttribute( 'data-align' );
                        if ( ! in_array( $alignment, [ 'left', 'center', 'right' ], true ) ) { return $this->fallback( $node ); }
                        $attributes = ' class="has-text-align-' . $alignment . '"';
                    }
                    foreach ( [ 'colspan', 'rowspan', 'scope', 'data-align' ] as $key ) { if ( $cell->hasAttribute( $key ) ) { $attributes .= ' ' . $key . '="' . esc_attr( $cell->getAttribute( $key ) ) . '"'; } }
                    $cells .= '<' . $tag . $attributes . '>' . $this->inner( $cell ) . '</' . $tag . '>';
                }
                $sections[$section] .= '<tr>' . $cells . '</tr>';
            }
        }
        $source_class = $this->without_class( $figure_class, 'wp-block-table is-style-stripes' );
        [ $attrs, $class ] = $this->classes( 'table', $source_class, 'wp-block-table' );
        if ( $this->rules['table']['style'] === 'stripes' ) { $attrs['className'] = trim( ( $attrs['className'] ?? '' ) . ' is-style-stripes' ); $class = ' class="wp-block-table ' . esc_attr( $attrs['className'] ) . '"'; }
        $attrs['hasFixedLayout'] = $this->rules['table']['fixed_layout'];
        $table_class = $attrs['hasFixedLayout'] ? 'has-fixed-layout' : '';
        // Core table classes only express its fixed-layout setting.
        if ( $this->without_class( $node->getAttribute( 'class' ), 'has-fixed-layout' ) !== '' ) { return $this->fallback( $node ); }
        $html = '<figure' . $class . '><table' . ( $table_class === '' ? '' : ' class="' . esc_attr( $table_class ) . '"' ) . '>';
        foreach ( $sections as $section => $rows ) { if ( $rows !== '' ) { $html .= '<' . $section . '>' . $rows . '</' . $section . '>'; } }
        $html .= '</table>' . ( $caption === '' ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' ) . '</figure>';
        return $this->counted( self::block( 'core/table', $attrs, $html ) );
    }

    private function image( DOMElement $node, string $caption = '', string $figure_class = '' ): array {
        if ( $this->has_attributes( $node, [ 'src', 'alt', 'width', 'height' ] ) || ! $node->hasAttribute( 'src' ) ) { return $this->fallback( $node ); }
        [ $attrs, $class ] = $this->classes( 'image', $this->without_class( $figure_class, 'wp-block-image is-resized' ), 'wp-block-image' );
        $attrs['url'] = $node->getAttribute( 'src' ); $attrs['alt'] = $node->getAttribute( 'alt' );
        $image = '<img src="' . esc_attr( $attrs['url'] ) . '" alt="' . esc_attr( $attrs['alt'] ) . '"';
        $styles = [];
        foreach ( [ 'width', 'height' ] as $dimension ) {
            if ( ! $node->hasAttribute( $dimension ) ) { continue; }
            if ( ! preg_match( '/^[1-9][0-9]{0,4}$/D', $node->getAttribute( $dimension ) ) ) { return $this->fallback( $node ); }
            $attrs[$dimension] = $node->getAttribute( $dimension ) . 'px'; $styles[] = $dimension . ':' . $attrs[$dimension];
        }
        if ( $styles ) { $image .= ' style="' . implode( ';', $styles ) . '"'; $class = substr( $class, 0, -1 ) . ' is-resized"'; }
        $image .= '/>';
        return $this->counted( self::block( 'core/image', $attrs, '<figure' . $class . '>' . $image . ( $caption === '' ? '' : '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' ) . '</figure>' ) );
    }

    private function figure( DOMElement $node, int $depth ): array {
        $main = null; $caption = '';
        foreach ( $node->childNodes as $child ) {
            if ( $child instanceof DOMText && trim( $child->textContent ) === '' ) { continue; }
            if ( $child instanceof DOMElement && strtolower( $child->tagName ) === 'figcaption' && $caption === '' && ! $this->has_attributes( $child, [ 'class' ] ) && $this->without_class( $child->getAttribute( 'class' ), 'wp-element-caption' ) === '' ) { $caption = $this->inner( $child ); }
            elseif ( $child instanceof DOMElement && in_array( strtolower( $child->tagName ), [ 'table', 'img' ], true ) && $main === null ) { $main = $child; }
            else { return $this->fallback( $node ); }
        }
        if ( $main === null ) { return $this->fallback( $node ); }
        $block = strtolower( $main->tagName ) === 'table' ? $this->table( $main, $caption, $node->getAttribute( 'class' ) ) : $this->image( $main, $caption, $node->getAttribute( 'class' ) );
        return $block['blockName'] === 'core/html' ? $this->fallback( $node ) : $block;
    }

    private function fallback( DOMNode $node ): array {
        if ( $this->rules['fallback'] === 'reject' ) { throw new InvalidArgumentException( 'An article element cannot be represented by the configured native blocks. Review its HTML or allow Custom HTML fallback.' ); }
        $this->warnings[] = 'Some article markup was retained in a Custom HTML block because its structure or attributes require it.';
        return $this->counted( self::block( 'core/html', [], $node->ownerDocument->saveHTML( $node ) ) );
    }
}
