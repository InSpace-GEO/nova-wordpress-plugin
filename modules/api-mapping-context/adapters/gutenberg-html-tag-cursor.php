<?php
/** Byte ranges for explicit tags in stored markup, including closing tags. */
defined( 'ABSPATH' ) || exit;

final class Nova_Mapping_Otter_HTML_Tags extends WP_HTML_Tag_Processor {
    public function span(): array {
        $this->set_bookmark( 'nova_otter' );
        $span = $this->bookmarks['nova_otter'];
        $this->release_bookmark( 'nova_otter' );
        return [ $span->start, $span->length ];
    }
}
