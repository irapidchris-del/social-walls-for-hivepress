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
 * Since 1.0.4 the posts are the wall's own cards in the wall's grid (one, two or three per row by
 * screen width), each with a row of the Vendor's controls: status pill, Edit, Pin, View and Delete
 * (templates/hpsw-post/view/block/hpsw-post-owner.php). Before that they were a stacked table.
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

		// The same cards as the Social Wall block, in its grid markup, so the site's own styling for
		// the wall reaches them too; each card carries this Vendor's controls (the owner view).
		$output .= '<div class="hpsw-wall hpsw-wall--owner">';
		$output .= $wall->render_posts( array_map( 'absint', $posts->get_ids() ), 2, true ); // Two per row: the account sidebar leaves too little width for three.
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}
}
