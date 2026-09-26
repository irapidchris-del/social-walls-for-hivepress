<?php
/**
 * Wall block.
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
 * A wall of posts: every Vendor's, or one Vendor's.
 *
 * Because its meta carries a label, core registers it as the "Social Wall" Gutenberg block and as
 * the `[hivepress_hpsw_wall]` shortcode (components/class-editor.php:346-360, core 1.7.31). The
 * Vendor page uses the same class in `vendor` mode, which that page's template sets; the editor
 * offers no mode setting, so a block placed by hand is always the all-Vendors wall.
 *
 * Every setting is a string attribute whatever its field type (class-editor.php:158-160), so each
 * one is cast here before use.
 */
class Hpsw_Wall extends Block {

	/**
	 * Mode: all or vendor.
	 *
	 * @var string
	 */
	protected $mode = 'all';

	/**
	 * Columns on wide screens.
	 *
	 * @var mixed
	 */
	protected $columns = 2;

	/**
	 * Posts per page.
	 *
	 * @var mixed
	 */
	protected $number = 10;

	/**
	 * Post type to show: deal, update or empty for both.
	 *
	 * @var mixed
	 */
	protected $type;

	/**
	 * Whether to show the filter form.
	 *
	 * @var mixed
	 */
	protected $filter = true;

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'    => esc_html__( 'Social Wall', 'social-walls-for-hivepress' ),

				'settings' => [
					'columns' => [
						'label'    => hivepress()->translator->get_string( 'columns_number' ),
						'type'     => 'select',
						'default'  => 2,
						'required' => true,
						'_order'   => 10,

						'options'  => [
							1 => '1',
							2 => '2',
							3 => '3',
						],
					],

					'number'  => [
						'label'     => hivepress()->translator->get_string( 'items_number' ),
						'type'      => 'number',
						'min_value' => 1,
						'max_value' => 50,
						'default'   => 10,
						'required'  => true,
						'_order'    => 20,
					],

					'type'    => [
						'label'       => esc_html__( 'Show', 'social-walls-for-hivepress' ),
						'type'        => 'select',
						'placeholder' => esc_html__( 'Deals and Updates', 'social-walls-for-hivepress' ),
						'_order'      => 30,

						'options'     => [
							'deal'   => esc_html__( 'Deals only', 'social-walls-for-hivepress' ),
							'update' => esc_html__( 'Updates only', 'social-walls-for-hivepress' ),
						],
					],

					'filter'  => [
						'label'   => esc_html__( 'Filters', 'social-walls-for-hivepress' ),
						'caption' => esc_html__( 'Show the filter form above the wall', 'social-walls-for-hivepress' ),
						'type'    => 'checkbox',
						'default' => true,
						'_order'  => 40,
					],
				],
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

		if ( ! $wall ) {
			return '';
		}

		// For a wall rendered outside the pages that enqueue these up front (a widget, a theme
		// template), WordPress prints them in the footer instead.
		$wall->load_assets();

		if ( 'vendor' === $this->mode ) {
			return $this->render_vendor_wall();
		}

		// A filter the block itself was set to beats one in the address.
		$type = in_array( (string) $this->type, [ 'deal', 'update' ], true ) ? (string) $this->type : '';

		// Filter values are read from a public address and only ever compared or escaped.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = [
			'category'  => isset( $_GET['hpsw_category'] ) ? absint( wp_unslash( $_GET['hpsw_category'] ) ) : 0,
			'location'  => isset( $_GET['hpsw_location'] ) ? sanitize_text_field( wp_unslash( $_GET['hpsw_location'] ) ) : '',
			'latitude'  => isset( $_GET['hpsw_lat'] ) ? sanitize_text_field( wp_unslash( $_GET['hpsw_lat'] ) ) : '',
			'longitude' => isset( $_GET['hpsw_lng'] ) ? sanitize_text_field( wp_unslash( $_GET['hpsw_lng'] ) ) : '',
			'radius'    => isset( $_GET['hpsw_radius'] ) ? sanitize_text_field( wp_unslash( $_GET['hpsw_radius'] ) ) : '',
			'region'    => isset( $_GET['hpsw_region'] ) ? sanitize_text_field( wp_unslash( $_GET['hpsw_region'] ) ) : '',
		];

		$keyword = isset( $_GET['hpsw_keyword'] ) ? sanitize_text_field( wp_unslash( $_GET['hpsw_keyword'] ) ) : '';

		if ( ! $type && isset( $_GET['hpsw_type'] ) ) {
			$type = sanitize_key( wp_unslash( $_GET['hpsw_type'] ) );
		}
		// phpcs:enable

		$result = $wall->query_posts(
			[
				'vendor_ids' => $wall->get_filter_vendor_ids( $filters ),
				'type'       => $type,
				'keyword'    => $keyword,
				'number'     => max( 1, min( 50, absint( $this->number ) ) ),
				'page'       => $wall->get_page_number(),
			]
		);

		$output = '<div class="hpsw-wall hpsw-wall--all" id="hpsw-wall">';

		if ( $this->filter && '0' !== (string) $this->filter ) {
			$base = is_singular() ? (string) get_permalink( get_queried_object_id() ) : '';

			$form = new Forms\Hpsw_Post_Filter(
				[
					'hide_type' => '' !== $type && (string) $this->type === $type,
					'base_url'  => $base,
				]
			);

			// Repopulating a public GET filter form: every field sanitises its own value.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$form->set_values( wp_unslash( $_GET ), true );

			$output .= $form->render();
		}

		if ( $result['ids'] ) {
			$output .= $wall->render_posts( $result['ids'], absint( $this->columns ) );
			$output .= $wall->render_pagination( $wall->get_page_number(), $result['pages'], 'hpsw-wall' );
		} else {
			$output .= '<p class="hpsw-wall__empty hp-meta">' . esc_html__( 'No posts found.', 'social-walls-for-hivepress' ) . '</p>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Renders one Vendor's wall, for their profile page.
	 *
	 * Returns an empty string when there is nothing to show, so the section wrapped around it on the
	 * Vendor page (an `optional` container) disappears rather than showing a bare heading.
	 *
	 * @return string
	 */
	protected function render_vendor_wall() {
		$wall   = hivepress()->hpsw_wall;
		$vendor = $this->get_context( 'vendor' );

		if ( ! $vendor instanceof Models\Vendor ) {
			return '';
		}

		$result = $wall->query_posts(
			[
				'vendor_ids' => [ $vendor->get_id() ],
				'number'     => max( 1, min( 50, hpsw_get_number_option( 'hpsw_per_page', 5 ) ) ),
				'page'       => $wall->get_page_number(),
			]
		);

		if ( ! $result['ids'] ) {
			return '';
		}

		// The owner's choice of columns (Settings, Display). Core's grid classes do the rest: below
		// 48em every item is hp-col-xs-12, so a phone always shows one post per row.
		$columns = (int) hpsw_get_choice_option( 'hpsw_vendor_columns', [ '1', '2', '3' ], '2' );

		$output  = '<div class="hpsw-wall hpsw-wall--vendor">';
		$output .= $wall->render_posts( $result['ids'], $columns );
		$output .= $wall->render_pagination( $wall->get_page_number(), $result['pages'], 'wall' );
		$output .= '</div>';

		return $output;
	}
}
