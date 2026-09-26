<?php
/**
 * Wall post card headline.
 *
 * A headline is optional, so a post without one prints nothing here rather than an empty heading.
 * Uses the Listing card's own title element and class, so the theme styles it as one.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpsw_title = trim( (string) $hpsw_post->get_title() );

if ( '' === $hpsw_title ) {
	return;
}
?>
<h4 class="hp-listing__title hpsw-post__title">
	<a href="<?php echo esc_url( hivepress()->hpsw_wall->get_post_url( $hpsw_post ) ); ?>"><?php echo esc_html( $hpsw_title ); ?></a>
</h4>
