<?php
/**
 * Post delete form.
 *
 * @package Social_Walls
 */

namespace HivePress\Forms;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Deletes a wall post, from the confirmation modal on its edit page.
 *
 * Modelled on core's Listing_Delete (forms/class-listing-delete.php): a model form with no fields
 * that sends DELETE to the post's own REST route.
 */
class Hpsw_Post_Delete extends Model_Form {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'model' => 'hpsw_post',
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Form arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'description' => esc_html__( 'Delete this post? It comes off your wall straight away, with its likes and comments. It still counts towards this month\'s posts.', 'social-walls-for-hivepress' ),
				'method'      => 'DELETE',
				'redirect'    => hivepress()->router->get_url( 'hpsw_wall_edit_page' ),

				'button'      => [
					'label' => esc_html__( 'Delete Post', 'social-walls-for-hivepress' ),
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/**
	 * Bootstraps form properties.
	 */
	protected function boot() {
		if ( $this->model->get_id() ) {
			$this->action = hivepress()->router->get_url(
				'hpsw_post_delete_action',
				[
					'hpsw_post_id' => $this->model->get_id(),
				]
			);
		}

		parent::boot();
	}
}
