<?php
/**
 * Post update form.
 *
 * @package Social_Walls
 */

namespace HivePress\Forms;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Creates or edits a wall post.
 *
 * One form for both, like core's Listing_Update: the new-post page opens it on an auto-draft, so
 * photos can be uploaded before the post is submitted, and the button wording says which it is.
 *
 * The same class is rebuilt inside the REST route that saves it, so the Listing choices are built
 * from the POST'S Vendor rather than from the request: the route has no page context, and building
 * them from the current user would let an administrator's own Listings leak into a Vendor's post.
 */
class Hpsw_Post_Update extends Model_Form {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'model' => 'hpsw_post',
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Form arguments.
	 */
	public function __construct( $args = [] ) {
		$post   = hp\get_array_value( $args, 'model' );
		$is_new = $post instanceof Models\Hpsw_Post && 'auto-draft' === $post->get_status();

		$button = esc_html__( 'Save Changes', 'social-walls-for-hivepress' );

		if ( $is_new ) {
			$button = function_exists( 'hivepress' ) && hivepress()->hpsw_wall && hivepress()->hpsw_wall->is_moderation_enabled() && ! current_user_can( 'edit_others_posts' ) ? esc_html__( 'Submit for Approval', 'social-walls-for-hivepress' ) : esc_html__( 'Publish Post', 'social-walls-for-hivepress' );
		}

		$fields = [
			'type'        => [
				'_order' => 10,
			],

			'images'      => [
				'_order' => 20,
			],

			'title'       => [
				'placeholder' => esc_html__( 'For example: 20% off this week', 'social-walls-for-hivepress' ),
				'_order'      => 30,
			],

			'text'        => [
				'placeholder' => esc_html__( 'What would you like to tell people?', 'social-walls-for-hivepress' ),
				'_order'      => 40,
			],

			'coupon'      => [
				'description' => trim( esc_html__( 'Shown on the Deal with a one-click copy button.', 'social-walls-for-hivepress' ) . ' ' . $this->get_coupon_hint() ),
				'attributes'  => [ 'data-hpsw-deal' => 'true' ],
				'_order'      => 50,
			],

			'expire_date' => [
				'description' => esc_html__( 'The Deal is shown until the end of this day, then disappears from every wall by itself.', 'social-walls-for-hivepress' ),
				'offset'      => 0,
				'attributes'  => [ 'data-hpsw-deal' => 'true' ],
				'_order'      => 60,
			],
		];

		// With Vendor coupons on in HivePress Marketplace, the code is picked from the Vendor's own
		// coupons instead of typed, so a Deal can only advertise a code the checkout will accept.
		$picker = $this->get_coupon_picker_field( $post );

		if ( $picker ) {
			unset( $fields['coupon'] );

			$fields['hpsw_coupon_id'] = $picker;
		}

		// The Vendor's own published Listings, for a Deal to link to.
		$listings = $this->get_listing_options( $post );

		if ( $listings ) {
			$fields['listing'] = [
				'description' => esc_html__( 'Leave as All my Listings if the Deal covers everything you offer, or choose the one Listing it is for.', 'social-walls-for-hivepress' ),
				'type'        => 'select',
				'options'     => $listings,
				'placeholder' => esc_html__( 'All my Listings', 'social-walls-for-hivepress' ),
				'attributes'  => [ 'data-hpsw-deal' => 'true' ],
				'_order'      => 70,
			];
		}

		$args = hp\merge_arrays(
			[
				'redirect' => function_exists( 'hivepress' ) ? hivepress()->router->get_url( 'hpsw_wall_edit_page' ) : true,
				'fields'   => $fields,

				'button'   => [
					'label' => $button,
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Bootstraps form properties.
	 */
	protected function boot() {
		if ( $this->model->get_id() ) {
			$this->action = hivepress()->router->get_url(
				'hpsw_post_update_action',
				[
					'hpsw_post_id' => $this->model->get_id(),
				]
			);
		}

		parent::boot();
	}

	/**
	 * Gets the hint under the coupon code field: where the code has to exist for it to work.
	 *
	 * A Deal only SHOWS its code. Nothing here validates it; a client redeems it at the WooCommerce
	 * checkout, which only accepts a code that exists as a real coupon. So the hint depends on who
	 * can create one, read from HivePress Marketplace 1.4.0:
	 *
	 * - Marketplace active with Vendor coupons on (option `hp_vendor_allow_coupons`, Settings >
	 *   Vendors > Selling, "Allow sellers to create and manage coupons", includes/configs/settings.php
	 *   :139): the Vendor creates it themselves on the Coupons page of their account, route
	 *   `coupons_edit_page` (includes/controllers/class-coupon.php:59, based on `vendor_account_page`,
	 *   so /account/vendor/coupons/ on default permalinks). The link is built by the router, so it
	 *   follows each site's permalinks, and that route's own redirect refuses the page when the option
	 *   is off (:210-215), which is why the link is gated on the same option.
	 * - WooCommerce active otherwise: only the site owner can create coupons, so a plain hint.
	 * - No WooCommerce: there is no checkout for a code to work at, so no hint at all. A Vendor may
	 *   still want a code clients quote to them directly, which the field keeps allowing.
	 *
	 * @return string Hint HTML (a link at most), or an empty string.
	 */
	protected function get_coupon_hint() {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}

		if ( hivepress()->get_version( 'marketplace' ) && get_option( 'hp_vendor_allow_coupons' ) ) {
			$url = (string) hivepress()->router->get_url( 'coupons_edit_page' );

			if ( '' !== $url ) {
				return sprintf(
					/* translators: %s: link to the Coupons page of the Vendor's account, labelled "Coupons". */
					esc_html__( 'Create this code under %s in your account first, so it works at checkout.', 'social-walls-for-hivepress' ),
					'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Coupons', 'social-walls-for-hivepress' ) . '</a>'
				);
			}
		}

		return esc_html__( 'The code only works at checkout if this site has a coupon with the same code.', 'social-walls-for-hivepress' );
	}

	/**
	 * Gets the coupon picker that replaces the typed code, when coupons come from Marketplace.
	 *
	 * Not a model field: the form offers coupon IDs, and the update route turns the chosen one back
	 * into its code after checking it belongs to the post's own Vendor
	 * (Hpsw_Wall::resolve_coupon_choice()), so the post goes on storing the code exactly as before
	 * and every existing Deal keeps showing its own. IDs rather than codes as the choices, because a
	 * Select sanitises a numeric value with absint() (fields/class-select.php, sanitize()), which
	 * would turn a code such as 00125000 into 125000.
	 *
	 * A Deal whose code is not among the Vendor's current coupons (typed before this existed, or its
	 * coupon has since expired) keeps it as a "keep" choice, selected, so saving the Deal for any
	 * other reason never strips its code. With no coupons and no code, the row is only the hint.
	 *
	 * @param mixed $post Wall post.
	 * @return array|null Field arguments, or null to keep the typed code box.
	 */
	protected function get_coupon_picker_field( $post ) {
		$wall = function_exists( 'hivepress' ) ? hivepress()->hpsw_wall : null;

		if ( ! $wall || ! $wall->is_coupon_picker_on() || ! $post instanceof Models\Hpsw_Post || ! $post->get_vendor__id() ) {
			return null;
		}

		$label   = esc_html__( 'Coupon Code', 'social-walls-for-hivepress' );
		$coupons = $wall->get_vendor_coupons( $post->get_vendor__id() );
		$current = trim( (string) $post->get_coupon() );
		$options = [];
		$chosen  = '';

		foreach ( $coupons as $coupon_id => $coupon ) {
			$options[ $coupon_id ] = $coupon['label'];

			if ( '' !== $current && 0 === strcasecmp( $coupon['code'], $current ) ) {
				$chosen = $coupon_id;
			}
		}

		if ( '' !== $current && '' === $chosen ) {
			$options['keep'] = sprintf(
				/* translators: %s: coupon code. */
				esc_html__( '%s (the code this Deal already shows)', 'social-walls-for-hivepress' ),
				$current
			);

			$chosen = 'keep';
		}

		$url  = (string) hivepress()->router->get_url( 'coupons_edit_page' );
		$link = '' !== $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Coupons', 'social-walls-for-hivepress' ) . '</a>' : esc_html__( 'Coupons', 'social-walls-for-hivepress' );

		if ( ! $options ) {
			return [
				'label'       => $label,
				'description' => sprintf(
					/* translators: %s: link to the Coupons page of the Vendor's account, labelled "Coupons". */
					esc_html__( 'You have no coupons yet. Create one under %s in your account and it will appear here, ready to add to a Deal.', 'social-walls-for-hivepress' ),
					$link
				),
				'type'        => 'hpsw_note',
				'attributes'  => [ 'data-hpsw-deal' => 'true' ],
				'_order'      => 50,
			];
		}

		return [
			'label'       => $label,
			'description' => sprintf(
				/* translators: %s: link to the Coupons page of the Vendor's account, labelled "Coupons". */
				esc_html__( 'Shown on the Deal with a one-click copy button. Create a coupon under %s in your account and it will appear here.', 'social-walls-for-hivepress' ),
				$link
			),
			'type'        => 'select',
			'options'     => $options,
			'placeholder' => esc_html__( 'No coupon', 'social-walls-for-hivepress' ),
			'default'     => $chosen,
			'attributes'  => [ 'data-hpsw-deal' => 'true' ],
			'_order'      => 50,
		];
	}

	/**
	 * Gets the Listings a post's Deal may link to.
	 *
	 * @param mixed $post Wall post.
	 * @return array<int, string>
	 */
	protected function get_listing_options( $post ) {
		if ( ! $post instanceof Models\Hpsw_Post || ! $post->get_vendor__id() ) {
			return [];
		}

		$options = [];

		$listing_ids = get_posts(
			[
				'post_type'      => 'hp_listing',
				'post_status'    => 'publish',
				'post_parent'    => absint( $post->get_vendor__id() ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			]
		);

		foreach ( $listing_ids as $listing_id ) {
			$options[ absint( $listing_id ) ] = get_the_title( $listing_id );
		}

		// A Listing chosen earlier that has since been hidden stays selectable, so saving an old Deal
		// does not fail on a choice the Vendor never touched.
		$current = absint( $post->get_listing__id() );

		if ( $current && ! isset( $options[ $current ] ) && absint( wp_get_post_parent_id( $current ) ) === absint( $post->get_vendor__id() ) ) {
			$options[ $current ] = get_the_title( $current );
		}

		return $options;
	}
}
