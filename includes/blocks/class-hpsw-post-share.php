<?php
/**
 * Post share block.
 *
 * @package Social_Walls
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Share button in a post page's sidebar, with its pop-up.
 *
 * Only for a post that is on show: a pending post is visible to its own Vendor, and a link to it
 * would only send anybody else to the home page.
 */
class Hpsw_Post_Share extends Block {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label' => null,
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Renders the block.
	 *
	 * @return string
	 */
	public function render() {
		$wall = hivepress()->hpsw_wall;
		$post = $this->get_context( 'hpsw_post' );

		if ( ! $wall || ! $post instanceof Models\Hpsw_Post || ! $wall->is_post_visible( $post ) ) {
			return '';
		}

		$title = trim( (string) $post->get_title() );

		if ( '' === $title ) {
			$title = $wall->get_post_label( $post->get_id() );
		}

		$output = $wall->render_share( $wall->get_post_url( $post ), $title );

		return '' === $output ? '' : '<div class="hpsw-share__block">' . $output . '</div>';
	}
}
