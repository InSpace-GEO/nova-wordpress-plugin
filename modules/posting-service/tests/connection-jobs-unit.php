<?php
/** Archived delivery IDs cannot re-enter the real active journal. No DB/network. */
require __DIR__ . '/posting-jobs-unit.php';
class Nova_Bridge_Suite_Posting_Connection {
    public static $retired = false;
    public static function archived( $db, $site, $delivery ) { return self::$retired; }
}
$checks = 0; $db = new Posting_Jobs_Database(); $jobs = new Nova_Bridge_Suite_Posting_Jobs( $db );
Nova_Bridge_Suite_Posting_Connection::$retired = true;
$retired = $jobs->enqueue_discovered( $site, journal_item(), '1' );
journal_check( 'superseded' === $retired['phase'] && $db->rows === [] && $db->queries === [], 'Retired delivery discovery creates no active row and cannot be claimed.' );
Nova_Bridge_Suite_Posting_Connection::$retired = new WP_Error( 'archive_unavailable' );
journal_check( is_wp_error( $jobs->enqueue_discovered( $site, journal_item(), '1' ) ) && $db->rows === [], 'An archive read failure cannot reactivate old work.' );
Nova_Bridge_Suite_Posting_Connection::$retired = false;
$fresh = $jobs->enqueue_discovered( $site, journal_item(), '1' );
journal_check( ! is_wp_error( $fresh ) && count( $db->rows ) === 1 && $jobs->claim( $site ) !== null, 'A nonarchived delivery still enters the existing claim workflow.' );
echo "PASS {$checks} archived journal checks\n";
