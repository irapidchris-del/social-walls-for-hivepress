<?php
/**
 * A Deal's end date as a small pill: "Ends on" a date, or "Ended on" once it has passed.
 *
 * On a card it sits on the left of the footer, opposite the like and comment counts (since 1.0.4). On
 * a post's own page the Deal box prints it, beside the linked Listing. It is the `hp-status` pill of
 * the byline's Deal / Update badge, made quieter.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( ! $hpsw_post->is_deal() ) {
	return;
}

$hpsw_end = (string) $hpsw_post->get_expire_date();

if ( '' === $hpsw_end ) {
	return;
}

$hpsw_end_date = date_create_immutable_from_format( 'Y-m-d', $hpsw_end, wp_timezone() );

if ( ! $hpsw_end_date ) {
	return;
}

$hpsw_ended = $hpsw_post->is_expired();
?>
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
