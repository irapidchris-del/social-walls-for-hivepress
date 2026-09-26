<?php
/**
 * Wall comment model.
 *
 * @package Social_Walls
 */

namespace HivePress\Models;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A comment on a wall post.
 *
 * Stored as an `hp_hpsw_comment` comment, following the Review model and Additional Gallery's photo
 * comments: the text is `comment_content`, the author is `user_id` with the display name and email
 * in the author columns (which is what makes the rows legible on the wp-admin Comments screen), and
 * the post is `comment_post_ID`. A reply carries its parent in `comment_parent`, one level deep.
 *
 * The class name is short on purpose. The comment type is `hp_` + the class name
 * (models/class-comment.php, Comment::init()) and `wp_comments.comment_type` is varchar(20), so a
 * longer name is truncated on write and then never matches on read, silently
 * (resources/hivepress-data.md, "A comment model's class name has 17 characters to play with").
 * `hp_hpsw_comment` is 15.
 *
 * `Comment::save()` writes with wp_insert_comment() directly, so nothing passes through the
 * moderation pipeline: `approved` is set by the caller.
 *
 * @method int|null get_id()
 * @method string|null get_text()
 * @method string|null get_created_date()
 * @method int|null get_approved()
 * @method int|null get_author__id()
 * @method string|null get_author__display_name()
 * @method int|null get_parent__id()
 * @method int|null get_post__id()
 * @method static \HivePress\Queries\Comment query()
 */
class Hpsw_Comment extends Comment {

	/**
	 * Class constructor.
	 *
	 * @param array $args Model arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'fields' => [
					'text'                 => [
						'label'      => esc_html__( 'Comment', 'social-walls-for-hivepress' ),
						'type'       => 'textarea',
						'max_length' => 1000,
						'required'   => true,
						'html'       => false,
						'_alias'     => 'comment_content',
					],

					'created_date'         => [
						'type'   => 'date',
						'format' => 'Y-m-d H:i:s',
						'_alias' => 'comment_date',
					],

					'approved'             => [
						'type'      => 'number',
						'min_value' => 0,
						'max_value' => 1,
						'_alias'    => 'comment_approved',
					],

					'author'               => [
						'type'     => 'id',
						'required' => true,
						'_alias'   => 'user_id',
						'_model'   => 'user',
					],

					'author__display_name' => [
						'type'       => 'text',
						'max_length' => 256,
						'required'   => true,
						'_alias'     => 'comment_author',
					],

					'author__email'        => [
						'type'     => 'email',
						'required' => true,
						'_alias'   => 'comment_author_email',
					],

					'parent'               => [
						'type'   => 'id',
						'_alias' => 'comment_parent',
						'_model' => 'hpsw_comment',
					],

					'post'                 => [
						'type'     => 'id',
						'required' => true,
						'_alias'   => 'comment_post_ID',
						'_model'   => 'hpsw_post',
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
