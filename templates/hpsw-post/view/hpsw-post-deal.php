<?php
/**
 * Deal details: the coupon row, then the end date pill and the linked Listing.
 *
 * The same part on a card and on a post's own page, so the two read alike. The coupon row runs the
 * full width of its column with the code on the left and a small Copy button at the far right end
 * (site owner's request, 28 Sept 2026). The end date is a small tinted pill in the `hp-status` family,
 * the same pill as the Deal / Update badge in the byline but quieter, so the card footer holds only
 * the like and comment counts.
 *
 * Copying is core's own `copy` component (hivepress/assets/js/common.js, it copies the element's
 * text), applied to the code itself; the button clicks the code, and this plugin's script swaps the
 * button label to "Copied" and announces it through the status region.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! $hpsw_post->is_deal() ) {
	return;
}

$hpsw_coupon = trim( (string) $hpsw_post->get_coupon() );
$hpsw_end    = (string) $hpsw_post->get_expire_date();

// The linked Listing is looked up by its stored ID, never through the magic get_listing(). With no
// Listing linked that getter asks core for the post with ID 0 (Model::_get_value() ->
// Model::get_model_object() -> Post::get(), hivepress/includes/models/class-post.php:51, core
// 1.7.31), and get_post( 0 ) is WordPress's GLOBAL post. On a Vendor page the Listings loop leaves
// that set to one of the Vendor's Listings, so a Deal linked to nothing showed "[a Listing]" on the
// profile wall and on no other page (reported 28 Sept 2026, fixed in 1.0.3).
$hpsw_listing_id = absint( $hpsw_post->get_listing__id() );
$hpsw_listing    = $hpsw_listing_id ? \HivePress\Models\Listing::query()->get_by_id( $hpsw_listing_id ) : null;

if ( ! $hpsw_listing instanceof \HivePress\Models\Listing || 'publish' !== $hpsw_listing->get_status() ) {
	$hpsw_listing = null;
}

$hpsw_ended    = $hpsw_post->is_expired();
$hpsw_end_date = '' !== $hpsw_end ? date_create_immutable_from_format( 'Y-m-d', $hpsw_end, wp_timezone() ) : false;
$hpsw_show     = '' !== $hpsw_coupon && ! $hpsw_ended;

if ( ! $hpsw_show && ! $hpsw_end_date && ! $hpsw_listing ) {
	return;
}
?>
<div class="hpsw-post__deal">
	<?php if ( $hpsw_show ) : ?>
		<div class="hpsw-post__coupon">
			<span class="hpsw-post__coupon-label hp-meta"><?php esc_html_e( 'Code', 'social-walls-for-hivepress' ); ?></span>
			<code class="hpsw-post__coupon-code" data-component="copy" data-hpsw-copy title="<?php esc_attr_e( 'Copy the code', 'social-walls-for-hivepress' ); ?>"><?php echo esc_html( $hpsw_coupon ); ?></code>
			<button type="button" class="hpsw-post__coupon-copy hp-button button button--secondary" data-hpsw-copy-button title="<?php esc_attr_e( 'Copy the code', 'social-walls-for-hivepress' ); ?>"><i class="hp-icon fas fa-copy" aria-hidden="true"></i><span data-hpsw-copy-label><?php esc_html_e( 'Copy', 'social-walls-for-hivepress' ); ?></span></button>
			<span class="screen-reader-text" role="status" data-hpsw-copy-status></span>
		</div>
	<?php endif; ?>

	<?php if ( $hpsw_end_date || $hpsw_listing ) : ?>
		<div class="hpsw-post__deal-meta">
			<?php if ( $hpsw_end_date ) : ?>
				<span class="hpsw-post__ends hp-status<?php echo $hpsw_ended ? ' hpsw-post__ends--ended' : ''; ?>">
					<span>
						<i class="hp-icon fas fa-hourglass-half" aria-hidden="true"></i>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: date. */
								$hpsw_ended ? esc_html__( 'Ended on %s', 'social-walls-for-hivepress' ) : esc_html__( 'Ends on %s', 'social-walls-for-hivepress' ),
								wp_date( get_option( 'date_format' ), $hpsw_end_date->setTime( 12, 0 )->getTimestamp() )
							)
						);
						?>
					</span>
				</span>
			<?php endif; ?>

			<?php if ( $hpsw_listing ) : ?>
				<a href="<?php echo esc_url( hivepress()->router->get_url( 'listing_view_page', [ 'listing_id' => $hpsw_listing->get_id() ] ) ); ?>" class="hpsw-post__listing hp-link">
					<i class="hp-icon fas fa-tag"></i><span><?php echo esc_html( $hpsw_listing->get_title() ); ?></span>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
