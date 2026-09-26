<?php
/**
 * Wall Post Awaiting Approval email.
 *
 * @package Social_Walls
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to the site administrator when a wall post is waiting for approval.
 *
 * The label makes it editable under HivePress, Emails (components/class-email.php:59-91), and makes
 * Notifications for HivePress list it as a notification type named after this class. The tokens are
 * passed to sprintf() rather than written into the translatable string: the I18n fixer reads "%u"
 * inside "%user_name%" as a printf placeholder and renumbers it into a token that matches nothing
 * (resources/releasing.md, Packaging, step 1).
 */
class Hpsw_Post_Submit extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Wall Post Awaiting Approval', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to the site administrator when a Vendor adds or changes a wall post while approval is required under Settings, Social Walls.', 'social-walls-for-hivepress' ),
				'recipient'   => esc_html__( 'Site administrator', 'social-walls-for-hivepress' ),
				'tokens'      => [ 'vendor_name', 'post_text', 'post_url', 'vendor' ],
			],
			$meta
		);

		parent::init( $meta );
	}

	/**
	 * Class constructor.
	 *
	 * @param array $args Email arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'subject' => esc_html__( 'Wall post awaiting approval', 'social-walls-for-hivepress' ),

				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the Vendor's name, 2: the start of the post, 3: the link to review it. All filled in automatically. */
						__( 'A wall post from %1$s is waiting for your approval: %2$s Review it here: %3$s', 'social-walls-for-hivepress' ),
						'%vendor_name%',
						'%post_text%',
						'%post_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
