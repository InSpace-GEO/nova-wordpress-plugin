<?php
/**
 * The client's NOVA publication setting, as posting-service freezes it on a delivery.
 *
 * Optional top-level `publication_status` (`draft` | `publish`, posting-service ADR 0036). When a
 * delivery carries it, it decides the native status instead of the mapping's
 * `routing.publication`, including mappings created with a fixed `publish`:
 *
 *  - creating a post: the post is created in that status;
 *  - updating a post: `publish` publishes it, `draft` keeps its current status — a delivery never
 *    takes a live page offline;
 *  - absent (or anything else): the mapping decides, exactly as before.
 *
 * The writers' own publish-capability checks run on the resolved status, so a user that may not
 * publish refuses the write instead of publishing anything.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Publication_Status {
    private const STATUSES = [ 'draft', 'publish' ];

    /** The frozen status on a delivery, or null when it carries none. */
    public static function from_delivery( array $delivery ): ?string {
        $status = $delivery['publication_status'] ?? null;
        return is_string( $status ) && in_array( $status, self::STATUSES, true ) ? $status : null;
    }

    /** The routing publication (`preserve` | `draft` | `publish`) a writer applies. */
    public static function routing( string $mapping_publication, array $delivery, bool $creating ): string {
        $status = self::from_delivery( $delivery );
        if ( null === $status ) { return $mapping_publication; }
        if ( $creating ) { return $status; }
        return 'publish' === $status ? 'publish' : 'preserve';
    }
}
