<?php
/**
 * Wall post text in full, on the post's own page.
 *
 * Escaped first, then web addresses are turned into links, so nothing a Vendor typed can ever
 * become markup; only the anchors make_clickable() builds from escaped text survive.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpsw_text = trim( wp_strip_all_tags( (string) $hpsw_post->get_text() ) );

if ( '' === $hpsw_text ) {
	return;
}
?>
<div class="hpsw-post__text hp-listing__description">
	<?php
	echo wp_kses(
		wpautop( make_clickable( esc_html( $hpsw_text ) ) ),
		[
			'p'  => [],
			'br' => [],
			'a'  => [
				'href' => [],
				'rel'  => [],
			],
		]
	);
	?>
</div>
