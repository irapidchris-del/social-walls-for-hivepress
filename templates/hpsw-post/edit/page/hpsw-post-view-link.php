<?php
/**
 * View link on a post's edit page, shown once the post is live.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( 'publish' !== $hpsw_post->get_status() ) {
	return;
}
?>
<a href="<?php echo esc_url( hivepress()->hpsw_wall->get_post_url( $hpsw_post ) ); ?>" class="hp-listing__action hp-link"><i class="hp-icon fas fa-external-link-alt"></i><span><?php esc_html_e( 'View Post', 'social-walls-for-hivepress' ); ?></span></a>
