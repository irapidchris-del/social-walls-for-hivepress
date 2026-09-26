<?php
/**
 * New Comment on a Wall Post email.
 *
 * @package Social_Walls
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to a Vendor when someone comments on one of their wall posts.
 *
 * The label makes it editable under HivePress, Emails (components/class-email.php:59-91), and makes
 * Notifications for HivePress list it as a notification type named after this class. The tokens are
 * passed to sprintf() rather than written into the translatable string: the I18n fixer reads "%u"
 * inside "%user_name%" as a printf placeholder and renumbers it into a token that matches nothing
 * (resources/releasing.md, Packaging, step 1).
 */
class Hpsw_Comment_Add extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'New Comment on a Wall Post', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a Vendor when someone comments on one of their wall posts.', 'social-walls-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'vendor' ),
				'tokens'      => [ 'user_name', 'author_name', 'comment_text', 'post_text', 'post_url', 'user' ],
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
				'subject' => esc_html__( 'New comment on your wall post', 'social-walls-for-hivepress' ),

				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the Vendor's name, 2: the commenter's name, 3: the start of the post, 4: the comment, 5: the link to the comment. All filled in automatically. */
						__( 'Hi, %1$s! %2$s has commented on your wall post "%3$s": %4$s Read and reply here: %5$s', 'social-walls-for-hivepress' ),
						'%user_name%',
						'%author_name%',
						'%post_text%',
						'%comment_text%',
						'%post_url%'
					)
				),
			],
			$args
		);

		parent::__construct( $args );
	}
}
