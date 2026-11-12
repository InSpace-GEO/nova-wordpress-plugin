<?php
/** Preview-only script fallback for themes which do not print WordPress's script queue. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Preview_Bootstrap {
    private const MAX_BYTES = 8388608;

    /** Called only after the preview has authenticated and verified the rendered reference. */
    public static function start( array $config, string $url, string $nonce ): void {
        $buffer = ''; $passthrough = false;
        ob_start( static function ( string $chunk, int $phase ) use ( $config, $url, $nonce, &$buffer, &$passthrough ): string {
            if ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) { $buffer = ''; return ''; }
            if ( $passthrough ) { return $chunk; }
            $buffer .= $chunk;
            if ( strlen( $buffer ) > self::MAX_BYTES ) { $passthrough = true; $output = $buffer; $buffer = ''; return $output; }
            if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) { return ''; }
            $output = $buffer; $buffer = '';
            $status = http_response_code();
            if ( ( $status && $status !== 200 ) || ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'HEAD' ) { return $output; }
            foreach ( headers_list() as $header ) {
                if ( stripos( $header, 'Content-Type:' ) === 0 && ! preg_match( '~^Content-Type:\s*text/html(?:\s*;|\s*$)~i', $header ) ) { return $output; }
                if ( stripos( $header, 'Content-Encoding:' ) === 0 && ! preg_match( '~^Content-Encoding:\s*identity\s*$~i', $header ) ) { return $output; }
            }
            $result = self::complete( $output, $config, $url, $nonce );
            if ( $result !== $output && ! headers_sent() ) { header_remove( 'Content-Length' ); }
            return $result;
        } );
    }

    /** Keep existing output byte-for-byte; add only our nonce-authorized bootstrap if absent. */
    public static function complete( string $html, array $config, string $url, string $nonce ): string {
        if ( $nonce === '' || stripos( $html, '<body' ) === false || ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $html; }
        $parser = new WP_HTML_Tag_Processor( $html ); $body = false; $configured = false; $loaded = false; $inert = 0;
        $configuration = 'window.NovaRecipeCanvas=' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';';
        while ( $parser->next_tag( [ 'tag_closers' => 'visit' ] ) ) {
            $tag = $parser->get_tag();
            if ( $tag === 'TEMPLATE' ) { $inert = max( 0, $inert + ( $parser->is_tag_closer() ? -1 : 1 ) ); continue; }
            if ( $inert || $parser->is_tag_closer() ) { continue; }
            if ( $tag === 'BODY' ) { $body = true; }
            if ( $tag !== 'SCRIPT' || $parser->get_attribute( 'nonce' ) !== $nonce || ! in_array( $parser->get_attribute( 'type' ), [ null, '', 'text/javascript', 'application/javascript' ], true ) ) { continue; }
            if ( $parser->get_attribute( 'id' ) === 'nova-recipe-canvas-js-before' && str_contains( $parser->get_modifiable_text(), $configuration ) ) { $configured = true; }
            if ( $configured && $parser->get_attribute( 'id' ) === 'nova-recipe-canvas-js' && $parser->get_attribute( 'src' ) === $url ) { $loaded = true; }
        }
        if ( ! $body || $parser->paused_at_incomplete_token() || $loaded ) { return $html; }
        // Appending leaves the theme HTML untouched. Browsers place these final scripts in
        // the document body even when the theme already emitted its closing HTML tag.
        return $html . "\n" . '<script id="nova-recipe-canvas-js-before" nonce="' . esc_attr( $nonce ) . '">' . $configuration . '</script>'
            . '<script id="nova-recipe-canvas-js" nonce="' . esc_attr( $nonce ) . '" src="' . esc_url( $url ) . '"></script>';
    }
}
