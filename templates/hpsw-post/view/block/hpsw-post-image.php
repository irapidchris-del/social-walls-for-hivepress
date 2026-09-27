<?php
/**
 * Wall post card photo.
 *
 * Prints nothing for a text-only post. Sits in the card's content, not in a Listing card's
 * `hp-listing__header`, on purpose: the themes restyle that header as the Listing's own picture,
 * and not as a photo in a post. ExpertHive draws it as a small round thumbnail beside the text and
 * hides it altogether on a phone, and MeetingHive's cards have no picture at all (measured on all
 * six themes, 26 Sept 2026). A photo someone posted has to show on every theme.
 *
 * With Photo Zoom off (Settings, Display) the photo is not a link: nothing happens when it is
 * clicked. The post still opens from its title and its other links.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpsw_image_ids = array_filter( (array) $hpsw_post->get_images__id() );

if ( ! $hpsw_image_ids ) {
	return;
}

$hpsw_image_url = wp_get_attachment_image_url( (int) reset( $hpsw_image_ids ), 'hp_landscape_small' );

if ( ! $hpsw_image_url ) {
	return;
}
?>
<div class="hpsw-post__image">
	<?php if ( hivepress()->hpsw_wall->is_photo_zoom_enabled() ) : ?>
		<a href="<?php echo esc_url( hivepress()->hpsw_wall->get_post_url( $hpsw_post ) ); ?>">
	<?php else : ?>
		<span class="hpsw-post__image-frame">
	<?php endif; ?>
		<img src="<?php echo esc_url( $hpsw_image_url ); ?>" alt="<?php echo esc_attr( hivepress()->hpsw_wall->get_post_label( $hpsw_post->get_id() ) ); ?>" loading="lazy">
		<?php if ( count( $hpsw_image_ids ) > 1 ) : ?>
			<span class="hpsw-post__image-count"><i class="hp-icon fas fa-images"></i> <?php echo esc_html( number_format_i18n( count( $hpsw_image_ids ) ) ); ?></span>
		<?php endif; ?>
	<?php if ( hivepress()->hpsw_wall->is_photo_zoom_enabled() ) : ?>
		</a>
	<?php else : ?>
		</span>
	<?php endif; ?>
</div>
