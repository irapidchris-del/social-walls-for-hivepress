<?php
/**
 * Wall management block.
 *
 * @package Social_Walls
 */

namespace HivePress\Blocks;

use HivePress\Helpers as hp;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * The Vendor's Wall page in their account: the New Post button, this month's allowance, and every
 * post they have made, with its status and what they can do with it.
 *
 * No label, so it is never offered in the block editor: it only makes sense inside the account page
 * that supplies its Vendor.
 *
 * The list is drawn as core's own stacked table (`hp-table hp-table--stack`), the markup core uses
 * for My Listings (blocks/class-listings.php in edit mode, templates/listing/edit/block/*), and the
 * status cell is core's `hp-status` pill, so the page looks like the rest of the account area on
 * every theme.
 */
class Hpsw_Wall_Manage extends Block {

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
		$wall   = hivepress()->hpsw_wall;
		$vendor = $this->get_context( 'vendor' );

		if ( ! $wall || ! $vendor instanceof Models\Vendor ) {
			return '';
		}

		$wall->load_assets();

		$output = '<div class="hpsw-manage">';

		// What the Vendor can do now.
		$output .= '<div class="hpsw-manage__header">';

		if ( ! $wall->can_post( $vendor ) ) {
			$output .= '<p class="hpsw-manage__notice">' . esc_html__( 'Your current plan does not include wall posts.', 'social-walls-for-hivepress' );

			$upgrade = $wall->get_upgrade_url();

			if ( $upgrade ) {
				$output .= ' <a href="' . esc_url( $upgrade ) . '">' . esc_html__( 'See the plans', 'social-walls-for-hivepress' ) . '</a>';
			}

			$output .= '</p>';
		} else {
			$remaining = $wall->get_remaining( $vendor );

			if ( 0 === $remaining ) {
				$output .= '<p class="hpsw-manage__notice">' . esc_html( $wall->get_limit_message( $vendor ) );

				$upgrade = $wall->get_upgrade_url();

				if ( $upgrade ) {
					$output .= ' <a href="' . esc_url( $upgrade ) . '">' . esc_html__( 'See the plans', 'social-walls-for-hivepress' ) . '</a>';
				}

				$output .= '</p>';
			} else {
				$output .= '<a href="' . esc_url( hivepress()->router->get_url( 'hpsw_post_submit_page' ) ) . '" class="hp-button button button--primary hpsw-manage__new"><i class="hp-icon fas fa-plus"></i><span>' . esc_html__( 'New post', 'social-walls-for-hivepress' ) . '</span></a>';

				if ( ! is_null( $remaining ) ) {
					$limit = (int) $wall->get_monthly_limit( $vendor );

					$output .= '<p class="hpsw-manage__allowance hp-meta">' . esc_html(
						sprintf(
							/* translators: 1: posts used, 2: posts allowed. */
							_n( 'You have used %1$s of your %2$s post this month.', 'You have used %1$s of your %2$s posts this month.', $limit, 'social-walls-for-hivepress' ),
							number_format_i18n( $limit - $remaining ),
							number_format_i18n( $limit )
						)
					) . '</p>';
				}
			}
		}

		$output .= '</div>';

		// The Vendor's posts, newest first; the auto-draft of an unfinished new post is not one.
		$posts = Models\Hpsw_Post::query()->filter(
			[
				'status__in' => [ 'publish', 'pending', 'draft' ],
				'vendor'     => $vendor->get_id(),
			]
		)->order( [ 'created_date' => 'desc' ] )
		->limit( 100 )
		->get();

		if ( ! $posts->count() ) {
			$output .= '<p class="hpsw-manage__empty hp-meta">' . esc_html__( 'You have not posted anything yet. Deals and Updates you post appear on your profile and on the site\'s wall.', 'social-walls-for-hivepress' ) . '</p>';
			$output .= '</div>';

			return $output;
		}

		$engagement = $wall->get_engagement( $posts->get_ids() );
		$pin_on     = (bool) $wall->get_pin_product_id();

		$output .= '<table class="hpsw-posts hp-block hp-table hp-table--stack">';

		foreach ( $posts as $post ) {
			if ( ! $post instanceof Models\Hpsw_Post ) {
				continue;
			}

			$output .= $this->render_row( $post, isset( $engagement[ $post->get_id() ] ) ? $engagement[ $post->get_id() ] : [], $pin_on );
		}

		$output .= '</table>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Renders one row of the Vendor's post list.
	 *
	 * @param \HivePress\Models\Hpsw_Post $post Wall post.
	 * @param array                       $engagement Likes and comments.
	 * @param bool                        $pin_on Whether paid pinning is available.
	 * @return string
	 */
	protected function render_row( $post, $engagement, $pin_on ) {
		$wall   = hivepress()->hpsw_wall;
		$status = (string) $post->get_status();
		$label  = $wall->get_post_label( $post->get_id() );

		// Status, with the same urgency rule core uses for Listings: the pill says what a reader needs
		// to know now, not only the raw status (templates/listing/edit/block/listing-status.php).
		if ( 'pending' === $status ) {
			$pill = [ 'pending', esc_html_x( 'Pending', 'wall post', 'social-walls-for-hivepress' ) ];
		} elseif ( $post->is_expired() ) {
			$pill = [ 'trash', esc_html__( 'Ended', 'social-walls-for-hivepress' ) ];
		} elseif ( $post->is_pinned() ) {
			$pill = [
				'publish',
				sprintf(
					/* translators: %s: date. */
					esc_html__( 'Pinned until %s', 'social-walls-for-hivepress' ),
					wp_date( get_option( 'date_format' ), (int) $post->get_pinned_time() )
				),
			];
		} elseif ( 'publish' === $status ) {
			$pill = [ 'publish', esc_html_x( 'Published', 'wall post', 'social-walls-for-hivepress' ) ];
		} else {
			$pill = [ 'draft', esc_html_x( 'Hidden', 'wall post', 'social-walls-for-hivepress' ) ];
		}

		$output  = '<tr class="hpsw-post hpsw-post--edit-block">';
		$output .= '<td class="hpsw-post__title">';
		$output .= '<a href="' . esc_url( hivepress()->router->get_url( 'hpsw_post_edit_page', [ 'hpsw_post_id' => $post->get_id() ] ) ) . '" class="hp-link hp-link--wrap"><i class="hp-icon fas fa-edit"></i><span>' . esc_html( $label ) . '</span></a>';
		$output .= '</td>';

		$output .= '<td class="hpsw-post__type hp-meta">' . ( $post->is_deal() ? esc_html__( 'Deal', 'social-walls-for-hivepress' ) : esc_html__( 'Update', 'social-walls-for-hivepress' ) ) . '</td>';

		$output .= '<td class="hpsw-post__date hp-meta">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $post->get_created_date() ) ) ) . '</td>';

		$output .= '<td class="hpsw-post__engagement hp-meta">';

		if ( $wall->are_likes_enabled() ) {
			$output .= '<span title="' . esc_attr__( 'Likes', 'social-walls-for-hivepress' ) . '"><i class="hp-icon fas fa-heart"></i> ' . esc_html( number_format_i18n( absint( hp\get_array_value( $engagement, 'likes', 0 ) ) ) ) . '</span> ';
		}

		if ( $wall->are_comments_enabled() ) {
			$output .= '<span title="' . esc_attr__( 'Comments', 'social-walls-for-hivepress' ) . '"><i class="hp-icon fas fa-comment"></i> ' . esc_html( number_format_i18n( absint( hp\get_array_value( $engagement, 'comments', 0 ) ) ) ) . '</span>';
		}

		$output .= '</td>';

		$output .= '<td class="hpsw-post__status hp-status hp-status--' . esc_attr( $pill[0] ) . '"><span>' . esc_html( $pill[1] ) . '</span></td>';

		$output .= '<td class="hpsw-post__actions hp-listing__actions hp-listing__actions--primary">';

		if ( $pin_on && 'publish' === $status && ! $post->is_expired() ) {
			$pin_url = wp_nonce_url( hivepress()->router->get_url( 'hpsw_post_pin_page', [ 'hpsw_post_id' => $post->get_id() ] ), 'hpsw_pin_' . $post->get_id() );

			$output .= '<a href="' . esc_url( $pin_url ) . '" class="hp-link hpsw-post__pin"><i class="hp-icon fas fa-thumbtack"></i><span>' . ( $post->is_pinned() ? esc_html__( 'Extend pin', 'social-walls-for-hivepress' ) : esc_html__( 'Pin to the top', 'social-walls-for-hivepress' ) ) . '</span></a>';
		}

		if ( 'publish' === $status ) {
			$output .= '<a href="' . esc_url( $wall->get_post_url( $post ) ) . '" class="hp-link" title="' . esc_attr__( 'View', 'social-walls-for-hivepress' ) . '"><i class="hp-icon fas fa-external-link-alt"></i><span class="screen-reader-text">' . esc_html__( 'View', 'social-walls-for-hivepress' ) . '</span></a>';
		}

		$output .= '</td>';
		$output .= '</tr>';

		return $output;
	}
}
