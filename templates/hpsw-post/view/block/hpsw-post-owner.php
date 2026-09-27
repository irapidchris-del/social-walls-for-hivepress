<?php
/**
 * The owner's row on a card in the account Wall page: the post's status, then Edit, Pin, View and
 * Delete.
 *
 * Prints nothing unless the wall drew the card for its owner (the `hpsw_owner_view` context, set
 * only by the account Wall page, which lists the viewer's own posts), so public walls never show it.
 * It sits in the card's footer as a full-width row under the counts. Since 1.0.4 it replaces the
 * table row the page printed before, and offers everything that row did, plus Delete behind the
 * same confirmation pop-up as the post's edit page.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 * @var bool|null                   $hpsw_owner_view
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

if ( empty( $hpsw_owner_view ) || ! hivepress()->hpsw_wall->can_manage_post( $hpsw_post ) ) {
	return;
}

$hpsw_wall    = hivepress()->hpsw_wall;
$hpsw_id      = $hpsw_post->get_id();
$hpsw_status  = (string) $hpsw_post->get_status();
$hpsw_pill    = $hpsw_wall->get_owner_status( $hpsw_post );
$hpsw_modal   = 'hpsw_post_delete_modal_' . $hpsw_id;
$hpsw_can_pin = $hpsw_wall->get_pin_product_id() && 'publish' === $hpsw_status && ! $hpsw_post->is_expired();
?>
<div class="hpsw-post__owner">
	<span class="hpsw-post__status hp-status hp-status--<?php echo esc_attr( $hpsw_pill[0] ); ?>"><span><?php echo esc_html( $hpsw_pill[1] ); ?></span></span>

	<div class="hpsw-post__owner-actions">
		<a href="<?php echo esc_url( hivepress()->router->get_url( 'hpsw_post_edit_page', [ 'hpsw_post_id' => $hpsw_id ] ) ); ?>" class="hpsw-post__edit hp-link"><i class="hp-icon fas fa-edit"></i><span><?php esc_html_e( 'Edit', 'social-walls-for-hivepress' ); ?></span></a>

		<?php if ( $hpsw_can_pin ) : ?>
			<a href="<?php echo esc_url( wp_nonce_url( hivepress()->router->get_url( 'hpsw_post_pin_page', [ 'hpsw_post_id' => $hpsw_id ] ), 'hpsw_pin_' . $hpsw_id ) ); ?>" class="hpsw-post__pin hp-link"><i class="hp-icon fas fa-thumbtack"></i><span><?php echo $hpsw_post->is_pinned() ? esc_html__( 'Extend pin', 'social-walls-for-hivepress' ) : esc_html__( 'Pin to the top', 'social-walls-for-hivepress' ); ?></span></a>
		<?php endif; ?>

		<?php if ( 'publish' === $hpsw_status ) : ?>
			<a href="<?php echo esc_url( $hpsw_wall->get_post_url( $hpsw_post ) ); ?>" class="hpsw-post__view hp-link"><i class="hp-icon fas fa-external-link-alt"></i><span><?php esc_html_e( 'View', 'social-walls-for-hivepress' ); ?></span></a>
		<?php endif; ?>

		<a href="#<?php echo esc_attr( $hpsw_modal ); ?>" class="hpsw-post__delete hp-link"><i class="hp-icon fas fa-times"></i><span><?php echo esc_html( hivepress()->translator->get_string( 'delete' ) ); ?></span></a>
	</div>

	<?php
	// One pop-up per card, each carrying its own post, so the form deletes the post it was opened
	// from. The form sends the owner back to this page (forms/class-hpsw-post-delete.php).
	$hpsw_delete = ( new \HivePress\Blocks\Modal(
		[
			'name'    => $hpsw_modal,
			'title'   => esc_html__( 'Delete Post', 'social-walls-for-hivepress' ),
			'context' => [ 'hpsw_post' => $hpsw_post ],

			'blocks'  => [
				'hpsw_post_delete_form' => [
					'type'   => 'form',
					'form'   => 'hpsw_post_delete',
					'_order' => 10,
				],
			],
		]
	) )->render();

	// Core's block markup, escaped as it is built.
	echo $hpsw_delete; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</div>
