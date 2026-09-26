<?php
/**
 * Comment types configuration.
 *
 * Read by core's Comment component (hivepress/includes/components/class-comment.php:52-66, core
 * 1.7.31), which hides every NON-public type from any comment query that does not name a type. A
 * like is bookkeeping, so it stays out of the wp-admin Comments screen and every recent-comments
 * widget. A wall comment is something a person wrote on the site, so it stays public, exactly as
 * Reviews declares its own type, and an administrator can unapprove or delete it from Comments.
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

return [
	'hpsw_comment' => [
		'public' => true,
	],

	'hpsw_like'    => [
		'public' => false,
	],
];
