<?php
/**
 * Everything to do with the images inside a merged document.
 *
 * An Elementor template carries no image files. It carries URLs, and the
 * importer on the other site downloads from them. So an image arrives only if
 * its URL still answers, from somewhere else, with no login. An image left
 * pointing at a site that has moved, gone private or gone away lands on the
 * other side as Elementor's grey placeholder, and nothing in the template file
 * can rescue it.
 *
 * This class gives those URLs the best chance: it points them at the address
 * this site actually answers on, and it offers to pull anything hosted
 * elsewhere into this site's own Media Library first, so the exported file
 * only ever asks for images this site serves.
 *
 * Nothing here touches a source document. It works on the merged copy.
 *
 * @package ElementorPageMerger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EPM_Images {

	/** Most images from another site to test in the report. */
	const MAX_URL_CHECKS = 6;

	/** @var array URL => attachment ID, so one image is fetched once a run. */
	private static $fetched = array();

	/**
	 * Every distinct image in the tree.
	 *
	 * Elementor's media control stores an id and a url together, which is what
	 * tells an image apart from a link control, which carries a url alone.
	 *
	 * @param array $elements Elements to scan.
	 * @return string[] Image URLs.
	 */
	public static function collect( array $elements ) {
		$urls = array();

		self::walk_collect( $elements, $urls );

		return array_keys( $urls );
	}

	/**
	 * @param mixed $value Any part of an element tree.
	 * @param array $urls  URLs found so far, by reference.
	 * @return void
	 */
	private static function walk_collect( $value, array &$urls ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( array_key_exists( 'id', $value ) && ! empty( $value['url'] ) && is_string( $value['url'] ) ) {
			$urls[ $value['url'] ] = true;
		}

		foreach ( $value as $item ) {
			if ( is_array( $item ) ) {
				self::walk_collect( $item, $urls );
			}
		}
	}

	/**
	 * How many distinct images the tree holds.
	 *
	 * @param array $elements Elements to scan.
	 * @return int
	 */
	public static function count( array $elements ) {
		return count( self::collect( $elements ) );
	}

	/**
	 * Image URLs that live on a site other than this one. These are the ones
	 * an importer elsewhere is most likely to fail to fetch.
	 *
	 * @param array  $elements Elements to scan.
	 * @param string $host     Host this site answers on.
	 * @return string[]
	 */
	public static function foreign( array $elements, $host ) {
		$foreign = array();

		foreach ( self::collect( $elements ) as $url ) {
			$url_host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( $url_host && $url_host !== $host ) {
				$foreign[] = $url;
			}
		}

		return $foreign;
	}

	/**
	 * Walk every media control in the tree and hand each one to a callback.
	 *
	 * @param array    $elements Elements to process.
	 * @param callable $callback Given an id/url pair, returns the new pair.
	 * @return array
	 */
	private static function map_media( array $elements, $callback ) {
		$out = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$element['settings'] = self::map_settings( $element['settings'], $callback );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::map_media( $element['elements'], $callback );
			}

			$out[] = $element;
		}

		return $out;
	}

	/**
	 * @param array    $settings Settings, or any part of them.
	 * @param callable $callback Given an id/url pair, returns the new pair.
	 * @return array
	 */
	private static function map_settings( array $settings, $callback ) {
		// A media control is an array holding both an id and a url. A link
		// control holds a url alone, and must not be touched.
		if ( array_key_exists( 'id', $settings ) && ! empty( $settings['url'] ) && is_string( $settings['url'] ) ) {
			$settings = call_user_func( $callback, $settings );
		}

		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				$settings[ $key ] = self::map_settings( $value, $callback );
			}
		}

		return $settings;
	}

	/**
	 * Point image URLs at the address this site answers on.
	 *
	 * A site reached through more than one domain stores whichever address was
	 * in use when the image was placed, so one page can carry images under two
	 * different hosts. An importer elsewhere fetches exactly what it is given,
	 * and an old or private address fails. A URL is only rewritten when the
	 * file is confirmed to sit in this site's own uploads folder, so an image
	 * genuinely hosted somewhere else is left alone for localize() to deal
	 * with.
	 *
	 * @param array  $elements Elements to process.
	 * @param string $host     Host this site answers on.
	 * @param string $base_url Uploads folder URL.
	 * @param string $base_dir Uploads folder path on disk.
	 * @return array
	 */
	public static function normalize( array $elements, $host, $base_url, $base_dir ) {
		return self::map_media(
			$elements,
			function ( $media ) use ( $host, $base_url, $base_dir ) {
				$media['url'] = self::canonical_url( $media['url'], $host, $base_url, $base_dir );

				return $media;
			}
		);
	}

	/**
	 * @param string $url      URL to consider.
	 * @param string $host     Host this site answers on.
	 * @param string $base_url Uploads folder URL.
	 * @param string $base_dir Uploads folder path on disk.
	 * @return string
	 */
	private static function canonical_url( $url, $host, $base_url, $base_dir ) {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( empty( $parts['host'] ) || empty( $parts['path'] ) || $parts['host'] === $host ) {
			return $url;
		}

		$base_path = parse_url( $base_url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $base_path ) {
			return $url;
		}

		$at = strpos( $parts['path'], $base_path );

		if ( false === $at ) {
			return $url;
		}

		$relative = substr( $parts['path'], $at + strlen( $base_path ) );

		if ( '' === $relative || ! file_exists( $base_dir . $relative ) ) {
			return $url;
		}

		return $base_url . $relative;
	}

	/**
	 * Pull every image hosted elsewhere into this site's Media Library and
	 * point the merged copy at the local file.
	 *
	 * This is what makes images survive the trip. After it, the exported file
	 * asks only for images this site serves, so the importer on the other side
	 * has a live address to download from, and a Template Kit export has a real
	 * attachment to bundle.
	 *
	 * @param array  $elements  Elements to process.
	 * @param string $host      Host this site answers on.
	 * @param bool   $allow_svg Whether SVG files may be brought in.
	 * @return array array( elements, downloaded, reused, failed ).
	 */
	public static function localize( array $elements, $host, $allow_svg = true ) {
		$downloaded = 0;
		$reused     = 0;
		$failed     = array();

		$elements = self::map_media(
			$elements,
			function ( $media ) use ( $host, $allow_svg, &$downloaded, &$reused, &$failed ) {
				$url      = $media['url'];
				$url_host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions

				if ( ! $url_host || $url_host === $host ) {
					return $media; // Already ours.
				}

				if ( isset( self::$fetched[ $url ] ) ) {
					++$reused;

					return self::apply_attachment( $media, self::$fetched[ $url ] );
				}

				$attachment_id = self::sideload( $url, $allow_svg );

				if ( is_wp_error( $attachment_id ) ) {
					// The code matters as much as the message: a 404 and a
					// blocked file type and a refused connection all read as
					// "it did not work" otherwise, and they need different
					// answers.
					$code   = $attachment_id->get_error_code();
					$reason = $attachment_id->get_error_message();

					$failed[ $url ] = array(
						'url'    => $url,
						'reason' => $code ? sprintf( '%s: %s', $code, $reason ) : $reason,
					);

					return $media;
				}

				self::$fetched[ $url ] = $attachment_id;
				++$downloaded;

				return self::apply_attachment( $media, $attachment_id );
			}
		);

		return array(
			'elements'   => $elements,
			'downloaded' => $downloaded,
			'reused'     => $reused,
			'failed'     => array_values( $failed ),
		);
	}

	/**
	 * @param array $media         A media control's id/url pair.
	 * @param int   $attachment_id Attachment now holding this image.
	 * @return array
	 */
	private static function apply_attachment( array $media, $attachment_id ) {
		$local = wp_get_attachment_url( $attachment_id );

		if ( ! $local ) {
			return $media;
		}

		$media['id']  = (int) $attachment_id;
		$media['url'] = $local;

		return $media;
	}

	/** Extensions worth treating as an image. */
	const IMAGE_TYPES = array( 'jpg', 'jpeg', 'jpe', 'gif', 'png', 'webp', 'avif', 'bmp', 'ico', 'svg' );

	/** Records which remote image an attachment came from. */
	const META_SOURCE_URL = '_epm_source_url';

	/**
	 * Download one image into the Media Library.
	 *
	 * WordPress's own media_sideload_image() is not used here, for two reasons
	 * that between them block most of what a template actually carries.
	 *
	 * It fetches through wp_safe_remote_get(), which refuses any host whose
	 * name does not resolve from this server, or that resolves to a private
	 * address. Two sites on one server usually resolve each other privately, so
	 * every image of a sister site is refused before a request is even made,
	 * with the message "A valid URL was not provided". The plain wp_remote_get()
	 * used below makes the request and reports what actually came back.
	 *
	 * It also accepts only jpg, jpeg, gif, png, webp and avif. An .svg is
	 * turned away as an "Invalid image URL", which is how a set of icons goes
	 * missing without anything appearing to be wrong.
	 *
	 * @param string $url       Image URL.
	 * @param bool   $allow_svg Whether SVG files may be brought in.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function sideload( $url, $allow_svg ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$already = self::find_imported( $url );

		if ( $already ) {
			return $already; // Brought in by an earlier row or an earlier run.
		}

		$path = parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$name = $path ? sanitize_file_name( urldecode( basename( $path ) ) ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'epm_no_filename', 'The URL carries no file name.' );
		}

		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::IMAGE_TYPES, true ) ) {
			return new WP_Error( 'epm_not_an_image', sprintf( 'Not an image file (.%s).', $extension ) );
		}

		if ( 'svg' === $extension && ! $allow_svg ) {
			return new WP_Error( 'epm_svg_skipped', 'An SVG, and SVG files were left out.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'epm_http_' . $code, sprintf( 'The server answered %d.', $code ) );
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error( 'epm_empty_file', 'The file came back empty.' );
		}

		if ( 'svg' === $extension && preg_match( '/<script|javascript:|\son\w+\s*=/i', $body ) ) {
			// Not a full sanitiser, but an SVG carrying script has no business
			// in a template kit, and silently storing one would be worse.
			return new WP_Error( 'epm_svg_has_script', 'This SVG carries script, so it was left out.' );
		}

		$temp = wp_tempnam( $name );

		if ( ! $temp ) {
			return new WP_Error( 'epm_no_temp_file', 'A temporary file could not be created.' );
		}

		file_put_contents( $temp, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( 'svg' === $extension ) {
			self::allow_svg_uploads( true );
		}

		$moved = wp_handle_sideload(
			array(
				'name'     => $name,
				'tmp_name' => $temp,
				'size'     => strlen( $body ),
			),
			array( 'test_form' => false )
		);

		if ( 'svg' === $extension ) {
			self::allow_svg_uploads( false );
		}

		if ( ! empty( $moved['error'] ) ) {
			if ( file_exists( $temp ) ) {
				unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}

			return new WP_Error( 'epm_rejected', $moved['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $name ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$moved['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $moved['file'] )
		);

		// So the same image is never fetched twice, here or in a later run.
		update_post_meta( $attachment_id, self::META_SOURCE_URL, $url );

		return (int) $attachment_id;
	}

	/**
	 * An attachment already brought in from this URL, by any earlier row or
	 * run. Without this, a header shared by thirty pages is downloaded thirty
	 * times over.
	 *
	 * @param string $url Image URL.
	 * @return int Attachment ID, or 0.
	 */
	private static function find_imported( $url ) {
		global $wpdb;

		$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_SOURCE_URL,
				$url
			)
		);

		if ( $id && get_post( $id ) ) {
			return $id;
		}

		return 0;
	}

	/**
	 * Let an SVG through WordPress's upload checks, for one file at a time.
	 *
	 * @param bool $on Whether to allow it.
	 * @return void
	 */
	private static function allow_svg_uploads( $on ) {
		if ( $on ) {
			add_filter( 'upload_mimes', array( __CLASS__, 'add_svg_mime' ) );
			add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'pass_svg_check' ), 10, 3 );

			return;
		}

		remove_filter( 'upload_mimes', array( __CLASS__, 'add_svg_mime' ) );
		remove_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'pass_svg_check' ), 10 );
	}

	/**
	 * @param array $mimes Allowed types.
	 * @return array
	 */
	public static function add_svg_mime( $mimes ) {
		$mimes['svg'] = 'image/svg+xml';

		return $mimes;
	}

	/**
	 * @param array  $data     Checked type and extension.
	 * @param string $file     File on disk.
	 * @param string $filename File name.
	 * @return array
	 */
	public static function pass_svg_check( $data, $file, $filename ) {
		if ( preg_match( '/\.svg$/i', (string) $filename ) ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}

		return $data;
	}

	/**
	 * Ask each URL whether it is still there.
	 *
	 * An importer on another site fetches these over plain HTTP with no login,
	 * which is what this does. A URL that does not answer here will not answer
	 * there either, and that is the difference between an image that arrives
	 * and one that lands as a grey placeholder.
	 *
	 * @param string[] $urls URLs to test.
	 * @return array[] Each: url, status, ok.
	 */
	public static function check( array $urls ) {
		if ( ! function_exists( 'wp_remote_head' ) ) {
			return array();
		}

		$checked = array();

		foreach ( array_slice( $urls, 0, self::MAX_URL_CHECKS ) as $url ) {
			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => 4,
					'redirection' => 2,
				)
			);

			if ( is_wp_error( $response ) ) {
				$checked[] = array(
					'url'    => $url,
					'status' => 'could not be reached: ' . $response->get_error_message(),
					'ok'     => false,
				);
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$ok   = ( $code >= 200 && $code < 300 );

			$checked[] = array(
				'url'    => $url,
				'status' => $ok ? sprintf( 'reachable (%d)', $code ) : sprintf( 'will not import (%d)', $code ),
				'ok'     => $ok,
			);
		}

		return $checked;
	}

	/**
	 * The host this site answers on.
	 *
	 * @return string
	 */
	public static function site_host() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Normalize, then optionally localize, in the order that gets the most
	 * images home: rewrite what is already ours, download what is not.
	 *
	 * @param array $elements  Elements to process.
	 * @param bool  $localize  Whether to download images hosted elsewhere.
	 * @param bool  $allow_svg Whether SVG files may be brought in.
	 * @return array array( elements, downloaded, reused, failed ).
	 */
	public static function prepare( array $elements, $localize, $allow_svg = true ) {
		$uploads = wp_upload_dir();
		$host    = self::site_host();

		$elements = self::normalize(
			$elements,
			$host,
			untrailingslashit( $uploads['baseurl'] ),
			untrailingslashit( $uploads['basedir'] )
		);

		if ( ! $localize ) {
			return array(
				'elements'   => $elements,
				'downloaded' => 0,
				'reused'     => 0,
				'failed'     => array(),
			);
		}

		return self::localize( $elements, $host, $allow_svg );
	}
}
