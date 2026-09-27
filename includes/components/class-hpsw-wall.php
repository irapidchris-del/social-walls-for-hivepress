<?php
/**
 * Wall component.
 *
 * @package Social_Walls
 */

namespace HivePress\Components;

use HivePress\Helpers as hp;
use HivePress\Models;
use HivePress\Blocks;
use HivePress\Emails;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Everything about walls that is not a route: who may post and how often, the Vendor page section,
 * the account menu item, Memberships gating, pinning, notifications and the wall query itself.
 *
 * Reached as `hivepress()->hpsw_wall`, a name core derives from this file's name. Prefixed, like
 * every class in the plugin, because core loads one file per class name across all extensions and
 * a clash means one of them silently never loads (resources/security-standards.md).
 */
final class Hpsw_Wall extends Component {

	/**
	 * The post type every wall post is stored as.
	 */
	const POST_TYPE = 'hp_hpsw_post';

	/**
	 * Comment types.
	 */
	const COMMENT_TYPE = 'hp_hpsw_comment';

	const LIKE_TYPE = 'hp_hpsw_like';

	/**
	 * Followers emailed per background job. Each job queues the next with a different offset, so
	 * Action Scheduler's duplicate guard never mistakes the successor for itself
	 * (resources/hivepress-framework.md, "A chained background job must vary its arguments").
	 */
	const FOLLOWER_BATCH = 50;

	/**
	 * The daily WP-Cron event that tidies ended Deals, with its batch size and the most one run
	 * handles before it hands the rest to a follow-up run.
	 */
	const ENDED_HOOK = 'hpsw_tidy_ended_deals';

	const ENDED_BATCH = 100;

	const ENDED_LIMIT = 2000;

	/**
	 * The current user's published Vendor, cached for the request. False once looked up and absent.
	 *
	 * @var \HivePress\Models\Vendor|false|null
	 */
	protected $current_vendor = null;

	/**
	 * When each Deal looked at in this request ended, keyed by post ID (see get_ended_time()).
	 *
	 * @var array<int, int|null>
	 */
	protected $ended_times = [];

	/**
	 * Published Deals the walls leave out because their coupon ended, once worked out.
	 *
	 * @var int[]|null
	 */
	protected $hidden_deal_ids = null;

	/**
	 * Class constructor.
	 *
	 * @param array $args Component arguments.
	 */
	public function __construct( $args = [] ) {

		// Rewrite rules are rebuilt from routes on every init but only applied after a flush, and an
		// update is not an activation, so flush whenever the stored version changes.
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );

		// Account menu.
		add_filter( 'hivepress/v1/menus/user_account', [ $this, 'alter_account_menu' ] );

		// The wall section on Vendor pages.
		add_filter( 'hivepress/v1/templates/vendor_view_page', [ $this, 'add_vendor_wall' ] );

		// The wall section on Listing pages, when the owner switches it on.
		add_filter( 'hivepress/v1/templates/listing_view_page', [ $this, 'add_listing_wall' ] );

		// Memberships: per-plan posting rights and allowance.
		add_filter( 'hivepress/v1/models/membership_plan', [ $this, 'add_plan_fields' ] );
		add_filter( 'hivepress/v1/meta_boxes/membership_plan_page_restrictions', [ $this, 'add_plan_restrictions' ] );
		add_filter( 'hivepress/v1/meta_boxes/membership_page_restrictions', [ $this, 'add_plan_restrictions' ] );

		add_action( 'save_post', [ $this, 'refresh_gating_flag_for_post' ], 999, 2 );
		add_action( 'deleted_post', [ $this, 'refresh_gating_flag_for_post' ], 10, 2 );
		add_action( 'trashed_post', [ $this, 'refresh_gating_flag_for_post' ] );
		add_action( 'untrashed_post', [ $this, 'refresh_gating_flag_for_post' ] );

		// Post lifecycle.
		add_action( 'hivepress/v1/models/hpsw_post/update_status', [ $this, 'update_post_status' ], 10, 4 );
		add_action( 'hivepress/v1/models/hpsw_post/update', [ $this, 'sync_post' ], 20 );
		add_action( 'save_post', [ $this, 'save_admin_pin' ], 20, 2 );

		// Background jobs.
		add_action( 'hivepress/v1/events/hourly', [ $this, 'unpin_expired_posts' ] );
		add_action( 'hpsw_notify_followers', [ $this, 'notify_followers' ], 10, 2 );
		add_action( 'hpsw_purge_cache', [ $this, 'purge_cache' ] );

		// Ended Deals: the daily pass, and a record when a coupon a Deal shows is deleted for good.
		add_action( self::ENDED_HOOK, [ $this, 'tidy_ended_deals' ] );
		add_action( 'before_delete_post', [ $this, 'record_deleted_coupon' ], 10, 2 );

		// Keep cached pages honest about likes and comments. Raw comment hooks rather than model
		// events: cache invalidation must never be missed, and model events are skipped during an
		// import (resources/hivepress-data.md, "Model hooks").
		add_action( 'wp_insert_comment', [ $this, 'queue_purge_for_comment' ], 10, 2 );
		add_action( 'deleted_comment', [ $this, 'queue_purge_for_comment' ], 10, 2 );
		add_action( 'transition_comment_status', [ $this, 'queue_purge_for_comment_status' ], 10, 3 );

		// Paid pinning through WooCommerce, the way Claim Listings sells a claim.
		add_action( 'woocommerce_order_status_changed', [ $this, 'update_order_status' ], 10, 4 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'add_cart_item_data' ], 10, 2 );

		// Front-end assets.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		parent::__construct( $args );
	}

	/**
	 * Flushes the rewrite rules once per version.
	 *
	 * Runs on init at 20, after the router has registered this plugin's routes at 10. Deleting the
	 * option is core's own flush (class-router.php:503-505); WordPress rebuilds lazily.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules() {
		if ( HPSW_VERSION === get_option( 'hp_hpsw_version' ) ) {
			return;
		}

		delete_option( 'rewrite_rules' );

		// The gating flag is derived state; recompute it whenever the code that reads it may have
		// changed.
		$this->refresh_gating_flag();

		// An update copied over the old files never runs the activation hook, which schedules it too.
		hpsw_schedule_ended_deals();

		update_option( 'hp_hpsw_version', HPSW_VERSION );
	}

	/*
	|--------------------------------------------------------------------------
	| Vendors and permissions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Gets the current user's published Vendor.
	 *
	 * Only a published Vendor has a wall: an auto-draft or draft Vendor is a half-finished sign-up
	 * with no public profile to show it on (resources/hivepress-data.md, "User, Vendor, Listing").
	 *
	 * @return \HivePress\Models\Vendor|null
	 */
	public function get_current_vendor() {
		if ( null === $this->current_vendor ) {
			$vendor = null;

			if ( is_user_logged_in() ) {
				$vendor = $this->get_user_vendor( get_current_user_id() );
			}

			$this->current_vendor = $vendor ? $vendor : false;
		}

		return $this->current_vendor ? $this->current_vendor : null;
	}

	/**
	 * Gets a user's published Vendor.
	 *
	 * @param int $user_id User ID.
	 * @return \HivePress\Models\Vendor|null
	 */
	public function get_user_vendor( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$vendor = Models\Vendor::query()->filter(
			[
				'status' => 'publish',
				'user'   => $user_id,
			]
		)->get_first();

		return $vendor instanceof Models\Vendor ? $vendor : null;
	}

	/**
	 * Checks whether Memberships is active.
	 *
	 * @return bool
	 */
	public function is_memberships_active() {
		return post_type_exists( 'hp_membership' ) && post_type_exists( 'hp_membership_plan' );
	}

	/**
	 * Gets a user's active memberships as membership ID => plan ID.
	 *
	 * A membership is an `hp_membership` post whose author is the member and whose parent is the
	 * plan; only `publish` is active (hivepress-memberships/includes/models/class-membership.php,
	 * statuses relabelled Active / Expired / Paused).
	 *
	 * @param int $user_id User ID.
	 * @return array<int, int>
	 */
	public function get_user_memberships( $user_id ) {
		static $cache = [];

		$user_id = absint( $user_id );

		if ( ! $user_id || ! $this->is_memberships_active() ) {
			return [];
		}

		if ( ! isset( $cache[ $user_id ] ) ) {
			$cache[ $user_id ] = [];

			$membership_ids = get_posts(
				[
					'post_type'   => 'hp_membership',
					'post_status' => 'publish',
					'author'      => $user_id,
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			);

			foreach ( $membership_ids as $membership_id ) {
				$cache[ $user_id ][ absint( $membership_id ) ] = absint( wp_get_post_parent_id( $membership_id ) );
			}
		}

		return $cache[ $user_id ];
	}

	/**
	 * Reads one of this plugin's plan values for a membership.
	 *
	 * Buying a plan COPIES it onto the membership (Membership::add_membership() fills the membership
	 * from `$plan->serialize()`, hivepress-memberships/includes/components/class-membership.php:631-685),
	 * and because `hivepress/v1/models/membership_plan` also fires for the Membership model (it
	 * extends the plan model, and model filters run once per ancestor class), our fields are copied
	 * too. So what a member bought is frozen on their membership, exactly like core's own limits,
	 * and editing the plan later does not change it. A membership bought before this plugin was
	 * installed has no copy, and falls back to the plan as it is now.
	 *
	 * @param int    $membership_id Membership ID.
	 * @param int    $plan_id Plan ID.
	 * @param string $key Meta key.
	 * @return string
	 */
	protected function get_membership_value( $membership_id, $plan_id, $key ) {
		if ( metadata_exists( 'post', $membership_id, $key ) ) {
			return (string) get_post_meta( $membership_id, $key, true );
		}

		return $plan_id ? (string) get_post_meta( $plan_id, $key, true ) : '';
	}

	/**
	 * Checks whether posting is limited to certain membership plans on this site.
	 *
	 * Persisted as an option, recomputed whenever a plan changes, so the check is one autoloaded
	 * read and still fails CLOSED if Memberships is later deactivated: paid posting is never given
	 * away by accident. The settings tab says so when that happens (Hpsw_Wall_Admin).
	 *
	 * @return bool
	 */
	public function is_posting_gated() {
		return (bool) get_option( 'hp_hpsw_access_gated' );
	}

	/**
	 * Checks whether a Vendor may post to their wall at all.
	 *
	 * Gating is opt-in: until at least one published plan ticks "Allow posting to the wall", every
	 * Vendor can post. Once one does, the Vendor needs an active membership whose plan allows it.
	 * `edit_others_posts` is the ecosystem-wide administrator bypass
	 * (hivepress-memberships/includes/components/class-membership.php:2190), not a role string.
	 *
	 * @param \HivePress\Models\Vendor|null $vendor Vendor object.
	 * @return bool
	 */
	public function can_post( $vendor ) {
		if ( ! $vendor instanceof Models\Vendor ) {
			return false;
		}

		$user_id = absint( $vendor->get_user__id() );
		$can     = true;

		if ( $this->is_posting_gated() && ! user_can( $user_id, 'edit_others_posts' ) ) {
			$can = false;

			foreach ( $this->get_user_memberships( $user_id ) as $membership_id => $plan_id ) {
				if ( $this->get_membership_value( $membership_id, $plan_id, 'hp_hpsw_post_access' ) ) {
					$can = true;

					break;
				}
			}
		}

		/**
		 * Filters whether a Vendor may post to their wall.
		 *
		 * @hook hpsw/can_post
		 * @param {bool} $can Whether the Vendor may post.
		 * @param {object} $vendor Vendor object.
		 * @return {bool} Whether the Vendor may post.
		 */
		return (bool) apply_filters( 'hpsw/can_post', $can, $vendor );
	}

	/**
	 * Gets a Vendor's monthly allowance.
	 *
	 * Null means unlimited. A plan's own allowance beats the site-wide one, and a Vendor on more than
	 * one plan gets the most generous: any plan with "Unlimited posts" ticked means no limit, otherwise
	 * the highest number set. Plans that set nothing leave the site-wide number in charge.
	 *
	 * @param \HivePress\Models\Vendor|null $vendor Vendor object.
	 * @return int|null
	 */
	public function get_monthly_limit( $vendor ) {
		$limit = hpsw_get_number_option( 'hpsw_monthly_limit', 0 );
		$limit = $limit >= 1 ? $limit : null;

		if ( $vendor instanceof Models\Vendor ) {
			$user_id = absint( $vendor->get_user__id() );

			if ( user_can( $user_id, 'edit_others_posts' ) ) {
				return null;
			}

			$plan_limit = null;

			foreach ( $this->get_user_memberships( $user_id ) as $membership_id => $plan_id ) {
				if ( $this->get_membership_value( $membership_id, $plan_id, 'hp_hpsw_post_unlimited' ) ) {
					$limit = null;

					$plan_limit = 0;

					break;
				}

				$value = $this->get_membership_value( $membership_id, $plan_id, 'hp_hpsw_post_limit' );

				// Anything below 1 is "no opinion" and falls through to the site-wide number. A stored
				// 0 must never read as a limit of zero: turning posting off is what the plan's own
				// "Allow posting" box is for.
				if ( is_numeric( $value ) && (int) $value >= 1 && ( is_null( $plan_limit ) || (int) $value > $plan_limit ) ) {
					$plan_limit = (int) $value;
				}
			}

			if ( $plan_limit ) {
				$limit = $plan_limit;
			}
		}

		/**
		 * Filters a Vendor's monthly post allowance. Null means unlimited.
		 *
		 * @hook hpsw/monthly_limit
		 * @param {int|null} $limit Posts per month.
		 * @param {object} $vendor Vendor object.
		 * @return {int|null} Posts per month.
		 */
		$limit = apply_filters( 'hpsw/monthly_limit', $limit, $vendor );

		return is_numeric( $limit ) && (int) $limit >= 1 ? (int) $limit : null;
	}

	/**
	 * Counts the posts a Vendor has added this calendar month.
	 *
	 * Every status a submitted post can reach counts, trash included, so deleting a post does not
	 * hand its place back: otherwise an allowance of five is five at a time, not five a month. Only
	 * the unsubmitted auto-draft that holds a new post's photos is left out. The month is the
	 * site's own calendar month, which is what the settings screen promises.
	 *
	 * @param \HivePress\Models\Vendor $vendor Vendor object.
	 * @return int
	 */
	public function get_month_count( $vendor ) {
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => [ 'publish', 'pending', 'draft', 'trash', 'future', 'private' ],
				'post_parent'    => $vendor->get_id(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,

				'date_query'     => [
					[
						'after'     => current_time( 'Y-m-01 00:00:00' ),
						'inclusive' => true,
					],
				],
			]
		);

		return count( $ids );
	}

	/**
	 * Gets how many more posts a Vendor may add this month. Null means unlimited.
	 *
	 * @param \HivePress\Models\Vendor $vendor Vendor object.
	 * @return int|null
	 */
	public function get_remaining( $vendor ) {
		$limit = $this->get_monthly_limit( $vendor );

		if ( is_null( $limit ) ) {
			return null;
		}

		return max( 0, $limit - $this->get_month_count( $vendor ) );
	}

	/**
	 * Builds the message shown when a Vendor has used their allowance.
	 *
	 * @param \HivePress\Models\Vendor $vendor Vendor object.
	 * @return string
	 */
	public function get_limit_message( $vendor ) {
		$limit = (int) $this->get_monthly_limit( $vendor );
		$next  = date_create_immutable( 'first day of next month', wp_timezone() );

		return sprintf(
			/* translators: 1: number of posts, 2: date. */
			esc_html( _n( 'You have used your %1$s wall post for this month. You can post again from %2$s.', 'You have used all %1$s of your wall posts for this month. You can post again from %2$s.', $limit, 'social-walls-for-hivepress' ) ),
			number_format_i18n( $limit ),
			$next ? wp_date( get_option( 'date_format' ), $next->setTime( 0, 0 )->getTimestamp() ) : ''
		);
	}

	/**
	 * Gets the address of the page that sells membership plans, if Memberships provides one.
	 *
	 * @return string
	 */
	public function get_upgrade_url() {
		$router = hivepress()->router;

		if ( $this->is_memberships_active() && $router && $router->get_route( 'membership_plans_view_page' ) ) {
			return (string) $router->get_url( 'membership_plans_view_page' );
		}

		return '';
	}

	/**
	 * Checks whether new posts wait for approval.
	 *
	 * @return bool
	 */
	public function is_moderation_enabled() {
		return (bool) hpsw_get_option( 'hpsw_enable_moderation', '' );
	}

	/**
	 * Checks whether likes are switched on.
	 *
	 * @return bool
	 */
	public function are_likes_enabled() {
		return (bool) hpsw_get_option( 'hpsw_enable_likes', '1' );
	}

	/**
	 * Gets who may comment: users, vendors or none.
	 *
	 * @return string
	 */
	public function get_comment_access() {
		return hpsw_get_choice_option( 'hpsw_comment_access', [ 'users', 'vendors', 'none' ], 'users' );
	}

	/**
	 * Checks whether comments are shown at all.
	 *
	 * @return bool
	 */
	public function are_comments_enabled() {
		return 'none' !== $this->get_comment_access();
	}

	/**
	 * Checks whether a user may comment.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function can_comment( $user_id ) {
		$user_id = absint( $user_id );
		$access  = $this->get_comment_access();

		if ( ! $user_id || 'none' === $access ) {
			return false;
		}

		if ( 'vendors' === $access ) {
			return user_can( $user_id, 'edit_others_posts' ) || (bool) $this->get_user_vendor( $user_id );
		}

		return true;
	}

	/**
	 * Checks whether a wall post can be shown to the public.
	 *
	 * @param \HivePress\Models\Hpsw_Post|null $post Wall post.
	 * @return bool
	 */
	public function is_post_visible( $post ) {
		if ( ! $post instanceof Models\Hpsw_Post || 'publish' !== $post->get_status() ) {
			return false;
		}

		$vendor = $post->get_vendor();

		return $vendor instanceof Models\Vendor && 'publish' === $vendor->get_status() && ! $this->is_ended_hidden( $post );
	}

	/**
	 * Checks whether the current user may manage a wall post.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @return bool
	 */
	public function can_manage_post( $post ) {
		return current_user_can( 'edit_others_posts' ) || ( is_user_logged_in() && get_current_user_id() === absint( $post->get_user__id() ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Memberships
	|--------------------------------------------------------------------------
	*/

	/**
	 * Adds this plugin's fields to the membership plan model.
	 *
	 * Stored as plan meta `hp_hpsw_post_access`, `hp_hpsw_post_unlimited` and `hp_hpsw_post_limit`.
	 * The same filter fires for the Membership model, so a purchase copies them onto the membership.
	 *
	 * @param array $model Model arguments.
	 * @return array
	 */
	public function add_plan_fields( $model ) {
		if ( ! isset( $model['fields'] ) || ! is_array( $model['fields'] ) ) {
			return $model;
		}

		$model['fields']['hpsw_post_access'] = [
			'type'      => 'checkbox',
			'_external' => true,
		];

		$model['fields']['hpsw_post_unlimited'] = [
			'type'      => 'checkbox',
			'_external' => true,
		];

		$model['fields']['hpsw_post_limit'] = [
			'type'      => 'number',
			'min_value' => 1,
			'_external' => true,
		];

		return $model;
	}

	/**
	 * Adds the posting options to the general Restrictions box of plans and memberships.
	 *
	 * The general box, not a per-model one: those are scoped to Listing categories, and a wall
	 * belongs to a Vendor as a whole, so a category-scoped allowance would promise something this
	 * plugin does not do. On a membership's own screen the same fields let an administrator adjust
	 * one member without touching the plan.
	 *
	 * @param array $meta_box Meta box arguments.
	 * @return array
	 */
	public function add_plan_restrictions( $meta_box ) {
		if ( ! is_array( $meta_box ) ) {
			return $meta_box;
		}

		$meta_box['fields']['hpsw_post_access'] = [
			'label'       => esc_html__( 'Wall Posts', 'social-walls-for-hivepress' ),
			'caption'     => esc_html__( 'Allow posting to the wall', 'social-walls-for-hivepress' ),
			'description' => esc_html__( 'Tick this on the plans that include wall posts. Once any plan has it ticked, only Vendors on one of those plans can post. Leave it unticked on every plan to let every Vendor post.', 'social-walls-for-hivepress' ),
			'type'        => 'checkbox',
			'_order'      => 300,
		];

		$meta_box['fields']['hpsw_post_unlimited'] = [
			'label'       => esc_html__( 'Wall Posts per Month', 'social-walls-for-hivepress' ),
			'caption'     => esc_html__( 'Unlimited wall posts', 'social-walls-for-hivepress' ),
			'description' => esc_html__( 'Lets Vendors on this plan post as often as they like, whatever the monthly allowance under Settings, Social Walls.', 'social-walls-for-hivepress' ),
			'type'        => 'checkbox',
			'_order'      => 310,
		];

		$meta_box['fields']['hpsw_post_limit'] = [
			'label'       => esc_html__( 'Wall Post Allowance (per month)', 'social-walls-for-hivepress' ),
			'description' => esc_html__( 'How many wall posts a Vendor on this plan may add each calendar month. Leave empty to use the allowance under Settings, Social Walls. A Vendor on more than one plan gets the most generous.', 'social-walls-for-hivepress' ),
			'type'        => 'number',
			'min_value'   => 1,
			'_order'      => 320,
		];

		return $meta_box;
	}

	/**
	 * Recomputes whether any published plan limits posting.
	 *
	 * @return void
	 */
	public function refresh_gating_flag() {
		$gated = false;

		if ( post_type_exists( 'hp_membership_plan' ) ) {
			$gated = (bool) get_posts(
				[
					'post_type'   => 'hp_membership_plan',
					'post_status' => 'publish',
					'numberposts' => 1,
					'fields'      => 'ids',

					'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-defined plan set, run only when a plan changes.
						[
							'key'   => 'hp_hpsw_post_access',
							'value' => '1',
						],
					],
				]
			);
		} elseif ( false !== get_option( 'hp_hpsw_access_gated' ) ) {

			// Memberships is not loaded, so the plans cannot be read: keep whatever was last known,
			// which is what makes the gate fail closed.
			return;
		}

		update_option( 'hp_hpsw_access_gated', $gated ? '1' : '' );
	}

	/**
	 * Refreshes the gating flag when a membership plan is saved, deleted, trashed or restored.
	 *
	 * Runs on the generic save_post at 999 so the plan's meta is already written, and reads the post
	 * type from the object passed in, so it still works from deleted_post after the row is gone.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post Post object, when the hook provides one.
	 * @return void
	 */
	public function refresh_gating_flag_for_post( $post_id, $post = null ) {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$type = $post instanceof \WP_Post ? $post->post_type : get_post_type( $post_id );

		if ( 'hp_membership_plan' === $type ) {
			$this->refresh_gating_flag();
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Account menu and Vendor pages
	|--------------------------------------------------------------------------
	*/

	/**
	 * Adds the Wall item to the account menu, for Vendors.
	 *
	 * Shown to every Vendor, including one whose plan does not allow posting: the page then says
	 * why, with a link to the plans, which is more useful than an item that is simply missing.
	 *
	 * @param array $menu Menu arguments.
	 * @return array
	 */
	public function alter_account_menu( $menu ) {
		if ( is_array( $menu ) && $this->get_current_vendor() ) {
			$menu['items']['hpsw_wall'] = [
				'route'  => 'hpsw_wall_edit_page',
				'_order' => 35,
			];
		}

		return $menu;
	}

	/**
	 * Adds the wall section to Vendor pages.
	 *
	 * Core's Vendor page puts its Listings at `page_content` order 20
	 * (templates/class-vendor-view-page.php, core 1.7.31). 15 sits just above them and 25 just
	 * below; neither is used by any sibling on that parent (Additional Gallery 30, Teams and Venues
	 * 5, 8 and 35, all read from their own alter_vendor_view_page()), so no tie is left to plugin
	 * load order. The block key is prefixed so no other plugin can overwrite it.
	 *
	 * @param array $template Template arguments.
	 * @return array
	 */
	public function add_vendor_wall( $template ) {
		$position = hpsw_get_choice_option( 'hpsw_vendor_position', [ 'below', 'above', 'hidden' ], 'below' );

		if ( 'hidden' === $position ) {
			return $template;
		}

		return hp\merge_trees(
			$template,
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'hpsw_vendor_wall_section' => [
								'type'       => 'section',
								'title'      => esc_html__( 'Deals and Updates', 'social-walls-for-hivepress' ),
								'optional'   => true,
								'_order'     => 'above' === $position ? 15 : 25,

								'attributes' => [
									'class' => [ 'hpsw-section' ],
									'id'    => 'wall',
								],

								'blocks'     => [
									'hpsw_vendor_wall' => [
										'type'   => 'hpsw_wall',
										'mode'   => 'vendor',
										'_label' => esc_html__( 'Wall', 'social-walls-for-hivepress' ),
										'_order' => 10,
									],
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Adds the wall section to Listing pages, when the owner switches it on.
	 *
	 * Core's Listing page ends `page_content` with the description at order 70
	 * (templates/class-listing-view-page.php, core 1.7.31). Siblings on that parent: Tags 70,
	 * Additional Gallery 85, Reviews 100 (each read from its own alter_listing_view_page()). 65 sits
	 * just above the description and 90 below it, before the reviews, so neither ties with anything.
	 * The section is `optional`, so a Listing with no posts shows nothing at all.
	 *
	 * @param array $template Template arguments.
	 * @return array
	 */
	public function add_listing_wall( $template ) {
		$position = $this->get_listing_position();

		if ( 'hidden' === $position ) {
			return $template;
		}

		return hp\merge_trees(
			$template,
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'hpsw_listing_wall_section' => [
								'type'       => 'section',
								'title'      => esc_html__( 'Deals and Updates', 'social-walls-for-hivepress' ),
								'optional'   => true,
								'_order'     => 'above' === $position ? 65 : 90,

								'attributes' => [
									'class' => [ 'hpsw-section' ],
									'id'    => 'wall',
								],

								'blocks'     => [
									'hpsw_listing_wall' => [
										'type'   => 'hpsw_wall',
										'mode'   => 'listing',
										'_label' => esc_html__( 'Wall', 'social-walls-for-hivepress' ),
										'_order' => 10,
									],
								],
							],
						],
					],
				],
			]
		);
	}

	/**
	 * Gets where the wall sits on Listing pages: below, above or hidden. Hidden until the owner
	 * chooses otherwise.
	 *
	 * @return string
	 */
	public function get_listing_position() {
		return hpsw_get_choice_option( 'hpsw_listing_position', [ 'below', 'above', 'hidden' ], 'hidden' );
	}

	/**
	 * Checks whether post photos open full size when clicked. On until the owner unticks it.
	 *
	 * @return bool
	 */
	public function is_photo_zoom_enabled() {
		return (bool) hpsw_get_option( 'hpsw_photo_zoom', '1' );
	}

	/*
	|--------------------------------------------------------------------------
	| Post lifecycle
	|--------------------------------------------------------------------------
	*/

	/**
	 * Reacts to a wall post changing status.
	 *
	 * Moderation emails fire only on the two transitions an administrator makes, pending to publish
	 * and pending to trash, which is the shape core uses for Listings
	 * (components/class-listing.php:224-272): bulk edits between other states stay silent.
	 * Followers hear about a post once, the first time it is published, whoever published it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $new_status New status.
	 * @param string $old_status Old status.
	 * @param object $post Wall post.
	 * @return void
	 */
	public function update_post_status( $post_id, $new_status, $old_status, $post ) {
		if ( ! $post instanceof Models\Hpsw_Post ) {
			return;
		}

		if ( 'pending' === $new_status && in_array( $old_status, [ 'auto-draft', 'draft', 'publish' ], true ) ) {
			$this->send_submit_email( $post );
		} elseif ( 'pending' === $old_status && in_array( $new_status, [ 'publish', 'trash' ], true ) ) {
			$this->send_moderation_email( $post, 'publish' === $new_status );
		}

		if ( 'publish' === $new_status && ! get_post_meta( $post_id, 'hp_hpsw_notified', true ) ) {
			update_post_meta( $post_id, 'hp_hpsw_notified', time() );

			$this->queue_follower_emails( $post_id );
		}

		$this->queue_purge( $post_id );
	}

	/**
	 * Keeps a post's author, search text and pin in step after every save.
	 *
	 * The author follows the Vendor, the rule core applies to a Listing
	 * (components/class-listing.php:122-128). The excerpt holds the Vendor's name, the linked
	 * Listing's title and the coupon, so the wall's keyword search finds a post by any of them the
	 * way HivePress's own snippet does for Listings. Each write only happens when the value actually
	 * differs, which is also what stops the update this triggers from looping.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function sync_post( $post_id ) {
		$post_id = absint( $post_id );
		$parent  = wp_get_post_parent_id( $post_id );
		$data    = [];

		if ( $parent && 'hp_vendor' === get_post_type( $parent ) ) {
			$user_id = absint( get_post_field( 'post_author', $parent ) );

			if ( $user_id && absint( get_post_field( 'post_author', $post_id ) ) !== $user_id ) {
				$data['post_author'] = $user_id;
			}
		}

		$snippet = [];

		if ( $parent ) {
			$snippet[] = get_the_title( $parent );
		}

		$listing_id = absint( get_post_meta( $post_id, 'hp_listing', true ) );

		if ( $listing_id && 'hp_listing' === get_post_type( $listing_id ) ) {
			$snippet[] = get_the_title( $listing_id );
		}

		$coupon = (string) get_post_meta( $post_id, 'hp_coupon', true );

		if ( '' !== $coupon ) {
			$snippet[] = $coupon;
		}

		$snippet = implode( '; ', array_filter( array_map( 'wp_strip_all_tags', $snippet ) ) );

		if ( get_post_field( 'post_excerpt', $post_id ) !== $snippet ) {
			$data['post_excerpt'] = $snippet;
		}

		if ( $data ) {
			wp_update_post( array_merge( $data, [ 'ID' => $post_id ] ) );
		}
	}

	/**
	 * Applies the Pinned Until date from the admin edit screen.
	 *
	 * Runs on save_post at 20, after core's meta box save at 10 has written the date
	 * (hivepress/includes/components/class-admin.php:80, :1245), and only for that form, using the
	 * same gate core does. Writing the pin from a per-field hook instead would be undone a moment
	 * later by core saving the rest of the box (resources/hivepress-settings.md, "A meta box
	 * per-field hook cannot write a field that the same form is about to save").
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post Post object.
	 * @return void
	 */
	public function save_admin_pin( $post_id, $post = null ) {
		static $running = false;

		if ( $running || ! $post instanceof \WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Read-only checks of the request core is already processing; core verified the nonce and
		// the capability before this hook could run (wp-admin/post.php, edit_post()).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( 'editpost' !== hp\get_array_value( $_POST, 'action' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$running = true;

		$date = (string) get_post_meta( $post_id, 'hp_pinned_date', true );
		$end  = $date ? $this->get_day_end( $date ) : 0;

		$wall_post = Models\Hpsw_Post::query()->get_by_id( $post_id );

		if ( $wall_post instanceof Models\Hpsw_Post ) {
			if ( $end > time() ) {
				$wall_post->fill(
					[
						'pinned'      => 1,
						'pinned_time' => $end,
					]
				)->save( [ 'pinned', 'pinned_time' ] );
			} else {
				$this->unpin_post( $wall_post );
			}
		}

		$running = false;
	}

	/**
	 * Gets the last second of a day in the site's timezone.
	 *
	 * @param string $date Date as Y-m-d.
	 * @return int Timestamp, or 0 for an unreadable date.
	 */
	protected function get_day_end( $date ) {
		$day = date_create_immutable_from_format( 'Y-m-d H:i:s', $date . ' 23:59:59', wp_timezone() );

		return $day ? $day->getTimestamp() : 0;
	}

	/*
	|--------------------------------------------------------------------------
	| Pinning
	|--------------------------------------------------------------------------
	*/

	/**
	 * Gets the WooCommerce product that pins a post, or 0 when paid pinning is off.
	 *
	 * @return int
	 */
	public function get_pin_product_id() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 0;
		}

		$product_id = absint( hpsw_get_option( 'hpsw_pin_product', 0 ) );

		return $product_id && 'product' === get_post_type( $product_id ) && 'publish' === get_post_status( $product_id ) ? $product_id : 0;
	}

	/**
	 * Gets how many days one purchase pins a post for.
	 *
	 * @return int
	 */
	public function get_pin_days() {
		return max( 1, min( 365, hpsw_get_number_option( 'hpsw_pin_days', 7 ) ) );
	}

	/**
	 * Pins a post, or extends its pin.
	 *
	 * Time bought while a post is already pinned is added to the end, so a Vendor who renews early
	 * loses nothing.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @param int                         $days Days to add.
	 * @return int The new end time.
	 */
	public function pin_post( $post, $days ) {
		$start = $post->is_pinned() ? (int) $post->get_pinned_time() : time();
		$end   = $start + absint( $days ) * DAY_IN_SECONDS;

		$post->fill(
			[
				'pinned'      => 1,
				'pinned_time' => $end,
			]
		)->save( [ 'pinned', 'pinned_time' ] );

		update_post_meta( $post->get_id(), 'hp_pinned_date', wp_date( 'Y-m-d', $end ) );

		$this->queue_purge( $post->get_id() );

		return $end;
	}

	/**
	 * Removes a post's pin.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @return void
	 */
	public function unpin_post( $post ) {
		$post->fill(
			[
				'pinned'      => 0,
				'pinned_time' => null,
			]
		)->save( [ 'pinned', 'pinned_time' ] );

		delete_post_meta( $post->get_id(), 'hp_pinned_date' );

		$this->queue_purge( $post->get_id() );
	}

	/**
	 * Takes the pin off posts whose time has run out.
	 *
	 * Hooked to HivePress's own hourly event, so no schedule of our own is needed. At most 50 per
	 * run, which keeps a busy site's hourly request short; a backlog clears over the next runs, and
	 * the badge never shows in the meantime because is_pinned() reads the end time too.
	 *
	 * @return void
	 */
	public function unpin_expired_posts() {
		$post_ids = get_posts(
			[
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'menu_order'     => 1,
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,

				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hourly background pass, bounded to 50 rows.
					'relation' => 'OR',

					[
						'key'     => 'hp_pinned_time',
						'value'   => time(),
						'compare' => '<',
						'type'    => 'NUMERIC',
					],

					[
						'key'     => 'hp_pinned_time',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		foreach ( $post_ids as $post_id ) {
			$post = Models\Hpsw_Post::query()->get_by_id( $post_id );

			if ( $post instanceof Models\Hpsw_Post ) {
				$this->unpin_post( $post );
			}
		}
	}

	/**
	 * Applies or withdraws paid pins when an order changes status.
	 *
	 * The shape of Claim Listings' own handler (hivepress-claim-listings/includes/components/
	 * class-listing-claim.php:287-330): find our product among the order's lines, read the post from
	 * the line's `hp_hpsw_post` meta, act on processing/completed and undo on failed, cancelled or
	 * refunded. Unlike a claim, a pin ADDS time, so applying it twice would double it: each line
	 * records what it applied, which makes the handler safe to run on both processing and
	 * completed, and lets a refund take back exactly what was given.
	 *
	 * @param int       $order_id Order ID.
	 * @param string    $old_status Old status.
	 * @param string    $new_status New status.
	 * @param \WC_Order $order Order object.
	 * @return void
	 */
	public function update_order_status( $order_id, $old_status, $new_status, $order ) {
		$product_id = absint( hpsw_get_option( 'hpsw_pin_product', 0 ) );

		if ( ! $product_id || ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$grant  = in_array( $new_status, [ 'processing', 'completed' ], true );
		$revoke = in_array( $new_status, [ 'failed', 'cancelled', 'refunded' ], true );

		if ( ! $grant && ! $revoke ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || absint( $item->get_product_id() ) !== $product_id ) {
				continue;
			}

			$post = Models\Hpsw_Post::query()->get_by_id( absint( $item->get_meta( 'hp_hpsw_post' ) ) );

			if ( ! $post instanceof Models\Hpsw_Post ) {
				continue;
			}

			$applied = absint( $item->get_meta( '_hpsw_pin_days' ) );

			if ( $grant && ! $applied ) {
				$days = $this->get_pin_days() * max( 1, absint( $item->get_quantity() ) );
				$end  = $this->pin_post( $post, $days );

				$item->update_meta_data( '_hpsw_pin_days', $days );
				$item->save();

				$order->add_order_note(
					sprintf(
						/* translators: 1: wall post ID, 2: date. */
						esc_html__( 'Wall post #%1$d pinned until %2$s.', 'social-walls-for-hivepress' ),
						$post->get_id(),
						wp_date( get_option( 'date_format' ), $end )
					)
				);
			} elseif ( $revoke && $applied ) {
				$end = (int) $post->get_pinned_time() - $applied * DAY_IN_SECONDS;

				if ( $end > time() ) {
					$post->fill( [ 'pinned_time' => $end ] )->save( [ 'pinned_time' ] );

					update_post_meta( $post->get_id(), 'hp_pinned_date', wp_date( 'Y-m-d', $end ) );
				} else {
					$this->unpin_post( $post );
				}

				$item->delete_meta_data( '_hpsw_pin_days' );
				$item->save();

				$order->add_order_note(
					sprintf(
						/* translators: %d: wall post ID. */
						esc_html__( 'Pin on wall post #%d withdrawn.', 'social-walls-for-hivepress' ),
						$post->get_id()
					)
				);
			}
		}
	}

	/**
	 * Names the post being pinned in the cart and at checkout.
	 *
	 * Core's own formatter shows nothing for our key, because it carries no label
	 * (includes/configs/woocommerce.php), so this adds a readable line instead of a bare ID.
	 *
	 * @param array $data Item data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public function add_cart_item_data( $data, $cart_item ) {
		$post_id = is_array( $cart_item ) ? absint( hp\get_array_value( $cart_item, 'hp_hpsw_post' ) ) : 0;

		if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return $data;
		}

		$data   = is_array( $data ) ? $data : [];
		$data[] = [
			'key'   => esc_html__( 'Wall post', 'social-walls-for-hivepress' ),
			'value' => esc_html( $this->get_post_label( $post_id ) ),
		];

		return $data;
	}

	/*
	|--------------------------------------------------------------------------
	| Notifications
	|--------------------------------------------------------------------------
	*/

	/**
	 * Checks whether Follow Vendors for HivePress is active.
	 *
	 * Tested by its component class, never by a slug: `hp\is_plugin_active()` takes a class name
	 * (resources/hivepress-framework.md). Only called after init, where class_exists() is safe.
	 *
	 * @return bool
	 */
	public function is_follow_active() {
		return class_exists( '\HivePress\Components\Hpfv_Follow' );
	}

	/**
	 * Queues the follower emails for a post.
	 *
	 * Never sent in the request that published the post: a Vendor with a thousand followers would
	 * otherwise hold a PHP worker for a thousand emails.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	protected function queue_follower_emails( $post_id ) {
		if ( ! hpsw_get_option( 'hpsw_notify_followers', '1' ) || ! $this->is_follow_active() ) {
			return;
		}

		$scheduler = hivepress()->scheduler;

		if ( $scheduler ) {
			$scheduler->add_action( 'hpsw_notify_followers', [ absint( $post_id ), 0 ] );
		}
	}

	/**
	 * Emails one batch of a Vendor's followers about a post, then queues the next batch.
	 *
	 * Everything is re-read here, because the job runs after the request that queued it and the
	 * post, the Vendor or the setting may have changed in between. Follow Vendors keeps each follow
	 * as an `hp_hpfv_follow` comment whose `comment_post_ID` is the Vendor and whose `user_id` is
	 * the follower (follow-vendors-for-hivepress/includes/models/class-hpfv-follow.php); the type is
	 * named explicitly because core hides non-public comment types from any query that names none.
	 *
	 * @param int $post_id Post ID.
	 * @param int $offset Followers already handled.
	 * @return void
	 */
	public function notify_followers( $post_id, $offset = 0 ) {
		if ( ! hpsw_get_option( 'hpsw_notify_followers', '1' ) || ! $this->is_follow_active() ) {
			return;
		}

		$post = Models\Hpsw_Post::query()->get_by_id( absint( $post_id ) );

		if ( ! $this->is_post_visible( $post ) ) {
			return;
		}

		$vendor = $post->get_vendor();

		$comments = get_comments(
			[
				'type'    => 'hp_hpfv_follow',
				'post_id' => $vendor->get_id(),
				'number'  => self::FOLLOWER_BATCH,
				'offset'  => absint( $offset ),
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			]
		);

		$sent = [];

		foreach ( (array) $comments as $comment ) {
			$user_id = $comment instanceof \WP_Comment ? absint( $comment->user_id ) : 0;

			// Never tell the Vendor about their own post, and never email one person twice.
			if ( ! $user_id || isset( $sent[ $user_id ] ) || absint( $vendor->get_user__id() ) === $user_id ) {
				continue;
			}

			$user = Models\User::query()->get_by_id( $user_id );

			if ( ! $user instanceof Models\User ) {
				continue;
			}

			$sent[ $user_id ] = true;

			( new Emails\Hpsw_Post_Follow(
				[
					'recipient' => $user->get_email(),

					'tokens'    => [
						'user'        => $user,
						'vendor'      => $vendor,
						'user_name'   => $user->get_display_name(),
						'vendor_name' => $vendor->get_name(),
						'post_type'   => $post->is_deal() ? esc_html__( 'Deal', 'social-walls-for-hivepress' ) : esc_html__( 'Update', 'social-walls-for-hivepress' ),
						'post_text'   => $this->get_post_label( $post->get_id() ),
						'post_url'    => $this->get_post_url( $post ),
					],
				]
			) )->send();
		}

		if ( count( (array) $comments ) >= self::FOLLOWER_BATCH ) {
			$scheduler = hivepress()->scheduler;

			if ( $scheduler ) {
				$scheduler->add_action( 'hpsw_notify_followers', [ $post->get_id(), absint( $offset ) + self::FOLLOWER_BATCH ] );
			}
		}
	}

	/**
	 * Tells the administrator a post is waiting for approval.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @return void
	 */
	protected function send_submit_email( $post ) {
		$vendor = $post->get_vendor();

		( new Emails\Hpsw_Post_Submit(
			[
				'recipient' => get_option( 'admin_email' ),

				'tokens'    => [
					'vendor'      => $vendor,
					'vendor_name' => $vendor instanceof Models\Vendor ? $vendor->get_name() : '',
					'post_text'   => $this->get_post_label( $post->get_id() ),
					'post_url'    => (string) get_edit_post_link( $post->get_id(), 'raw' ),
				],
			]
		) )->send();
	}

	/**
	 * Tells a Vendor their post was approved or rejected.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @param bool                        $approved Whether it was approved.
	 * @return void
	 */
	protected function send_moderation_email( $post, $approved ) {
		$user = Models\User::query()->get_by_id( absint( $post->get_user__id() ) );

		if ( ! $user instanceof Models\User ) {
			return;
		}

		$args = [
			'recipient' => $user->get_email(),

			'tokens'    => [
				'user'      => $user,
				'user_name' => $user->get_display_name(),
				'post_text' => $this->get_post_label( $post->get_id() ),
				'post_url'  => $approved ? $this->get_post_url( $post ) : (string) hivepress()->router->get_url( 'hpsw_wall_edit_page' ),
			],
		];

		if ( $approved ) {
			( new Emails\Hpsw_Post_Approve( $args ) )->send();
		} else {
			( new Emails\Hpsw_Post_Reject( $args ) )->send();
		}
	}

	/**
	 * Tells a Vendor about a new comment on their post, and a commenter about a reply to theirs.
	 *
	 * Nobody is emailed about their own words: a Vendor replying on their own post tells the
	 * commenter only, and a commenter replying to themselves tells nobody but the Vendor.
	 *
	 * @param \HivePress\Models\Hpsw_Comment $comment Comment.
	 * @param \HivePress\Models\Hpsw_Post    $post Wall post.
	 * @return void
	 */
	public function send_comment_emails( $comment, $post ) {
		$author_id = absint( $comment->get_author__id() );
		$tokens    = [
			'author_name'  => (string) $comment->get_author__display_name(),
			'comment_text' => wp_trim_words( (string) $comment->get_text(), 40 ),
			'post_text'    => $this->get_post_label( $post->get_id() ),
			'post_url'     => $this->get_post_url( $post ) . '#hpsw-comment-' . $comment->get_id(),
		];

		$notified = [ $author_id => true ];
		$owner    = Models\User::query()->get_by_id( absint( $post->get_user__id() ) );

		if ( $owner instanceof Models\User && ! isset( $notified[ $owner->get_id() ] ) ) {
			$notified[ $owner->get_id() ] = true;

			( new Emails\Hpsw_Comment_Add(
				[
					'recipient' => $owner->get_email(),
					'tokens'    => array_merge(
						$tokens,
						[
							'user'      => $owner,
							'user_name' => $owner->get_display_name(),
						]
					),
				]
			) )->send();
		}

		$parent_id = absint( $comment->get_parent__id() );

		if ( $parent_id ) {
			$parent = get_comment( $parent_id );
			$user   = $parent instanceof \WP_Comment ? Models\User::query()->get_by_id( absint( $parent->user_id ) ) : null;

			if ( $user instanceof Models\User && ! isset( $notified[ $user->get_id() ] ) ) {
				( new Emails\Hpsw_Comment_Reply(
					[
						'recipient' => $user->get_email(),
						'tokens'    => array_merge(
							$tokens,
							[
								'user'      => $user,
								'user_name' => $user->get_display_name(),
							]
						),
					]
				) )->send();
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Cache purging
	|--------------------------------------------------------------------------
	*/

	/**
	 * Queues a purge of a post's page and its Vendor's page.
	 *
	 * Queued, never done inline: some hosts' purge calls out over the network (SiteGround's does),
	 * and a like is exactly the kind of request a busy page makes many times at once. The scheduler
	 * drops a job whose hook and arguments are already queued, so a burst collapses into one purge.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function queue_purge( $post_id ) {
		$scheduler = hivepress()->scheduler;

		if ( $scheduler && absint( $post_id ) ) {
			$scheduler->add_action( 'hpsw_purge_cache', [ absint( $post_id ) ] );
		}
	}

	/**
	 * Queues a purge when a wall comment or like is added or deleted.
	 *
	 * @param int                   $comment_id Comment ID.
	 * @param \WP_Comment|null|bool $comment Comment object.
	 * @return void
	 */
	public function queue_purge_for_comment( $comment_id, $comment = null ) {
		if ( ! $comment instanceof \WP_Comment ) {
			$comment = get_comment( $comment_id );
		}

		if ( $comment instanceof \WP_Comment && in_array( $comment->comment_type, [ self::COMMENT_TYPE, self::LIKE_TYPE ], true ) ) {
			$this->queue_purge( absint( $comment->comment_post_ID ) );
		}
	}

	/**
	 * Queues a purge when a wall comment is approved or unapproved.
	 *
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment Comment object.
	 * @return void
	 */
	public function queue_purge_for_comment_status( $new_status, $old_status, $comment ) {
		$this->queue_purge_for_comment( 0, $comment );
	}

	/**
	 * Purges a post's page and its Vendor's page from the common page caches.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function purge_cache( $post_id ) {
		$post_id = absint( $post_id );
		$urls    = [ (string) hivepress()->router->get_url( 'hpsw_post_view_page', [ 'hpsw_post_id' => $post_id ] ) ];
		$parent  = wp_get_post_parent_id( $post_id );

		if ( $parent && 'hp_vendor' === get_post_type( $parent ) ) {
			$urls[] = (string) get_permalink( $parent );
		}

		$urls = array_values( array_filter( $urls ) );

		/**
		 * Fires with the addresses whose cached copies are out of date, for any cache this plugin
		 * does not know about.
		 *
		 * @hook hpsw/purge_urls
		 * @param {array} $urls Addresses.
		 * @param {int} $post_id Wall post ID.
		 */
		do_action( 'hpsw/purge_urls', $urls, $post_id );

		if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_urls' ) ) {
			\FlyingPress\Purge::purge_urls( $urls );
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			foreach ( $urls as $url ) {
				sg_cachepress_purge_cache( $url );
			}
		}

		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls );
		}

		if ( has_action( 'litespeed_purge_url' ) ) {
			foreach ( $urls as $url ) {
				do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own purge action.
			}
		}
	}

	/*
	|--------------------------------------------------------------------------
	| Reading walls
	|--------------------------------------------------------------------------
	*/

	/**
	 * Gets a wall post's public address.
	 *
	 * @param \HivePress\Models\Hpsw_Post|int $post Wall post or ID.
	 * @return string
	 */
	public function get_post_url( $post ) {
		$post_id = $post instanceof Models\Hpsw_Post ? $post->get_id() : absint( $post );

		return (string) hivepress()->router->get_url( 'hpsw_post_view_page', [ 'hpsw_post_id' => $post_id ] );
	}

	/*
	|--------------------------------------------------------------------------
	| Coupons
	|--------------------------------------------------------------------------
	*/

	/**
	 * Checks whether Deals pick their code from the Vendor's own HivePress Marketplace coupons.
	 *
	 * Only when Vendors can create coupons at all: Marketplace active, WooCommerce behind it, and the
	 * "Allow sellers to create and manage coupons" option on (option `hp_vendor_allow_coupons`,
	 * hivepress-marketplace/includes/configs/settings.php:139, Marketplace 1.4.0). Otherwise the
	 * Deal form keeps its free-text code box.
	 *
	 * @return bool
	 */
	public function is_coupon_picker_on() {
		return function_exists( 'WC' ) && hivepress()->get_version( 'marketplace' ) && (bool) get_option( 'hp_vendor_allow_coupons' );
	}

	/**
	 * Gets a Vendor's coupons that could work at checkout today.
	 *
	 * Marketplace saves a Vendor's coupon as a WooCommerce `shop_coupon` post whose `post_parent` is
	 * the Vendor and whose title is the code (hivepress-marketplace/includes/models/class-coupon.php,
	 * fields `vendor` and `code`; controllers/class-coupon.php, create_coupon()). Published ones only,
	 * and none that has expired or been used up, since a Deal pointing at either would advertise a
	 * code the checkout refuses.
	 *
	 * The expiry is stored in `date_expires` as a Y-m-d date when Marketplace wrote it (its model
	 * field has that format) and as a Unix timestamp when WooCommerce's own screen did, so both are
	 * read.
	 *
	 * @param int $vendor_id Vendor ID.
	 * @return array<int, array{code: string, label: string, expires: string}> Keyed by coupon ID.
	 */
	public function get_vendor_coupons( $vendor_id ) {
		$vendor_id = absint( $vendor_id );

		if ( ! $vendor_id || ! $this->is_coupon_picker_on() ) {
			return [];
		}

		$coupon_ids = get_posts(
			[
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'post_parent'    => $vendor_id,
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$today   = wp_date( 'Y-m-d' );
		$coupons = [];

		foreach ( $coupon_ids as $coupon_id ) {
			$coupon_id = absint( $coupon_id );
			$code      = trim( (string) get_post_field( 'post_title', $coupon_id ) );

			if ( '' === $code ) {
				continue;
			}

			$expires = $this->get_coupon_expiry( $coupon_id );

			if ( '' !== $expires && $expires < $today ) {
				continue;
			}

			$limit = absint( get_post_meta( $coupon_id, 'usage_limit', true ) );

			if ( $limit && absint( get_post_meta( $coupon_id, 'usage_count', true ) ) >= $limit ) {
				continue;
			}

			$coupons[ $coupon_id ] = [
				'code'    => $code,
				'label'   => $this->get_coupon_label( $coupon_id, $code ),
				'expires' => $expires,
			];
		}

		return $coupons;
	}

	/**
	 * Gets a coupon's expiry date as Y-m-d in the site's timezone, or an empty string for none.
	 *
	 * @param int $coupon_id Coupon ID.
	 * @return string
	 */
	protected function get_coupon_expiry( $coupon_id ) {
		return $this->format_coupon_expiry( get_post_meta( $coupon_id, 'date_expires', true ) );
	}

	/**
	 * Turns a stored coupon `date_expires` value into Y-m-d in the site's timezone.
	 *
	 * @param mixed $value Stored value: a Unix time, or a date.
	 * @return string Y-m-d, or an empty string for none.
	 */
	protected function format_coupon_expiry( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ctype_digit( $value ) ) {
			return wp_date( 'Y-m-d', (int) $value );
		}

		$date = date_create_immutable_from_format( 'Y-m-d', substr( $value, 0, 10 ), wp_timezone() );

		return $date ? $date->format( 'Y-m-d' ) : '';
	}

	/**
	 * Gets the words a Vendor sees for a coupon in the Deal form: the code and what it takes off.
	 *
	 * @param int    $coupon_id Coupon ID.
	 * @param string $code Coupon code.
	 * @return string
	 */
	protected function get_coupon_label( $coupon_id, $code ) {
		$type   = (string) get_post_meta( $coupon_id, 'discount_type', true );
		$amount = (float) get_post_meta( $coupon_id, 'coupon_amount', true );

		if ( $amount <= 0 ) {
			return $code;
		}

		if ( 'percent' === $type ) {
			$discount = rtrim( rtrim( number_format_i18n( $amount, 2 ), '0' ), '.,' ) . '%';
		} elseif ( function_exists( 'wc_price' ) ) {
			$discount = html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		} else {
			$discount = number_format_i18n( $amount, 2 );
		}

		return sprintf(
			/* translators: 1: coupon code, 2: discount, for example "20%" or "£5.00". */
			esc_html__( '%1$s (%2$s off)', 'social-walls-for-hivepress' ),
			$code,
			$discount
		);
	}

	/**
	 * Resolves the coupon a Vendor picked in the Deal form into the code the post stores.
	 *
	 * The submitted value is never trusted: whatever the form offered, the coupon is looked up again
	 * here and must be one of the post's own Vendor's current coupons.
	 *
	 * @param mixed $choice Submitted choice: a coupon ID, "keep" for the code already on the post, or empty.
	 * @param int   $vendor_id The post's Vendor.
	 * @return array{code: string|null, id: int|null, expires: string}|\WP_Error|null Null to leave the code as it is.
	 */
	public function resolve_coupon_choice( $choice, $vendor_id ) {
		if ( 'keep' === $choice ) {
			return null;
		}

		if ( null === $choice || '' === $choice || 0 === $choice || '0' === $choice ) {
			return [
				'code'    => null,
				'id'      => null,
				'expires' => '',
			];
		}

		$coupons   = $this->get_vendor_coupons( $vendor_id );
		$coupon_id = absint( $choice );

		if ( ! $coupon_id || ! isset( $coupons[ $coupon_id ] ) ) {
			return new \WP_Error( 'hpsw_coupon_invalid', esc_html__( 'Choose one of your own coupons, or No coupon.', 'social-walls-for-hivepress' ) );
		}

		return [
			'code'    => $coupons[ $coupon_id ]['code'],
			'id'      => $coupon_id,
			'expires' => $coupons[ $coupon_id ]['expires'],
		];
	}

	/*
	|--------------------------------------------------------------------------
	| Ended Deals
	|--------------------------------------------------------------------------
	*/

	/**
	 * Gets what happens to an ended Deal: keep, hide or trash.
	 *
	 * Keep until the owner chooses otherwise, so a site updating from 1.0.x sees no change.
	 *
	 * @return string
	 */
	public function get_ended_mode() {
		return hpsw_get_choice_option( 'hpsw_ended_deals', [ 'keep', 'hide', 'trash' ], 'keep' );
	}

	/**
	 * Gets the days an ended Deal is left as it is before it is hidden or binned.
	 *
	 * @return int
	 */
	public function get_ended_grace() {
		return max( 0, min( 365, hpsw_get_number_option( 'hpsw_ended_grace', 0 ) ) );
	}

	/**
	 * Gets when a Deal ended.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null Null while the Deal runs (and always for an Update), 0 when it ended at a moment
	 *                  not yet recorded, otherwise the Unix time it ended.
	 */
	public function get_ended_time( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return null;
		}

		if ( ! array_key_exists( $post_id, $this->ended_times ) ) {
			$this->load_ended_times( [ $post_id ] );
		}

		return $this->ended_times[ $post_id ];
	}

	/**
	 * Works out, in two queries, which of the given posts are ended Deals, and remembers the answers
	 * for the request. Pages call it with every post they are about to draw, so the cards then read
	 * from memory rather than querying the coupon once per card.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @param bool  $fresh Look again at posts already answered in this request.
	 * @return void
	 */
	public function load_ended_times( $post_ids, $fresh = false ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );

		if ( ! $fresh ) {
			$post_ids = array_values( array_diff( $post_ids, array_keys( $this->ended_times ) ) );
		}

		if ( ! $post_ids ) {
			return;
		}

		$rows = $this->query_deal_rows( 'ids', $post_ids );

		foreach ( $post_ids as $post_id ) {
			$this->ended_times[ $post_id ] = null;
		}

		foreach ( $this->resolve_ended_times( $rows ) as $post_id => $time ) {
			$this->ended_times[ $post_id ] = $time;
		}
	}

	/**
	 * Checks whether an ended Deal is due to leave public view under the owner's setting.
	 *
	 * @param int|null $time What get_ended_time() returned.
	 * @return bool
	 */
	protected function is_ended_due( $time ) {
		if ( null === $time || 'keep' === $this->get_ended_mode() ) {
			return false;
		}

		$grace = $this->get_ended_grace();

		if ( ! $grace ) {
			return true;
		}

		// An end nobody has timed yet starts its grace period when the daily pass first records it.
		return $time > 0 && $time + $grace * DAY_IN_SECONDS <= time();
	}

	/**
	 * Checks whether a post is an ended Deal that visitors no longer see.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @return bool
	 */
	public function is_ended_hidden( $post ) {
		if ( ! $post instanceof Models\Hpsw_Post || ! $post->is_deal() || 'keep' === $this->get_ended_mode() ) {
			return false;
		}

		return $this->is_ended_due( $this->get_ended_time( (int) $post->get_id() ) );
	}

	/**
	 * Gets the published Deals a wall must leave out because their coupon ended.
	 *
	 * Deals past their own end date are already left out by the wall query itself, so only live Deals
	 * with a code are looked at: two queries per request, whatever the size of the wall.
	 *
	 * @return int[]
	 */
	public function get_hidden_deal_ids() {
		if ( 'keep' === $this->get_ended_mode() ) {
			return [];
		}

		if ( null === $this->hidden_deal_ids ) {
			$rows = $this->query_deal_rows( 'live', [ current_time( 'Y-m-d' ) ] );

			$this->hidden_deal_ids = [];

			foreach ( $this->resolve_ended_times( $rows ) as $post_id => $time ) {
				$this->ended_times[ $post_id ] = $time;

				if ( $this->is_ended_due( $time ) ) {
					$this->hidden_deal_ids[] = $post_id;
				}
			}
		}

		return $this->hidden_deal_ids;
	}

	/**
	 * Reads the Deal details the end check needs, for one of four fixed sets of Deals.
	 *
	 * One query with a join per meta key, rather than the model, so a whole wall costs one round trip.
	 * The conditions are fixed here and only ever take values through placeholders.
	 *
	 * @param string $scope Which Deals: `ids` (the posts in $args), `live` (published, with a code,
	 *                      not past their own end date; $args is today), `tidy` (published or hidden,
	 *                      with a code or past their end date, above an ID; $args is the ID, today and
	 *                      the batch size) or `code` (showing the code in $args).
	 * @param array  $args Values for the placeholders.
	 * @return array<int, array{code: string, coupon_id: int, end_date: string, ended_time: int}> Keyed by post ID.
	 */
	protected function query_deal_rows( $scope, $args = [] ) {
		global $wpdb;

		$args = array_values( $args );

		// Each condition is prepared on its own, then placed in the query below. WordPress swaps any "%"
		// in a prepared value for a placeholder token until the query runs (wpdb::add_placeholder_escape()),
		// so preparing the whole query again cannot misread a code that contains one.
		if ( 'ids' === $scope && $args ) {
			$where = $wpdb->prepare( 'p.ID IN (' . implode( ',', array_fill( 0, count( $args ), '%d' ) ) . ')', $args );
		} elseif ( 'live' === $scope ) {
			$where = $wpdb->prepare( "p.post_status = 'publish' AND c.meta_value <> '' AND ( e.meta_value IS NULL OR e.meta_value = '' OR e.meta_value >= %s )", $args );
		} elseif ( 'tidy' === $scope && 3 === count( $args ) ) {
			$where = $wpdb->prepare( "p.post_status IN ( 'publish', 'draft' ) AND p.ID > %d AND ( c.meta_value <> '' OR ( e.meta_value <> '' AND e.meta_value < %s ) ) ORDER BY p.ID ASC LIMIT %d", absint( $args[0] ), (string) $args[1], absint( $args[2] ) );
		} elseif ( 'code' === $scope ) {
			$where = $wpdb->prepare( 'c.meta_value = %s', $args );
		} else {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above; a join per meta key is not expressible through WP_Query, and the answer changes as coupons are used, so it is not cached beyond the request.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, c.meta_value AS code, i.meta_value AS coupon_id, e.meta_value AS end_date, s.meta_value AS ended_time
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = 'hp_type' AND t.meta_value = 'deal'
				LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = 'hp_coupon'
				LEFT JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = 'hp_coupon_id'
				LEFT JOIN {$wpdb->postmeta} e ON e.post_id = p.ID AND e.meta_key = 'hp_expire_date'
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'hp_hpsw_ended_time'
				WHERE p.post_type = %s AND {$where}",
				self::POST_TYPE
			),
			ARRAY_A
		);
		// phpcs:enable

		$rows = [];

		foreach ( (array) $results as $result ) {
			$post_id = absint( $result['ID'] );

			if ( isset( $rows[ $post_id ] ) ) {
				continue;
			}

			$rows[ $post_id ] = [
				'code'       => trim( (string) $result['code'] ),
				'coupon_id'  => absint( $result['coupon_id'] ),
				'end_date'   => trim( (string) $result['end_date'] ),
				'ended_time' => absint( $result['ended_time'] ),
			];
		}

		return $rows;
	}

	/**
	 * Works out when each Deal ended, from its own end date and the coupon it shows.
	 *
	 * @param array $rows Deal details from query_deal_rows().
	 * @return array<int, int|null> Keyed by post ID, as get_ended_time() describes.
	 */
	protected function resolve_ended_times( $rows ) {
		$today   = current_time( 'Y-m-d' );
		$coupons = $this->query_coupon_rows( $rows );
		$times   = [];

		foreach ( $rows as $post_id => $row ) {
			$ends  = [];
			$ended = false;

			// The Deal's own date is inclusive, like is_expired(): it ends when that day is over.
			if ( '' !== $row['end_date'] && $row['end_date'] < $today ) {
				$ended  = true;
				$ends[] = $this->get_day_end_time( $row['end_date'] );
			}

			$coupon_end = $this->get_coupon_end_time( $row, $coupons, $today );

			if ( null !== $coupon_end ) {
				$ended  = true;
				$ends[] = $coupon_end;
			}

			if ( ! $ended ) {
				$times[ $post_id ] = null;

				continue;
			}

			$ends[] = $row['ended_time'];
			$ends   = array_filter( $ends );

			$times[ $post_id ] = $ends ? (int) min( $ends ) : 0;
		}

		return $times;
	}

	/**
	 * Gets when the coupon a Deal shows stopped working at checkout.
	 *
	 * The coupon is found by the ID stored when it was picked, as long as it still carries the Deal's
	 * code, and otherwise by the code, the way WooCommerce's own wc_get_coupon_id_by_code() finds it:
	 * a published coupon first. A code with no coupon behind it at all counts as ended only when a
	 * stored ID proves one existed; a typed code never backed by a coupon may be one the Vendor honours
	 * in person, and is left alone.
	 *
	 * @param array  $row Deal details from query_deal_rows().
	 * @param array  $coupons Coupons from query_coupon_rows().
	 * @param string $today Today as Y-m-d in the site's timezone.
	 * @return int|null Null while the coupon works (or there is none), 0 when it stopped at an unknown
	 *                  moment, otherwise the Unix time it stopped.
	 */
	protected function get_coupon_end_time( $row, $coupons, $today ) {
		if ( '' === $row['code'] ) {
			return null;
		}

		$coupon = null;

		if ( $row['coupon_id'] && isset( $coupons['ids'][ $row['coupon_id'] ] ) && 0 === strcasecmp( $coupons['ids'][ $row['coupon_id'] ]['code'], $row['code'] ) ) {
			$coupon = $coupons['ids'][ $row['coupon_id'] ];
		}

		if ( ! $coupon ) {
			$key = $this->get_code_key( $row['code'] );

			if ( isset( $coupons['codes'][ $key ] ) ) {
				$coupon = $coupons['codes'][ $key ];
			}
		}

		if ( ! $coupon ) {
			return $row['coupon_id'] ? 0 : null;
		}

		if ( 'trash' === $coupon['status'] ) {
			return $coupon['trashed'];
		}

		// A draft or scheduled coupon may still be on its way; only a published one is judged.
		if ( 'publish' !== $coupon['status'] ) {
			return null;
		}

		$ends   = [];
		$expiry = $this->format_coupon_expiry( $coupon['date_expires'] );

		if ( '' !== $expiry && $expiry < $today ) {
			$ends[] = $this->get_day_end_time( $expiry );
		}

		if ( $coupon['usage_limit'] && $coupon['usage_count'] >= $coupon['usage_limit'] ) {
			$ends[] = 0;
		}

		if ( ! $ends ) {
			return null;
		}

		$known = array_filter( $ends );

		return $known ? (int) min( $known ) : 0;
	}

	/**
	 * Reads every coupon the given Deals could be showing, in one query.
	 *
	 * @param array $rows Deal details from query_deal_rows().
	 * @return array{ids: array<int, array>, codes: array<string, array>} Coupons by ID, and the best
	 *                                                                     coupon for each code.
	 */
	protected function query_coupon_rows( $rows ) {
		global $wpdb;

		$coupons = [
			'ids'   => [],
			'codes' => [],
		];

		$ids   = [ 0 ];
		$codes = [];

		foreach ( $rows as $row ) {
			if ( '' === $row['code'] ) {
				continue;
			}

			$codes[ $this->get_code_key( $row['code'] ) ] = $row['code'];

			if ( $row['coupon_id'] ) {
				$ids[] = $row['coupon_id'];
			}
		}

		if ( ! $codes ) {
			return $coupons;
		}

		$ids   = array_values( array_unique( $ids ) );
		$codes = array_values( $codes );

		$sql = "SELECT c.ID, c.post_title, c.post_status, x.meta_value AS date_expires, l.meta_value AS usage_limit, u.meta_value AS usage_count, t.meta_value AS trashed
			FROM {$wpdb->posts} c
			LEFT JOIN {$wpdb->postmeta} x ON x.post_id = c.ID AND x.meta_key = 'date_expires'
			LEFT JOIN {$wpdb->postmeta} l ON l.post_id = c.ID AND l.meta_key = 'usage_limit'
			LEFT JOIN {$wpdb->postmeta} u ON u.post_id = c.ID AND u.meta_key = 'usage_count'
			LEFT JOIN {$wpdb->postmeta} t ON t.post_id = c.ID AND t.meta_key = '_wp_trash_meta_time'
			WHERE c.post_type = 'shop_coupon'
			AND ( c.ID IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') OR c.post_title IN (' . implode( ',', array_fill( 0, count( $codes ), '%s' ) ) . ') )
			ORDER BY c.ID DESC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- only placeholders are added to the fixed SQL above and every value goes through prepare(); one query for a whole page of Deals, which WP_Query cannot express with the meta it needs.
		$results = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $ids, $codes ) ), ARRAY_A );

		// Published beats binned beats anything else; among equals the newest wins, as the rows come
		// newest first.
		$rank = [
			'publish' => 2,
			'trash'   => 1,
		];

		foreach ( (array) $results as $result ) {
			$coupon = [
				'id'           => absint( $result['ID'] ),
				'code'         => trim( (string) $result['post_title'] ),
				'status'       => (string) $result['post_status'],
				'date_expires' => (string) $result['date_expires'],
				'usage_limit'  => absint( $result['usage_limit'] ),
				'usage_count'  => absint( $result['usage_count'] ),
				'trashed'      => absint( $result['trashed'] ),
			];

			if ( isset( $coupons['ids'][ $coupon['id'] ] ) ) {
				continue;
			}

			$coupons['ids'][ $coupon['id'] ] = $coupon;

			$key = $this->get_code_key( $coupon['code'] );

			if ( ! isset( $coupons['codes'][ $key ] ) || hp\get_array_value( $rank, $coupon['status'], 0 ) > hp\get_array_value( $rank, $coupons['codes'][ $key ]['status'], 0 ) ) {
				$coupons['codes'][ $key ] = $coupon;
			}
		}

		return $coupons;
	}

	/**
	 * Gets the form of a coupon code used to match it, ignoring case as WooCommerce does.
	 *
	 * @param string $code Coupon code.
	 * @return string
	 */
	protected function get_code_key( $code ) {
		$code = trim( (string) $code );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $code ) : strtolower( $code );
	}

	/**
	 * Gets the moment an inclusive Y-m-d date is over: midnight at its end, in the site's timezone.
	 *
	 * @param string $date Date as Y-m-d.
	 * @return int Unix time, or 0 for a date that cannot be read.
	 */
	protected function get_day_end_time( $date ) {
		$day = date_create_immutable_from_format( '!Y-m-d', substr( (string) $date, 0, 10 ), wp_timezone() );

		return $day ? $day->modify( '+1 day' )->getTimestamp() : 0;
	}

	/**
	 * Records the end of the Deals showing a coupon that is being deleted for good.
	 *
	 * HivePress Marketplace deletes a Vendor's coupon outright rather than binning it
	 * (hivepress/includes/models/class-post.php, delete(): wp_delete_post( $id, true )), which leaves
	 * nothing behind to tell it apart from a code that never had a coupon. So the Deals showing it are
	 * given the coupon's ID and the time, and read as ended from then on.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function record_deleted_coupon( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'shop_coupon' !== $post->post_type ) {
			return;
		}

		$code = trim( (string) $post->post_title );

		if ( '' === $code ) {
			return;
		}

		$rows = $this->query_deal_rows( 'code', [ $code ] );

		foreach ( $rows as $deal_id => $row ) {
			if ( $row['coupon_id'] && absint( $post->ID ) !== $row['coupon_id'] ) {
				continue;
			}

			if ( ! $row['coupon_id'] ) {
				update_post_meta( $deal_id, 'hp_coupon_id', absint( $post->ID ) );
			}

			if ( ! $row['ended_time'] ) {
				update_post_meta( $deal_id, 'hp_hpsw_ended_time', time() );
			}

			unset( $this->ended_times[ $deal_id ] );
		}

		$this->hidden_deal_ids = null;
	}

	/**
	 * The daily pass over ended Deals: records when each ended, and moves those due to the Bin.
	 *
	 * Hiding needs nothing from this pass, since walls and post pages test for it as they are drawn;
	 * the recorded time is what a grace period counts from when nothing else says when a Deal ended.
	 * Works in batches of ENDED_BATCH by ascending ID, and hands anything past ENDED_LIMIT to a
	 * follow-up run a minute later, so no single request runs long on a large site.
	 *
	 * Binning is wp_trash_post() only, never a permanent delete: WordPress empties the Bin on its own
	 * schedule (EMPTY_TRASH_DAYS), and until then an administrator can restore the post. Pending posts
	 * are left for the administrator, because binning one sends the Vendor the rejection email.
	 *
	 * @param int $after Only Deals with a higher ID; set by a follow-up run.
	 * @return void
	 */
	public function tidy_ended_deals( $after = 0 ) {
		$mode = $this->get_ended_mode();

		if ( 'keep' === $mode ) {
			return;
		}

		$after   = absint( $after );
		$handled = 0;
		$batch   = 0;
		$today   = current_time( 'Y-m-d' );

		do {
			$rows = $this->query_deal_rows( 'tidy', [ $after, $today, self::ENDED_BATCH ] );

			if ( ! $rows ) {
				break;
			}

			$after    = max( array_keys( $rows ) );
			$batch    = count( $rows );
			$handled += $batch;

			foreach ( $this->resolve_ended_times( $rows ) as $post_id => $time ) {
				if ( null === $time ) {

					// Running again (a new coupon, a later date): any earlier record no longer applies.
					if ( $rows[ $post_id ]['ended_time'] ) {
						delete_post_meta( $post_id, 'hp_hpsw_ended_time' );
					}

					continue;
				}

				// Only an end with no date of its own is recorded (a used-up or deleted coupon), so a
				// Deal whose date is later moved on never keeps a stale record.
				if ( ! $time ) {
					$time = time();

					update_post_meta( $post_id, 'hp_hpsw_ended_time', $time );
				}

				$this->ended_times[ $post_id ] = $time;

				if ( 'trash' === $mode && $this->is_ended_due( $time ) ) {
					wp_trash_post( $post_id );
				}
			}
		} while ( self::ENDED_BATCH === $batch && $handled < self::ENDED_LIMIT );

		// The limit was reached with Deals still to look at: a follow-up run carries on from there.
		if ( $rows && self::ENDED_BATCH === $batch ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::ENDED_HOOK, [ $after ] );
		}

		$this->hidden_deal_ids = null;
	}

	/*
	|--------------------------------------------------------------------------
	| Sharing
	|--------------------------------------------------------------------------
	*/

	/**
	 * Checks whether the Share button is switched on. On until the owner unticks it: a never-saved
	 * option means the default, an empty string means unticked (hpsw_get_option()).
	 *
	 * @return bool
	 */
	public function is_share_enabled() {
		return (bool) hpsw_get_option( 'hpsw_enable_share', '1' );
	}

	/**
	 * Gets the owner's QR code logo, if one is chosen and the Share button is on.
	 *
	 * The child setting is read only behind its parent, because `_parent` hides the row without
	 * clearing it (resources/hivepress-settings.md, "_parent hides the row").
	 *
	 * @return string Image address, or an empty string.
	 */
	public function get_share_logo_url() {
		$logo_id = absint( get_option( 'hp_hpsw_share_logo' ) );

		if ( ! $logo_id || ! $this->is_share_enabled() || ! wp_attachment_is_image( $logo_id ) ) {
			return '';
		}

		return (string) wp_get_attachment_image_url( $logo_id, 'medium' );
	}

	/**
	 * Renders the Share button and its pop-up.
	 *
	 * SHARED MARKUP: Additional Gallery for HivePress renders the same structure from
	 * Agl_Gallery::render_share(), with its own class prefix. The behaviour lives in the shared,
	 * byte-identical assets/js/share.js, which reads only the data attributes printed here, so keep
	 * the attributes in step between the two plugins. `hp-share` and `hp-share-modal` are the shared
	 * marker classes that script claims the page by; they are never styled. The look comes from this
	 * plugin's own `hpsw-share` classes (assets/css/frontend.css).
	 *
	 * Facebook and WhatsApp are plain links to their own share pages, opened only when a visitor
	 * clicks, so the page itself sends nothing to either. Their icons are inline SVG from Font
	 * Awesome Free 7.1.0 (brands, CC BY 4.0, credited in the readme), because HivePress ships only the
	 * solid icon font, which has no brand glyphs (resources/hivepress-ui.md, "Icons").
	 *
	 * @param string $url   Address to share.
	 * @param string $title Title to share with it.
	 * @return string
	 */
	public function render_share( $url, $title ) {
		if ( ! $this->is_share_enabled() || '' === $url ) {
			return '';
		}

		wp_enqueue_script( 'hpsw-share' );

		$modal_id = 'hpsw_share_modal';
		$logo     = $this->get_share_logo_url();
		$library  = add_query_arg( 'ver', '2.0.4', plugin_dir_url( HPSW_FILE ) . 'assets/vendor/qrcode-generator/qrcode.js' );

		$facebook = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url );
		$whatsapp = 'https://wa.me/?text=' . rawurlencode( trim( $title . ' ' . $url ) );

		$icons = [
			'facebook' => '<svg class="hpsw-share__icon hpsw-share__icon--facebook" viewBox="0 0 320 512" aria-hidden="true" focusable="false"><path fill="currentColor" d="M80 299.3l0 212.7 116 0 0-212.7 86.5 0 18-97.8-104.5 0 0-34.6c0-51.7 20.3-71.5 72.7-71.5 16.3 0 29.4 .4 37 1.2l0-88.7C291.4 4 256.4 0 236.2 0 129.3 0 80 50.5 80 159.4l0 42.1-66 0 0 97.8 66 0z"/></svg>',
			'whatsapp' => '<svg class="hpsw-share__icon hpsw-share__icon--whatsapp" viewBox="0 0 448 512" aria-hidden="true" focusable="false"><path fill="currentColor" d="M380.9 97.1c-41.9-42-97.7-65.1-157-65.1-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480 117.7 449.1c32.4 17.7 68.9 27 106.1 27l.1 0c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3 18.6-68.1-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1s56.2 81.2 56.1 130.5c0 101.8-84.9 184.6-186.6 184.6zM325.1 300.5c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8s-14.3 18-17.6 21.8c-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7 .9-6.9-.5-9.7s-12.5-30.1-17.1-41.2c-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2s-9.7 1.4-14.8 6.9c-5.1 5.6-19.4 19-19.4 46.3s19.9 53.7 22.6 57.4c2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4s4.6-24.1 3.2-26.4c-1.3-2.5-5-3.9-10.5-6.6z"/></svg>',
		];

		$output  = '<button type="button" class="hp-share hpsw-share__button hp-button hp-button--wide button button--large button--secondary" data-hp-share="#' . esc_attr( $modal_id ) . '" data-url="' . esc_url( $url ) . '" data-title="' . esc_attr( $title ) . '">';
		$output .= '<i class="hp-icon fas fa-share-alt"></i><span>' . esc_html__( 'Share', 'social-walls-for-hivepress' ) . '</span>';
		$output .= '</button>';

		$output .= '<div id="' . esc_attr( $modal_id ) . '" class="hp-modal hp-share-modal hpsw-share" data-component="modal">';
		$output .= '<h3 class="hp-modal__title">' . esc_html__( 'Share', 'social-walls-for-hivepress' ) . '</h3>';
		$output .= '<div class="hpsw-share__options">';
		$output .= '<a href="' . esc_url( $facebook ) . '" class="hpsw-share__option" target="_blank" rel="noopener noreferrer">' . $icons['facebook'] . '<span>' . esc_html__( 'Share on Facebook', 'social-walls-for-hivepress' ) . '</span></a>';
		$output .= '<a href="' . esc_url( $whatsapp ) . '" class="hpsw-share__option" target="_blank" rel="noopener noreferrer">' . $icons['whatsapp'] . '<span>' . esc_html__( 'Share on WhatsApp', 'social-walls-for-hivepress' ) . '</span></a>';
		$output .= '<button type="button" class="hpsw-share__option" data-hp-share-copy="' . esc_url( $url ) . '"><i class="hp-icon fas fa-link hpsw-share__icon"></i><span>' . esc_html__( 'Copy link', 'social-walls-for-hivepress' ) . '</span></button>';
		$output .= '<p class="hpsw-share__copied hp-meta" data-hp-share-copied role="status" hidden>' . esc_html__( 'Link copied', 'social-walls-for-hivepress' ) . '</p>';
		$output .= '</div>';
		$output .= '<div class="hpsw-share__qr" data-hp-share-qr data-text="' . esc_url( $url ) . '" data-src="' . esc_url( $library ) . '" data-label="' . esc_attr__( 'QR code for this page', 'social-walls-for-hivepress' ) . '"' . ( $logo ? ' data-logo="' . esc_url( $logo ) . '"' : '' ) . '></div>';
		$output .= '<p class="hpsw-share__hint hp-meta">' . esc_html__( 'Scan with a phone camera to open this page.', 'social-walls-for-hivepress' ) . '</p>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Gets the status pill a post shows its owner: the `hp-status` modifier and the label.
	 *
	 * The same urgency rule core uses for Listings: the pill says what a reader needs to know now,
	 * not only the raw status (templates/listing/edit/block/listing-status.php).
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @return string[] Modifier and label.
	 */
	public function get_owner_status( $post ) {
		$status = (string) $post->get_status();

		if ( 'pending' === $status ) {
			return [ 'pending', esc_html_x( 'Pending', 'wall post', 'social-walls-for-hivepress' ) ];
		}

		if ( $post->is_ended() ) {
			return [ 'trash', esc_html__( 'Ended', 'social-walls-for-hivepress' ) ];
		}

		if ( $post->is_pinned() ) {
			return [
				'publish',
				sprintf(
					/* translators: %s: date. */
					esc_html__( 'Pinned until %s', 'social-walls-for-hivepress' ),
					wp_date( get_option( 'date_format' ), (int) $post->get_pinned_time() )
				),
			];
		}

		if ( 'publish' === $status ) {
			return [ 'publish', esc_html_x( 'Published', 'wall post', 'social-walls-for-hivepress' ) ];
		}

		return [ 'draft', esc_html_x( 'Hidden', 'wall post', 'social-walls-for-hivepress' ) ];
	}

	/**
	 * Gets a short plain-text label for a post, for emails, carts and admin screens.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public function get_post_label( $post_id ) {
		$title = trim( (string) get_post_field( 'post_title', $post_id ) );

		if ( '' !== $title ) {
			return $title;
		}

		return wp_trim_words( wp_strip_all_tags( (string) get_post_field( 'post_content', $post_id ) ), 12 );
	}

	/**
	 * Finds wall posts.
	 *
	 * Built on WP_Query directly rather than a model query, because the wall needs things the model
	 * query does not express: an OR between "no end date" and "ends today or later", and ordering by
	 * the pin column first. Pinned posts come first, then newest first.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type int[]|null $vendor_ids Only these Vendors; null for all, an empty array for none.
	 *     @type int[]|null $post_ids   Only these posts; null for all, an empty array for none.
	 *     @type int        $listing_id Only posts for this Listing or for all the Vendor's Listings.
	 *     @type string     $type       deal, update or '' for both.
	 *     @type string     $keyword    Words to search for.
	 *     @type int        $number     Posts per page.
	 *     @type int        $page       Page number.
	 * }
	 * @return array{ids: int[], pages: int, total: int}
	 */
	public function query_posts( $args ) {
		$args = array_merge(
			[
				'vendor_ids' => null,
				'post_ids'   => null,
				'listing_id' => 0,
				'type'       => '',
				'keyword'    => '',
				'number'     => 10,
				'page'       => 1,
			],
			$args
		);

		$empty = [
			'ids'   => [],
			'pages' => 0,
			'total' => 0,
		];

		if ( ( is_array( $args['vendor_ids'] ) && ! $args['vendor_ids'] ) || ( is_array( $args['post_ids'] ) && ! $args['post_ids'] ) ) {
			return $empty;
		}

		$meta_query = [
			'expiry' => [
				'relation' => 'OR',

				[
					'key'     => 'hp_expire_date',
					'compare' => 'NOT EXISTS',
				],

				[
					'key'     => 'hp_expire_date',
					'value'   => current_time( 'Y-m-d' ),
					'compare' => '>=',
					'type'    => 'DATE',
				],
			],
		];

		if ( in_array( $args['type'], [ 'deal', 'update' ], true ) ) {
			$meta_query['type'] = [
				'key'   => 'hp_type',
				'value' => $args['type'],
			];
		}

		// A Listing page: posts for this Listing, and posts for all the Vendor's Listings, whose meta
		// HivePress deletes when saved empty (models/class-post.php:177-182, core 1.7.31).
		$listing_id = absint( $args['listing_id'] );

		if ( $listing_id ) {
			$meta_query['listing'] = [
				'relation' => 'OR',

				[
					'key'     => 'hp_listing',
					'compare' => 'NOT EXISTS',
				],

				[
					'key'     => 'hp_listing',
					'value'   => [ (string) $listing_id, '', '0' ],
					'compare' => 'IN',
				],
			];
		}

		$query_args = [
			'post_type'           => self::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, absint( $args['number'] ) ),
			'paged'               => max( 1, absint( $args['page'] ) ),
			'fields'              => 'ids',
			'ignore_sticky_posts' => true,
			'meta_query'          => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the end date and type are what a wall filters on; indexed by meta_key.

			'orderby'             => [
				'menu_order' => 'DESC',
				'date'       => 'DESC',
				'ID'         => 'DESC',
			],
		];

		// Deals whose coupon ended, when the owner hides or bins ended Deals. Left out here rather than
		// after the query, so page numbers and counts stay right before the daily pass runs.
		$hidden = $this->get_hidden_deal_ids();

		if ( $hidden ) {
			$query_args['post__not_in'] = $hidden;
		}

		// A location filter, already worked out post by post (get_location_post_ids()).
		if ( is_array( $args['post_ids'] ) ) {
			$query_args['post__in'] = array_map( 'absint', $args['post_ids'] );
		}

		if ( is_array( $args['vendor_ids'] ) ) {
			$query_args['post_parent__in'] = array_map( 'absint', $args['vendor_ids'] );
		} else {

			// Posts of Vendors who are no longer published stay off every wall.
			$query_args['post_parent__in'] = $this->get_published_vendor_ids();

			if ( ! $query_args['post_parent__in'] ) {
				return $empty;
			}
		}

		$keyword = trim( (string) $args['keyword'] );

		if ( '' !== $keyword ) {
			$query_args['s'] = $keyword;
		}

		$query = new \WP_Query( $query_args );

		return [
			'ids'   => array_map( 'absint', $query->posts ),
			'pages' => (int) $query->max_num_pages,
			'total' => (int) $query->found_posts,
		];
	}

	/**
	 * Gets the IDs of every published Vendor that has at least one published wall post.
	 *
	 * @return int[]
	 */
	protected function get_published_vendor_ids() {
		global $wpdb;

		/*
		 * Remembered for this request only, never in the object cache. On a site with a persistent
		 * object cache (Memcached, Redis) a cached list would outlive the request and hide every
		 * Vendor who posted for the first time after it was stored, with no expiry to rescue it.
		 */
		static $ids = null;

		if ( ! is_array( $ids ) ) {
			// One query rather than every Vendor: only Vendors who have posted can appear on a wall.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a JOIN no WordPress API can express; see above for why it is not cached.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT v.ID FROM {$wpdb->posts} v INNER JOIN {$wpdb->posts} p ON p.post_parent = v.ID WHERE v.post_type = %s AND v.post_status = %s AND p.post_type = %s AND p.post_status = %s",
					'hp_vendor',
					'publish',
					self::POST_TYPE,
					'publish'
				)
			);

			$ids = array_map( 'absint', (array) $ids );
		}

		return $ids;
	}

	/**
	 * Works out which Vendors a wall's category filter allows.
	 *
	 * A category is a property of a Vendor, not of a single post: a Vendor matches when they have a
	 * published Listing in it. Location is matched post by post instead (get_location_post_ids()).
	 *
	 * @param array $filters {
	 *     Filter values.
	 *
	 *     @type int $category Listing category ID.
	 * }
	 * @return int[]|null Vendor IDs, or null when nothing restricts the Vendors.
	 */
	public function get_filter_vendor_ids( $filters ) {
		$category = absint( hp\get_array_value( $filters, 'category' ) );

		if ( ! $category ) {
			return null;
		}

		return $this->get_listing_vendor_ids(
			[
				'tax_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the category filter itself.
					[
						'taxonomy'         => 'hp_listing_category',
						'terms'            => [ $category ],
						'include_children' => true,
					],
				],
			]
		);
	}

	/**
	 * Gets the wall posts a location filter allows.
	 *
	 * Matched post by post, because a Deal belongs to a place, not just to a Vendor. Up to 1.1.0 a
	 * Vendor matched when any of their Listings was near, and then every post they had ever made
	 * showed, so a Deal for a Listing in another town passed the filter too. Now:
	 *
	 * - A post that applies to one Listing matches when that Listing is near (or in the region).
	 * - A post that applies to all the Vendor's Listings matches when the Vendor's own profile, or any
	 *   of their published Listings, is near (or in the region).
	 *
	 * @param array $filters {
	 *     Filter values.
	 *
	 *     @type string $location Location text.
	 *     @type float  $latitude Latitude, when a place was picked.
	 *     @type float  $longitude Longitude, when a place was picked.
	 *     @type float  $radius Radius in the site's units.
	 *     @type string $region Region code, when regions are generated.
	 * }
	 * @return int[]|null Post IDs, or null when no location was given.
	 */
	public function get_location_post_ids( $filters ) {
		$matches = $this->get_location_matches( $filters );

		if ( ! is_array( $matches ) ) {
			return null;
		}

		$ids = [];

		// phpcs:disable WordPress.DB.SlowDBQuery -- the location filter itself; runs only when a visitor sets one, and returns IDs only.
		// Posts for one Listing: the Listing is in the place.
		if ( $matches['listings'] ) {
			$ids = get_posts(
				[
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,

					'meta_query'     => [
						[
							'key'     => 'hp_listing',
							'value'   => array_map( 'strval', $matches['listings'] ),
							'compare' => 'IN',
						],
					],
				]
			);
		}

		// Posts for all of a Vendor's Listings: the Vendor, or one of their Listings, is in the place.
		// HivePress deletes the meta when "All my Listings" is saved (models/class-post.php:177-182,
		// core 1.7.31); an empty or zero value is matched too, for posts saved any other way.
		if ( $matches['vendors'] ) {
			$ids = array_merge(
				$ids,
				get_posts(
					[
						'post_type'       => self::POST_TYPE,
						'post_status'     => 'publish',
						'post_parent__in' => $matches['vendors'],
						'posts_per_page'  => -1,
						'fields'          => 'ids',
						'no_found_rows'   => true,

						'meta_query'      => [
							'relation' => 'OR',

							[
								'key'     => 'hp_listing',
								'compare' => 'NOT EXISTS',
							],

							[
								'key'     => 'hp_listing',
								'value'   => [ '', '0' ],
								'compare' => 'IN',
							],
						],
					]
				)
			);
		}
		// phpcs:enable

		return array_values( array_unique( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Gets the Listings and Vendors a location filter reaches.
	 *
	 * With HivePress Geolocation active and a place picked, a Listing or Vendor matches when its
	 * coordinates are within the radius of that place, measured as a true distance rather than the
	 * square box Geolocation's own search uses (hivepress-geolocation/includes/fields/
	 * class-latitude.php:84-98, class-longitude.php:84-106), so a corner of the box never counts as
	 * "within 15 km". A picked region is matched by its code, as the extension does
	 * (components/class-geolocation.php:565-615), and then the coordinates are not used, which is also
	 * what the extension does. Without Geolocation, or with typed text that was never matched to a
	 * place, the text is looked for in the stored addresses.
	 *
	 * @param array $filters Filter values.
	 * @return array{listings: int[], vendors: int[]}|null Matching Listing IDs, and the Vendors whose
	 *                                                     all-Listings posts match; null when no
	 *                                                     location was given.
	 */
	protected function get_location_matches( $filters ) {
		// phpcs:disable WordPress.DB.SlowDBQuery -- every meta and tax clause below IS the location filter a visitor asked for; they run only when one is set, and return IDs only.
		$location  = trim( (string) hp\get_array_value( $filters, 'location' ) );
		$latitude  = hp\get_array_value( $filters, 'latitude' );
		$longitude = hp\get_array_value( $filters, 'longitude' );
		$region    = sanitize_text_field( (string) hp\get_array_value( $filters, 'region' ) );

		if ( '' === $location && '' === $region && ! is_numeric( $latitude ) ) {
			return null;
		}

		// Listing ID => Vendor ID, and Vendor IDs matched by their own profile.
		$listings = [];
		$vendors  = [];

		if ( '' !== $region && get_option( 'hp_geolocation_generate_regions' ) ) {

			// A region picked from the suggestions.
			foreach ( [
				'hp_listing_region' => 'hp_listing',
				'hp_vendor_region'  => 'hp_vendor',
			] as $taxonomy => $post_type ) {
				if ( ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}

				$term_ids = get_terms(
					[
						'taxonomy'   => $taxonomy,
						'fields'     => 'ids',
						'number'     => 1,
						'hide_empty' => false,
						'meta_key'   => 'hp_code', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- how Geolocation itself finds a region.
						'meta_value' => $region, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- as above.
					]
				);

				if ( ! is_array( $term_ids ) || ! $term_ids ) {
					continue;
				}

				$found = $this->get_post_parents(
					$post_type,
					[
						'tax_query' => [
							[
								'taxonomy' => $taxonomy,
								'terms'    => array_map( 'absint', $term_ids ),
							],
						],
					]
				);

				if ( 'hp_listing' === $post_type ) {
					$listings += $found;
				} else {
					$vendors = array_merge( $vendors, array_keys( $found ) );
				}
			}
		} elseif ( is_numeric( $latitude ) && is_numeric( $longitude ) ) {

			// A place picked from the suggestions: a radius search.
			$latitude  = max( -90, min( 90, (float) $latitude ) );
			$longitude = max( -180, min( 180, (float) $longitude ) );
			$radius    = $this->get_radius_km( hp\get_array_value( $filters, 'radius' ) );

			$listings += $this->get_nearby_posts( 'hp_listing', 'hp_latitude', 'hp_longitude', $latitude, $longitude, $radius );
			$vendors   = array_keys( $this->get_nearby_posts( 'hp_vendor', 'hp_latitude', 'hp_longitude', $latitude, $longitude, $radius ) );

			$extra = $this->get_geolocation_plus_matches( $latitude, $longitude, $radius );

			$listings += $extra['listings'];
			$vendors   = array_merge( $vendors, $extra['vendors'] );
		} else {

			// Plain text, matched against the stored addresses.
			$args = [
				'meta_query' => [
					[
						'key'     => 'hp_location',
						'value'   => $location,
						'compare' => 'LIKE',
					],
				],
			];

			$listings += $this->get_post_parents( 'hp_listing', $args );
			$vendors   = array_keys( $this->get_post_parents( 'hp_vendor', $args ) );
		}
		// phpcs:enable

		// A Vendor with a Listing in the place matches for their all-Listings posts too.
		$vendors = array_merge( $vendors, array_values( $listings ) );

		return [
			'listings' => array_values( array_filter( array_map( 'absint', array_keys( $listings ) ) ) ),
			'vendors'  => array_values( array_unique( array_filter( array_map( 'absint', $vendors ) ) ) ),
		];
	}

	/**
	 * Gets the published posts of one type whose coordinates are within a radius of a point.
	 *
	 * One query for the lot. The latitude and longitude ranges are a cheap first cut before the
	 * distance itself, which uses the spherical law of cosines, the same formula Geolocation Plus
	 * uses for service areas (components/class-hpgp-service-area.php, get_travelling_ids()). The
	 * longitude range is skipped when it would wrap past 180 degrees, where it could not be written as
	 * one BETWEEN; the distance test alone then decides.
	 *
	 * @param string $post_type Post type, hp_listing or hp_vendor.
	 * @param string $lat_key Latitude meta key.
	 * @param string $lng_key Longitude meta key.
	 * @param float  $latitude Latitude of the place searched for.
	 * @param float  $longitude Longitude of the place searched for.
	 * @param float  $radius Radius in kilometres.
	 * @return array<int, int> Post ID => parent ID (a Listing's Vendor; 0 for a Vendor).
	 */
	protected function get_nearby_posts( $post_type, $lat_key, $lng_key, $latitude, $longitude, $radius ) {
		global $wpdb;

		$lat_delta = $radius / 110.574;
		$lng_delta = $radius / max( 0.01, 111.320 * cos( deg2rad( $latitude ) ) );
		$lng_range = ( $longitude - $lng_delta ) >= -180 && ( $longitude + $lng_delta ) <= 180 ? 1 : 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a per-search distance test no WordPress API can express, and nothing to reuse across searches.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_parent FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} la ON la.post_id = p.ID AND la.meta_key = %s
				INNER JOIN {$wpdb->postmeta} lo ON lo.post_id = p.ID AND lo.meta_key = %s
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND la.meta_value <> '' AND lo.meta_value <> ''
				AND CAST(la.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f
				AND ( 0 = %d OR CAST(lo.meta_value AS DECIMAL(10,6)) BETWEEN %f AND %f )
				AND 6371 * ACOS( LEAST( 1, GREATEST( -1,
					COS( RADIANS( %f ) ) * COS( RADIANS( la.meta_value ) ) * COS( RADIANS( lo.meta_value ) - RADIANS( %f ) )
					+ SIN( RADIANS( %f ) ) * SIN( RADIANS( la.meta_value ) )
				) ) ) <= %f",
				$lat_key,
				$lng_key,
				$post_type,
				$latitude - $lat_delta,
				$latitude + $lat_delta,
				$lng_range,
				$longitude - $lng_delta,
				$longitude + $lng_delta,
				$latitude,
				$longitude,
				$latitude,
				$radius
			)
		);

		$found = [];

		foreach ( (array) $rows as $row ) {
			$found[ absint( $row->ID ) ] = absint( $row->post_parent );
		}

		return $found;
	}

	/**
	 * Checks whether Geolocation Plus for HivePress is active, with the Geolocation extension it
	 * builds on. It needs that extension (its own admin notice says so, geolocation-plus-for-hivepress
	 * .php), so it never stands in for it: with both active, the wall's place box is still the
	 * extension's Location field, which Geolocation Plus's script takes over with its own provider and
	 * suggestion list (assets/js/common.js, hivepress.initGeolocation()), and this plugin adds the
	 * matches only Geolocation Plus knows about (get_geolocation_plus_matches()).
	 *
	 * @return bool
	 */
	public function is_geolocation_plus_active() {
		return hivepress()->get_version( 'geolocation' ) && hivepress()->get_version( 'geolocation_plus_for_hivepress' );
	}

	/**
	 * Gets the Listings and Vendors that Geolocation Plus places within a radius search, beyond the
	 * built-in coordinates.
	 *
	 * Two sources, both read from Geolocation Plus 1.3.1:
	 *
	 * 1. Service areas. A Vendor who travels (a Service Radius on their profile, post meta
	 *    `hp_hpgp_service_radius`) is found from anywhere inside their radius, which is how its own
	 *    Listing search behaves. Geolocation Plus widens that search from a `posts_where` filter
	 *    (components/class-hpgp-service-area.php, widen_location_filter()), which the wall's own
	 *    queries never reach, so the same public get_travelling_ids() it uses is called here instead:
	 *    the travelling Listings match directly (it already honours the owner's choice of which
	 *    Listings travel), and the travelling Vendors match for their all-Listings posts.
	 * 2. Its custom Location attributes on Vendors and Listings, such as a "Studio Address". Each
	 *    stores its coordinates in two more attributes named `{name}_latitude` and `{name}_longitude`
	 *    (components/class-hpgp-geolocation.php, add_location_attributes()), saved as post meta with
	 *    core's `hp_` prefix, and measured the same way as the built-in ones.
	 *
	 * @param float $latitude Latitude of the place searched for.
	 * @param float $longitude Longitude of the place searched for.
	 * @param float $radius Radius in kilometres.
	 * @return array{listings: array<int, int>, vendors: int[]}
	 */
	protected function get_geolocation_plus_matches( $latitude, $longitude, $radius ) {
		$matches = [
			'listings' => [],
			'vendors'  => [],
		];

		if ( ! $this->is_geolocation_plus_active() ) {
			return $matches;
		}

		// 1. Service areas.
		$area = hivepress()->hpgp_service_area;

		if ( $area instanceof \HivePress\Components\Hpgp_Service_Area && $area->is_enabled() ) {
			$listing_ids = array_filter( array_map( 'absint', (array) $area->get_travelling_ids( 'listing', $latitude, $longitude ) ) );

			if ( $listing_ids ) {
				$matches['listings'] += $this->get_post_parents( 'hp_listing', [ 'post__in' => $listing_ids ] );
			}

			$matches['vendors'] = array_map( 'absint', (array) $area->get_travelling_ids( 'vendor', $latitude, $longitude ) );
		}

		// 2. Custom Location attributes.
		foreach ( [
			'listing' => 'hp_listing',
			'vendor'  => 'hp_vendor',
		] as $model => $post_type ) {
			foreach ( (array) hivepress()->attribute->get_attributes( $model ) as $name => $attribute ) {
				if ( 'hpgp_location' !== hp\get_array_value( (array) hp\get_array_value( $attribute, 'edit_field', [] ), 'type' ) ) {
					continue;
				}

				$found = $this->get_nearby_posts( $post_type, 'hp_' . $name . '_latitude', 'hp_' . $name . '_longitude', $latitude, $longitude, $radius );

				if ( 'hp_listing' === $post_type ) {
					$matches['listings'] += $found;
				} else {
					$matches['vendors'] = array_merge( $matches['vendors'], array_keys( $found ) );
				}
			}
		}

		return $matches;
	}

	/**
	 * Checks whether the wall filter shows its distance box. On until the owner unticks it.
	 *
	 * @return bool
	 */
	public function is_radius_field_shown() {
		return (bool) hpsw_get_option( 'hpsw_show_radius', '1' );
	}

	/**
	 * Gets the radius the wall searches within when a visitor has not chosen one, in the site's
	 * distance unit (Geolocation's miles setting).
	 *
	 * The wall's own "Default Search Radius" when the owner set one. Left empty, it is what the wall
	 * has always used: Geolocation's own default radius (`hp_geolocation_radius`), or 15 if even that
	 * is missing. A cleared number field is stored as an empty string, which hpsw_get_number_option()
	 * already treats as "use the fallback".
	 *
	 * @return int
	 */
	public function get_default_radius() {
		$fallback = absint( get_option( 'hp_geolocation_radius', 15 ) );
		$fallback = $fallback ? $fallback : 15;
		$radius   = hpsw_get_number_option( 'hpsw_default_radius', $fallback );

		return $radius >= 1 ? $radius : $fallback;
	}

	/**
	 * Converts a submitted radius into kilometres.
	 *
	 * Honours Geolocation's miles setting and its maximum, the same numbers the extension's own search
	 * uses (components/class-geolocation.php:133-137, :680-690). With no usable radius, and always
	 * when the owner has hidden the distance box, the wall's default applies (get_default_radius()),
	 * so a filter address with no radius in it behaves exactly like one from a wall with no box.
	 *
	 * @param mixed $radius Submitted radius.
	 * @return float
	 */
	public function get_radius_km( $radius ) {
		$max    = absint( get_option( 'hp_geolocation_max_radius', 100 ) );
		$radius = $this->is_radius_field_shown() && is_numeric( $radius ) ? absint( $radius ) : 0;

		if ( $radius < 1 || ( $max && $radius > $max ) ) {
			$radius = $this->get_default_radius();
		}

		if ( get_option( 'hp_geolocation_use_miles' ) ) {
			return $radius * 1.60934;
		}

		return (float) $radius;
	}

	/**
	 * Gets the Vendors of the published Listings matching some query arguments.
	 *
	 * @param array $args Extra WP_Query arguments.
	 * @return int[]
	 */
	protected function get_listing_vendor_ids( $args ) {
		return array_values( array_unique( array_filter( $this->get_post_parents( 'hp_listing', $args ) ) ) );
	}

	/**
	 * Gets the published posts of one type matching some query arguments, with their parents.
	 *
	 * WP_Query's `id=>parent` field returns each post's parent in the same query, as an array of
	 * parent IDs keyed by post ID, not post objects (wp-includes/class-wp-query.php, get_posts()), so
	 * a Listing's Vendor (its `post_parent`, hivepress/includes/models/class-listing.php) never costs
	 * a query per Listing.
	 *
	 * @param string $post_type Post type.
	 * @param array  $args Extra WP_Query arguments.
	 * @return array<int, int> Post ID => parent ID.
	 */
	protected function get_post_parents( $post_type, $args ) {
		$posts = get_posts(
			array_merge(
				[
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'id=>parent',
					'no_found_rows'  => true,
				],
				$args
			)
		);

		$parents = [];

		foreach ( $posts as $post_id => $parent_id ) {
			$parents[ absint( $post_id ) ] = absint( $parent_id );
		}

		return $parents;
	}

	/**
	 * Counts likes and comments for a set of posts, and which of them the current user has liked.
	 *
	 * One grouped query for the page rather than two per card. Only approved rows count, so a
	 * comment an administrator has unapproved drops out of the number at once.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return array<int, array{likes: int, comments: int, liked: bool}>
	 */
	public function get_engagement( $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
		$result   = [];

		foreach ( $post_ids as $post_id ) {
			$result[ $post_id ] = [
				'likes'    => 0,
				'comments' => 0,
				'liked'    => false,
			];
		}

		if ( ! $post_ids ) {
			return $result;
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a grouped count no WordPress API can express. The only interpolation is the list of %d placeholders built above; every value is passed to prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_post_ID AS post_id, comment_type AS type, COUNT(*) AS total FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders) AND comment_type IN (%s, %s) AND comment_approved = '1' GROUP BY comment_post_ID, comment_type",
				array_merge( $post_ids, [ self::LIKE_TYPE, self::COMMENT_TYPE ] )
			)
		);

		$liked = [];

		if ( is_user_logged_in() ) {
			$liked = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT comment_post_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($placeholders) AND comment_type = %s AND user_id = %d",
					array_merge( $post_ids, [ self::LIKE_TYPE, get_current_user_id() ] )
				)
			);
		}
		// phpcs:enable

		foreach ( (array) $rows as $row ) {
			$post_id = absint( $row->post_id );

			if ( isset( $result[ $post_id ] ) ) {
				$result[ $post_id ][ self::LIKE_TYPE === $row->type ? 'likes' : 'comments' ] = absint( $row->total );
			}
		}

		foreach ( (array) $liked as $post_id ) {
			if ( isset( $result[ absint( $post_id ) ] ) ) {
				$result[ absint( $post_id ) ]['liked'] = true;
			}
		}

		return $result;
	}

	/**
	 * Renders wall posts as a grid of cards.
	 *
	 * Each card is the `hpsw_post_view_block` template, built exactly the way core's Listings block
	 * renders `listing_view_block` (blocks/class-listings.php), so themes can restyle the card and
	 * an owner can override any part from their theme's `hivepress/hpsw-post/` folder.
	 *
	 * The owner view (the account Wall page) shows every post the caller passes, whatever its status,
	 * so the caller must pass only posts the viewer owns; each card then carries the owner's controls
	 * (the `hpsw-post-owner` part), and the grid steps from one column on a phone to two from 48em and
	 * three from 64em, core's `sm` and `md` breakpoints (hivepress/assets/css/grid.min.css).
	 *
	 * @param int[] $post_ids Post IDs.
	 * @param int   $columns Columns on wide screens; the owner view always uses two.
	 * @param bool  $owner_view Whether the cards are drawn for their owner.
	 * @return string
	 */
	public function render_posts( $post_ids, $columns = 1, $owner_view = false ) {
		$engagement = $this->get_engagement( $post_ids );

		// Every card asks whether its Deal has ended; answered here for all of them at once.
		$this->load_ended_times( $post_ids );
		$width      = hp\get_column_width( max( 1, min( 3, absint( $columns ) ) ) );
		$item_class = $owner_view ? 'hp-col-sm-6 hp-col-xs-12' : 'hp-col-sm-' . $width . ' hp-col-xs-12';

		$output  = '<div class="hp-listings hpsw-posts hp-block hp-grid' . ( $owner_view ? ' hpsw-posts--owner' : '' ) . '">';
		$output .= '<div class="hp-row">';

		foreach ( $post_ids as $post_id ) {
			$post = Models\Hpsw_Post::query()->get_by_id( $post_id );

			if ( $owner_view ? ! $post instanceof Models\Hpsw_Post : ! $this->is_post_visible( $post ) ) {
				continue;
			}

			$output .= '<div class="hp-grid__item ' . esc_attr( $item_class ) . '">';

			// `hpsw_card` keeps the end date out of the Deal box, since the card's footer shows it.
			$output .= ( new Blocks\Template(
				[
					'template' => 'hpsw_post_view_block',

					'context'  => [
						'hpsw_post'       => $post,
						'hpsw_engagement' => isset( $engagement[ $post_id ] ) ? $engagement[ $post_id ] : [],
						'hpsw_card'       => true,
						'hpsw_owner_view' => (bool) $owner_view,
					],
				]
			) )->render();

			$output .= '</div>';
		}

		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Renders page numbers in core's own pagination markup.
	 *
	 * The wall has its own page parameter because a Vendor page already paginates its Listings with
	 * `paged`: sharing it would move both lists at once. Markup copied from core's pagination part
	 * (templates/page/pagination.php), and `plain` type on purpose: `list` wraps each number in an
	 * `<li>` that no theme resets inside `.hp-pagination` (resources/hivepress-ui.md, "Pagination").
	 *
	 * @param int    $current Current page.
	 * @param int    $pages Total pages.
	 * @param string $anchor Fragment to land on.
	 * @return string
	 */
	public function render_pagination( $current, $pages, $anchor = '' ) {
		if ( $pages < 2 ) {
			return '';
		}

		$base = remove_query_arg( 'hpsw_page' );

		$links = paginate_links(
			[
				'base'         => add_query_arg( 'hpsw_page', '%#%', $base ),
				'format'       => '',
				'current'      => max( 1, absint( $current ) ),
				'total'        => absint( $pages ),
				'type'         => 'plain',
				'add_args'     => false,
				'add_fragment' => $anchor ? '#' . $anchor : '',
				'prev_text'    => '<i class="hp-icon fas fa-chevron-left"></i><span class="screen-reader-text">' . esc_html__( 'Previous page', 'social-walls-for-hivepress' ) . '</span>',
				'next_text'    => '<span class="screen-reader-text">' . esc_html__( 'Next page', 'social-walls-for-hivepress' ) . '</span><i class="hp-icon fas fa-chevron-right"></i>',
			]
		);

		if ( ! $links ) {
			return '';
		}

		$output  = '<div class="hp-pagination hpsw-pagination">';
		$output .= '<nav class="navigation pagination" aria-label="' . esc_attr__( 'Wall posts', 'social-walls-for-hivepress' ) . '">';
		$output .= '<h2 class="screen-reader-text">' . esc_html__( 'Wall posts navigation', 'social-walls-for-hivepress' ) . '</h2>';
		$output .= '<div class="nav-links">' . wp_kses_post( $links ) . '</div>';
		$output .= '</nav>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Reads the wall's page number from the address.
	 *
	 * @return int
	 */
	public function get_page_number() {
		// A page number in a public address: read-only, no state changes.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['hpsw_page'] ) ? max( 1, absint( wp_unslash( $_GET['hpsw_page'] ) ) ) : 1;
	}

	/*
	|--------------------------------------------------------------------------
	| Assets
	|--------------------------------------------------------------------------
	*/

	/**
	 * Registers the front-end assets, and enqueues them where a wall can appear.
	 *
	 * Not on every page: only this plugin's own pages, Vendor pages, Listing pages while they show a
	 * wall, and any page whose content
	 * carries the Social Wall block or its shortcode. A wall placed somewhere else (a widget, a
	 * theme template) still gets them, because the block enqueues them itself as it renders, which
	 * WordPress then prints in the footer.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		$path = plugin_dir_path( HPSW_FILE );
		$url  = plugin_dir_url( HPSW_FILE );

		wp_register_style(
			'hpsw-frontend',
			$url . 'assets/css/frontend.css',
			[],
			HPSW_VERSION . '.' . (int) filemtime( $path . 'assets/css/frontend.css' )
		);

		wp_register_script(
			'hpsw-frontend',
			$url . 'assets/js/frontend.js',
			[ 'hivepress-core' ],
			HPSW_VERSION . '.' . (int) filemtime( $path . 'assets/js/frontend.js' ),
			true
		);

		// Registered here, enqueued only by render_share() on a page that prints a Share button. It has
		// no dependencies: it opens the pop-up through core's fancybox only when somebody clicks, by
		// which time the page's scripts have all run. The QR library it fetches on first use is not
		// registered at all, so it never loads with a page.
		wp_register_script(
			'hpsw-share',
			$url . 'assets/js/share.js',
			[],
			HPSW_VERSION . '.' . (int) filemtime( $path . 'assets/js/share.js' ),
			true
		);

		// The owner's coupon code colour (Settings, Social Walls, Display). Empty keeps the theme's own
		// `code` colour, which is what every earlier version showed. sanitize_hex_color() is the same
		// check core's Color field saves with (hivepress/includes/fields/class-color.php), so a value
		// that reaches the stylesheet is always a plain hex colour.
		$coupon_color = sanitize_hex_color( (string) get_option( 'hp_hpsw_coupon_color' ) );

		if ( $coupon_color ) {
			wp_add_inline_style( 'hpsw-frontend', '.hpsw-post .hpsw-post__coupon .hpsw-post__coupon-code{color:' . $coupon_color . '}' );
		}

		wp_localize_script(
			'hpsw-frontend',
			'hpswFrontendData',
			[
				'copied'        => esc_html__( 'Copied', 'social-walls-for-hivepress' ),
				'myLocation'    => esc_html__( 'My location', 'social-walls-for-hivepress' ),
				'likeFailed'    => esc_html__( 'Your like could not be saved. Please try again.', 'social-walls-for-hivepress' ),
				'deleteConfirm' => esc_html__( 'Delete this comment? Its replies go with it.', 'social-walls-for-hivepress' ),
				'deleteFailed'  => esc_html__( 'The comment could not be deleted.', 'social-walls-for-hivepress' ),
			]
		);

		$route = (string) hivepress()->router->get_current_route_name();
		$load  = 0 === strpos( $route, 'hpsw_' ) || 'vendor_view_page' === $route || ( 'listing_view_page' === $route && 'hidden' !== $this->get_listing_position() );

		if ( ! $load && is_singular() ) {
			$content = (string) get_post_field( 'post_content', get_queried_object_id() );

			$load = has_block( 'hivepress/hpsw-wall', $content ) || has_shortcode( $content, 'hivepress_hpsw_wall' );
		}

		if ( $load ) {
			$this->load_assets();
		}
	}

	/**
	 * Enqueues the front-end assets.
	 *
	 * @return void
	 */
	public function load_assets() {
		if ( wp_style_is( 'hpsw-frontend', 'registered' ) ) {
			wp_enqueue_style( 'hpsw-frontend' );
		}

		if ( wp_script_is( 'hpsw-frontend', 'registered' ) ) {
			wp_enqueue_script( 'hpsw-frontend' );
		}
	}
}
