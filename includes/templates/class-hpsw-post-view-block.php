<?php
/**
 * Wall post card template.
 *
 * @package Social_Walls
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * One wall post as a card.
 *
 * Built in the shape of core's Listing card (templates/class-listing-view-block.php): an `article`
 * carrying `hp-listing hp-listing--view-block`, with `hp-listing__header`, `__content` and
 * `__footer` sections. Those class names come from core, not from the theme, so every official
 * theme's own card styling (border, shadow, spacing, type) reaches a wall post without a line of
 * CSS from this plugin. The `hpsw-post` classes beside them are this plugin's own hooks.
 *
 * Each piece is a template part, so a theme or a child theme can replace any one of them from its
 * own `hivepress/hpsw-post/view/...` folder, exactly as it can a Listing card's parts.
 */
class Hpsw_Post_View_Block extends Template {

	/**
	 * Class constructor.
	 *
	 * @param array $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_trees(
			[
				'blocks' => [
					'hpsw_post_container' => [
						'type'       => 'container',
						'tag'        => 'article',
						'_order'     => 10,

						'attributes' => [
							'class' => [ 'hp-listing', 'hp-listing--view-block', 'hpsw-post', 'hpsw-post--view-block' ],
						],

						'blocks'     => [
							'hpsw_post_content' => [
								'type'       => 'container',
								'_order'     => 20,

								'attributes' => [
									'class' => [ 'hp-listing__content' ],
								],

								'blocks'     => [
									'hpsw_post_meta'  => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/hpsw-post-meta',
										'_order' => 10,
									],

									'hpsw_post_title' => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/block/hpsw-post-title',
										'_order' => 20,
									],

									'hpsw_post_text'  => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/block/hpsw-post-text',
										'_order' => 30,
									],

									'hpsw_post_image' => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/block/hpsw-post-image',
										'_order' => 35,
									],

									// The coupon row, then the end date pill and the linked Listing. Since
									// 1.0.3 the end date is a pill here rather than in the footer, which holds
									// only the like and comment counts.
									'hpsw_post_deal'  => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/hpsw-post-deal',
										'_order' => 40,
									],
								],
							],

							'hpsw_post_footer'  => [
								'type'       => 'container',
								'tag'        => 'footer',
								'_order'     => 30,

								'attributes' => [
									'class' => [ 'hp-listing__footer' ],
								],

								'blocks'     => [
									'hpsw_post_actions' => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/hpsw-post-actions',
										'_order' => 10,
									],
								],
							],
						],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
