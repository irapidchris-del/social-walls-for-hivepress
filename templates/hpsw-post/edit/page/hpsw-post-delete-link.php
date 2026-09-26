<?php
/**
 * Delete link on a post's edit page. Opens the confirmation modal, as core's Listing delete link
 * does (templates/listing/edit/page/listing-delete-link.php): core opens a modal for any link to its
 * id (assets/js/common.js:57-73).
 *
 * @package Social_Walls
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;
?>
<a href="#hpsw_post_delete_modal" class="hp-listing__action hp-listing__action--delete hp-link"><i class="hp-icon fas fa-times"></i><span><?php echo esc_html( hivepress()->translator->get_string( 'delete' ) ); ?></span></a>
