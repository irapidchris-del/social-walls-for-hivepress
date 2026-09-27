<?php
/**
 * Post manage block.
 *
 * @package Social_Walls
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The owner's box in a post page's sidebar: Edit, Pin to the top when paid pinning is on, Delete
 * with a confirmation pop-up, then Add New and My Wall for a viewer with a Vendor profile.
 *
 * Laid out like Additional Gallery's Manage Photo card on its photo page (a sidebar widget card with
 * a title, the main action as a full-width button, then the smaller actions each in their own
 * ruled-off section with a line of explanation), so an owner who runs both plugins meets the same
 * box in the same place.
 *
 * Shown to the post's author and to anybody who may manage every post (can_manage_post()), and to
 * nobody else. Hiding the box is not the protection: every action it links to checks again on the
 * server. The edit page's redirect and the update route both call can_manage_post(), the pin
 * address checks it plus a nonce (controllers/class-hpsw-wall.php, redirect_post_pin_page()), and
 * the delete route refuses anyone but the author or a user who can delete other people's posts
 * (delete_post()).
 */
class Hpsw_Post_Manage extends Block {

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

		if ( ! $wall || ! $post instanceof Models\Hpsw_Post || ! is_user_logged_in() || ! $wall->can_manage_post( $post ) ) {
			return '';
		}

		$post_id   = $post->get_id();
		$status    = (string) $post->get_status();
		$is_author = get_current_user_id() === absint( $post->get_user__id() );
		$is_admin  = current_user_can( 'edit_others_posts' );

		$output  = '<div class="hp-widget widget widget--sidebar hpsw-post-manage">';
		$output .= '<h3 class="widget__title hp-section__title">' . esc_html__( 'Manage', 'social-walls-for-hivepress' ) . '</h3>';

		// Edit. A post waiting for approval cannot be changed by its author until it is reviewed (the
		// update route refuses it), so the box says so rather than offering a form that will refuse.
		if ( 'pending' === $status && ! $is_admin ) {
			$output .= '<p class="hpsw-post-manage__state"><span class="hp-status hp-status--pending"><span>' . esc_html_x( 'Pending', 'wall post', 'social-walls-for-hivepress' ) . '</span></span></p>';
			$output .= '<p class="hp-meta">' . esc_html__( 'This post is waiting for approval and cannot be changed until it has been reviewed.', 'social-walls-for-hivepress' ) . '</p>';
		} else {
			$output .= '<a href="' . esc_url( hivepress()->router->get_url( 'hpsw_post_edit_page', [ 'hpsw_post_id' => $post_id ] ) ) . '" class="hpsw-post-manage__edit hp-button hp-button--wide button button--large button--primary alt"><i class="hp-icon fas fa-edit"></i><span>' . esc_html__( 'Edit Post', 'social-walls-for-hivepress' ) . '</span></a>';
		}

		// Pinning, when it is on sale and the post can be pinned.
		if ( $wall->get_pin_product_id() && 'publish' === $status && ! $post->is_ended() ) {
			$output .= '<div class="hpsw-post-manage__section hpsw-post-manage__pin">';

			if ( $post->is_pinned() ) {
				$output .= '<p class="hpsw-post-manage__state"><span class="hp-status hp-status--publish"><span><i class="hp-icon fas fa-thumbtack"></i> ' . esc_html(
					sprintf(
						/* translators: %s: date. */
						esc_html__( 'Pinned until %s', 'social-walls-for-hivepress' ),
						wp_date( get_option( 'date_format' ), (int) $post->get_pinned_time() )
					)
				) . '</span></span></p>';
			}

			// The purchase is the author's own: it empties THEIR basket and puts the pin in it. An
			// administrator managing someone else's post pins it from its edit screen instead.
			if ( $is_author ) {
				$pin_url = wp_nonce_url( hivepress()->router->get_url( 'hpsw_post_pin_page', [ 'hpsw_post_id' => $post_id ] ), 'hpsw_pin_' . $post_id );

				$output .= '<a href="' . esc_url( $pin_url ) . '" class="hpsw-post-manage__action hp-link"><i class="hp-icon fas fa-thumbtack"></i><span>' . ( $post->is_pinned() ? esc_html__( 'Extend pin', 'social-walls-for-hivepress' ) : esc_html__( 'Pin to the top', 'social-walls-for-hivepress' ) ) . '</span></a>';

				$output .= '<p class="hp-meta">' . esc_html(
					sprintf(
						/* translators: %s: number of days. */
						_n( 'A pinned post sits above newer posts on every wall for %s day.', 'A pinned post sits above newer posts on every wall for %s days.', $wall->get_pin_days(), 'social-walls-for-hivepress' ),
						number_format_i18n( $wall->get_pin_days() )
					)
				) . '</p>';
			} elseif ( current_user_can( 'edit_post', $post_id ) ) {
				$output .= '<a href="' . esc_url( (string) get_edit_post_link( $post_id ) ) . '" class="hpsw-post-manage__action hp-link"><i class="hp-icon fas fa-thumbtack"></i><span>' . esc_html__( 'Pin from the dashboard', 'social-walls-for-hivepress' ) . '</span></a>';
			}

			$output .= '</div>';
		}

		// Delete, behind the same confirmation pop-up as the edit page.
		if ( $is_author || current_user_can( 'delete_others_posts' ) ) {
			$modal_id = 'hpsw_post_manage_delete_modal';

			$output .= '<div class="hpsw-post-manage__section hpsw-post-manage__delete">';
			$output .= '<button type="button" class="hpsw-post-manage__action hp-link" data-url="#' . esc_attr( $modal_id ) . '"><i class="hp-icon fas fa-times"></i><span>' . esc_html__( 'Delete this post', 'social-walls-for-hivepress' ) . '</span></button>';
			$output .= '<p class="hp-meta">' . esc_html__( 'Deleting a post also removes its likes and comments.', 'social-walls-for-hivepress' ) . '</p>';
			$output .= '</div>';

			// The author goes back to their own Wall page; anybody else to the Vendor's profile, since
			// the account Wall page lists only the viewer's own posts.
			$redirect = $is_author ? hivepress()->router->get_url( 'hpsw_wall_edit_page' ) : hivepress()->router->get_url( 'vendor_view_page', [ 'vendor_id' => $post->get_vendor__id() ] );

			$output .= ( new Modal(
				[
					'name'    => $modal_id,
					'title'   => esc_html__( 'Delete Post', 'social-walls-for-hivepress' ),
					'context' => $this->context,

					'blocks'  => [
						'hpsw_post_delete_form' => [
							'type'     => 'form',
							'form'     => 'hpsw_post_delete',
							'redirect' => $redirect,
							'_order'   => 10,
						],
					],
				]
			) )->render();
		}

		// Shortcuts to the viewer's own wall: a new post, and the Wall page in their account. Only for
		// a viewer with a Vendor profile, because both pages send anybody else straight to their
		// account (controllers/class-hpsw-wall.php, redirect_wall_edit_page() and
		// redirect_post_submit_page()), so an administrator without one would meet two dead links.
		if ( $wall->get_current_vendor() ) {
			$output .= '<div class="hpsw-post-manage__section hpsw-post-manage__links">';
			$output .= '<a href="' . esc_url( hivepress()->router->get_url( 'hpsw_post_submit_page' ) ) . '" class="hpsw-post-manage__action hpsw-post-manage__new hp-link"><i class="hp-icon fas fa-plus"></i><span>' . esc_html__( 'Add New', 'social-walls-for-hivepress' ) . '</span></a>';
			$output .= '<a href="' . esc_url( hivepress()->router->get_url( 'hpsw_wall_edit_page' ) ) . '" class="hpsw-post-manage__action hpsw-post-manage__wall hp-link"><i class="hp-icon fas fa-th-large"></i><span>' . esc_html__( 'My Wall', 'social-walls-for-hivepress' ) . '</span></a>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}
}
