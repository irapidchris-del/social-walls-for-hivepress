<?php
/**
 * Wall post page template.
 *
 * @package Social_Walls
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * One wall post on its own page, with its comments, and the Vendor's card in the sidebar.
 *
 * Laid out like core's own Listing page (templates/class-listing-view-page.php): content on one
 * side, a sticky sidebar on the other, the sidebar made sticky by core's `sticky` component rather
 * than by CSS (resources/hivepress-ui.md, "Sticky sidebars are a component, never CSS"). The Vendor
 * card is core's own `vendor_view_block` template, the one the Vendors block renders
 * (blocks/class-vendors.php), so it looks exactly like a Vendor card anywhere else on the site.
 */
class Hpsw_Post_View_Page extends Page_Sidebar_Right {

	/**
	 * Class constructor.
	 *
	 * @param array $args Template arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_trees(
			[
				'blocks' => [
					'page_content' => [
						'blocks' => [
							'hpsw_post_container' => [
								'type'       => 'container',
								'tag'        => 'article',
								'_order'     => 20,

								'attributes' => [
									'class' => [ 'hp-listing', 'hp-listing--view-page', 'hpsw-post', 'hpsw-post--view-page' ],
								],

								'blocks'     => [
									'hpsw_post_meta'    => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/hpsw-post-meta',
										'_order' => 10,
									],

									// Facebook-style order since 1.0.3 (site owner's request, 28 Sept 2026): the
									// byline, the post's words with its Deal box, then the photos, then the like
									// and comment counts, so the name and its text sit together. The counts row is
									// one part in one place: under the photos, or under the text when there are
									// none, because the photos come last.
									'hpsw_post_text'    => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/page/hpsw-post-text',
										'_order' => 20,
									],

									'hpsw_post_deal'    => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/hpsw-post-deal',
										'_order' => 30,
									],

									'hpsw_post_images'  => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/page/hpsw-post-images',
										'_order' => 40,
									],

									'hpsw_post_actions' => [
										'type'   => 'part',
										'path'   => 'hpsw-post/view/hpsw-post-actions',
										'_order' => 50,
									],
								],
							],

							'hpsw_post_comments'  => [
								'type'   => 'hpsw_post_comments',
								'_label' => esc_html__( 'Comments', 'social-walls-for-hivepress' ),
								'_order' => 30,
							],
						],
					],

					'page_sidebar' => [
						'attributes' => [
							'data-component' => 'sticky',
						],

						// Owner's box, the Vendor card, then Share: the order of Additional Gallery's photo
						// page sidebar (Manage Photo 10, Vendor 20, Share 30), so the two read alike.
						'blocks'     => [
							'hpsw_post_manage' => [
								'type'   => 'hpsw_post_manage',
								'_label' => esc_html__( 'Manage Post', 'social-walls-for-hivepress' ),
								'_order' => 5,
							],

							'hpsw_post_vendor' => [
								'type'     => 'template',
								'template' => 'vendor_view_block',
								'_label'   => hivepress()->translator->get_string( 'vendor' ),
								'_order'   => 10,
							],

							'hpsw_post_share'  => [
								'type'   => 'hpsw_post_share',
								'_label' => esc_html__( 'Share', 'social-walls-for-hivepress' ),
								'_order' => 20,
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
