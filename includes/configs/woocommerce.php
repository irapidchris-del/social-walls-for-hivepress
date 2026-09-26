<?php
/**
 * WooCommerce configuration.
 *
 * Declares the order item meta that links a "Pin to the top" purchase back to its wall post. This
 * is the mechanism the official Claim Listings extension uses for its paid claims
 * (hivepress-claim-listings/includes/configs/woocommerce.php): the cart item carries
 * `hp_hpsw_post`, and core's own `woocommerce_checkout_create_order_line_item` handler copies every
 * declared `hp_` key onto the order line, validating it through the field type given here
 * (hivepress/includes/components/class-woocommerce.php:243-272, core 1.7.31).
 *
 * No label, on purpose: core's cart and order formatters drop a declared key without a label
 * (:274-345), so the bare post ID is never shown to the buyer. The component adds its own readable
 * line to the cart instead.
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'item_meta' => [
		'hpsw_post' => [
			'type' => 'id',
		],
	],
];
