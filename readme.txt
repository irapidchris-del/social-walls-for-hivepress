=== Social Walls for HivePress ===
Contributors: chrisb
Tags: hivepress, vendors, deals, coupons, wall
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gives every Vendor a wall for Deals and Updates, shows it on their profile, and adds an all-Vendors wall block with filters, likes and comments.

== Description ==

HivePress gives every Vendor a profile and their Listings. This plugin adds a place for the news in between: a wall where a Vendor posts time-limited Deals with coupon codes and everyday Updates, and where visitors can like and comment on them.

Features:

* Two kinds of post. A Deal can carry a coupon code with a one-click copy button, an end date after which it disappears from every wall by itself, and a link to one of the Vendor's own Listings. An Update is news or an announcement. Both take text and photos, as many as you allow (four by default, up to ten).
* A Wall page in every Vendor's account, to write, edit and delete posts, see how many likes and comments each has, and see how many posts are left this month.
* Each Vendor's posts on their profile page, newest first, above or below their Listings, one, two or three to a row, with page numbers.
* The Social Wall block (also a shortcode) for any page, showing posts from every Vendor, with filters for Deals or Updates, keywords, Listing category and location. With HivePress Geolocation active the location filter uses the same place search and radius as the Listing search; without it, it matches the addresses on file. With Geolocation Plus for HivePress as well, the place search uses its map provider and suggestions, and the filter also finds Vendors who travel to the searched place and Vendors whose own Location attributes are nearby. The distance box can be hidden, with a default radius of your choice.
* A page for every post, with its photos in the HivePress slider and lightbox, the Vendor's card beside it, and the comments. The post's Vendor and administrators see a Manage Post box there, to edit, pin or delete it.
* A Share button on every post's page: Facebook, WhatsApp, Copy link and a QR code to scan with a phone camera, which can carry your own logo in the middle. On phones and tablets it opens the device's own share menu. The QR code is drawn in the visitor's browser by a small library bundled with the plugin.
* Likes (a heart) and comments on every post, with one level of replies. Choose who can comment: anyone signed in, Vendors only, or nobody. Likes can be switched off.
* Optional approval: new and changed posts wait as Pending until you publish them, and the Vendor is emailed either way.
* Optional monthly allowance per Vendor. With HivePress Memberships, each membership plan can allow or refuse posting and set its own allowance, including unlimited. What a member bought is kept even if you edit the plan later, exactly like Memberships' own limits.
* Optional paid pinning. Choose a WooCommerce product and Vendors can pay to keep a post at the top of every wall for a set number of days. A refund takes the time back. You can also pin any post yourself.
* Emails, all editable under HivePress, Emails: followers are told when a Vendor posts (with Follow Vendors for HivePress), Vendors about comments on their posts, commenters about replies, and Vendors about approvals. With Notifications for HivePress, each also arrives as an on-site notification.
* Cards reuse HivePress's own Listing card classes, so they take on each official theme's look.
* Approval, posting allowances and paid pinning are off or unlimited out of the box, everything is translatable, no assets load from outside your site, and nothing is sent anywhere.
* Your settings and every post are kept if you delete the plugin, unless you tick the box that asks for them to be removed.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/social-walls-for-hivepress` directory, or install the plugin zip through the WordPress admin.
2. Activate the plugin through the Plugins screen. HivePress must be installed and active.
3. Go to HivePress, Settings, Social Walls, to choose approval, the monthly allowance, where the wall sits on Vendor pages, who can comment, and paid pinning.
4. To show every Vendor's posts together, add the Social Wall block to a page, or paste `[hivepress_hpsw_wall]` into it.

Once installed, the plugin checks for new versions automatically and updates through the normal WordPress Plugins screen, just like a plugin from the WordPress.org directory.

== Frequently Asked Questions ==

= Who can post? =

Every published Vendor, from the Wall page in their account. If HivePress Memberships is active and at least one plan has "Allow posting to the wall" ticked (on the plan's edit screen, under Restrictions), only Vendors on one of those plans can post.

= How does the monthly allowance count? =

By calendar month in your site's timezone. A post counts towards the month it was added in, even if it is later deleted, so an allowance of five means five a month, not five at a time. Editing a post never uses up the allowance.

= How do I sell pinning? =

Create a virtual WooCommerce product for it, hidden from the shop catalogue, and choose it under Settings, Social Walls, Pinned Posts. Vendors then see a "Pin to the top" button beside each live post on their Wall page, which takes them straight to the checkout with just that product in the basket.

= What does the Social Wall block show? =

Posts from every published Vendor, pinned posts first, then newest first. In the block settings you can choose the number of columns, how many posts per page, Deals only or Updates only, and whether to show the filters.

= Why does a Deal's coupon code not work at checkout? =

A Deal only shows its coupon code; it does not create a coupon. For the code to work at checkout, HivePress Marketplace must be active with "Allow sellers to create and manage coupons" ticked under HivePress, Settings, Vendors, and the Vendor must create the same code under Coupons in their account. With that switched on, the Deal form lists the Vendor's own coupons to choose from, so the code on the Deal is always one the checkout accepts.

= The wall's location box shows no place suggestions. Why? =

The suggestions come from the HivePress Geolocation extension's own scripts, which it loads on every page. If a speed plugin or a code snippet removes them (or the map library they need) from ordinary pages, the box on your wall page can no longer suggest places. Allow those scripts on the page that holds the Social Wall block. Until then the box still searches the text typed into it, and the locate icon still finds the visitor's position.

= Can I put my logo on the QR code? =

Yes. Under Settings, Social Walls, Sharing, choose an image for "QR Code Logo". A small square logo on a plain background works best. Nothing is added to the QR code until you choose one.

= Can I change the wording? =

Every piece of text is translatable, so you can reword anything with a translation plugin such as Loco Translate. The emails can be reworded under HivePress, Emails.

= What happens to my data if I delete the plugin? =

It is kept, so reinstalling brings everything back. To remove it all, tick "Delete All Data" under Settings, Social Walls before deleting the plugin. Photos stay in the Media Library either way.

== Credits ==

* QR codes are drawn by QR Code Generator for JavaScript 2.0.4 by Kazuhiko Arase (npm package "qrcode-generator"), MIT licence, bundled in assets/vendor/qrcode-generator with its licence header. "QR Code" is a registered trademark of DENSO WAVE INCORPORATED.
* The Facebook and WhatsApp icons in the Share pop-up are from Font Awesome Free 7.1.0 by Fonticons, Inc., licensed under CC BY 4.0 (https://fontawesome.com/license/free).

== Changelog ==

= 1.0.4 =
* Fixed: the coupon Copy button now carries its own copy icon, which turns into a tick once the code is copied. On sites whose icon set lacked that icon, an empty gap showed before "Copy".
* Changed: on wall cards, a Deal's end date now sits on the left of the card's footer, level with the like and comment counts on the right.
* Changed: the Wall page in a Vendor's account now shows their posts as cards (three per row on wide screens, two on tablets, one on phones), each with its status, Edit, Pin and View, plus a new Delete link.

= 1.0.3 =
* Gives every Vendor a wall for Deals and Updates. A Deal can carry a coupon code with a copy button, an end date and a linked Listing; both kinds take text and photos.
* Adds a Wall page to the Vendor's account to write, edit and delete posts.
* Shows each Vendor's posts on their profile page, above or below their Listings, one to three per row.
* Adds a Social Wall block and shortcode showing posts from every Vendor, with filters for type, keywords, Listing category and location. The location filter uses HivePress Geolocation, and Geolocation Plus for HivePress when active.
* Gives every post its own page with its photos, the Vendor's card, comments, a Share button (Facebook, WhatsApp, Copy link and a QR code) and a Manage Post box for its Vendor and administrators.
* Adds likes and comments with one level of replies. Choose who can comment, or switch likes off.
* Optional approval, monthly posting allowances (per plan with HivePress Memberships) and paid pinning through a WooCommerce product.
* With HivePress Marketplace coupons switched on, Vendors choose a Deal's code from their own coupons.
* Editable emails for new posts to followers (with Follow Vendors for HivePress), comments, replies and approvals, also shown on-site with Notifications for HivePress.
* Settings are under HivePress, Settings, Social Walls. Settings and posts are kept when the plugin is deleted unless "Delete All Data" is ticked first.
