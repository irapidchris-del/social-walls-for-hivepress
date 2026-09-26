<?php
/**
 * Post comments block.
 *
 * @package Social_Walls
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;
use HivePress\Forms;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The comment thread and comment form on a post's own page.
 *
 * Replies are one level deep, listed under the comment they answer. Every piece of text a member
 * wrote is escaped here, on output, even though the model already stripped tags at save.
 */
class Hpsw_Post_Comments extends Block {

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

		if ( ! $wall || ! $post instanceof Models\Hpsw_Post || ! $wall->are_comments_enabled() || 'publish' !== $post->get_status() ) {
			return '';
		}

		$comments = get_comments(
			[
				'type'    => $wall::COMMENT_TYPE,
				'post_id' => $post->get_id(),
				'status'  => 'approve',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
				'number'  => 500,
			]
		);

		$top     = [];
		$replies = [];

		foreach ( (array) $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}

			if ( absint( $comment->comment_parent ) ) {
				$replies[ absint( $comment->comment_parent ) ][] = $comment;
			} else {
				$top[] = $comment;
			}
		}

		$can_comment = $wall->can_comment( get_current_user_id() );

		$output  = '<div class="hpsw-comments" id="hpsw-comments" data-hpsw-comments="' . esc_attr( (string) $post->get_id() ) . '">';
		$output .= '<h3 class="hpsw-comments__title hp-section__title">' . esc_html(
			sprintf(
				/* translators: %s: number of comments. */
				_n( '%s Comment', '%s Comments', count( (array) $comments ), 'social-walls-for-hivepress' ),
				number_format_i18n( count( (array) $comments ) )
			)
		) . '</h3>';

		if ( $top ) {
			$output .= '<ul class="hpsw-comments__list">';

			foreach ( $top as $comment ) {
				$output .= '<li>' . $this->render_comment( $comment, $post, $can_comment, false );

				if ( isset( $replies[ absint( $comment->comment_ID ) ] ) ) {
					$output .= '<ul class="hpsw-comments__replies">';

					foreach ( $replies[ absint( $comment->comment_ID ) ] as $reply ) {
						$output .= '<li>' . $this->render_comment( $reply, $post, $can_comment, true ) . '</li>';
					}

					$output .= '</ul>';
				}

				$output .= '</li>';
			}

			$output .= '</ul>';
		}

		$output .= $this->render_form( $post, $can_comment );
		$output .= '</div>';

		return $output;
	}

	/**
	 * Renders one comment.
	 *
	 * @param \WP_Comment                 $comment Comment.
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @param bool                        $can_comment Whether the viewer may reply.
	 * @param bool                        $is_reply Whether this is a reply.
	 * @return string
	 */
	protected function render_comment( $comment, $post, $can_comment, $is_reply ) {
		$comment_id = absint( $comment->comment_ID );
		$user_id    = absint( $comment->user_id );
		$viewer     = get_current_user_id();
		$is_vendor  = $user_id && absint( $post->get_user__id() ) === $user_id;

		$output  = '<article class="hpsw-comment' . ( $is_reply ? ' hpsw-comment--reply' : '' ) . '" id="hpsw-comment-' . esc_attr( (string) $comment_id ) . '">';
		$output .= '<img class="hpsw-comment__avatar" src="' . esc_url( (string) get_avatar_url( $user_id ? $user_id : $comment->comment_author_email, [ 'size' => 64 ] ) ) . '" alt="" loading="lazy">';
		$output .= '<div class="hpsw-comment__body">';
		$output .= '<div class="hpsw-comment__header">';
		$output .= '<strong class="hpsw-comment__author">' . esc_html( $comment->comment_author ) . '</strong>';

		if ( $is_vendor ) {
			$output .= ' <span class="hpsw-comment__badge hp-status hp-status--publish"><span>' . esc_html( hivepress()->translator->get_string( 'vendor' ) ) . '</span></span>';
		}

		$output .= ' <time class="hpsw-comment__date hp-meta" datetime="' . esc_attr( mysql2date( 'c', $comment->comment_date_gmt ) ) . '">' . esc_html(
			sprintf(
				/* translators: %s: time since, for example "3 hours". */
				esc_html__( '%s ago', 'social-walls-for-hivepress' ),
				human_time_diff( (int) mysql2date( 'U', $comment->comment_date_gmt ) )
			)
		) . '</time>';
		$output .= '</div>';

		$output .= '<div class="hpsw-comment__text">' . nl2br( esc_html( $comment->comment_content ) ) . '</div>';

		$actions = '';

		if ( $can_comment ) {
			$actions .= '<button type="button" class="hpsw-comment__reply hp-link" data-hpsw-reply="' . esc_attr( (string) ( $is_reply ? absint( $comment->comment_parent ) : $comment_id ) ) . '" data-hpsw-reply-name="' . esc_attr( $comment->comment_author ) . '"><i class="hp-icon fas fa-reply"></i><span>' . esc_html__( 'Reply', 'social-walls-for-hivepress' ) . '</span></button>';
		}

		if ( $viewer && ( current_user_can( 'moderate_comments' ) || $viewer === $user_id || absint( $post->get_user__id() ) === $viewer ) ) {
			$actions .= '<button type="button" class="hpsw-comment__delete hp-link" data-hpsw-comment-delete="' . esc_attr( (string) $comment_id ) . '"><i class="hp-icon fas fa-trash-alt"></i><span>' . esc_html__( 'Delete', 'social-walls-for-hivepress' ) . '</span></button>';
		}

		if ( $actions ) {
			$output .= '<div class="hpsw-comment__actions">' . $actions . '</div>';
		}

		$output .= '</div>';
		$output .= '</article>';

		return $output;
	}

	/**
	 * Renders the comment form, or says why there is none.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @param bool                        $can_comment Whether the viewer may comment.
	 * @return string
	 */
	protected function render_form( $post, $can_comment ) {
		if ( ! is_user_logged_in() ) {

			// Core's own sign-in modal, opened by any link to its id (assets/js/common.js:57-73).
			return '<p class="hpsw-comments__notice hp-meta"><a href="#user_login_modal">' . esc_html__( 'Sign in to comment', 'social-walls-for-hivepress' ) . '</a></p>';
		}

		if ( ! $can_comment ) {
			return '<p class="hpsw-comments__notice hp-meta">' . esc_html__( 'Only Vendors can comment on wall posts.', 'social-walls-for-hivepress' ) . '</p>';
		}

		$form = new Forms\Hpsw_Comment_Submit(
			[
				'model'  => new Models\Hpsw_Comment(),
				'action' => hivepress()->router->get_url( 'hpsw_post_comment_action', [ 'hpsw_post_id' => $post->get_id() ] ),
				'footer' => '<button type="button" class="hpsw-comments__cancel hp-link" data-hpsw-reply-cancel hidden><span></span><i class="hp-icon fas fa-times"></i></button>',
			]
		);

		return $form->render();
	}
}
