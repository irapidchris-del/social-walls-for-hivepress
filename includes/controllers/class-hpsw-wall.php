<?php
/**
 * Wall controller.
 *
 * @package Social_Walls
 */

namespace HivePress\Controllers;

use HivePress\Helpers as hp;
use HivePress\Blocks;
use HivePress\Forms;
use HivePress\Models;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Wall routes: the account pages, the public post page and the REST actions behind them.
 *
 * Every HivePress REST route registers with `permission_callback => '__return_true'`
 * (hivepress/includes/components/class-router.php:403-417, core 1.7.31), so each action below does
 * its own authentication and ownership checks before anything else. A route that forgot would be a
 * public endpoint.
 *
 * Page routes are named exactly like their template classes (`hpsw_wall_edit_page` renders
 * `Hpsw_Wall_Edit_Page`), because core derives the page's `hp-template--*` body classes from the
 * ROUTE name, and a prefixed template on an unprefixed route gets none at all, losing every account
 * page style the theme keys on them (resources/hivepress-ui.md, "A prefixed template on an
 * unprefixed route gets NO body classes").
 */
final class Hpsw_Wall extends Controller {

	/**
	 * Class constructor.
	 *
	 * @param array $args Controller arguments.
	 */
	public function __construct( $args = [] ) {
		$args = hp\merge_arrays(
			[
				'routes' => [
					'hpsw_posts_resource'        => [
						'path' => '/hpsw-posts',
						'rest' => true,
					],

					'hpsw_post_resource'         => [
						'base' => 'hpsw_posts_resource',
						'path' => '/(?P<hpsw_post_id>\d+)',
						'rest' => true,
					],

					'hpsw_post_update_action'    => [
						'base'   => 'hpsw_post_resource',
						'method' => 'POST',
						'action' => [ $this, 'update_post' ],
						'rest'   => true,
					],

					'hpsw_post_delete_action'    => [
						'base'   => 'hpsw_post_resource',
						'method' => 'DELETE',
						'action' => [ $this, 'delete_post' ],
						'rest'   => true,
					],

					'hpsw_post_like_action'      => [
						'base'   => 'hpsw_post_resource',
						'path'   => '/like',
						'method' => 'POST',
						'action' => [ $this, 'toggle_like' ],
						'rest'   => true,
					],

					'hpsw_post_comment_action'   => [
						'base'   => 'hpsw_post_resource',
						'path'   => '/comments',
						'method' => 'POST',
						'action' => [ $this, 'create_comment' ],
						'rest'   => true,
					],

					'hpsw_comment_delete_action' => [
						'path'   => '/hpsw-comments/(?P<hpsw_comment_id>\d+)',
						'method' => 'DELETE',
						'action' => [ $this, 'delete_comment' ],
						'rest'   => true,
					],

					// Account page: the Vendor's own wall.
					'hpsw_wall_edit_page'        => [
						'title'    => esc_html__( 'Wall', 'social-walls-for-hivepress' ),
						'base'     => 'user_account_page',
						'path'     => '/wall',
						'redirect' => [ $this, 'redirect_wall_edit_page' ],
						'action'   => [ $this, 'render_wall_edit_page' ],
					],

					// Account page: a new post.
					'hpsw_post_submit_page'      => [
						'title'    => esc_html__( 'New Post', 'social-walls-for-hivepress' ),
						'base'     => 'hpsw_wall_edit_page',
						'path'     => '/new',
						'redirect' => [ $this, 'redirect_post_submit_page' ],
						'action'   => [ $this, 'render_post_submit_page' ],
					],

					// Account page: editing a post.
					'hpsw_post_edit_page'        => [
						'title'    => esc_html__( 'Edit Post', 'social-walls-for-hivepress' ),
						'base'     => 'hpsw_wall_edit_page',
						'path'     => '/(?P<hpsw_post_id>\d+)',
						'redirect' => [ $this, 'redirect_post_edit_page' ],
						'action'   => [ $this, 'render_post_edit_page' ],
					],

					// Redirect only: puts the pinning product in the basket and goes to the checkout.
					'hpsw_post_pin_page'         => [
						'base'     => 'hpsw_post_edit_page',
						'path'     => '/pin',
						'redirect' => [ $this, 'redirect_post_pin_page' ],
					],

					// Public page: one post with its comments.
					'hpsw_post_view_page'        => [
						'title'    => [ $this, 'get_post_view_title' ],
						'path'     => '/wall-post/(?P<hpsw_post_id>\d+)',
						'redirect' => [ $this, 'redirect_post_view_page' ],
						'action'   => [ $this, 'render_post_view_page' ],
					],
				],
			],
			$args
		);

		parent::__construct( $args );
	}

	/*
	|--------------------------------------------------------------------------
	| REST actions
	|--------------------------------------------------------------------------
	*/

	/**
	 * Saves a post: the first submit of a new one, or an edit.
	 *
	 * The form is the schema: its fields are exactly what this endpoint accepts
	 * (resources/hivepress-framework.md, "REST payloads and responses"). The same form class builds
	 * the page and validates the request, so the Listing choices a Vendor was offered are the only
	 * ones that validate.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_post( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$post = Models\Hpsw_Post::query()->get_by_id( absint( $request->get_param( 'hpsw_post_id' ) ) );

		if ( ! $post instanceof Models\Hpsw_Post ) {
			return hp\rest_error( 404 );
		}

		$wall     = hivepress()->hpsw_wall;
		$is_admin = current_user_can( 'edit_others_posts' );
		$status   = (string) $post->get_status();

		if ( ! $wall->can_manage_post( $post ) ) {
			return hp\rest_error( 403 );
		}

		if ( 'pending' === $status && ! $is_admin ) {
			return hp\rest_error( 403, esc_html__( 'This post is waiting for approval and cannot be changed until it has been reviewed.', 'social-walls-for-hivepress' ) );
		}

		if ( ! in_array( $status, [ 'auto-draft', 'draft', 'publish', 'pending' ], true ) ) {
			return hp\rest_error( 403 );
		}

		$vendor = $post->get_vendor();

		if ( ! $vendor instanceof Models\Vendor || ( 'publish' !== $vendor->get_status() && ! $is_admin ) ) {
			return hp\rest_error( 403 );
		}

		$is_new = 'auto-draft' === $status;

		// Posting rights and the monthly allowance are checked when a post is first submitted. An edit
		// of a post already on the wall is never refused for either.
		if ( $is_new && ! $is_admin ) {
			if ( ! $wall->can_post( $vendor ) ) {
				return hp\rest_error( 403, esc_html__( 'Your plan does not include wall posts.', 'social-walls-for-hivepress' ) );
			}

			if ( 0 === $wall->get_remaining( $vendor ) ) {
				return hp\rest_error( 403, $wall->get_limit_message( $vendor ) );
			}
		}

		$form = new Forms\Hpsw_Post_Update( [ 'model' => $post ] );

		$form->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		$values = $form->get_values();

		// A coupon picked from the Vendor's own Marketplace coupons. The form offered only theirs, and
		// the choice is checked again here against the post's own Vendor, never taken on trust.
		if ( array_key_exists( 'hpsw_coupon_id', $values ) ) {
			$coupon = 'deal' === hp\get_array_value( $values, 'type' ) ? $wall->resolve_coupon_choice( $values['hpsw_coupon_id'], $vendor->get_id() ) : null;

			if ( is_wp_error( $coupon ) ) {
				return hp\rest_error( 400, $coupon->get_error_message() );
			}

			if ( is_array( $coupon ) ) {
				$values['coupon'] = $coupon['code'];

				// The Deal ends when its coupon does, unless the Vendor chose an earlier day themselves.
				if ( '' !== $coupon['expires'] && ! hp\get_array_value( $values, 'expire_date' ) ) {
					$values['expire_date'] = $coupon['expires'];
				}
			}
		}

		unset( $values['hpsw_coupon_id'] );

		// The Deal-only details mean nothing on an Update, and a stale end date would even make an
		// Update vanish from the walls, so they are cleared rather than kept out of sight.
		if ( 'deal' !== hp\get_array_value( $values, 'type' ) ) {
			$values['coupon']      = null;
			$values['expire_date'] = null;
			$values['listing']     = null;
		}

		$post->fill( $values );

		$moderated = $wall->is_moderation_enabled() && ! $is_admin;

		if ( $is_new ) {
			$post->fill(
				[
					'status'           => $moderated ? 'pending' : 'publish',

					// The auto-draft was created when the form was opened, possibly days ago; the post is
					// dated when it is actually submitted, which is also the month it counts towards.
					'created_date'     => current_time( 'mysql' ),
					'created_date_gmt' => current_time( 'mysql', true ),
				]
			);
		} elseif ( $moderated && 'publish' === $status ) {

			// Under approval, a change to a live post is reviewed like a new one; otherwise approval
			// could be sidestepped by posting something harmless and editing it afterwards.
			$post->set_status( 'pending' );
		}

		if ( ! $post->save() ) {
			return hp\rest_error( 400, $post->_get_errors() );
		}

		return hp\rest_response(
			200,
			[
				'id' => $post->get_id(),
			]
		);
	}

	/**
	 * Deletes a post, by moving it to the bin.
	 *
	 * Trash, never a hard delete: an administrator can still restore it, and it keeps counting
	 * towards the month it was added in.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_post( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$post = Models\Hpsw_Post::query()->get_by_id( absint( $request->get_param( 'hpsw_post_id' ) ) );

		if ( ! $post instanceof Models\Hpsw_Post || 'trash' === $post->get_status() ) {
			return hp\rest_error( 404 );
		}

		if ( ! current_user_can( 'delete_others_posts' ) && get_current_user_id() !== absint( $post->get_user__id() ) ) {
			return hp\rest_error( 403 );
		}

		if ( ! $post->trash() ) {
			return hp\rest_error( 400 );
		}

		return hp\rest_response( 204 );
	}

	/**
	 * Likes or unlikes a post.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function toggle_like( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$wall = hivepress()->hpsw_wall;

		if ( ! $wall->are_likes_enabled() ) {
			return hp\rest_error( 403 );
		}

		$post = Models\Hpsw_Post::query()->get_by_id( absint( $request->get_param( 'hpsw_post_id' ) ) );

		if ( ! $wall->is_post_visible( $post ) ) {
			return hp\rest_error( 404 );
		}

		$user_id = get_current_user_id();

		/*
		 * `status => any` on purpose, as in Additional Gallery's comment likes: a like an
		 * administrator trashed still occupies the pair, and hiding it here would let a second row be
		 * created for the same person and post, orphaning the first for good.
		 */
		$existing = get_comments(
			[
				'type'    => $wall::LIKE_TYPE,
				'post_id' => $post->get_id(),
				'user_id' => $user_id,
				'status'  => 'any',
				'fields'  => 'ids',
				'number'  => 1,
			]
		);

		if ( $existing ) {
			wp_delete_comment( absint( $existing[0] ), true );

			$liked = false;
		} else {
			$like = ( new Models\Hpsw_Like() )->fill(
				[
					'user'     => $user_id,
					'post'     => $post->get_id(),
					'approved' => 1,
				]
			);

			if ( ! $like->save() ) {
				return hp\rest_error( 400, $like->_get_errors() );
			}

			$liked = true;
		}

		$engagement = $wall->get_engagement( [ $post->get_id() ] );

		return hp\rest_response(
			200,
			[
				'id'    => $post->get_id(),
				'liked' => $liked,
				'count' => absint( $engagement[ $post->get_id() ]['likes'] ),
			]
		);
	}

	/**
	 * Adds a comment or a reply to a post.
	 *
	 * Replies stay one level deep: replying to a reply attaches to its top-level parent, the way
	 * Reviews and Additional Gallery do it.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_comment( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$wall = hivepress()->hpsw_wall;

		if ( ! $wall->can_comment( get_current_user_id() ) ) {
			return hp\rest_error( 403, esc_html__( 'You cannot comment on this post.', 'social-walls-for-hivepress' ) );
		}

		$post = Models\Hpsw_Post::query()->get_by_id( absint( $request->get_param( 'hpsw_post_id' ) ) );

		if ( ! $wall->is_post_visible( $post ) ) {
			return hp\rest_error( 404 );
		}

		$user = get_userdata( get_current_user_id() );

		if ( ! $user ) {
			return hp\rest_error( 401 );
		}

		$form = new Forms\Hpsw_Comment_Submit( [ 'model' => new Models\Hpsw_Comment() ] );

		$form->set_values( $request->get_params() );

		if ( ! $form->validate() ) {
			return hp\rest_error( 400, $form->get_errors() );
		}

		$parent_id = absint( $form->get_value( 'parent' ) );

		if ( $parent_id ) {
			$parent = get_comment( $parent_id );

			if ( ! $parent instanceof \WP_Comment || $wall::COMMENT_TYPE !== $parent->comment_type || absint( $parent->comment_post_ID ) !== $post->get_id() ) {
				return hp\rest_error( 400 );
			}

			if ( absint( $parent->comment_parent ) ) {
				$parent_id = absint( $parent->comment_parent );
			}
		}

		$comment = ( new Models\Hpsw_Comment() )->fill(
			[
				'text'                 => (string) $form->get_value( 'text' ),
				'post'                 => $post->get_id(),
				'author'               => $user->ID,
				'author__display_name' => $user->display_name,
				'author__email'        => $user->user_email,
				'approved'             => 1,
				'parent'               => $parent_id ? $parent_id : null,
			]
		);

		if ( ! $comment->save() ) {
			return hp\rest_error( 400, $comment->_get_errors() );
		}

		$wall->send_comment_emails( $comment, $post );

		return hp\rest_response(
			201,
			[
				'id' => $comment->get_id(),
			]
		);
	}

	/**
	 * Deletes a comment and its replies.
	 *
	 * The comment's author may delete it, and so may the Vendor whose post it is on, because it is
	 * their wall; an administrator can too. Replies go with it, or WordPress would promote them to
	 * top-level comments answering nothing.
	 *
	 * @param \WP_REST_Request $request API request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_comment( $request ) {
		if ( ! is_user_logged_in() ) {
			return hp\rest_error( 401 );
		}

		$wall    = hivepress()->hpsw_wall;
		$comment = get_comment( absint( $request->get_param( 'hpsw_comment_id' ) ) );

		if ( ! $comment instanceof \WP_Comment || $wall::COMMENT_TYPE !== $comment->comment_type ) {
			return hp\rest_error( 404 );
		}

		$user_id = get_current_user_id();
		$owner   = absint( get_post_field( 'post_author', absint( $comment->comment_post_ID ) ) );

		if ( ! current_user_can( 'moderate_comments' ) && absint( $comment->user_id ) !== $user_id && $owner !== $user_id ) {
			return hp\rest_error( 403 );
		}

		$replies = get_comments(
			[
				'type'   => $wall::COMMENT_TYPE,
				'parent' => absint( $comment->comment_ID ),
				'status' => 'any',
				'fields' => 'ids',
			]
		);

		foreach ( (array) $replies as $reply_id ) {
			wp_delete_comment( absint( $reply_id ), true );
		}

		if ( ! wp_delete_comment( absint( $comment->comment_ID ), true ) ) {
			return hp\rest_error( 400 );
		}

		return hp\rest_response(
			200,
			[
				'id' => absint( $comment->comment_ID ),
			]
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Account pages
	|--------------------------------------------------------------------------
	*/

	/**
	 * Sends signed-out visitors to sign in, and non-Vendors to their account.
	 *
	 * @return string|false
	 */
	public function redirect_wall_edit_page() {
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		if ( ! hivepress()->hpsw_wall->get_current_vendor() ) {
			return hivepress()->router->get_url( 'user_account_page' );
		}

		return false;
	}

	/**
	 * Renders the Vendor's own wall page.
	 *
	 * @return string
	 */
	public function render_wall_edit_page() {
		return ( new Blocks\Template(
			[
				'template' => 'hpsw_wall_edit_page',

				'context'  => [
					'vendor' => hivepress()->hpsw_wall->get_current_vendor(),
				],
			]
		) )->render();
	}

	/**
	 * Prepares the new-post page: checks the Vendor may post, and finds or creates the draft.
	 *
	 * A child route inherits its parent's PATH but none of its callbacks
	 * (resources/hivepress-framework.md, "A child route inherits its parent's path"), so every
	 * check is repeated here. The draft is an auto-draft, as core does for a new Listing
	 * (controllers/class-listing.php:848-871), because photos can only be uploaded to a post that
	 * already exists (controllers/class-attachment.php:119-160). An unfinished draft is reused, so
	 * opening the page twice never leaves two behind; WordPress bins stale auto-drafts itself after
	 * a week.
	 *
	 * @return string|false
	 */
	public function redirect_post_submit_page() {
		$redirect = $this->redirect_wall_edit_page();

		if ( $redirect ) {
			return $redirect;
		}

		$wall   = hivepress()->hpsw_wall;
		$vendor = $wall->get_current_vendor();

		// The Wall page explains why posting is not possible, with a link where there is one.
		if ( ! $wall->can_post( $vendor ) || 0 === $wall->get_remaining( $vendor ) ) {
			return hivepress()->router->get_url( 'hpsw_wall_edit_page' );
		}

		$post = Models\Hpsw_Post::query()->filter(
			[
				'status' => 'auto-draft',
				'vendor' => $vendor->get_id(),
				'user'   => get_current_user_id(),
			]
		)->get_first();

		if ( ! $post instanceof Models\Hpsw_Post ) {
			$post = ( new Models\Hpsw_Post() )->fill(
				[
					'status' => 'auto-draft',
					'type'   => 'update',
					'vendor' => $vendor->get_id(),
					'user'   => get_current_user_id(),
				]
			);

			if ( ! $post->save( [ 'status', 'type', 'vendor', 'user' ] ) ) {
				return hivepress()->router->get_url( 'hpsw_wall_edit_page' );
			}
		}

		hivepress()->request->set_context( 'hpsw_post', $post );

		return false;
	}

	/**
	 * Renders the new-post page.
	 *
	 * @return string
	 */
	public function render_post_submit_page() {
		return ( new Blocks\Template(
			[
				'template' => 'hpsw_post_submit_page',

				'context'  => [
					'hpsw_post' => hivepress()->request->get_context( 'hpsw_post' ),
					'vendor'    => hivepress()->hpsw_wall->get_current_vendor(),
				],
			]
		) )->render();
	}

	/**
	 * Checks the post being edited belongs to the current user.
	 *
	 * @return string|false
	 */
	public function redirect_post_edit_page() {
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		$post = Models\Hpsw_Post::query()->get_by_id( absint( hivepress()->request->get_param( 'hpsw_post_id' ) ) );

		if ( ! $post instanceof Models\Hpsw_Post || ! in_array( $post->get_status(), [ 'draft', 'publish', 'pending' ], true ) || ! hivepress()->hpsw_wall->can_manage_post( $post ) ) {
			return hivepress()->router->get_url( 'hpsw_wall_edit_page' );
		}

		hivepress()->request->set_context( 'hpsw_post', $post );

		return false;
	}

	/**
	 * Renders the edit page.
	 *
	 * @return string
	 */
	public function render_post_edit_page() {
		return ( new Blocks\Template(
			[
				'template' => 'hpsw_post_edit_page',

				'context'  => [
					'hpsw_post' => hivepress()->request->get_context( 'hpsw_post' ),
				],
			]
		) )->render();
	}

	/**
	 * Puts the pinning product in the basket and sends the Vendor to the checkout.
	 *
	 * The official pattern from Claim Listings (controllers/class-listing-claim.php:194-226): empty
	 * the basket, add the product with the post's ID as cart item meta, go to the checkout. Emptying
	 * the basket is not tidiness: Marketplace credits a whole order to the author of its FIRST line
	 * (resources/hivepress-data.md, "Who an order pays"), so a pin must never share an order with
	 * another seller's item. The link carries a nonce, so another site cannot empty a Vendor's
	 * basket by sending them to this address.
	 *
	 * @return string
	 */
	public function redirect_post_pin_page() {
		if ( ! is_user_logged_in() ) {
			return hivepress()->router->get_return_url( 'user_login_page' );
		}

		$wall_url = hivepress()->router->get_url( 'hpsw_wall_edit_page' );
		$wall     = hivepress()->hpsw_wall;
		$post     = Models\Hpsw_Post::query()->get_by_id( absint( hivepress()->request->get_param( 'hpsw_post_id' ) ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed straight to wp_verify_nonce(), which only compares it.
		$nonce = isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';

		if ( ! $post instanceof Models\Hpsw_Post || 'publish' !== $post->get_status() || ! $wall->can_manage_post( $post ) || ! wp_verify_nonce( $nonce, 'hpsw_pin_' . $post->get_id() ) ) {
			return $wall_url;
		}

		$product_id = $wall->get_pin_product_id();

		if ( ! $product_id || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $wall_url;
		}

		WC()->cart->empty_cart();

		if ( ! WC()->cart->add_to_cart( $product_id, 1, 0, [], [ 'hp_hpsw_post' => $post->get_id() ] ) ) {
			return $wall_url;
		}

		return wc_get_checkout_url();
	}

	/*
	|--------------------------------------------------------------------------
	| Public post page
	|--------------------------------------------------------------------------
	*/

	/**
	 * Resolves the post for its public page and returns the page title.
	 *
	 * Runs before the redirect callback, so the post is resolved once here and handed on through
	 * the request context. Only reads the request parameter: a route title that touched the main
	 * query would fatal wherever a title is built outside the front end.
	 *
	 * @return string|null
	 */
	public function get_post_view_title() {
		$post  = Models\Hpsw_Post::query()->get_by_id( absint( hivepress()->request->get_param( 'hpsw_post_id' ) ) );
		$title = null;

		if ( $post instanceof Models\Hpsw_Post && ( hivepress()->hpsw_wall->is_post_visible( $post ) || ( 'pending' === $post->get_status() && hivepress()->hpsw_wall->can_manage_post( $post ) ) ) ) {
			hivepress()->request->set_context( 'hpsw_post', $post );

			$title  = trim( (string) $post->get_title() );
			$vendor = $post->get_vendor();

			if ( '' === $title && $vendor instanceof Models\Vendor ) {
				$title = sprintf(
					/* translators: 1: "Deal" or "Update", 2: Vendor name. */
					esc_html__( '%1$s from %2$s', 'social-walls-for-hivepress' ),
					$post->is_deal() ? esc_html__( 'Deal', 'social-walls-for-hivepress' ) : esc_html__( 'Update', 'social-walls-for-hivepress' ),
					$vendor->get_name()
				);
			}
		}

		return $title;
	}

	/**
	 * Sends visitors away from a post that is not on show.
	 *
	 * @return string|false
	 */
	public function redirect_post_view_page() {
		if ( ! hivepress()->request->get_context( 'hpsw_post' ) ) {
			return home_url( '/' );
		}

		return false;
	}

	/**
	 * Renders the public post page.
	 *
	 * @return string
	 */
	public function render_post_view_page() {
		$post = hivepress()->request->get_context( 'hpsw_post' );

		return ( new Blocks\Template(
			[
				'template' => 'hpsw_post_view_page',

				'context'  => [
					'hpsw_post' => $post,
					'vendor'    => $post instanceof Models\Hpsw_Post ? $post->get_vendor() : null,
				],
			]
		) )->render();
	}
}
