<?php
/** Source positions, including the end of raw-text elements, from the native HTML tokenizer. */
defined( 'ABSPATH' ) || exit;

final class Nova_Mapping_HTML_Cursor extends WP_HTML_Processor {
    public function source_span(): array {
        if ( ! $this->set_bookmark( 'nova_field' ) ) {
            throw new InvalidArgumentException( 'Cannot locate an editable value in the original HTML.' );
        }
        // WP_HTML_Processor prefixes user bookmarks to separate them from its tree builder.
        $span = $this->bookmarks['_nova_field'];
        $this->release_bookmark( 'nova_field' );
        return [ $span->start, $span->length ];
    }
}
