<?php
/**
 * New post page template.
 *
 * @package Social_Walls
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The new-post page in a Vendor's account: the post form, opened on the draft the route prepared.
 */
class Hpsw_Post_Submit_Page extends User_Account_Page {

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
							'hpsw_post_update_form' => [
								'type'   => 'form',
								'form'   => 'hpsw_post_update',
								'_label' => hivepress()->translator->get_string( 'form' ),
								'_order' => 10,
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
