<?php
/**
 * Plugin Name: Social Walls for HivePress
 * Plugin URI: https://github.com/irapidchris-del/social-walls-for-hivepress
 * Description: Gives every Vendor a wall for deals and updates, shows it on their profile, and adds an all-Vendors wall block with filters, likes and comments.
 * Version: 1.1.0
 * Author: ChrisB @ HivePress Community
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Text Domain: social-walls-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/irapidchris-del/social-walls-for-hivepress
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Keep in step with the Version header above and the readme Stable tag on every release.
define( 'HPSW_VERSION', '1.1.0' );

// The main file, for asset paths and URLs that must not depend on the installed folder name.
define( 'HPSW_FILE', __FILE__ );

// Set up updates from GitHub releases.
require_once __DIR__ . '/includes/updater.php';

Social_Walls\Updater\bootstrap( __FILE__ );

/**
 * Seeds the settings that are ON by default, and flushes the rewrite rules.
 *
 * HivePress seeds a field's `default` only when HivePress ITSELF is activated or updated
 * (hivepress/includes/components/class-admin.php:265, core 1.7.31), so a site that installs this
 * plugin later would otherwise have likes and follower emails switched off until the tab was first
 * saved. `add_option()` never overwrites, so an owner who switched either off keeps that choice when
 * the plugin is deactivated and activated again.
 *
 * Deleting the rewrite option is core's own way of flushing (components/class-router.php:503-505):
 * WordPress rebuilds lazily on the next request, once this plugin's routes are registered. The
 * component flushes again whenever the stored version changes, which covers an update copied over
 * the old files, where no activation hook ever runs.
 *
 * @return void
 */
function hpsw_activate() {
	add_option( 'hp_hpsw_enable_likes', '1' );
	add_option( 'hp_hpsw_notify_followers', '1' );
	add_option( 'hp_hpsw_enable_share', '1' );
	add_option( 'hp_hpsw_show_radius', '1' );

	delete_option( 'rewrite_rules' );

	hpsw_schedule_ended_deals();
}

register_activation_hook( __FILE__, 'hpsw_activate' );

/**
 * Schedules the daily pass over ended Deals, unless it is already scheduled.
 *
 * A plain function rather than a component method: on the activation request HivePress has not
 * loaded this plugin's classes, because the extension was registered before it was active.
 *
 * @return void
 */
function hpsw_schedule_ended_deals() {
	if ( ! wp_next_scheduled( 'hpsw_tidy_ended_deals' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hpsw_tidy_ended_deals' );
	}
}

/**
 * Drops the cached rewrite rules on deactivation so the wall addresses disappear, and stops the daily
 * pass over ended Deals.
 *
 * Not `flush_rewrite_rules()`: on the deactivation request this plugin's routes are still
 * registered, so a flush would write the dead rules straight back.
 *
 * @return void
 */
function hpsw_deactivate() {
	delete_option( 'rewrite_rules' );

	// Every run of the daily pass, including a follow-up run queued with a batch offset.
	wp_unschedule_hook( 'hpsw_tidy_ended_deals' );
}

register_deactivation_hook( __FILE__, 'hpsw_deactivate' );

/**
 * Registers the extension.
 *
 * Two registration forms exist and both have a failure mode. HivePress resolves a bare directory
 * path to `{dirname}/{dirname}.php`, so the string form fails silently whenever the installed folder
 * name differs from the main file name (a source zip unpacks to `social-walls-for-hivepress-main`,
 * for instance). The array form always registers, but core's updater probe concatenates every entry
 * as a string (class-core.php:249-250), so an array entry makes it log a warning on each request
 * unless the probe has already been satisfied. So: the string form whenever the folder name matches,
 * and only for a renamed folder the array form, with the probe run here first over the string
 * entries so core's loop never reaches the array. Registered late (priority 100) so extensions that
 * bundle the updates package are already listed when that probe runs.
 *
 * @param array<string, mixed> $extensions Extension arguments.
 * @return array<string, mixed>
 */
function hpsw_register_extension( $extensions ) {
	if ( file_exists( __DIR__ . '/' . basename( __DIR__ ) . '.php' ) ) {
		$extensions[] = __DIR__;

		return $extensions;
	}

	if ( ! isset( $extensions['updates'] ) ) {
		$path = '/vendor/hivepress/hivepress-updates';

		foreach ( $extensions as $dir ) {
			if ( is_string( $dir ) && file_exists( $dir . $path . '/hivepress-updates.php' ) ) {
				$extensions['updates'] = $dir . $path;

				break;
			}
		}

		// Set it even when nothing was found. Core's own probe only runs while this key is unset and
		// concatenates EVERY entry as a string, so on a site with no premium extension the array
		// entry below would make it warn "Array to string conversion" on every request. A path that
		// does not exist is dropped by core's own file_exists() guard (class-core.php:277).
		if ( ! isset( $extensions['updates'] ) ) {
			$extensions['updates'] = __DIR__ . $path;
		}
	}

	$extensions['social_walls_for_hivepress'] = [
		'name'    => 'Social Walls for HivePress',
		'version' => HPSW_VERSION,
		'path'    => __DIR__,
		'url'     => rtrim( plugin_dir_url( __FILE__ ), '/' ),
	];

	return $extensions;
}

add_filter( 'hivepress/v1/extensions', 'hpsw_register_extension', 100 );

// Add a Settings link on the Plugins screen, pointing at this plugin's own HivePress settings tab.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		if ( class_exists( '\HivePress\Core' ) ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=social_walls' ) ) . '">' . esc_html__( 'Settings', 'social-walls-for-hivepress' ) . '</a>' );
		}

		return $links;
	}
);

// Show a notice if HivePress is not active.
add_action(
	'admin_notices',
	function () {
		if ( ! class_exists( '\HivePress\Core' ) && current_user_can( 'activate_plugins' ) ) {

			// Dismissible, because an undismissable notice on every admin screen is admin hijacking even
			// when the thing it says is true. WordPress hides it for the current page load only, so the
			// warning returns until HivePress is actually activated.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Social Walls for HivePress requires the HivePress plugin to be installed and activated.', 'social-walls-for-hivepress' ) . '</p></div>';
		}
	}
);

/**
 * The author's support page.
 *
 * One place, so the Plugins row and the View details popup can never drift apart.
 *
 * @return string
 */
function hpsw_get_support_url() {
	return 'https://ko-fi.com/chrisbathivepresscommunity';
}

/**
 * Adds a quiet "Donate" link to this plugin's row meta.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen, so without the basename test the
 * link would appear on every row on the site. The markup is copied verbatim from the house spec so
 * every plugin's row looks identical, and WordPress joins row-meta items with " | " itself.
 *
 * @param array<string> $meta Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function hpsw_add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( hpsw_get_support_url() ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'social-walls-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', 'hpsw_add_row_meta', 10, 2 );

/**
 * Reads one of this plugin's settings, with HivePress's stored-empty behaviour accounted for.
 *
 * HivePress seeds a field's `default` only when HivePress itself is activated or updated, and
 * otherwise applies it only while RENDERING the settings screen (class-admin.php:265, :307), so a
 * site that installed this plugin later has no stored value until the tab is first saved. Once it
 * is saved, an unticked checkbox and a cleared field are both stored as an empty string. So: absent
 * (null or false) means "use the default", and an empty string is a deliberate empty that must be
 * respected (resources/hivepress-settings.md, "The stored-empty trap").
 *
 * @param string $name Option name without the "hp_" prefix.
 * @param mixed  $fallback Value when the option has never been saved.
 * @return mixed
 */
function hpsw_get_option( $name, $fallback ) {
	$value = get_option( 'hp_' . $name, null );

	if ( null === $value || false === $value ) {
		return $fallback;
	}

	return $value;
}

/**
 * Reads a number setting. A cleared number field is stored as an empty string, which `(int)` would
 * turn into 0 rather than the documented default, so anything non-numeric falls back. An explicit 0
 * is numeric and is respected.
 *
 * @param string $name Option name without the "hp_" prefix.
 * @param int    $fallback Value when the option is absent or not a number.
 * @return int
 */
function hpsw_get_number_option( $name, $fallback ) {
	$value = hpsw_get_option( $name, $fallback );

	return is_numeric( $value ) ? (int) $value : (int) $fallback;
}

/**
 * Reads a single-choice setting against a closed list of stored values.
 *
 * A radio field that has never been saved is absent, and one stored as an empty string is not one
 * of the choices the screen offers, so both fall back to the default rather than reaching a branch
 * that matches nothing.
 *
 * @param string   $name Option name without the "hp_" prefix.
 * @param string[] $allowed The values the field can store.
 * @param string   $fallback Value when the stored value is absent or not in the list.
 * @return string
 */
function hpsw_get_choice_option( $name, $allowed, $fallback ) {
	$value = hpsw_get_option( $name, $fallback );

	return in_array( $value, $allowed, true ) ? (string) $value : $fallback;
}
