<?php
/**
 * Wall post photos, on the post's own page.
 *
 * Core's own `carousel-slider` component, with the markup core's Listing page uses
 * (templates/listing/view/page/listing-images.php): one photo shows as it is, several become a
 * slider, and each opens full size in core's lightbox through `data-zoom`
 * (hivepress/assets/js/frontend.js, the carousel-slider component). `hp-listing__images` comes
 * first because the component names its slider wrappers after the element's FIRST class, which is
 * what the themes style.
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

$hpsw_alt = hivepress()->hpsw_wall->get_post_label( $hpsw_post->get_id() );
?>
<div class="hp-listing__images hpsw-post__images" data-component="carousel-slider" data-aspect-ratio="<?php echo esc_attr( hivepress()->asset->get_aspect_ratio( 'landscape_large' ) ); ?>">
	<?php
	foreach ( $hpsw_image_ids as $hpsw_image_id ) :
		$hpsw_src  = wp_get_attachment_image_url( (int) $hpsw_image_id, 'hp_landscape_large' );
		$hpsw_zoom = wp_get_attachment_image_url( (int) $hpsw_image_id, 'large' );

		if ( ! $hpsw_src ) :
			continue;
		endif;
		?>
		<img src="<?php echo esc_url( $hpsw_src ); ?>" data-zoom="<?php echo esc_url( $hpsw_zoom ? $hpsw_zoom : $hpsw_src ); ?>" alt="<?php echo esc_attr( $hpsw_alt ); ?>" loading="lazy">
	<?php endforeach; ?>
</div>
