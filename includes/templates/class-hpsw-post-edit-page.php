<?php
/**
 * Edit post page template.
 *
 * @package Social_Walls
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The edit page of one wall post: the post form, and a Delete link opening a confirmation modal,
 * laid out the way core's own Listing edit page does it (templates/class-listing-edit-page.php).
 */
class Hpsw_Post_Edit_Page extends User_Account_Page {

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
							'hpsw_post_update_form'  => [
								'type'   => 'form',
								'form'   => 'hpsw_post_update',
								'_label' => hivepress()->translator->get_string( 'form' ),
								'_order' => 10,
							],

							'hpsw_post_actions'      => [
								'type'       => 'container',
								'_order'     => 20,

								'attributes' => [
									'class' => [ 'hpsw-post__manage', 'hp-listing__actions', 'hp-listing__actions--secondary' ],
								],

								'blocks'     => [
									'hpsw_post_view_link' => [
										'type'   => 'part',
										'path'   => 'hpsw-post/edit/page/hpsw-post-view-link',
										'_order' => 10,
									],

									'hpsw_post_delete_link' => [
										'type'   => 'part',
										'path'   => 'hpsw-post/edit/page/hpsw-post-delete-link',
										'_order' => 20,
									],
								],
							],

							'hpsw_post_delete_modal' => [
								'type'   => 'modal',
								'title'  => esc_html__( 'Delete Post', 'social-walls-for-hivepress' ),
								'_order' => 30,

								'blocks' => [
									'hpsw_post_delete_form' => [
										'type'   => 'form',
										'form'   => 'hpsw_post_delete',
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
