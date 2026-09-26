<?php
/**
 * Wall filter form.
 *
 * @package Social_Walls
 */

namespace HivePress\Forms;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Filters the all-Vendors wall: type, keywords, category and location.
 *
 * A plain GET form back to the page it sits on, so a filtered wall is an ordinary address that can
 * be bookmarked, shared and cached, and it works with scripts switched off.
 *
 * Every parameter is prefixed `hpsw_`. The bare names HivePress's own search uses (`location`,
 * `latitude`, `_region`, `s`) would be read by WordPress's main query or by the Geolocation
 * extension on whatever page the block is placed, so this form never borrows them. The Geolocation
 * location picker does not need them either: it finds the coordinate inputs by their
 * `data-coordinate` attribute, which the Latitude and Longitude field classes stamp on themselves
 * (hivepress-geolocation/includes/fields/class-latitude.php, boot()), and the region input by
 * `data-region` (assets/js/common.js:11-12).
 */
class Hpsw_Post_Filter extends Form {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'name' => 'hpsw_post_filter',
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
		$fields = [
			'hpsw_keyword'  => [
				'placeholder' => esc_html__( 'Keywords', 'social-walls-for-hivepress' ),
				'type'        => 'text',
				'max_length'  => 256,
				'_order'      => 10,
			],

			'hpsw_type'     => [
				'placeholder' => esc_html__( 'Deals and Updates', 'social-walls-for-hivepress' ),
				'type'        => 'select',
				'_order'      => 20,

				'options'     => [
					'deal'   => esc_html__( 'Deals', 'social-walls-for-hivepress' ),
					'update' => esc_html__( 'Updates', 'social-walls-for-hivepress' ),
				],
			],

			'hpsw_category' => [
				'placeholder' => esc_html__( 'All categories', 'social-walls-for-hivepress' ),
				'type'        => 'select',
				'options'     => 'terms',
				'option_args' => [ 'taxonomy' => 'hp_listing_category' ],
				'_order'      => 30,
			],
		];

		// A wall whose block settings fix the type offers no type choice: it would do nothing.
		if ( ! empty( $args['hide_type'] ) ) {
			unset( $fields['hpsw_type'] );
		}

		unset( $args['hide_type'] );

		$geolocation = function_exists( 'hivepress' ) ? hivepress()->geolocation : null;

		if ( $geolocation && class_exists( '\HivePress\Fields\Location' ) ) {

			// The extension's own place picker, suggestions and all. With Geolocation Plus active this
			// same field is driven by Geolocation Plus's own provider and suggestion list, restricted to
			// the Suggestion Types its settings choose for the site's search (its alter_location_field()
			// and assets/js/common.js). Its own `hpgp_location` field type is deliberately not used here:
			// it is for custom attributes, offers every kind of place and writes no region.
			$fields['hpsw_location'] = [
				'placeholder' => esc_html__( 'Location', 'social-walls-for-hivepress' ),
				'type'        => 'location',
				'countries'   => array_filter( (array) get_option( 'hp_geolocation_countries', [] ) ),
				'_order'      => 40,
			];

			$fields['hpsw_lat'] = [
				'type'   => 'latitude',
				'_order' => 41,
			];

			$fields['hpsw_lng'] = [
				'type'   => 'longitude',
				'_order' => 42,
			];

			if ( get_option( 'hp_geolocation_generate_regions' ) ) {
				$fields['hpsw_region'] = [
					'type'       => 'hidden',
					'attributes' => [ 'data-region' => 'true' ],
					'_order'     => 43,
				];
			}

			// The owner can hide the distance box; the wall then searches within its default radius
			// (Hpsw_Wall::get_radius_km(), which also ignores any radius typed into the address).
			if ( ! hivepress()->hpsw_wall || hivepress()->hpsw_wall->is_radius_field_shown() ) {
				$fields['hpsw_radius'] = [
					'placeholder' => get_option( 'hp_geolocation_use_miles' ) ? esc_html__( 'Within (miles)', 'social-walls-for-hivepress' ) : esc_html__( 'Within (km)', 'social-walls-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 1,
					'max_value'   => max( 1, absint( get_option( 'hp_geolocation_max_radius', 100 ) ) ),
					'_order'      => 44,
				];
			}
		} else {

			// No Geolocation: a text box matched against the addresses stored on Vendors and Listings.
			$fields['hpsw_location'] = [
				'placeholder' => esc_html__( 'Location', 'social-walls-for-hivepress' ),
				'type'        => 'text',
				'max_length'  => 128,
				'_order'      => 40,
			];
		}

		/*
		 * A browser drops the query string of a GET form's action and sends only the fields, so on a
		 * site with plain permalinks, where the page itself is `?page_id=12`, the filter would land on
		 * the home page. Whatever the page's own address carries goes along as hidden fields instead,
		 * the way core's search forms carry `post_type`.
		 */
		$action = isset( $args['base_url'] ) ? (string) $args['base_url'] : '';

		unset( $args['base_url'] );

		$query = (string) wp_parse_url( $action, PHP_URL_QUERY );

		if ( '' !== $query ) {
			parse_str( $query, $base_args );

			foreach ( $base_args as $name => $value ) {
				if ( is_string( $value ) && 0 !== strpos( (string) $name, 'hpsw_' ) && ! isset( $fields[ $name ] ) ) {
					$fields[ sanitize_key( $name ) ] = [
						'type'    => 'hidden',
						'default' => $value,
						'_order'  => 1,
					];
				}
			}

			$action = strtok( $action, '?' );
		}

		$args = hp\merge_arrays(
			[
				'method'     => 'GET',
				'action'     => $action,
				'fields'     => $fields,

				'attributes' => [
					'class' => [ 'hpsw-filter' ],
				],

				'button'     => [
					'label' => esc_html__( 'Filter', 'social-walls-for-hivepress' ),
				],
			],
			$args
		);

		parent::__construct( $args );
	}
}
