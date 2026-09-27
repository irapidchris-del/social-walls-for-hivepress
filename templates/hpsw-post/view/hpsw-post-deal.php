<?php
/**
 * Deal details: the coupon row, then the end date pill and the linked Listing.
 *
 * The same part on a card and on a post's own page, so the two read alike. The coupon row runs the
 * full width of its column with the code on the left and a small Copy button at the far right end.
 * On a post's own page the end date pill (the `hpsw-post-ends` part) sits under it beside the linked
 * Listing. On a card it is left out here, because since 1.0.4 the card's footer carries it on the
 * left, opposite the like and comment counts; the wall marks a card with the `hpsw_card` context.
 *
 * Copying is core's own `copy` component (hivepress/assets/js/common.js, it copies the element's
 * text), applied to the code itself; the button clicks the code, and this plugin's script swaps the
 * button label to "Copied" and its icon to a tick, and announces it through the status region.
 *
 * The button's icon is an inline SVG, not a Font Awesome class: a site whose icon font is trimmed or
 * replaced has no `fa-copy` glyph, and the `<i>` printed before 1.0.4 then left an empty gap before
 * the label.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 * @var bool|null                   $hpsw_card
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! $hpsw_post->is_deal() ) {
	return;
}

$hpsw_coupon  = trim( (string) $hpsw_post->get_coupon() );
$hpsw_on_card = ! empty( $hpsw_card );

// The linked Listing is looked up by its stored ID, never through the magic get_listing(). With no
// Listing linked that getter asks core for the post with ID 0 (Model::_get_value() ->
// Model::get_model_object() -> Post::get(), hivepress/includes/models/class-post.php:51, core
// 1.7.31), and get_post( 0 ) is WordPress's GLOBAL post. On a Vendor page the Listings loop leaves
// that set to one of the Vendor's Listings, so a Deal linked to nothing showed "[a Listing]" on the
// profile wall and on no other page (fixed in 1.0.3).
$hpsw_listing_id = absint( $hpsw_post->get_listing__id() );
$hpsw_listing    = $hpsw_listing_id ? \HivePress\Models\Listing::query()->get_by_id( $hpsw_listing_id ) : null;

if ( ! $hpsw_listing instanceof \HivePress\Models\Listing || 'publish' !== $hpsw_listing->get_status() ) {
	$hpsw_listing = null;
}

$hpsw_show = '' !== $hpsw_coupon && ! $hpsw_post->is_expired();

// The end date pill, rendered through its own part so a theme can replace it in one place.
$hpsw_ends = '';

if ( ! $hpsw_on_card ) {
	$hpsw_ends = ( new \HivePress\Blocks\Part(
		[
			'path'    => 'hpsw-post/view/hpsw-post-ends',
			'context' => [ 'hpsw_post' => $hpsw_post ],
		]
	) )->render();
}

if ( ! $hpsw_show && '' === trim( $hpsw_ends ) && ! $hpsw_listing ) {
	return;
}
?>
<div class="hpsw-post__deal">
	<?php if ( $hpsw_show ) : ?>
		<div class="hpsw-post__coupon">
			<span class="hpsw-post__coupon-label hp-meta"><?php esc_html_e( 'Code', 'social-walls-for-hivepress' ); ?></span>
			<code class="hpsw-post__coupon-code" data-component="copy" data-hpsw-copy title="<?php esc_attr_e( 'Copy the code', 'social-walls-for-hivepress' ); ?>"><?php echo esc_html( $hpsw_coupon ); ?></code>
			<button type="button" class="hpsw-post__coupon-copy hp-button button button--secondary" data-hpsw-copy-button title="<?php esc_attr_e( 'Copy the code', 'social-walls-for-hivepress' ); ?>">
				<svg class="hpsw-post__copy-icon hpsw-post__copy-icon--copy" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M11 9h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-8a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2zM15 9V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h4"/></svg>
				<svg class="hpsw-post__copy-icon hpsw-post__copy-icon--done" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.5l5 5L19.5 7"/></svg>
				<span data-hpsw-copy-label><?php esc_html_e( 'Copy', 'social-walls-for-hivepress' ); ?></span>
			</button>
			<span class="screen-reader-text" role="status" data-hpsw-copy-status></span>
		</div>
	<?php endif; ?>

	<?php if ( '' !== trim( $hpsw_ends ) || $hpsw_listing ) : ?>
		<div class="hpsw-post__deal-meta">
			<?php
			// Built from escaped values by the hpsw-post-ends part.
			echo $hpsw_ends; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>

			<?php if ( $hpsw_listing ) : ?>
				<a href="<?php echo esc_url( hivepress()->router->get_url( 'listing_view_page', [ 'listing_id' => $hpsw_listing->get_id() ] ) ); ?>" class="hpsw-post__listing hp-link">
					<i class="hp-icon fas fa-tag"></i><span><?php echo esc_html( $hpsw_listing->get_title() ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
