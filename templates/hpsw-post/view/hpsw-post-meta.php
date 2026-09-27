<?php
/**
 * Wall post byline: the Vendor, when, and what kind of post.
 *
 * Variables are prefixed because template locals are reported as globals by Plugin Check, which
 * cannot see that HivePress includes this file from inside a method (blocks/class-part.php).
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpsw_vendor = $hpsw_post->get_vendor();

if ( ! $hpsw_vendor instanceof \HivePress\Models\Vendor ) {
	return;
}

$hpsw_vendor_url = hivepress()->router->get_url( 'vendor_view_page', [ 'vendor_id' => $hpsw_vendor->get_id() ] );
$hpsw_image      = $hpsw_vendor->get_image__url( 'hp_square_small' );

if ( ! $hpsw_image ) {
	$hpsw_image = hivepress()->asset->get_image_url( get_option( 'hp_vendor_placeholder_image' ), 'hp_square_small', hivepress()->get_url() . '/assets/images/placeholders/user-square.svg' );
}

// WordPress leaves the GMT date of a pending or hidden post at 0000-00-00 until it is published, which
// printed "November 30, -0001" on those cards in the account Wall page. Their local date is real, so
// it is converted instead.
$hpsw_gmt = (string) $hpsw_post->get_created_date_gmt();

if ( '' === $hpsw_gmt || 0 === strpos( $hpsw_gmt, '0000' ) ) {
	$hpsw_gmt = get_gmt_from_date( (string) $hpsw_post->get_created_date() );
}

$hpsw_time = strtotime( $hpsw_gmt . ' UTC' );
?>
<div class="hpsw-post__byline">
	<a href="<?php echo esc_url( $hpsw_vendor_url ); ?>" class="hpsw-post__avatar">
		<img src="<?php echo esc_url( $hpsw_image ); ?>" alt="" loading="lazy">
	</a>
	<div class="hpsw-post__byline-text">
		<a href="<?php echo esc_url( $hpsw_vendor_url ); ?>" class="hpsw-post__vendor"><?php echo esc_html( $hpsw_vendor->get_name() ); ?></a>
		<?php if ( $hpsw_time ) : ?>
			<time class="hpsw-post__date hp-meta" datetime="<?php echo esc_attr( gmdate( 'c', $hpsw_time ) ); ?>">
				<?php
				echo esc_html(
					time() - $hpsw_time < WEEK_IN_SECONDS
						/* translators: %s: time since, for example "3 hours". */
						? sprintf( esc_html__( '%s ago', 'social-walls-for-hivepress' ), human_time_diff( $hpsw_time ) )
						: wp_date( get_option( 'date_format' ), $hpsw_time )
				);
				?>
			</time>
		<?php endif; ?>
	</div>
	<div class="hpsw-post__badges">
		<?php if ( $hpsw_post->is_pinned() ) : ?>
			<span class="hpsw-post__pinned hp-status hp-status--pending"><span><i class="hp-icon fas fa-thumbtack"></i> <?php esc_html_e( 'Pinned', 'social-walls-for-hivepress' ); ?></span></span>
		<?php endif; ?>
		<?php if ( $hpsw_post->is_deal() ) : ?>
			<span class="hpsw-post__type hp-status hp-status--<?php echo esc_attr( $hpsw_post->is_ended() ? 'trash' : 'publish' ); ?>"><span><?php echo $hpsw_post->is_ended() ? esc_html__( 'Deal ended', 'social-walls-for-hivepress' ) : esc_html__( 'Deal', 'social-walls-for-hivepress' ); ?></span></span>
		<?php else : ?>
			<span class="hpsw-post__type hp-status"><span><?php esc_html_e( 'Update', 'social-walls-for-hivepress' ); ?></span></span>
		<?php endif; ?>
	</div>
</div>
