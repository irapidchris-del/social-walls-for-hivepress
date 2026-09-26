<?php
/**
 * Post types configuration.
 *
 * Merged into the HivePress `post_types` config. The `hpsw_post` key is prefixed by core, which
 * registers the `hp_hpsw_post` post type: 12 characters, well inside WordPress's 20-character
 * limit on post type names.
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'hpsw_post' => [

		/*
		 * Not public: a wall post is only ever shown through this plugin's own pages and blocks, which
		 * check the status, the expiry date and the Vendor themselves. A public post type would also
		 * answer at WordPress's own single-post address, bypassing every one of those checks.
		 */
		'public'           => false,
		'show_ui'          => true,

		// Listed under Vendors, beside the Vendors themselves, because every wall post belongs to one.
		'show_in_menu'     => 'edit.php?post_type=hp_vendor',
		'delete_with_user' => false,

		/*
		 * No `author` support, matching how core declares hp_listing and hp_vendor
		 * (hivepress/includes/configs/post-types.php:60, :86). The Vendor field in the Wall Post
		 * meta box is the one control over who owns a post, and the component keeps the post author
		 * in step with it on save, which is the rule core applies to a Listing.
		 */
		'supports'         => [ 'title', 'editor' ],

		'labels'           => [
			'name'               => esc_html__( 'Wall Posts', 'social-walls-for-hivepress' ),
			'singular_name'      => esc_html__( 'Wall Post', 'social-walls-for-hivepress' ),
			'add_new'            => esc_html_x( 'Add New', 'wall post', 'social-walls-for-hivepress' ),
			'add_new_item'       => esc_html__( 'Add Wall Post', 'social-walls-for-hivepress' ),
			'edit_item'          => esc_html__( 'Edit Wall Post', 'social-walls-for-hivepress' ),
			'new_item'           => esc_html__( 'Add Wall Post', 'social-walls-for-hivepress' ),
			'all_items'          => esc_html__( 'Wall Posts', 'social-walls-for-hivepress' ),
			'search_items'       => esc_html__( 'Search Wall Posts', 'social-walls-for-hivepress' ),
			'not_found'          => esc_html__( 'No wall posts found.', 'social-walls-for-hivepress' ),
			'not_found_in_trash' => esc_html__( 'No wall posts found.', 'social-walls-for-hivepress' ),
		],
	],
];
