<?php
/**
 * Wall post actions: the like heart with its count, and the comments link.
 *
 * Signed-out visitors get core's sign-in modal from the heart instead of an error, the same thing
 * core's own toggles do (blocks/class-toggle.php). The counts come from the page's one grouped
 * query when the wall passes them in, and are looked up here otherwise, so a template override that
 * forgets them still works.
 *
 * On a post's own page the row sits on the right after the photos, which since 1.0.3 come last, so one
 * placement covers posts with and without photos. The optional `hpsw_actions_slot` context ("media"
 * or "text") is still honoured for template overrides written for 1.0.2, which placed the part twice
 * and printed only the slot that fitted the post. A card passes no slot.
 *
 * @package Social_Walls
 * @var \HivePress\Models\Hpsw_Post $hpsw_post
 * @var array|null                  $hpsw_engagement
 * @var string|null                 $hpsw_actions_slot
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

$hpsw_slot = isset( $hpsw_actions_slot ) ? (string) $hpsw_actions_slot : '';

if ( '' !== $hpsw_slot && ( 'media' === $hpsw_slot ) !== (bool) array_filter( (array) $hpsw_post->get_images__id() ) ) {
	return;
}

$hpsw_wall = hivepress()->hpsw_wall;

if ( ! isset( $hpsw_engagement ) || ! is_array( $hpsw_engagement ) || ! isset( $hpsw_engagement['likes'] ) ) {
	$hpsw_counts     = $hpsw_wall->get_engagement( [ $hpsw_post->get_id() ] );
	$hpsw_engagement = $hpsw_counts[ $hpsw_post->get_id() ];
}

$hpsw_likes    = $hpsw_wall->are_likes_enabled();
$hpsw_comments = $hpsw_wall->are_comments_enabled();

if ( ! $hpsw_likes && ! $hpsw_comments ) {
	return;
}

$hpsw_liked = ! empty( $hpsw_engagement['liked'] );
?>
<div class="hp-listing__actions hp-listing__actions--primary hpsw-post__actions<?php echo '' !== $hpsw_slot ? ' hpsw-post__actions--' . esc_attr( $hpsw_slot ) : ''; ?>">
	<?php
	// The pair sits in a wrapper of its own so they stay together on the right whatever a theme or
	// the site's own CSS does to `.hp-listing__actions`: a site stylesheet that spread its children
	// with `justify-content: space-between` put the heart and the comment count 84px apart
	// (measured 28 Sept 2026, 1300px). The wrapper is the container's only child and keeps its
	// place with an auto margin, which wins over any justify-content.
	?>
	<span class="hpsw-post__counts">
	<?php if ( $hpsw_likes ) : ?>
		<?php if ( is_user_logged_in() ) : ?>
			<button type="button" class="hpsw-post__like hp-link<?php echo $hpsw_liked ? ' is-liked' : ''; ?>" data-hpsw-like="<?php echo esc_attr( (string) $hpsw_post->get_id() ); ?>" aria-pressed="<?php echo $hpsw_liked ? 'true' : 'false'; ?>" title="<?php echo $hpsw_liked ? esc_attr__( 'Remove your like', 'social-walls-for-hivepress' ) : esc_attr__( 'Like this post', 'social-walls-for-hivepress' ); ?>">
				<i class="hp-icon fas fa-heart"></i>
				<span class="hpsw-post__count" data-hpsw-like-count><?php echo esc_html( number_format_i18n( absint( $hpsw_engagement['likes'] ) ) ); ?></span>
				<span class="screen-reader-text"><?php esc_html_e( 'likes', 'social-walls-for-hivepress' ); ?></span>
			</button>
		<?php else : ?>
			<a href="#user_login_modal" class="hpsw-post__like hp-link" title="<?php esc_attr_e( 'Sign in to like this post', 'social-walls-for-hivepress' ); ?>">
				<i class="hp-icon fas fa-heart"></i>
				<span class="hpsw-post__count"><?php echo esc_html( number_format_i18n( absint( $hpsw_engagement['likes'] ) ) ); ?></span>
				<span class="screen-reader-text"><?php esc_html_e( 'likes', 'social-walls-for-hivepress' ); ?></span>
			</a>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $hpsw_comments ) : ?>
		<a href="<?php echo esc_url( $hpsw_wall->get_post_url( $hpsw_post ) . '#hpsw-comments' ); ?>" class="hpsw-post__comments hp-link" title="<?php esc_attr_e( 'Comments', 'social-walls-for-hivepress' ); ?>">
			<i class="hp-icon fas fa-comment"></i>
			<span class="hpsw-post__count"><?php echo esc_html( number_format_i18n( absint( $hpsw_engagement['comments'] ) ) ); ?></span>
			<span class="screen-reader-text"><?php esc_html_e( 'comments', 'social-walls-for-hivepress' ); ?></span>
		</a>
	<?php endif; ?>
	</span>
</div>
