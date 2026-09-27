<?php
/**
 * Wall admin component.
 *
 * @package Social_Walls
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The wp-admin side: the settings-screen chrome, the notes that explain what a setting depends on,
 * and the Wall Posts list screen.
 */
final class Hpsw_Wall_Admin extends Component {

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {
		if ( is_admin() ) {

			// After HivePress's own settings pass at 10.
			add_filter( 'hivepress/v1/settings', [ $this, 'add_settings_notes' ], 20 );

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );

			// The Wall Posts list.
			add_filter( 'manage_hp_hpsw_post_posts_columns', [ $this, 'add_admin_columns' ] );
			add_action( 'manage_hp_hpsw_post_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
			add_filter( 'display_post_states', [ $this, 'add_post_states' ], 10, 2 );
			add_filter( 'the_title', [ $this, 'fill_empty_title' ], 10, 2 );
		}

		parent::__construct( $args );
	}

	/**
	 * Appends site-specific notes to the section descriptions.
	 *
	 * A limitation that changes what an owner should choose belongs beside the choice, not in the
	 * readme (resources/hivepress-settings.md, "Put a limitation where the decision is made"). Every
	 * read is guarded, including this plugin's own tab: a request racing a plugin update can reach
	 * this callback with the config file momentarily unreadable ("A settings-filter callback must
	 * guard its own tab"). class_exists() on a HivePress class is only safe after init.
	 *
	 * @param array $settings Settings configuration.
	 * @return array
	 */
	public function add_settings_notes( $settings ) {
		if ( ! is_array( $settings ) || ! isset( $settings['social_walls']['sections'] ) || ! is_array( $settings['social_walls']['sections'] ) || ! did_action( 'init' ) ) {
			return $settings;
		}

		$sections = &$settings['social_walls']['sections'];
		$wall     = hivepress()->hpsw_wall;

		if ( $wall && isset( $sections['posting']['description'] ) && $wall->is_posting_gated() && ! $wall->is_memberships_active() ) {
			$sections['posting']['description'] .= ' ' . esc_html__( 'Posting is limited to certain membership plans, but HivePress Memberships is not active, so no Vendor can post at the moment. Activate Memberships, or untick "Allow posting to the wall" on every plan, to let Vendors post again.', 'social-walls-for-hivepress' );
		}

		if ( isset( $sections['pinning']['description'] ) && ! class_exists( 'WooCommerce' ) ) {
			$sections['pinning']['description'] .= ' ' . esc_html__( 'WooCommerce is not active on this site, so pinning can only be done by hand for now.', 'social-walls-for-hivepress' );
		}

		if ( $wall && isset( $sections['notifications']['description'] ) && ! $wall->is_follow_active() ) {
			$sections['notifications']['description'] .= ' ' . esc_html__( 'Follow Vendors for HivePress is not active on this site, so there are no followers to email yet.', 'social-walls-for-hivepress' );
		}

		return $settings;
	}

	/**
	 * Checks whether the settings tab being rendered is this plugin's own.
	 *
	 * The address cannot answer it: HivePress falls back to the FIRST tab whenever `tab` is absent
	 * (class-admin.php, get_settings_tab()). The registered fields can: register_settings() builds
	 * the fields of exactly one tab, keyed by the prefixed option name (class-admin.php:275-325, core
	 * 1.7.31), so after admin_init this plugin's keys are present on its own tab and no other
	 * (resources/hivepress-settings.md, "The tab IS knowable server-side").
	 *
	 * @return bool
	 */
	protected function is_settings_tab() {
		if ( ! isset( $GLOBALS['wp_settings_fields']['hp_settings'] ) || ! is_array( $GLOBALS['wp_settings_fields']['hp_settings'] ) ) {
			return false;
		}

		foreach ( $GLOBALS['wp_settings_fields']['hp_settings'] as $section ) {
			foreach ( array_keys( (array) $section ) as $field ) {
				if ( 0 === strpos( (string) $field, 'hp_hpsw_' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Dresses this plugin's settings tab with the shared settings-screen chrome.
	 *
	 * The quick-links nav, the sideways floating Save control and the back-to-top button, copied
	 * from the reference implementation in Account Menu Enhancer for HivePress so every extension
	 * in the family puts the same controls in the same places (resources/hivepress-settings.md, "The
	 * settings anchor nav: one shared marker class"). Two gates, neither replacing the other:
	 * is_settings_tab() decides whether the files load, the script's field-prefix test whether it acts.
	 *
	 * @return void
	 */
	public function enqueue_settings_assets() {
		// Screen detection only; no form data is read or written.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin page is rendering.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'hp_settings' !== $page || ! $this->is_settings_tab() ) {
			return;
		}

		$path = plugin_dir_path( HPSW_FILE );
		$url  = plugin_dir_url( HPSW_FILE );

		wp_enqueue_style(
			'hpsw-backend',
			$url . 'assets/css/backend.css',
			[],
			HPSW_VERSION . '.' . (int) filemtime( $path . 'assets/css/backend.css' )
		);

		// WordPress's own colour picker for the Coupon Code Colour field. Core's Color field is a plain
		// native colour input with no picker of its own, and an empty native colour input submits
		// "#000000", so without the script's conversion saving the tab would turn every code black.
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_script(
			'hpsw-backend',
			$url . 'assets/js/backend.js',
			[ 'jquery', 'wp-color-picker' ],
			HPSW_VERSION . '.' . (int) filemtime( $path . 'assets/js/backend.js' ),
			true
		);

		wp_localize_script(
			'hpsw-backend',
			'hpswBackendData',
			[
				'labels' => [
					// The colon is part of the wording: it leads into the links that follow it.
					'jumpTo'          => esc_html__( 'Jump to a section:', 'social-walls-for-hivepress' ),
					'save'            => esc_html__( 'Save Changes', 'social-walls-for-hivepress' ),
					'backToTop'       => esc_html__( 'Back to top', 'social-walls-for-hivepress' ),
					'defaultSettings' => esc_html__( 'Default Settings', 'social-walls-for-hivepress' ),
				],
			]
		);
	}

	/**
	 * Adds the Vendor, type and engagement columns to the Wall Posts list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_admin_columns( $columns ) {
		$added = [
			'hpsw_vendor'     => hivepress()->translator->get_string( 'vendor' ),
			'hpsw_type'       => esc_html__( 'Type', 'social-walls-for-hivepress' ),
			'hpsw_engagement' => esc_html__( 'Likes and Comments', 'social-walls-for-hivepress' ),
		];

		// Straight after the title, where core puts a Listing's Vendor column
		// (components/class-listing.php:522-530).
		return array_merge( array_slice( $columns, 0, 2, true ), $added, array_slice( $columns, 2, null, true ) );
	}

	/**
	 * Renders a Wall Posts list column.
	 *
	 * @param string $column Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( 'hpsw_vendor' === $column ) {
			$vendor_id = wp_get_post_parent_id( $post_id );

			if ( $vendor_id && 'hp_vendor' === get_post_type( $vendor_id ) ) {
				echo '<a href="' . esc_url( (string) get_edit_post_link( $vendor_id ) ) . '">' . esc_html( get_the_title( $vendor_id ) ) . '</a>';
			} else {
				echo '&mdash;';
			}
		} elseif ( 'hpsw_type' === $column ) {
			echo 'deal' === get_post_meta( $post_id, 'hp_type', true ) ? esc_html__( 'Deal', 'social-walls-for-hivepress' ) : esc_html__( 'Update', 'social-walls-for-hivepress' );
		} elseif ( 'hpsw_engagement' === $column ) {
			$wall = hivepress()->hpsw_wall;

			if ( $wall ) {
				$counts = $wall->get_engagement( [ $post_id ] );

				echo esc_html(
					sprintf(
						/* translators: 1: number of likes, 2: number of comments. */
						__( '%1$s likes, %2$s comments', 'social-walls-for-hivepress' ),
						number_format_i18n( $counts[ $post_id ]['likes'] ),
						number_format_i18n( $counts[ $post_id ]['comments'] )
					)
				);
			}
		}
	}

	/**
	 * Checks whether a Deal in the admin list ended because of its coupon.
	 *
	 * The first row asks for every post on the screen at once, so the list costs two queries in all
	 * rather than two per row.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_coupon_ended( $post_id ) {
		global $wp_query;

		$wall = hivepress()->hpsw_wall;

		if ( ! $wall ) {
			return false;
		}

		if ( $wp_query instanceof \WP_Query && is_array( $wp_query->posts ) ) {
			$wall->load_ended_times( wp_list_pluck( $wp_query->posts, 'ID' ) );
		}

		return null !== $wall->get_ended_time( $post_id );
	}

	/**
	 * Marks pinned posts and ended Deals in the list, the way core marks Featured Listings
	 * (components/class-listing.php:580-596).
	 *
	 * @param array    $states Post states.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function add_post_states( $states, $post ) {
		if ( ! $post instanceof \WP_Post || 'hp_hpsw_post' !== $post->post_type ) {
			return $states;
		}

		if ( 1 === (int) $post->menu_order && (int) get_post_meta( $post->ID, 'hp_pinned_time', true ) > time() ) {
			$states['hpsw_pinned'] = esc_html__( 'Pinned', 'social-walls-for-hivepress' );
		}

		$end = (string) get_post_meta( $post->ID, 'hp_expire_date', true );

		// The coupon half of the test is batched for the whole screen on its first row.
		if ( ( '' !== $end && $end < current_time( 'Y-m-d' ) ) || $this->is_coupon_ended( $post->ID ) ) {
			$states['hpsw_ended'] = esc_html__( 'Ended', 'social-walls-for-hivepress' );
		}

		return $states;
	}

	/**
	 * Shows the start of the text for a post without a headline, in the admin list only.
	 *
	 * A headline is optional, so without this most rows would read "(no title)".
	 *
	 * @param string $title Title.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function fill_empty_title( $title, $post_id = 0 ) {
		if ( '' !== trim( (string) $title ) || ! $post_id || 'hp_hpsw_post' !== get_post_type( $post_id ) ) {
			return $title;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-hp_hpsw_post' !== $screen->id ) {
			return $title;
		}

		return wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 10 );
	}
}
