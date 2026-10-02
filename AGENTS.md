# AGENTS.md

## What this is

Geekswipe Avatars is a WordPress plugin that lets members upload their own profile picture, from the bbPress Edit Profile form or from their wp-admin profile. It replaces WP User Avatar 2.2.16 and carries over its data. It was written for [Geekswipe](https://geekswipe.net) and is released free under GPL-2.0-or-later.

The whole plugin is `geekswipe-avatars.php`, namespaced `Geekswipe\Avatars`.

## Hard rules

These hold for every change. If a task needs one broken, stop and say so.

- **One file.** No build step, no Composer dependencies at runtime, no scripts and no stylesheet. Themes style the field.
- **No requests to other services.**
- **No queries on render.** A page full of avatars must cost no more queries than it did without the plugin. The image path is cached in user meta (`geekswipe_avatar_file`) and the default avatar's path in an autoloaded option.
- **Never delete what the member does not own.** An attachment is deleted only when its owner marker matches the member and no other user references it.
- **Every upload is re-encoded.** Square crop, metadata stripped, no original kept.
- **Capability and nonce on every write.** `save()` checks `edit_user` and the `update-user_{id}` nonce itself, even though WordPress and bbPress check them first.
- **WordPress Coding Standards, clean.** `phpcs` with `phpcs.xml.dist` reports nothing. Prefix globals with `geekswipe_avatar` or keep them in the namespace. The text domain is `geekswipe-avatars`.
- **PHP 8.1 and WordPress 6.4 are the floor.**

## Data

| Where | Key | Holds |
| --- | --- | --- |
| User meta | `{blog_prefix}geekswipe_avatar` | Avatar attachment ID |
| User meta | `geekswipe_avatar_file` | Render cache, the image path inside uploads |
| Post meta | `_geekswipe_avatar_user` | Owner of an avatar attachment |
| Option | `geekswipe_avatars_default` | Default avatar attachment ID |
| Option | `geekswipe_avatars_default_file` | Cached default avatar path |
| Option | `geekswipe_avatars_max_kb` | Upload limit in KB |
| Option | `geekswipe_avatars_size` | Side of the square, in px |

WP User Avatar stored the same things in `{blog_prefix}user_avatar`, `_wp_attachment_wp_user_avatar` and `avatar_default_wp_user_avatar`, and its uninstaller deletes all of them and resets `avatar_default` to `mystery`. `copy_legacy_data()` copies them on activation and when WP User Avatar is deactivated. Legacy keys are read as a fallback only and never written. The `avatar_default` value `wp_user_avatar` is kept as the key for the site default, so existing sites keep their setting.

## Testing

There is no automated test suite in this repository. Before a release, run these on a WordPress site with bbPress, each in a fresh PHP process, because `avatar_url()` and `default_avatar_url()` cache statically.

1. Switchover. Give a user WP User Avatar data and a site default, activate this plugin, run WP User Avatar's `uninstall.php`, and check that the user's avatar, the default avatar and the owner marker survive. Then pick another default in Settings › Discussion and check that it applies.
2. Uploads through the real bbPress form. A valid JPEG becomes a square of the configured size with no EXIF. A PNG replaces it and the old file is deleted. Too small, too large, a fake `.jpg` and an SVG are each rejected with a message, and the existing avatar stays.
3. Remove. The file, the attachment and all meta are gone.
4. Permissions. A subscriber cannot change or remove another member's avatar, even with a nonce of their own.
5. Render. A page of avatars runs no extra queries.

## Releasing

1. Bump `Version` in the plugin header and `Stable tag` in `readme.txt`, and add a changelog entry.
2. Run `phpcs`.
3. Commit, tag `vX.Y.Z` and push.
4. Build the zip with `git archive --format=zip --prefix=geekswipe-avatars/ -o geekswipe-avatars.zip vX.Y.Z` and attach it to a GitHub release.

## Writing

British spelling. No em dashes and no semicolons in prose. Commit messages carry no AI co-author trailer. Documents say what is true now, not how they came to be.
