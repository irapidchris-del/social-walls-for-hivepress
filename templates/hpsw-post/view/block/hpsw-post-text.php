<?php
/**
 * Wall post card text, shortened, with a link to the whole post.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpsw_text  = trim( wp_strip_all_tags( (string) $hpsw_post->get_text() ) );
$hpsw_short = wp_trim_words( $hpsw_text, 40, '' );
?>
<div class="hpsw-post__text">
	<p>
		<?php
		echo nl2br( esc_html( $hpsw_short ) );

		if ( $hpsw_short !== $hpsw_text ) :
			?>
			&hellip; <a href="<?php echo esc_url( hivepress()->hpsw_wall->get_post_url( $hpsw_post ) ); ?>" class="hpsw-post__more"><?php esc_html_e( 'Read more', 'social-walls-for-hivepress' ); ?></a>
		<?php endif; ?>
	</p>
</div>
