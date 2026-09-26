<?php
/**
 * New Wall Post from a Followed Vendor email.
 *
 * @package Social_Walls
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to each follower of a Vendor when that Vendor's post goes live. Needs Follow Vendors for HivePress.
 *
 * The label makes it editable under HivePress, Emails (components/class-email.php:59-91), and makes
 * Notifications for HivePress list it as a notification type named after this class. The tokens are
 * passed to sprintf() rather than written into the translatable string: the I18n fixer reads "%u"
 * inside "%user_name%" as a printf placeholder and renumbers it into a token that matches nothing
 * (resources/releasing.md, Packaging, step 1).
 */
class Hpsw_Post_Follow extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'New Wall Post from a Followed Vendor', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to users who follow a Vendor when that Vendor posts a Deal or an Update on their wall.', 'social-walls-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'user' ),
				'tokens'      => [ 'user_name', 'vendor_name', 'post_type', 'post_text', 'post_url', 'user', 'vendor' ],
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
				'subject' => sprintf(
					/* translators: 1: "Deal" or "Update", 2: the Vendor's name. Both filled in automatically. */
					esc_html__( 'New %1$s from %2$s', 'social-walls-for-hivepress' ),
					'%post_type%',
					'%vendor_name%'
				),

				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the follower's name, 2: the Vendor's name, 3: "Deal" or "Update", 4: the start of the post, 5: the link to the post. All filled in automatically. */
						__( 'Hi, %1$s! %2$s, a Vendor you follow, has posted a new %3$s: %4$s Read it here: %5$s', 'social-walls-for-hivepress' ),
						'%user_name%',
						'%vendor_name%',
						'%post_type%',
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
