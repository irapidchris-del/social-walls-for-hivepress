<?php
/**
 * Wall account page template.
 *
 * @package Social_Walls
 */

namespace HivePress\Templates;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Wall page in a Vendor's account. Named exactly like its route, `hpsw_wall_edit_page`, so core
 * gives the page its `hp-template--user-account-page` body class (class-template.php:217-228).
 */
class Hpsw_Wall_Edit_Page extends User_Account_Page {

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
							'hpsw_wall_manage' => [
								'type'   => 'hpsw_wall_manage',
								'_label' => esc_html__( 'Wall', 'social-walls-for-hivepress' ),
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
