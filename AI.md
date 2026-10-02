# AI usage disclosure

Geekswipe Avatars is written with AI tools. This file says how, and what a person has checked. A plugin that handles uploaded files runs on your server, so you should know.

## Tools used

- **Claude** (Anthropic), through Claude Code

## What AI contributed

- **The code.** All of `geekswipe-avatars.php`.
- **The research.** Reading WP User Avatar 2.2.16's source, which showed that its uninstaller deletes every avatar and resets the default avatar, and that WordPress's Imagick editor keeps EXIF data when it crops.
- **The tests.** Scripts that drove a local copy of Geekswipe through the real bbPress form, the WP User Avatar switchover and a permission attack. They are described in `AGENTS.md` and are not in this repository.
- **The words.** The README, `readme.txt`, `AGENTS.md` and this file.

## What a human checked

I decided what the plugin does and how it behaves, and I read the code.

Before 1.0.0 it passed the WordPress Coding Standards with no errors or warnings, and the tests in `AGENTS.md` on WordPress 7.1.2, PHP 8.2, bbPress 2.6.19 and Imagick.

Not checked yet:

- A server with GD and no Imagick
- PHP 8.3 or 8.4, and WordPress versions before 7.1
- Multisite
- A dedicated security audit

## Why disclose this

If you run this plugin, you should know how it was made. The code is GPL, so read it, audit it, and judge it on its merits. Security reports are welcome through GitHub's private vulnerability reporting.
