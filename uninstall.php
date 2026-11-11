<?php
/**
 * Uninstall routine for Forest Valley Redirect & 404 Helper for Ecwid.
 *
 * Runs only when the user deletes the plugin from the Plugins screen. Removes
 * every persistent artifact the plugin created — its two tables, its options,
 * its transients, and its scheduled cron events — so a fresh install starts
 * clean and a deletion leaves no orphans.
 *
 * It deliberately does NOT touch the official Ecwid Shopping Cart plugin's
 * `ecwid_store_id` / `ecwid_public_token` options: the helper only ever reads
 * those, it does not own them.
 *
 * @package ForestValley\RedirectHelper
 */

// Exit if not called by WordPress during plugin uninstall.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove all of the plugin's data from the current site.
 *
 * Called once per site so the same teardown applies on single-site installs and
 * on every site of a multisite network.
 *
 * @return void
 */
function fvrh404_uninstall_site() {
	global $wpdb;

	// 1. Drop the plugin's tables. Identifiers cannot be bound via prepare(); the
	// names are built from the trusted table prefix and hard-coded bases.
	$log_table       = $wpdb->prefix . 'fvrh404_404_log';
	$redirects_table = $wpdb->prefix . 'fvrh404_redirects';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$log_table}`" );
	$wpdb->query( "DROP TABLE IF EXISTS `{$redirects_table}`" );

	// 2. Delete the plugin's own options.
	$options = array(
		'fvrh404_connection',          // ConnectionState — store id/token + connect opt-in.
		'fvrh404_cta_dismissed',       // UpgradeCta — dismissed in-context upgrade prompts.
		'fvrh404_collision_dismissed', // CollisionScanner — dismissed collision set.
		'fvrh404_db_version',          // Schema — installed DB schema version.
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// 3. Delete every transient the plugin created (single-key + per-store
	// prefixes: fvrh404_collisions, fvrh404_rules_*, fvrh404_deleted_*,
	// fvrh404_app_status_*). Covers both the value and timeout rows.
	$wpdb->query(
		"DELETE FROM `{$wpdb->options}`
		 WHERE option_name LIKE '\_transient\_fvrh404\_%'
		    OR option_name LIKE '\_transient\_timeout\_fvrh404\_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// 4. Unschedule the hourly verdict/collision task and any pending one-off
	// collision scan. Deactivation clears both too, but a WP-CLI uninstall can
	// run without the deactivation hook ever firing.
	wp_clear_scheduled_hook( 'fvrh404_hourly_tasks' );
	wp_clear_scheduled_hook( 'fvrh404_collision_scan' );
}

/**
 * Run the teardown across every site (single-site or full multisite network).
 *
 * Wraps the dispatch in a function so the loop variables stay function-local
 * rather than being seen as plugin-defined globals at uninstall's file scope.
 *
 * @return void
 */
function fvrh404_uninstall() {
	if ( is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			fvrh404_uninstall_site();
			restore_current_blog();
		}
	} else {
		fvrh404_uninstall_site();
	}
}

fvrh404_uninstall();
