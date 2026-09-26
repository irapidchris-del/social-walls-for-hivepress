<?php
/**
 * Meta boxes configuration.
 *
 * The admin edit screen of a wall post. HivePress saves these only from the classic edit form
 * (`$_POST['action'] === 'editpost'`, hivepress/includes/components/class-admin.php:1253, core
 * 1.7.31), and a field without `_alias` is stored as `hp_{name}` post meta, the same key the model
 * reads, so the two can never disagree.
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpsw_max_images = function_exists( 'hpsw_get_number_option' ) ? max( 1, min( 10, hpsw_get_number_option( 'hpsw_max_images', 4 ) ) ) : 4;

return [
	'hpsw_post_images'   => [
		'title'  => hivepress()->translator->get_string( 'images' ),
		'screen' => 'hpsw_post',
		'model'  => 'hpsw_post',

		'fields' => [
			'images' => [
				'caption'   => hivepress()->translator->get_string( 'select_images' ),
				'type'      => 'attachment_upload',
				'multiple'  => true,
				'max_files' => $hpsw_max_images,
				'formats'   => [ 'jpg', 'jpeg', 'png', 'webp', 'gif' ],
				'_order'    => 10,
			],
		],
	],

	'hpsw_post_settings' => [

		// Named rather than borrowing core's bare "Settings", so it is clear what these belong to.
		'title'  => esc_html__( 'Wall Post Settings', 'social-walls-for-hivepress' ),
		'screen' => 'hpsw_post',
		'model'  => 'hpsw_post',

		'fields' => [
			'vendor'      => [
				'label'       => hivepress()->translator->get_string( 'vendor' ),
				'type'        => 'select',
				'options'     => 'posts',
				'option_args' => [ 'post_type' => 'hp_vendor' ],
				'source'      => hivepress()->router->get_url( 'vendors_resource' ),

				// Required, so the label does not read "Vendor (optional)": a post with no Vendor
				// appears on nobody's wall, and this is the only control over who owns it.
				'required'    => true,
				'_alias'      => 'post_parent',
				'_order'      => 10,
			],

			'type'        => [
				'label'    => esc_html__( 'Post Type', 'social-walls-for-hivepress' ),
				'type'     => 'radio',
				'required' => true,
				'default'  => 'update',
				'_order'   => 20,

				'options'  => [
					'update' => esc_html__( 'Update', 'social-walls-for-hivepress' ),
					'deal'   => esc_html__( 'Deal', 'social-walls-for-hivepress' ),
				],
			],

			'coupon'      => [
				'label'       => esc_html__( 'Coupon Code', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Deals only. Shown with a one-click copy button.', 'social-walls-for-hivepress' ),
				'type'        => 'text',
				'max_length'  => 64,
				'_order'      => 30,
			],

			'expire_date' => [
				'label'       => esc_html__( 'Ends On', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Deals only. The Deal is shown until the end of this day, then disappears from every wall by itself.', 'social-walls-for-hivepress' ),
				'type'        => 'date',
				'format'      => 'Y-m-d',
				'_order'      => 40,
			],

			'listing'     => [
				'label'       => esc_html__( 'Applies to', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Deals only. One of the Vendor\'s own Listings, linked from the Deal.', 'social-walls-for-hivepress' ),
				'type'        => 'select',
				'options'     => 'posts',
				'option_args' => [ 'post_type' => 'hp_listing' ],
				'source'      => hivepress()->router->get_url( 'listings_resource' ),
				'_order'      => 50,
			],

			/*
			 * A date, not the timestamp itself. The component turns it into the end of that day in
			 * the site's timezone after core has saved the box (Hpsw_Wall::save_admin_pin(), on
			 * save_post at 20, after core's update_meta_box() at 10), and writes the date back
			 * whenever a purchase pins or extends a post, so this box always shows the real end.
			 */
			'pinned_date' => [
				'label'       => esc_html__( 'Pinned Until', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Keeps this post at the top of every wall until the end of the chosen day. Leave empty to unpin it.', 'social-walls-for-hivepress' ),
				'type'        => 'date',
				'format'      => 'Y-m-d',
				'_order'      => 60,
			],
		],
	],
];
