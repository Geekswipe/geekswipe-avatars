=== Geekswipe Avatars ===
Contributors: karthikeyankc
Tags: avatar, profile picture, user avatar, bbpress, local avatar
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Members upload their own profile picture from bbPress or their WordPress profile. A lightweight replacement for WP User Avatar.

== Description ==

Geekswipe Avatars lets members upload their own profile picture, from the bbPress Edit Profile form or from their WordPress profile. It replaces WP User Avatar, which now ships inside ProfilePress, and carries over everything WP User Avatar stored.

It is one PHP file. It has no settings page of its own, no scripts, no stylesheet and makes no calls to other services.

* Accepts JPEG, PNG, WebP and GIF. SVG and files that only claim to be images are rejected.
* Rejects pictures smaller than 96 × 96 pixels, larger than 36 million pixels, or over the upload limit.
* Crops every upload to a square, 256 px by default, and strips EXIF, XMP and GPS data.
* Deletes the previous picture when a member replaces or removes theirs, and when a member is deleted.
* Serves the site default avatar to members without a picture.
* Adds no database queries to a page that shows avatars.

The upload limit and the avatar size are under Settings › Discussion › Avatars.

Development happens on [GitHub](https://github.com/Geekswipe/geekswipe-avatars).

== Installation ==

1. Upload the plugin through Plugins › Add New Plugin › Upload Plugin, or search for it.
2. Activate it.
3. Members find the Profile picture field on their profile.

= Switching from WP User Avatar =

1. Activate Geekswipe Avatars.
2. Deactivate WP User Avatar.
3. Delete WP User Avatar.

Keep that order. Activation copies WP User Avatar's avatars, default avatar and limits, and deactivating WP User Avatar copies them again, so pictures uploaded in between are kept. WP User Avatar's uninstaller resets the default avatar to Mystery Person. Geekswipe Avatars keeps the site default through that reset.

== Frequently Asked Questions ==

= Where are the pictures stored? =

In the uploads folder, as Media Library attachments owned by the member.

= Does it contact Gravatar? =

The plugin makes no requests of its own. A member without a picture gets the site default avatar. If no site default is set, WordPress shows its own default, which may come from Gravatar.

= Can I choose a new site default avatar? =

Not yet. The default is the one carried over from WP User Avatar.

= How do I style the bbPress field? =

The field uses the theme's `label`, `field-group` and `field-help-text` classes, plus `.gsa-field`, `.gsa-field__row`, `.gsa-field__preview`, `.gsa-field__controls` and `.gsa-field__remove`. It works unstyled.

= What happens when I delete the plugin? =

Avatars and settings stay in place, so reinstalling restores them.

== Changelog ==

= 1.0.0 =
* First release.
