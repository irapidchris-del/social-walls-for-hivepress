<?php
/**
 * Wall post model.
 *
 * @package Social_Walls
 */

namespace HivePress\Models;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A post on a Vendor's wall: a Deal or an Update.
 *
 * Stored as an `hp_hpsw_post` post owned the way core owns a Listing: `post_parent` is the Vendor
 * and `post_author` is the Vendor's user (hivepress/includes/models/class-listing.php, core 1.7.31).
 *
 * **Do not rename this class once any site has data.** Three things are derived from it and each
 * fails silently: the post type (`hp_` + the class name), the `hp_parent_model` meta core writes on
 * every uploaded image (resolved back into this class by the attachment endpoints), and the model
 * name that forms and REST routes address. A rename orphans every stored post and photo.
 *
 * Pinning uses the `menu_order` column rather than meta, so a wall sorts pinned posts first with a
 * plain ORDER BY and no meta join: 1 while pinned, 0 otherwise. `pinned_time` records when it ends.
 *
 * Every getter below is magic (Model::__call), so `method_exists()` is always false for them; they
 * are declared for static analysis only.
 *
 * @method int|null get_id()
 * @method string|null get_title()
 * @method string|null get_text()
 * @method string|null get_type()
 * @method string|null get_coupon()
 * @method string|null get_expire_date()
 * @method int|null get_listing__id()
 * @method int|null get_pinned()
 * @method int|null get_pinned_time()
 * @method string|null get_status()
 * @method string|null get_created_date()
 * @method string|null get_created_date_gmt()
 * @method string|null get_modified_date()
 * @method int|null get_user__id()
 * @method int|null get_vendor__id()
 * @method \HivePress\Models\Vendor|null get_vendor()
 * @method \HivePress\Models\Listing|null get_listing()
 * @method array get_images()
 * @method array|null get_images__id()
 * @method $this set_images( array $ids )
 * @method $this set_status( string $status )
 * @method $this set_pinned( int $pinned )
 * @method $this set_pinned_time( int|null $time )
 * @method static \HivePress\Queries\Post query()
 */
class Hpsw_Post extends Post {

	/**
	 * Class constructor.
	 *
	 * @param array $args Model arguments.
	 */
	public function __construct( $args = [] ) {
		$max_images = 4;

		if ( function_exists( 'hpsw_get_number_option' ) ) {
			$max_images = max( 1, min( 10, hpsw_get_number_option( 'hpsw_max_images', 4 ) ) );
		}

		$args = hp\merge_arrays(
			[
				'fields' => [
					'type'             => [
						'label'     => esc_html__( 'Post Type', 'social-walls-for-hivepress' ),
						'type'      => 'radio',
						'required'  => true,
						'default'   => 'update',

						'options'   => [
							'update' => esc_html__( 'Update', 'social-walls-for-hivepress' ),
							'deal'   => esc_html__( 'Deal', 'social-walls-for-hivepress' ),
						],

						'_external' => true,
					],

					'title'            => [
						'label'      => esc_html__( 'Headline', 'social-walls-for-hivepress' ),
						'type'       => 'text',
						'max_length' => 120,
						'_alias'     => 'post_title',
					],

					'text'             => [
						'label'      => esc_html__( 'Text', 'social-walls-for-hivepress' ),
						'type'       => 'textarea',
						'max_length' => 2000,
						'required'   => true,

						// Plain text only. Stripped at save, and still escaped on output.
						'html'       => false,
						'_alias'     => 'post_content',
					],

					'coupon'           => [
						'label'      => esc_html__( 'Coupon Code', 'social-walls-for-hivepress' ),
						'type'       => 'text',
						'max_length' => 64,
						'_external'  => true,
					],

					'expire_date'      => [
						'label'     => esc_html__( 'Ends On', 'social-walls-for-hivepress' ),
						'type'      => 'date',
						'format'    => 'Y-m-d',
						'_external' => true,
					],

					// "Applies to", not "Listing": the choice is optional, and an empty one means the Deal
					// covers everything the Vendor offers, which a bare "Listing: None" read as the opposite.
					'listing'          => [
						'label'     => esc_html__( 'Applies to', 'social-walls-for-hivepress' ),
						'type'      => 'id',
						'_model'    => 'listing',
						'_external' => true,
					],

					'pinned'           => [
						'type'      => 'number',
						'min_value' => 0,
						'max_value' => 1,
						'_alias'    => 'menu_order',
					],

					'pinned_time'      => [
						'type'      => 'number',
						'min_value' => 0,
						'_external' => true,
					],

					'status'           => [
						'type'    => 'select',
						'_alias'  => 'post_status',

						'options' => [
							'publish'    => esc_html_x( 'Published', 'wall post', 'social-walls-for-hivepress' ),
							'future'     => '',
							'draft'      => esc_html_x( 'Hidden', 'wall post', 'social-walls-for-hivepress' ),
							'pending'    => esc_html_x( 'Pending', 'wall post', 'social-walls-for-hivepress' ),
							'private'    => '',
							'trash'      => '',
							'auto-draft' => '',
							'inherit'    => '',
						],
					],

					'created_date'     => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'post_date',
					],

					'created_date_gmt' => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'post_date_gmt',
					],

					'modified_date'    => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'post_modified',
					],

					'user'             => [
						'type'     => 'id',
						'required' => true,
						'_alias'   => 'post_author',
						'_model'   => 'user',
					],

					'vendor'           => [
						'type'   => 'id',
						'_alias' => 'post_parent',
						'_model' => 'vendor',
					],

					/*
					 * Not required, and it cannot be: an upload field is written by core's own upload
					 * route, never by the form, so a required one fails validation on every whole-object
					 * save until something is attached (resources/hivepress-data.md, "A required upload
					 * field on a model blocks every whole-object save").
					 */
					'images'           => [
						'label'     => hivepress()->translator->get_string( 'images' ),
						'caption'   => hivepress()->translator->get_string( 'select_images' ),
						'type'      => 'attachment_upload',
						'multiple'  => true,
						'max_files' => $max_images,
						'formats'   => [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ],
						'_model'    => 'attachment',
						'_relation' => 'one_to_many',
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Gets image IDs.
	 *
	 * One-to-many relation fields are not populated when a model is read from the database, so this
	 * loads them lazily, mirroring the core Listing model (models/class-listing.php, get_images__id).
	 * `get_attached_media()` orders by `menu_order`, which keeps the drag-and-drop order set in the
	 * upload field. The IDs are cached per post in the attachment group, which core clears whenever
	 * an attachment of this post is added, changed or removed.
	 *
	 * @return array
	 */
	final public function get_images__id() {
		if ( ! isset( $this->values['images__id'] ) ) {
			$image_ids = hivepress()->cache->get_post_cache( $this->id, 'image_ids', 'models/attachment' );

			if ( is_null( $image_ids ) ) {
				$image_ids = [];

				foreach ( get_attached_media( 'image', $this->id ) as $image ) {
					if ( 'images' === $image->hp_parent_field ) {
						$image_ids[] = $image->ID;
					}
				}

				hivepress()->cache->set_post_cache( $this->id, 'image_ids', 'models/attachment', $image_ids );
			}

			$this->set_images( $image_ids );

			$this->values['images__id'] = $image_ids;
		}

		return $this->fields['images']->get_value();
	}

	/**
	 * Checks whether this post is a Deal.
	 *
	 * @return bool
	 */
	final public function is_deal() {
		return 'deal' === $this->get_type();
	}

	/**
	 * Checks whether this post is pinned right now.
	 *
	 * The column says "pinned" until the hourly pass clears it, so the end time is tested as well:
	 * a pin never shows its badge for a moment longer than was paid for.
	 *
	 * @return bool
	 */
	final public function is_pinned() {
		return 1 === (int) $this->get_pinned() && (int) $this->get_pinned_time() > time();
	}

	/**
	 * Checks whether a Deal's end date has passed.
	 *
	 * The date is inclusive: a Deal ending today is still shown today. Compared in the site's own
	 * timezone, because that is the calendar the Vendor typed the date against.
	 *
	 * @return bool
	 */
	final public function is_expired() {
		$date = (string) $this->get_expire_date();

		return '' !== $date && $date < current_time( 'Y-m-d' );
	}
}
