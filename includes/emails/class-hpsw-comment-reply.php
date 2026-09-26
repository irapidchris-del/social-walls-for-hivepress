<?php
/**
 * Reply to a Wall Post Comment email.
 *
 * @package Social_Walls
 */

namespace HivePress\Emails;

use HivePress\Helpers as hp;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sent to a commenter when someone replies to their comment on a wall post.
 *
 * The label makes it editable under HivePress, Emails (components/class-email.php:59-91), and makes
 * Notifications for HivePress list it as a notification type named after this class. The tokens are
 * passed to sprintf() rather than written into the translatable string: the I18n fixer reads "%u"
 * inside "%user_name%" as a printf placeholder and renumbers it into a token that matches nothing
 * (resources/releasing.md, Packaging, step 1).
 */
class Hpsw_Comment_Reply extends Email {

	/**
	 * Class initializer.
	 *
	 * @param array $meta Email meta.
	 */
	public static function init( $meta = [] ) {
		$meta = hp\merge_arrays(
			[
				'label'       => esc_html__( 'Reply to a Wall Post Comment', 'social-walls-for-hivepress' ),
				'description' => esc_html__( 'This email is sent to a member when someone replies to their comment on a wall post.', 'social-walls-for-hivepress' ),
				'recipient'   => hivepress()->translator->get_string( 'user' ),
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
				'subject' => esc_html__( 'New reply to your comment', 'social-walls-for-hivepress' ),

				'body'    => hp\sanitize_html(
					sprintf(
						/* translators: 1: the member's name, 2: the replier's name, 3: the start of the post, 4: the reply, 5: the link to the reply. All filled in automatically. */
						__( 'Hi, %1$s! %2$s has replied to your comment on "%3$s": %4$s Read it here: %5$s', 'social-walls-for-hivepress' ),
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
