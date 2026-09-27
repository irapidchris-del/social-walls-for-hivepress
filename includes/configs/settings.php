<?php
/**
 * Settings configuration.
 *
 * Merged into the HivePress settings screen as its own tab. Every field name starts with `hpsw_`,
 * so it is stored as `hp_hpsw_*` (HivePress prefixes settings with "hp_",
 * hivepress/includes/components/class-admin.php:297), which is the prefix uninstall.php sweeps and
 * the prefix the settings-screen chrome tests for to recognise this tab.
 *
 * Defaults that are ON are also seeded on activation (see hpsw_activate()), because HivePress only
 * seeds a `default` when HivePress itself is activated or updated. Every reader goes through
 * hpsw_get_option() and friends, which treat a never-saved option as its default.
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'social_walls' => [
		'title'    => esc_html__( 'Social Walls', 'social-walls-for-hivepress' ),
		'_order'   => 160,

		'sections' => [
			'posting'       => [
				'title'       => esc_html__( 'Posting', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Vendors add Deals and Updates to their wall from the Wall page in their account. Everything here is off or unlimited out of the box, so the wall works for free until you decide otherwise. If HivePress Memberships is active, each membership plan can also allow or refuse posting and set its own monthly allowance, on the plan\'s own edit screen under Restrictions (General). A Deal only shows its coupon code: for the code to work at checkout, HivePress Marketplace must be active with "Allow sellers to create and manage coupons" ticked under HivePress, Settings, Vendors, and the Vendor must create the same code under Coupons in their account.', 'social-walls-for-hivepress' ),
				'_order'      => 10,

				'fields'      => [
					'hpsw_enable_moderation' => [
						'label'       => esc_html__( 'Approval', 'social-walls-for-hivepress' ),
						'caption'     => esc_html__( 'Require approval before posts go live', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'New posts wait as Pending until you publish them under Vendors, Wall Posts, and so do changes to a post that is already live. The Vendor is emailed when a post is approved or rejected. Leave this unticked to let posts go live straight away.', 'social-walls-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],

					'hpsw_monthly_limit'     => [
						'label'       => esc_html__( 'Posts per Month (per Vendor)', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'The number of posts each Vendor may add in a calendar month. The count starts again on the 1st, and a deleted post still counts towards the month it was added in. Leave empty for no limit. A membership plan with its own allowance overrides this for the Vendors on it.', 'social-walls-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'_order'      => 20,
					],

					'hpsw_max_images'        => [
						'label'       => esc_html__( 'Photos per Post', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'The most photos a Vendor may add to one post, from 1 to 10. Photos are optional; a post can be text only.', 'social-walls-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 10,
						'default'     => 4,
						'_order'      => 30,
					],
				],
			],

			'display'       => [
				'title'       => esc_html__( 'Display', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Each Vendor\'s own posts appear on their profile page. To show posts from every Vendor, add the Social Wall block to any page in the block editor, or paste the shortcode [hivepress_hpsw_wall] into it. Deals disappear from every wall by themselves after their end date.', 'social-walls-for-hivepress' ),
				'_order'      => 20,

				'fields'      => [
					'hpsw_vendor_position' => [
						'label'       => esc_html__( 'Wall on Vendor Pages', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Where a Vendor\'s wall sits on their profile page, relative to their Listings. Choose Hidden to show walls only through the Social Wall block.', 'social-walls-for-hivepress' ),
						'type'        => 'radio',
						'default'     => 'below',
						'_order'      => 10,

						'options'     => [
							'below'  => esc_html__( 'Below the Listings', 'social-walls-for-hivepress' ),
							'above'  => esc_html__( 'Above the Listings', 'social-walls-for-hivepress' ),
							'hidden' => esc_html__( 'Hidden', 'social-walls-for-hivepress' ),
						],
					],

					'hpsw_vendor_columns'  => [
						'label'       => esc_html__( 'Columns on Vendor Pages', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'How many posts sit side by side on a Vendor\'s profile, like the columns of a Listings block. Two suits most themes, because the profile\'s main column is narrower than a full page. Phones always show one post per row. The Social Wall block has its own number in the block settings.', 'social-walls-for-hivepress' ),
						'type'        => 'radio',
						'default'     => '2',
						'_order'      => 15,

						'options'     => [
							'1' => esc_html__( 'One', 'social-walls-for-hivepress' ),
							'2' => esc_html__( 'Two', 'social-walls-for-hivepress' ),
							'3' => esc_html__( 'Three', 'social-walls-for-hivepress' ),
						],
					],

					'hpsw_per_page'        => [
						'label'       => esc_html__( 'Posts per Page', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'How many posts a Vendor\'s profile shows before the page numbers. The Social Wall block has its own number in the block settings.', 'social-walls-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 50,
						'default'     => 5,
						'_order'      => 20,
					],

					'hpsw_coupon_color'    => [
						'label'       => esc_html__( 'Coupon Code Colour', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'The colour of a Deal\'s coupon code, on the wall cards and on the post\'s own page. Leave it empty to keep your theme\'s own colour for codes. Choose one with strong contrast against your card background so the code stays easy to read.', 'social-walls-for-hivepress' ),
						'type'        => 'color',
						'_order'      => 25,
					],

					'hpsw_show_radius'     => [
						'label'       => esc_html__( 'Distance Field', 'social-walls-for-hivepress' ),
						'caption'     => esc_html__( 'Show the distance field on the wall filter', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'The box where visitors choose how far from a place to look, next to the location box. Untick it to keep the filter shorter: the wall then always looks within the default search radius below. Needs the HivePress Geolocation extension.', 'social-walls-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 30,
					],

					'hpsw_default_radius'  => [
						'label'       => get_option( 'hp_geolocation_use_miles' ) ? esc_html__( 'Default Search Radius (miles)', 'social-walls-for-hivepress' ) : esc_html__( 'Default Search Radius (km)', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'How far from the chosen place the wall looks when a visitor has not chosen a distance, or when the distance field is hidden. Leave it empty to use the default radius from the HivePress Geolocation settings.', 'social-walls-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 1000,
						'_order'      => 31,
					],
				],
			],

			'ended'         => [
				'title'       => esc_html__( 'Ended Deals', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'A Deal has ended once its end date has passed, or once the coupon it shows stops working at checkout: the coupon\'s own expiry date has passed, it has been used as many times as it allows, or it has been deleted or moved to the Bin. A Deal whose end date has passed already leaves the walls by itself. Choose here what else happens to ended Deals. Updates are never affected.', 'social-walls-for-hivepress' ),
				'_order'      => 25,

				'fields'      => [
					'hpsw_ended_deals' => [
						'label'       => esc_html__( 'Ended Deals', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Keep leaves every ended Deal in place, marked as Ended, with its coupon code hidden. Its own page still opens, and a Deal whose coupon stopped working stays on the walls. Hide takes ended Deals off every wall and profile straight away, and their pages stop opening for visitors; the Vendor still sees them, marked as Ended, on the Wall page in their account. Move to the Bin hides them the same way, then moves them to the Bin once a day, which also removes them from the Vendor\'s Wall page. An administrator can restore a binned post under Vendors, Wall Posts, Bin, until WordPress empties the Bin by itself (after 30 days, unless your site is set up differently).', 'social-walls-for-hivepress' ),
						'type'        => 'radio',
						'default'     => 'keep',
						'_order'      => 10,

						'options'     => [
							'keep'  => esc_html__( 'Keep and show as Ended', 'social-walls-for-hivepress' ),
							'hide'  => esc_html__( 'Hide from walls', 'social-walls-for-hivepress' ),
							'trash' => esc_html__( 'Move to the Bin', 'social-walls-for-hivepress' ),
						],
					],

					'hpsw_ended_grace' => [
						'label'       => esc_html__( 'Grace Period (days)', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'How many days an ended Deal stays as it is before it is hidden or moved to the Bin. Leave it at 0 to act as soon as a Deal ends. When a coupon is used up, the days count from when the plugin first notices, which can be up to a day later. Has no effect while ended Deals are kept.', 'social-walls-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 0,
						'max_value'   => 365,
						'default'     => 0,
						'_order'      => 20,
					],
				],
			],

			'engagement'    => [
				'title'       => esc_html__( 'Comments and Likes', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Signed-in visitors can like a post with the heart, and comment on it from the post\'s own page. Vendors are emailed about new comments on their posts, and commenters about replies. Comments can be unapproved or deleted under Comments in this dashboard.', 'social-walls-for-hivepress' ),
				'_order'      => 30,

				'fields'      => [
					'hpsw_comment_access' => [
						'label'       => esc_html__( 'Who Can Comment', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Anyone signed in lets every member comment. Vendors only limits comments to people with a published Vendor profile. Nobody hides comments everywhere, including ones already written, and brings them back if you change your mind.', 'social-walls-for-hivepress' ),
						'type'        => 'radio',
						'default'     => 'users',
						'_order'      => 10,

						'options'     => [
							'users'   => esc_html__( 'Anyone signed in', 'social-walls-for-hivepress' ),
							'vendors' => esc_html__( 'Vendors only', 'social-walls-for-hivepress' ),
							'none'    => esc_html__( 'Nobody', 'social-walls-for-hivepress' ),
						],
					],

					'hpsw_enable_likes'   => [
						'label'       => esc_html__( 'Likes', 'social-walls-for-hivepress' ),
						'caption'     => esc_html__( 'Show the heart and let members like posts', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Untick to hide the heart and its count everywhere. Likes already given are kept, and return if you tick this again.', 'social-walls-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 20,
					],
				],
			],

			'pinning'       => [
				'title'       => esc_html__( 'Pinned Posts', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'A pinned post sits at the top of every wall it appears on, above newer posts, until its time runs out. To sell pinning, create a WooCommerce product for it (a virtual product, hidden from the shop catalogue) and choose it below. Vendors then see a "Pin to the top" button beside each live post on their Wall page and go straight to the checkout. You can also pin any post yourself from its edit screen under Vendors, Wall Posts.', 'social-walls-for-hivepress' ),
				'_order'      => 40,

				'fields'      => [
					'hpsw_pin_product' => [
						'label'       => esc_html__( 'Pinning Product', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'The WooCommerce product a Vendor buys to pin one post. Its price is what they pay. Leave empty to switch paid pinning off. Needs WooCommerce.', 'social-walls-for-hivepress' ),
						'type'        => 'select',
						'options'     => 'posts',
						'option_args' => [ 'post_type' => 'product' ],
						'_order'      => 10,
					],

					'hpsw_pin_days'    => [
						'label'       => esc_html__( 'Pinned for (days)', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'How long one purchase keeps a post pinned. Buying again while a post is pinned adds the time on to the end.', 'social-walls-for-hivepress' ),
						'type'        => 'number',
						'min_value'   => 1,
						'max_value'   => 365,
						'default'     => 7,
						'_order'      => 20,
					],
				],
			],

			'sharing'       => [
				'title'       => esc_html__( 'Sharing', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'A Share button on each post\'s own page lets visitors share it on Facebook or WhatsApp, copy its link, or scan a QR code with a phone. On a phone or tablet the button opens the device\'s own share menu instead. Nothing is sent to Facebook or WhatsApp unless a visitor chooses to share, and the QR code is drawn in the visitor\'s browser.', 'social-walls-for-hivepress' ),
				'_order'      => 45,

				'fields'      => [
					'hpsw_enable_share' => [
						'label'       => esc_html__( 'Share Button', 'social-walls-for-hivepress' ),
						'caption'     => esc_html__( 'Show a Share button on each post\'s page', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Untick to remove the button from every post page.', 'social-walls-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],

					'hpsw_share_logo'   => [
						'label'       => esc_html__( 'QR Code Logo', 'social-walls-for-hivepress' ),
						'caption'     => esc_html__( 'Select Image', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Optional. A small image, such as your logo, drawn in the middle of the QR code. A square image on a plain background works best. Leave it empty for a plain QR code.', 'social-walls-for-hivepress' ),
						'type'        => 'attachment_select',
						'formats'     => [ 'jpg', 'jpeg', 'png', 'webp' ],
						'_parent'     => 'hpsw_enable_share',
						'_order'      => 11,
					],
				],
			],

			'notifications' => [
				'title'       => esc_html__( 'Notifications', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Every email this plugin sends can be reworded under HivePress, Emails. If Notifications for HivePress is active, each one also arrives as an on-site notification, following each member\'s own notification settings.', 'social-walls-for-hivepress' ),
				'_order'      => 50,

				'fields'      => [
					'hpsw_notify_followers' => [
						'label'       => esc_html__( 'Followers', 'social-walls-for-hivepress' ),
						'caption'     => esc_html__( 'Email a Vendor\'s followers when they post', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Needs Follow Vendors for HivePress. Followers hear about each post once, when it goes live, and the emails are sent in the background in batches, so posting stays quick however many followers a Vendor has.', 'social-walls-for-hivepress' ),
						'type'        => 'checkbox',
						'default'     => true,
						'_order'      => 10,
					],
				],
			],

			'removal'       => [
				'title'       => esc_html__( 'Removing the Plugin', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'Your settings, every wall post, comment and like are kept if you delete this plugin, whatever the delete screen\'s generic warning says, unless you tick the box below. Deactivating the plugin never removes anything either.', 'social-walls-for-hivepress' ),
				'_order'      => 100,

				'fields'      => [
					'hpsw_delete_data' => [
						'label'       => esc_html__( 'Delete All Data', 'social-walls-for-hivepress' ),
						'caption'     => esc_html__( 'Delete everything this plugin stored when the plugin is deleted', 'social-walls-for-hivepress' ),
						'description' => esc_html__( 'Removes every wall post with its comments and likes, the settings on this tab, the posting options added to membership plans, and any reworded copy of this plugin\'s emails. Photos stay in the Media Library. This cannot be undone.', 'social-walls-for-hivepress' ),
						'type'        => 'checkbox',
						'_order'      => 10,
					],
				],
			],
		],
	],
];
