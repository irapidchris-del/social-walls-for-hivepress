<?php
/**
 * Comment form.
 *
 * @package Social_Walls
 */

namespace HivePress\Forms;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Adds a comment, or a reply, to a wall post.
 *
 * A normal HivePress form posting to the post's REST route, so core's own form script sends it,
 * shows any error and reloads the page on success: the new comment then arrives in the thread as
 * server-rendered, escaped markup, and nothing a visitor typed is ever put into the page by script.
 *
 * `parent` is a hidden field the reply links fill in; left empty, the comment is top-level. The
 * action is set by whoever renders the form, because the form alone does not know which post it is
 * on: the comment model it is built from has no post yet.
 */
class Hpsw_Comment_Submit extends Model_Form {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'model' => 'hpsw_comment',
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
				'redirect' => true,
				'reset'    => true,

				'fields'   => [
					'text'   => [
						'label'       => null,
						'placeholder' => esc_html__( 'Write a comment...', 'social-walls-for-hivepress' ),
						'attributes'  => [ 'rows' => 3 ],
						'_order'      => 10,
					],

					'parent' => [
						'display_type' => 'hidden',
						'attributes'   => [ 'data-hpsw-parent' => 'true' ],
						'_order'       => 20,
					],
				],

				'button'   => [
					'label' => esc_html__( 'Post Comment', 'social-walls-for-hivepress' ),
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
