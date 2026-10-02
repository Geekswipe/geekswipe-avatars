# Geekswipe Avatars

A small WordPress plugin that lets members upload their own profile picture, from the bbPress Edit Profile form or from their WordPress profile. It can replace WP User Avatar and carries over everything WP User Avatar stored.

It is one PHP file with no settings page of its own, no scripts, no stylesheet, and the server makes no requests to other services. It runs on [Geekswipe](https://geekswipe.net). It was written with AI, and [AI.md](AI.md) says how and what a person has checked.

## Features

- Accepts JPEG, PNG, WebP and GIF. SVG and files that only claim to be images are rejected.
- Rejects pictures smaller than 96 × 96 pixels, larger than 36 million pixels, or over the upload limit.
- Crops every upload to a square, 256 px by default, and strips EXIF, XMP and GPS data.
- Deletes the previous picture when a member replaces or removes theirs, and when a member is deleted.
- Shows a member's uploaded picture first, then their Gravatar, then the Default Avatar chosen in Settings › Discussion. That can be your own image or a generated one such as Initials or RoboHash.
- Gravatar can be turned off, so members without an upload always get the Default Avatar.
- Adds no database queries to a page that shows avatars. The image path is cached in user meta.

## Requirements

- WordPress 6.4 or later
- PHP 8.1 or later
- bbPress, optional. Without it, members use their WordPress profile.

## Installation

1. Download `geekswipe-avatars.zip` from the [latest release](https://github.com/Geekswipe/geekswipe-avatars/releases/latest).
2. In WordPress, go to Plugins › Add New Plugin › Upload Plugin and upload the zip.
3. Activate it.

The upload limit (in KB), the avatar size (in px) and the Gravatar switch are under Settings › Discussion › Avatars. The Settings link on the Plugins screen goes there.

## Switching from WP User Avatar

1. Install and activate Geekswipe Avatars.
2. Deactivate WP User Avatar.
3. Delete WP User Avatar.

Keep that order. Activation copies WP User Avatar's avatars, default avatar and limits into this plugin's own keys, and deactivating WP User Avatar copies again, so pictures uploaded in between are kept. WP User Avatar's uninstaller deletes its own data and resets the default avatar to Mystery Person. This plugin keeps the site default avatar through that reset.

## Styling the bbPress field

The field uses the active theme's `label`, `field-group` and `field-help-text` classes where they exist. Its own elements are:

| Class | Element |
| --- | --- |
| `.gsa-field` | The whole field |
| `.gsa-field__row` | Preview and controls |
| `.gsa-field__preview` | The current avatar, 64 px |
| `.gsa-field__controls` | File input, help text and remove checkbox |
| `.gsa-field__remove` | The remove checkbox label |

It works unstyled. A flex row with a gap on `.gsa-field__row` and a round `.gsa-field__preview` is usually all a theme needs.

## Limitations

- A new default image cannot be uploaded yet. The image option is the one carried over from WP User Avatar. WordPress's generated defaults all work.
- Deleting the plugin leaves avatars and settings in place.
- Multisite has not been tested.

## Building a release zip

```sh
git archive --format=zip --prefix=geekswipe-avatars/ -o geekswipe-avatars.zip HEAD
```

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
