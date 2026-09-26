<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen, never on deactivation, so switching the
 * plugin off temporarily loses nothing at all.
 *
 * **Deleting the plugin keeps every wall post, comment, like and setting by default.** Someone who
 * deletes it by accident, or removes it to install a clean copy, gets everything back when they
 * reinstall. Destruction is opt-in, through the "Delete All Data" box on the plugin's settings tab.
 *
 * There is no way to ask at delete time: the confirmation form in wp-admin/plugins.php (the
 * `verify-delete` form, WP 7.1) has no hook inside it, so the setting lives on our own tab. And
 * WordPress prints "(will also delete its data)" on that screen whenever an uninstall.php exists at
 * all, whatever the file actually does, which is why the setting's description says the warning
 * does not apply unless the box is ticked.
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Exit unless WordPress is genuinely uninstalling this plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes this plugin's traces from the site that is current when it is called.
 *
 * A function so that on a network it can run once per site: the uninstaller runs in the context of
 * one site only.
 *
 * @return void
 */
function hpsw_uninstall_site() {
	global $wpdb;

	// Read the owner's choice first, before anything is touched.
	$delete_all = ! empty( get_option( 'hp_hpsw_delete_data' ) );

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Always cleaned: regenerable runtime state, never the owner's content or configuration.
	 * -------------------------------------------------------------------------------------------------
	 */

	// The updater's cached release and its two companions. Site transients live under their own
	// prefix, so no option sweep reaches them.
	delete_site_transient( 'social_walls_for_hivepress_release' );
	delete_site_transient( 'social_walls_for_hivepress_release_reason' );
	delete_site_transient( 'social_walls_for_hivepress_release_rate_limit' );

	/*
	 * Background jobs whose callbacks stop existing with the plugin: the updater's refresh, the
	 * follower emails and the cache purges. With no group and no arguments Action Scheduler cancels
	 * every argument set of a hook (action-scheduler/functions.php, cancel_actions_by_hook()), which
	 * is what is needed: every follower job carries a post ID and an offset. The updater's refresh is
	 * also cleared from WP-Cron, where it is queued when HivePress is absent.
	 */
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( [ 'social_walls_for_hivepress_release_refresh', 'hpsw_notify_followers', 'hpsw_purge_cache' ] as $hpsw_hook ) {
			as_unschedule_all_actions( $hpsw_hook );
		}
	}

	wp_clear_scheduled_hook( 'social_walls_for_hivepress_release_refresh' );

	// The stored version keys the rewrite flush on init; left behind, a reinstall of the same
	// version would never flush and every wall page would answer 404.
	delete_option( 'hp_hpsw_version' );

	/*
	 * -------------------------------------------------------------------------------------------------
	 * Everything below happens only when the owner asked for it.
	 * -------------------------------------------------------------------------------------------------
	 */

	if ( ! $delete_all ) {
		return;
	}

	// Every wall post, in every status. wp_delete_post() takes each post's comments and likes with
	// it; photos stay in the Media Library, as the setting's description promises.
	do {
		$post_ids = get_posts(
			[
				'post_type'      => 'hp_hpsw_post',
				// Named in full: 'any' leaves out the bin and auto-drafts.
				'post_status'    => [ 'publish', 'pending', 'draft', 'future', 'private', 'trash', 'auto-draft' ],
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		foreach ( $post_ids as $post_id ) {
			wp_delete_post( (int) $post_id, true );
		}
	} while ( $post_ids );

	// Any comment or like left without its post. `type` is named explicitly because core hides
	// non-public comment types from queries that name none.
	$comment_ids = get_comments(
		[
			'type__in' => [ 'hp_hpsw_comment', 'hp_hpsw_like' ],
			'status'   => 'all',
			'fields'   => 'ids',
			'number'   => 0,
		]
	);

	foreach ( (array) $comment_ids as $comment_id ) {
		wp_delete_comment( (int) $comment_id, true );
	}

	// The posting options added to membership plans, and the copies bought onto memberships.
	foreach ( [ 'hp_hpsw_post_access', 'hp_hpsw_post_unlimited', 'hp_hpsw_post_limit' ] as $meta_key ) {
		delete_post_meta_by_key( $meta_key );
	}

	/*
	 * HivePress's per-object caches for this plugin's models. They are ordinary post-meta and
	 * user-meta rows named "_transient_hp_models/hpsw_.../..." plus a timeout twin, and WordPress's
	 * transient garbage collection never looks in the meta tables, so without this they are
	 * permanent (resources/wordpress-php-notes.md, "A transient stored in USER META is never garbage
	 * collected").
	 */
	// Two plain queries rather than one with an interpolated table: a column name cannot be a
	// prepare() placeholder, and nothing here needs to vary.
	$cache_key = '_transient_' . $wpdb->esc_like( 'hp_models/hpsw_' ) . '%';
	$cache_ttl = '_transient_timeout_' . $wpdb->esc_like( 'hp_models/hpsw_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard meta keys, which no WordPress API can enumerate.
	$user_rows = $wpdb->get_col( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $cache_key, $cache_ttl ) );

	foreach ( (array) $user_rows as $row_id ) {
		delete_metadata_by_mid( 'user', absint( $row_id ) );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
	$post_rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $cache_key, $cache_ttl ) );

	foreach ( (array) $post_rows as $row_id ) {
		delete_metadata_by_mid( 'post', absint( $row_id ) );
	}

	// Reworded copies of this plugin's emails. HivePress keeps an edited email as an hp_email post
	// named after the email (components/class-email.php:59-91); with the plugin gone nothing can
	// send them.
	$email_ids = get_posts(
		[
			'post_type'      => 'hp_email',
			'post_status'    => 'any',
			'post_name__in'  => [ 'hpsw_post_follow', 'hpsw_post_submit', 'hpsw_post_approve', 'hpsw_post_reject', 'hpsw_comment_add', 'hpsw_comment_reply' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);

	foreach ( $email_ids as $email_id ) {
		wp_delete_post( (int) $email_id, true );
	}

	/*
	 * The settings, by prefix. The "delete all data" option is excluded here and removed at the very
	 * end: if this run fails part-way, the flag is still set and a second attempt finishes the job,
	 * rather than the site silently flipping back to "retain" with half the data gone.
	 *
	 * The sweep covers every setting on the tab, including those added in 1.0.2: hp_hpsw_enable_share,
	 * hp_hpsw_share_logo (an attachment ID only; the image stays in the Media Library),
	 * hp_hpsw_show_radius and hp_hpsw_default_radius, and the one added in 1.0.3,
	 * hp_hpsw_coupon_color. Checked against the settings config.
	 */
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of wildcard option names, which no WordPress API can enumerate.
	$option_names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name != %s", $wpdb->esc_like( 'hp_hpsw_' ) . '%', 'hp_hpsw_delete_data' ) );

	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}

	// Any ordinary transient of ours: stored as "_transient_{name}" plus "_transient_timeout_{name}",
	// which the prefix sweep above cannot match.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- as above.
	$transients = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . $wpdb->esc_like( 'hp_hpsw_' ) . '%', '_transient_timeout_' . $wpdb->esc_like( 'hp_hpsw_' ) . '%' ) );

	foreach ( (array) $transients as $transient_name ) {
		delete_option( $transient_name );
	}

	// Last, and only once everything above has succeeded.
	delete_option( 'hp_hpsw_delete_data' );
}

/*
 * A network install runs this file once, in one site's context, so every site is visited in turn.
 * On a single site the loop is skipped and nothing changes.
 */
if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $hpsw_site_id ) {
		switch_to_blog( (int) $hpsw_site_id );

		hpsw_uninstall_site();

		restore_current_blog();
	}
} else {
	hpsw_uninstall_site();
}
