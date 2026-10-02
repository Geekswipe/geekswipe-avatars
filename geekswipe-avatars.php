<?php
/**
 * Plugin Name:       Geekswipe Avatars
 * Plugin URI:        https://github.com/Geekswipe/geekswipe-avatars
 * Description:       Members upload their own profile picture from their bbPress or WordPress profile. A lightweight replacement for WP User Avatar that keeps its avatars, default avatar and size limit.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Karthikeyan KC
 * Author URI:        https://karthikeyankc.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Update URI:        https://github.com/Geekswipe/geekswipe-avatars
 * Text Domain:       geekswipe-avatars
 *
 * Data model:
 * - User meta {prefix}geekswipe_avatar holds the avatar's attachment ID.
 * - User meta geekswipe_avatar_file caches the image path inside uploads, so
 *   rendering an avatar costs no query.
 * - Option geekswipe_avatars_default (attachment ID) is the default avatar for
 *   members without one, used while avatar_default is 'wp_user_avatar'.
 * - Options geekswipe_avatars_max_kb and geekswipe_avatars_size, on Settings › Discussion › Avatars.
 *
 * WP User Avatar kept the same data in {prefix}user_avatar and
 * avatar_default_wp_user_avatar, and its uninstaller deletes both. Activation
 * copies them into this plugin's own keys, so deleting the old plugin is safe.
 * The legacy keys are still read as a fallback.
 *
 * @package Geekswipe\Avatars
 */

namespace Geekswipe\Avatars;

use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

const FIELD             = 'geekswipe_avatar';
const REMOVE            = 'geekswipe_avatar_remove';
const FILE_META         = 'geekswipe_avatar_file';
const OWNER_META        = '_geekswipe_avatar_user';
const LEGACY_OWNER_META = '_wp_attachment_wp_user_avatar';
const MIN_SOURCE        = 96;
const MAX_PIXELS        = 36000000; // 6000 × 6000. Larger images can exhaust memory while resizing.
const MIMES             = array(
	'jpg|jpeg|jpe' => 'image/jpeg',
	'png'          => 'image/png',
	'webp'         => 'image/webp',
	'gif'          => 'image/gif',
);

// ─── Settings ────────────────────────────────────────────────────────────────

/** User meta key holding a member's avatar attachment ID. */
function meta_key(): string {
	global $wpdb;
	return $wpdb->get_blog_prefix() . 'geekswipe_avatar';
}

/** WP User Avatar's key for the same thing. Read as a fallback only. */
function legacy_key(): string {
	global $wpdb;
	return $wpdb->get_blog_prefix() . 'user_avatar';
}

/**
 * A member's avatar attachment ID. A legacy-only value is copied into this
 * plugin's key on first read.
 *
 * @param int $user_id User ID.
 */
function avatar_id( int $user_id ): int {
	$id = (int) get_user_meta( $user_id, meta_key(), true );
	if ( ! $id ) {
		$id = (int) get_user_meta( $user_id, legacy_key(), true );
		if ( $id ) {
			update_user_meta( $user_id, meta_key(), $id );
		}
	}
	return $id;
}

/** Largest accepted upload, in bytes. Never above the server's own limit. */
function max_bytes(): int {
	$kb = (int) get_option( 'geekswipe_avatars_max_kb', 0 );
	return (int) min( ( $kb > 0 ? $kb : 2048 ) * KB_IN_BYTES, wp_max_upload_size() );
}

/**
 * Whether members without an uploaded picture show their Gravatar. Until the
 * option is saved, follow WP User Avatar's Disable Gravatar setting.
 */
function use_gravatar(): bool {
	$value = get_option( 'geekswipe_avatars_gravatar' );
	if ( false === $value ) {
		return ! get_option( 'wp_user_avatar_disable_gravatar' );
	}
	return (bool) $value;
}

/** Side length of the square avatar written to disk, in pixels. */
function output_size(): int {
	$size = (int) get_option( 'geekswipe_avatars_size', 0 );
	return $size >= MIN_SOURCE ? min( $size, 1024 ) : 256;
}

/**
 * On activation, start from WP User Avatar's limits so nothing changes for
 * members. Its size limit is stored in bytes, 0 meaning the server maximum.
 */
function activate(): void {
	if ( false === get_option( 'geekswipe_avatars_max_kb' ) ) {
		$legacy = (int) get_option( 'wp_user_avatar_upload_size_limit', 0 );
		add_option( 'geekswipe_avatars_max_kb', $legacy > 0 ? (int) ceil( $legacy / KB_IN_BYTES ) : 2048 );
	}
	if ( false === get_option( 'geekswipe_avatars_size' ) ) {
		$legacy = get_option( 'wp_user_avatar_resize_upload' )
			? max( (int) get_option( 'wp_user_avatar_resize_w' ), (int) get_option( 'wp_user_avatar_resize_h' ) )
			: 0;
		add_option( 'geekswipe_avatars_size', $legacy >= MIN_SOURCE ? $legacy : 256 );
	}
	if ( false === get_option( 'geekswipe_avatars_gravatar' ) ) {
		add_option( 'geekswipe_avatars_gravatar', use_gravatar() ? '1' : '0' );
	}

	copy_legacy_data();
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

/**
 * Copy WP User Avatar's data into this plugin's keys, because its uninstaller
 * deletes it. Runs on activation and again when WP User Avatar is deactivated,
 * which WordPress requires before it can be deleted, so avatars uploaded
 * through it in the meantime are kept too.
 */
function copy_legacy_data(): void {
	$legacy_default = (int) get_option( 'avatar_default_wp_user_avatar', 0 );
	if ( $legacy_default && ! get_option( 'geekswipe_avatars_default' ) ) {
		update_option( 'geekswipe_avatars_default', $legacy_default );
	}
	$legacy_users = get_users(
		array(
			'meta_key' => legacy_key(), // phpcs:ignore WordPress.DB.SlowDBQuery -- Runs on activation only.
			'fields'   => 'ID',
			'number'   => -1,
		)
	);
	foreach ( $legacy_users as $user_id ) {
		$user_id   = (int) $user_id;
		$legacy_id = (int) get_user_meta( $user_id, legacy_key(), true );
		if ( ! $legacy_id ) {
			continue;
		}
		if ( ! get_user_meta( $user_id, meta_key(), true ) ) {
			update_user_meta( $user_id, meta_key(), $legacy_id );
		}
		// The uninstaller also deletes the marker that says this attachment
		// is the member's avatar, which delete_avatar() relies on.
		$legacy_owner = (int) get_post_meta( $legacy_id, LEGACY_OWNER_META, true );
		if ( ! get_post_meta( $legacy_id, OWNER_META, true ) && ( $legacy_owner === $user_id || (int) get_post_field( 'post_author', $legacy_id ) === $user_id ) ) {
			update_post_meta( $legacy_id, OWNER_META, $user_id );
		}
	}
}
add_action( 'deactivate_wp-user-avatar/wp-user-avatar.php', __NAMESPACE__ . '\\copy_legacy_data' );

// WP User Avatar's uninstaller resets avatar_default to 'mystery', which
// would drop the site default avatar. Keep it, for that uninstaller only.
add_filter(
	'pre_update_option_avatar_default',
	function ( $value, $old_value ) {
		$uninstalling_wpua = defined( 'WP_UNINSTALL_PLUGIN' ) && 'wp-user-avatar/wp-user-avatar.php' === WP_UNINSTALL_PLUGIN;
		return ( $uninstalling_wpua && 'wp_user_avatar' === $old_value && get_option( 'geekswipe_avatars_default' ) ) ? $old_value : $value;
	},
	10,
	2
);

add_action(
	'admin_init',
	function (): void {
		register_setting(
			'discussion',
			'geekswipe_avatars_max_kb',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 2048,
			)
		);
		register_setting(
			'discussion',
			'geekswipe_avatars_size',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 256,
			)
		);
		register_setting(
			'discussion',
			'geekswipe_avatars_gravatar',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => function ( $value ): string {
					return $value ? '1' : '0';
				},
				'default'           => '1',
			)
		);

		add_settings_field(
			'geekswipe_avatars_max_kb',
			__( 'Avatar upload limit', 'geekswipe-avatars' ),
			function (): void {
				printf(
					'<input name="geekswipe_avatars_max_kb" id="geekswipe_avatars_max_kb" type="number" min="1" step="1" class="small-text" value="%d"> KB <p class="description">%s</p>',
					(int) get_option( 'geekswipe_avatars_max_kb', 2048 ),
					/* translators: %s: server upload limit, e.g. 64 MB */
					esc_html( sprintf( __( 'Largest picture members can upload. The server allows up to %s.', 'geekswipe-avatars' ), size_format( wp_max_upload_size() ) ) )
				);
			},
			'discussion',
			'avatars'
		);

		add_settings_field(
			'geekswipe_avatars_size',
			__( 'Avatar size', 'geekswipe-avatars' ),
			function (): void {
				printf(
					'<input name="geekswipe_avatars_size" id="geekswipe_avatars_size" type="number" min="%1$d" max="1024" step="1" class="small-text" value="%2$d"> px <p class="description">%3$s</p>',
					(int) MIN_SOURCE,
					(int) output_size(),
					esc_html__( 'Uploads are cropped to a square of this size. 256 px stays sharp on high-density screens.', 'geekswipe-avatars' )
				);
			},
			'discussion',
			'avatars'
		);

		add_settings_field(
			'geekswipe_avatars_gravatar',
			__( 'Gravatar', 'geekswipe-avatars' ),
			function (): void {
				printf(
					'<label for="geekswipe_avatars_gravatar"><input name="geekswipe_avatars_gravatar" id="geekswipe_avatars_gravatar" type="checkbox" value="1"%1$s> %2$s</label><p class="description">%3$s</p>',
					checked( use_gravatar(), true, false ),
					esc_html__( 'Show a member\'s Gravatar when they have not uploaded a picture', 'geekswipe-avatars' ),
					esc_html__( 'Members with no Gravatar get the Default Avatar chosen above. Turned off, members without an upload always get the Default Avatar.', 'geekswipe-avatars' )
				);
			},
			'discussion',
			'avatars'
		);
	}
);

// ─── Rendering ───────────────────────────────────────────────────────────────

/**
 * Resolve whatever get_avatar() was given to a user ID, or 0.
 *
 * @param mixed $id_or_email User ID, email, WP_User, WP_Post or WP_Comment.
 */
function user_id_from( $id_or_email ): int {
	if ( is_numeric( $id_or_email ) ) {
		return (int) $id_or_email;
	}
	if ( $id_or_email instanceof WP_User ) {
		return (int) $id_or_email->ID;
	}
	if ( $id_or_email instanceof \WP_Post ) {
		return (int) $id_or_email->post_author;
	}
	if ( $id_or_email instanceof \WP_Comment ) {
		if ( (int) $id_or_email->user_id ) {
			return (int) $id_or_email->user_id;
		}
		$id_or_email = $id_or_email->comment_author_email;
	}
	if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$user = get_user_by( 'email', $id_or_email );
		return $user ? (int) $user->ID : 0;
	}
	return 0;
}

/**
 * Path inside uploads for an attachment, preferring its square thumbnail
 * when one exists. Avatars uploaded through WP User Avatar were often stored
 * at full size.
 *
 * @param int $attachment_id Attachment ID.
 */
function attachment_file( int $attachment_id ): string {
	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
		$thumb = $meta['sizes']['thumbnail']['file'] ?? '';
		return $thumb ? trailingslashit( dirname( $meta['file'] ) ) . $thumb : $meta['file'];
	}
	return (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
}

/**
 * Full URL of a path inside the uploads folder.
 *
 * @param string $file Path relative to the uploads folder.
 */
function uploads_url( string $file ): string {
	static $base = null;
	if ( null === $base ) {
		$base = trailingslashit( wp_get_upload_dir()['baseurl'] );
	}
	return $base . ltrim( $file, '/' );
}

/**
 * URL of a member's uploaded avatar, or '' when they have none.
 *
 * @param int $user_id User ID.
 */
function avatar_url( int $user_id ): string {
	static $cache = array();
	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$file = (string) get_user_meta( $user_id, FILE_META, true );
	if ( '' === $file ) {
		$attachment_id = avatar_id( $user_id );
		if ( $attachment_id ) {
			$file = attachment_file( $attachment_id );
			if ( $file ) {
				update_user_meta( $user_id, FILE_META, $file ); // Computed once, then free.
			}
		}
	}

	$cache[ $user_id ] = $file ? uploads_url( $file ) : '';
	return $cache[ $user_id ];
}

/**
 * The site's default avatar for members without one, if one is configured.
 * Its path is cached in an autoloaded option, so it costs no query per page.
 */
function default_avatar_url(): string {
	static $url = null;
	if ( null !== $url ) {
		return $url;
	}
	$url = '';
	$id  = (int) get_option( 'geekswipe_avatars_default', 0 );
	if ( ! $id ) {
		$id = (int) get_option( 'avatar_default_wp_user_avatar', 0 );
	}
	if ( ! $id || 'wp_user_avatar' !== get_option( 'avatar_default' ) ) {
		return $url;
	}
	$cached = get_option( 'geekswipe_avatars_default_file' );
	if ( ! is_array( $cached ) || (int) ( $cached['id'] ?? 0 ) !== $id ) {
		$cached = array(
			'id'   => $id,
			'file' => attachment_file( $id ),
		);
		update_option( 'geekswipe_avatars_default_file', $cached, true );
	}
	$url = $cached['file'] ? uploads_url( $cached['file'] ) : '';
	return $url;
}

add_filter(
	'pre_get_avatar_data',
	function ( $args, $id_or_email ) {
		if ( ! empty( $args['url'] ) ) {
			return $args;
		}
		// Settings › Discussion previews each default with force_default.
		if ( ! empty( $args['force_default'] ) ) {
			if ( 'wp_user_avatar' === ( $args['default'] ?? '' ) && default_avatar_url() ) {
				$args['url']          = default_avatar_url();
				$args['found_avatar'] = true;
			}
			return $args;
		}
		// 1. The member's uploaded picture.
		$user_id = user_id_from( $id_or_email );
		$url     = $user_id ? avatar_url( $user_id ) : '';
		if ( '' !== $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
			return $args;
		}

		// 2. Their Gravatar, then 3. the Default Avatar. WordPress builds the
		// Gravatar URL with the default as Gravatar's own fallback, so the
		// server never checks whether a Gravatar exists.
		$default = default_avatar_url();
		if ( $default ) {
			$args['default'] = $default;
		} elseif ( 'wp_user_avatar' === ( $args['default'] ?? '' ) ) {
			$args['default'] = 'mm'; // The site default image is missing.
		}
		if ( use_gravatar() ) {
			return $args;
		}

		// Gravatar turned off. The site default image is served from this
		// server, and a generated default such as RoboHash is forced.
		if ( $default ) {
			$args['url']          = $default;
			$args['found_avatar'] = true;
		} else {
			$args['force_default'] = true;
		}
		return $args;
	},
	10,
	2
);

// WordPress shows avatar_default as a radio list. Keep the legacy default
// selectable so saving Settings › Discussion does not switch it off.
add_filter(
	'avatar_defaults',
	function ( array $defaults ): array {
		if ( get_option( 'geekswipe_avatars_default' ) || get_option( 'avatar_default_wp_user_avatar' ) ) {
			$defaults['wp_user_avatar'] = __( 'Site default avatar', 'geekswipe-avatars' );
		}
		return $defaults;
	}
);

/**
 * The render cache follows the attachment ID, whoever changes it.
 *
 * @param int[]  $meta_ids Meta row IDs.
 * @param int    $user_id  User ID.
 * @param string $key      Meta key.
 */
function clear_render_cache( $meta_ids, $user_id, $key ): void {
	if ( meta_key() === $key || legacy_key() === $key ) {
		delete_user_meta( (int) $user_id, FILE_META );
	}
}
add_action( 'added_user_meta', __NAMESPACE__ . '\\clear_render_cache', 10, 3 );
add_action( 'updated_user_meta', __NAMESPACE__ . '\\clear_render_cache', 10, 3 );
add_action( 'deleted_user_meta', __NAMESPACE__ . '\\clear_render_cache', 10, 3 );

// ─── Upload and removal ──────────────────────────────────────────────────────

/** Errors from this request's upload, shown with the profile's own errors. */
function errors(): WP_Error {
	static $errors   = null;
	return $errors ??= new WP_Error();
}

/**
 * Delete an avatar attachment, but only one this plugin or WP User Avatar made
 * for this user, and only when no other user still uses it.
 *
 * @param int $attachment_id Attachment ID.
 * @param int $user_id       User ID.
 */
function delete_avatar( int $attachment_id, int $user_id ): void {
	if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
		return;
	}
	$owner = (int) get_post_meta( $attachment_id, OWNER_META, true );
	if ( ! $owner ) {
		$owner = (int) get_post_meta( $attachment_id, LEGACY_OWNER_META, true );
	}
	if ( $owner !== $user_id ) {
		return;
	}
	foreach ( array( meta_key(), legacy_key() ) as $key ) {
		$shared = get_users(
			array(
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery -- Runs on replace and remove only.
				'meta_value' => $attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'exclude'    => array( $user_id ),
				'fields'     => 'ID',
				'number'     => 1,
			)
		);
		if ( $shared ) {
			return;
		}
	}
	wp_delete_attachment( $attachment_id, true );
}

/**
 * Remove a member's avatar and everything stored about it.
 *
 * @param int $user_id User ID.
 */
function remove( int $user_id ): void {
	delete_avatar( avatar_id( $user_id ), $user_id );
	delete_user_meta( $user_id, meta_key() );
	delete_user_meta( $user_id, legacy_key() );
	delete_user_meta( $user_id, FILE_META );
}

/**
 * Remove EXIF, XMP and other embedded metadata, such as a phone's GPS
 * position. WordPress's Imagick editor only strips metadata when it resizes
 * without cropping, and a square avatar is always a crop. GD never writes
 * metadata, so only Imagick needs this.
 *
 * @param string $path Image file path.
 */
function strip_metadata( string $path ): void {
	if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
		return;
	}
	try {
		$image = new \Imagick( $path );
		$image->stripImage();
		$image->writeImage( $path );
		$image->clear();
	} catch ( \Exception $e ) {
		// The image is still valid without stripping. Keep it.
		unset( $e );
	}
}

/**
 * Validate, square-crop and store an uploaded picture as the user's avatar.
 *
 * @param int   $user_id User ID.
 * @param array $file    One entry of $_FILES.
 * @return int|WP_Error Attachment ID.
 */
function store_upload( int $user_id, array $file ) {
	$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
	$limit = size_format( max_bytes() );

	if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error || (int) ( $file['size'] ?? 0 ) > max_bytes() ) {
		/* translators: %s: size limit, e.g. 2 MB */
		return new WP_Error( 'gsa_too_big', sprintf( __( 'That picture is too large. The limit is %s.', 'geekswipe-avatars' ), $limit ) );
	}
	if ( UPLOAD_ERR_OK !== $error || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'gsa_failed', __( 'The picture could not be uploaded. Please try again.', 'geekswipe-avatars' ) );
	}

	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], MIMES );
	if ( empty( $check['type'] ) ) {
		return new WP_Error( 'gsa_type', __( 'Please upload a JPEG, PNG, WebP or GIF picture.', 'geekswipe-avatars' ) );
	}
	$dims = wp_getimagesize( $file['tmp_name'] );
	if ( ! $dims ) {
		return new WP_Error( 'gsa_type', __( 'Please upload a JPEG, PNG, WebP or GIF picture.', 'geekswipe-avatars' ) );
	}
	if ( $dims[0] < MIN_SOURCE || $dims[1] < MIN_SOURCE ) {
		/* translators: %d: minimum width and height in pixels */
		return new WP_Error( 'gsa_small', sprintf( __( 'That picture is too small. It needs to be at least %1$d × %1$d pixels.', 'geekswipe-avatars' ), MIN_SOURCE ) );
	}
	if ( $dims[0] * $dims[1] > MAX_PIXELS ) {
		return new WP_Error( 'gsa_huge', __( 'That picture has too many pixels to process. Please use a smaller one.', 'geekswipe-avatars' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$uploaded = wp_handle_upload(
		$file,
		array(
			'test_form' => false,
			'mimes'     => MIMES,
		)
	);
	if ( isset( $uploaded['error'] ) ) {
		return new WP_Error( 'gsa_failed', $uploaded['error'] );
	}

	// Re-encoding strips embedded metadata such as camera location.
	$editor = wp_get_image_editor( $uploaded['file'] );
	if ( is_wp_error( $editor ) ) {
		wp_delete_file( $uploaded['file'] );
		return new WP_Error( 'gsa_failed', __( 'The picture could not be processed. Please try another one.', 'geekswipe-avatars' ) );
	}
	$side = min( $dims[0], $dims[1], output_size() ); // Never upscale.
	$editor->resize( $side, $side, true );
	$editor->set_quality( 85 );
	$saved = $editor->save( $uploaded['file'] );
	if ( is_wp_error( $saved ) ) {
		wp_delete_file( $uploaded['file'] );
		return new WP_Error( 'gsa_failed', __( 'The picture could not be processed. Please try another one.', 'geekswipe-avatars' ) );
	}
	if ( $saved['path'] !== $uploaded['file'] ) {
		wp_delete_file( $uploaded['file'] );
	}
	strip_metadata( $saved['path'] );

	$user          = get_userdata( $user_id );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $saved['mime-type'],
			/* translators: %s: member display name */
			'post_title'     => sprintf( __( 'Avatar of %s', 'geekswipe-avatars' ), $user ? $user->display_name : $user_id ),
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		),
		$saved['path'],
		0,
		true
	);
	if ( is_wp_error( $attachment_id ) ) {
		wp_delete_file( $saved['path'] );
		return $attachment_id;
	}

	$relative = _wp_relative_upload_path( $saved['path'] );
	wp_update_attachment_metadata(
		$attachment_id,
		array(
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
			'file'   => $relative,
			'sizes'  => array(), // Already small. No subsizes needed.
		)
	);
	update_post_meta( $attachment_id, OWNER_META, $user_id );

	$previous = avatar_id( $user_id );
	update_user_meta( $user_id, meta_key(), $attachment_id ); // Clears the render cache.
	delete_user_meta( $user_id, legacy_key() ); // So a stale legacy value can never come back.
	update_user_meta( $user_id, FILE_META, $relative );
	if ( $previous && $previous !== $attachment_id ) {
		delete_avatar( $previous, $user_id );
	}

	return $attachment_id;
}

/**
 * Handle the avatar fields on profile save. wp-admin and bbPress both fire
 * these hooks after checking the same nonce and capability, then call
 * edit_user(), whose user_profile_update_errors shows our errors.
 *
 * @param int $user_id User ID.
 */
function save( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'update-user_' . $user_id ) ) {
		return;
	}

	if ( ! empty( $_POST[ REMOVE ] ) ) {
		remove( $user_id );
	}

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in store_upload().
	$file = $_FILES[ FIELD ] ?? null;
	if ( ! is_array( $file ) || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
		return;
	}
	$result = store_upload( $user_id, $file );
	if ( is_wp_error( $result ) ) {
		errors()->add( $result->get_error_code(), $result->get_error_message(), array( 'form-field' => FIELD ) );
	}
}
add_action( 'personal_options_update', __NAMESPACE__ . '\\save' );
add_action( 'edit_user_profile_update', __NAMESPACE__ . '\\save' );

add_action(
	'user_profile_update_errors',
	function ( WP_Error $profile_errors ): void {
		foreach ( errors()->get_error_codes() as $code ) {
			$profile_errors->add( $code, errors()->get_error_message( $code ) );
		}
	}
);

add_action(
	'delete_user',
	function ( int $user_id ): void {
		remove( $user_id );
	}
);

// ─── Profile fields ──────────────────────────────────────────────────────────

/** The upload rules, shown under the file input. */
function help_text(): string {
	/* translators: 1: size limit, 2: avatar side in pixels */
	return sprintf( __( 'JPEG, PNG, WebP or GIF, up to %1$s. It is cropped to a %2$d px square.', 'geekswipe-avatars' ), size_format( max_bytes() ), output_size() );
}

/**
 * The field on the wp-admin Profile and Edit User screens.
 *
 * @param WP_User $user The user being edited.
 */
function admin_field( WP_User $user ): void {
	$has = '' !== avatar_url( $user->ID );
	?>
	<h2><?php esc_html_e( 'Profile picture', 'geekswipe-avatars' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="<?php echo esc_attr( FIELD ); ?>"><?php esc_html_e( 'Upload a picture', 'geekswipe-avatars' ); ?></label></th>
			<td>
				<?php echo get_avatar( $user->ID, 96 ); ?>
				<p><input type="file" name="<?php echo esc_attr( FIELD ); ?>" id="<?php echo esc_attr( FIELD ); ?>" accept="image/jpeg,image/png,image/webp,image/gif"></p>
				<p class="description"><?php echo esc_html( help_text() ); ?></p>
				<?php if ( $has ) : ?>
					<p><label><input type="checkbox" name="<?php echo esc_attr( REMOVE ); ?>" value="1"> <?php esc_html_e( 'Remove the current picture', 'geekswipe-avatars' ); ?></label></p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', __NAMESPACE__ . '\\admin_field' );
add_action( 'edit_user_profile', __NAMESPACE__ . '\\admin_field' );

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-discussion.php#geekswipe_avatars_max_kb' ) ),
			esc_html__( 'Settings', 'geekswipe-avatars' )
		);
		array_unshift( $links, $settings );
		return $links;
	}
);

add_action(
	'user_edit_form_tag',
	function (): void {
		echo ' enctype="multipart/form-data"';
	}
);

add_filter(
	'user_profile_picture_description',
	function (): string {
		return esc_html__( 'Change it under Profile picture below.', 'geekswipe-avatars' );
	}
);

/**
 * The field on the bbPress front-end Edit Profile form. Uses the active theme's form
 * classes (label, field-group, field-help-text), and plain markup elsewhere.
 */
add_action(
	'bbp_user_edit_after_name',
	function (): void {
		$user_id = function_exists( 'bbp_get_displayed_user_id' ) ? (int) bbp_get_displayed_user_id() : 0;
		if ( ! $user_id || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$has = '' !== avatar_url( $user_id );
		?>
	<div class="field-group gsa-field">
		<label class="label" for="<?php echo esc_attr( FIELD ); ?>"><?php esc_html_e( 'Profile picture', 'geekswipe-avatars' ); ?></label>
		<div class="gsa-field__row">
			<?php echo get_avatar( $user_id, 64, '', '', array( 'class' => 'gsa-field__preview' ) ); ?>
			<div class="gsa-field__controls">
				<input type="file" name="<?php echo esc_attr( FIELD ); ?>" id="<?php echo esc_attr( FIELD ); ?>" accept="image/jpeg,image/png,image/webp,image/gif" aria-describedby="gsa-avatar-help">
				<p class="field-help-text" id="gsa-avatar-help"><?php echo esc_html( help_text() ); ?></p>
				<?php if ( $has ) : ?>
					<label class="gsa-field__remove"><input type="checkbox" name="<?php echo esc_attr( REMOVE ); ?>" value="1"> <?php esc_html_e( 'Remove my picture', 'geekswipe-avatars' ); ?></label>
				<?php endif; ?>
			</div>
		</div>
	</div>
		<?php
	}
);
