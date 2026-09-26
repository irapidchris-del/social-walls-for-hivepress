<?php
/**
 * Note field.
 *
 * @package Social_Walls
 */

namespace HivePress\Fields;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * A form row that holds only a label and a line of help, with no control.
 *
 * Used on the Deal form when coupons come from HivePress Marketplace and the Vendor has none yet:
 * there is nothing to pick, so the row says where to create one instead of showing an empty list.
 * It carries the `data-hpsw-deal` marker like the other Deal-only rows, so the form script hides it
 * on an Update. It posts nothing and is never saved.
 */
class Hpsw_Note extends Field {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Class meta values.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'      => null,
				'filterable' => false,
				'sortable'   => false,
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Sanitizes field value. A note has none.
	 */
	protected function sanitize() {
		$this->value = null;
	}

	/**
	 * Renders field HTML: an empty marker the form script can find. The words are the field's
	 * description, which the form prints under the label like any other field's help.
	 *
	 * @return string
	 */
	public function render() {
		return '<span ' . hp\html_attributes( $this->attributes ) . '></span>';
	}
}
