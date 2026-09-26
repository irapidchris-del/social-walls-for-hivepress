<?php
/**
 * Wall like model.
 *
 * @package Social_Walls
 */

namespace HivePress\Models;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A like (heart) on a wall post.
 *
 * Stored as an `hp_hpsw_like` comment, mirroring how the Favorites extension stores a favourite
 * and how Additional Gallery stores a photo like: the person is `user_id` and the post is
 * `comment_post_ID`. One row per person per post; the controller toggles it.
 *
 * Short class name for the same reason as the comment model: the comment type is `hp_` + the class
 * name and the column holds 20 characters. `hp_hpsw_like` is 12.
 *
 * @method int|null get_id()
 * @method int|null get_user__id()
 * @method int|null get_post__id()
 * @method static \HivePress\Queries\Comment query()
 */
class Hpsw_Like extends Comment {

	/**
	 * Class constructor.
	 *
	 * @param array $args Model arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'fields' => [
					'created_date' => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'comment_date',
					],

					'approved'     => [
						'type'      => 'number',
						'min_value' => 0,
						'max_value' => 1,
						'_alias'    => 'comment_approved',
					],

					'user'         => [
						'type'      => 'number',
						'min_value' => 1,
						'required'  => true,
						'_alias'    => 'user_id',
						'_model'    => 'user',
					],

					'post'         => [
						'type'      => 'number',
						'min_value' => 1,
						'required'  => true,
						'_alias'    => 'comment_post_ID',
						'_model'    => 'hpsw_post',
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
