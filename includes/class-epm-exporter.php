<?php
/**
 * Builds merged documents as Elementor import files and hands them over as a
 * single .json or a .zip, without writing anything to the library.
 *
 * @package ElementorPageMerger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EPM_Exporter {

	const DIR_NAME   = 'epm-export';
	const TRANSIENT  = 'epm_batch_';
	const BATCH_LIFE = HOUR_IN_SECONDS;

	/**
	 * Merge one row and stage the result as a file for download.
	 *
	 * @param string $batch     Batch ID, empty to start a new batch.
	 * @param int    $source_id Source document ID.
	 * @param int    $header_id Header document ID, 0 for none.
	 * @param int    $footer_id Footer document ID, 0 for none.
	 * @param bool   $localize  Pull images hosted elsewhere into this site first.
	 * @return array|WP_Error
	 */
	public static function stage( $batch, $source_id, $header_id, $footer_id, $localize = true, $allow_svg = true ) {
		$merged = EPM_Merger::build( $source_id, $header_id, $footer_id );

		if ( is_wp_error( $merged ) ) {
			return $merged;
		}

		// Give the images the best address this site can offer before the file
		// leaves: the importer on the other side has nothing but these URLs.
		$images = EPM_Images::prepare( $merged, $localize, $allow_svg );

		// The file is going to another site, so it is put through Elementor's
		// own export pass: that is what drops the attachment IDs, which mean
		// nothing there, and leaves the URLs the importer downloads from.
		$merged = EPM_Merger::export_pass( $images['elements'] );

		$title = get_the_title( $source_id );
		$title = '' !== trim( (string) $title ) ? $title : sprintf( 'document-%d', (int) $source_id );

		$payload = array(
			'version'       => self::db_version(),
			'title'         => $title,
			'type'          => 'page',
			'content'       => $merged,
			'page_settings' => EPM_Merger::build_page_settings( $source_id ),
		);

		$json = wp_json_encode( $payload );

		if ( ! is_string( $json ) ) {
			return new WP_Error( 'epm_encode_failed', 'The merged data could not be encoded as JSON.' );
		}

		$batch = self::open_batch( $batch );

		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		$dir   = self::batch_dir( $batch );
		$state = get_transient( self::TRANSIENT . $batch );
		$name  = self::unique_filename( $title, $state['files'] );

		if ( false === file_put_contents( $dir . '/' . $name, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'epm_write_failed', 'The export file could not be written to the uploads folder.' );
		}

		$state['files'][] = $name;
		set_transient( self::TRANSIENT . $batch, $state, self::BATCH_LIFE );

		return array(
			'batch'      => $batch,
			'filename'   => $name,
			'chars'      => strlen( wp_json_encode( $merged ) ),
			'elements'   => EPM_Merger::count_elements( $merged ),
			'images'     => EPM_Images::count( $merged ),
			'downloaded' => $images['downloaded'],
			'failed'     => $images['failed'],
		);
	}

	/**
	 * Send a staged batch to the browser and clean up after it.
	 *
	 * @param string $batch Batch ID.
	 * @return void|WP_Error
	 */
	public static function stream( $batch ) {
		$batch = self::sanitize_batch( $batch );
		$state = $batch ? get_transient( self::TRANSIENT . $batch ) : false;

		if ( ! is_array( $state ) || empty( $state['files'] ) ) {
			return new WP_Error( 'epm_no_batch', 'That export has expired. Run it again.' );
		}

		if ( (int) $state['user'] !== get_current_user_id() ) {
			return new WP_Error( 'epm_wrong_user', 'That export belongs to another user.' );
		}

		$dir = self::batch_dir( $batch );

		if ( 1 === count( $state['files'] ) ) {
			$path = $dir . '/' . $state['files'][0];
			$name = $state['files'][0];
			$type = 'application/json';
		} else {
			$name = 'elementor-merged-templates-' . gmdate( 'Ymd-His' ) . '.zip';
			$path = $dir . '/' . $name;
			$type = 'application/zip';

			$zipped = self::zip( $dir, $state['files'], $path );

			if ( is_wp_error( $zipped ) ) {
				return $zipped;
			}
		}

		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'epm_no_file', 'The export file is no longer on disk. Run it again.' );
		}

		nocache_headers();
		header( 'Content-Type: ' . $type );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		self::remove_dir( $dir );
		delete_transient( self::TRANSIENT . $batch );

		exit;
	}

	/**
	 * Start a batch, or confirm an existing one belongs to this user.
	 *
	 * @param string $batch Batch ID, empty to start a new batch.
	 * @return string|WP_Error
	 */
	private static function open_batch( $batch ) {
		$batch = self::sanitize_batch( $batch );

		if ( $batch ) {
			$state = get_transient( self::TRANSIENT . $batch );

			if ( is_array( $state ) && (int) $state['user'] === get_current_user_id() ) {
				return $batch;
			}
		}

		self::prune();

		$batch = md5( uniqid( (string) wp_rand(), true ) );
		$dir   = self::batch_dir( $batch );

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'epm_mkdir_failed', 'The export folder could not be created inside uploads.' );
		}

		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( dirname( $dir ) . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		set_transient(
			self::TRANSIENT . $batch,
			array(
				'user'  => get_current_user_id(),
				'files' => array(),
			),
			self::BATCH_LIFE
		);

		return $batch;
	}

	/**
	 * Zip the staged files.
	 *
	 * @param string   $dir   Batch folder.
	 * @param string[] $files File names inside it.
	 * @param string   $path  Zip file to write.
	 * @return true|WP_Error
	 */
	private static function zip( $dir, $files, $path ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new ZipArchive();

			if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				return new WP_Error( 'epm_zip_failed', 'The zip file could not be created.' );
			}

			foreach ( $files as $file ) {
				$zip->addFile( $dir . '/' . $file, $file );
			}

			$zip->close();

			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$archive = new PclZip( $path );
		$list    = array();

		foreach ( $files as $file ) {
			$list[] = $dir . '/' . $file;
		}

		if ( 0 === $archive->create( implode( ',', $list ), PCLZIP_OPT_REMOVE_ALL_PATH ) ) {
			return new WP_Error( 'epm_zip_failed', 'The zip file could not be created.' );
		}

		return true;
	}

	/**
	 * A file name that is not already taken inside the batch.
	 *
	 * @param string   $title Document title.
	 * @param string[] $taken Names already used.
	 * @return string
	 */
	private static function unique_filename( $title, $taken ) {
		$base = sanitize_file_name( strtolower( $title ) );
		$base = '' !== $base ? $base : 'template';
		$name = $base . '.json';
		$i    = 2;

		while ( in_array( $name, $taken, true ) ) {
			$name = $base . '-' . $i . '.json';
			++$i;
		}

		return $name;
	}

	/**
	 * Folder a batch stages its files in.
	 *
	 * @param string $batch Batch ID.
	 * @return string
	 */
	private static function batch_dir( $batch ) {
		$uploads = wp_upload_dir();

		return untrailingslashit( $uploads['basedir'] ) . '/' . self::DIR_NAME . '/' . $batch;
	}

	/**
	 * Drop batch folders left behind by an abandoned download.
	 *
	 * @return void
	 */
	private static function prune() {
		$uploads = wp_upload_dir();
		$root    = untrailingslashit( $uploads['basedir'] ) . '/' . self::DIR_NAME;

		if ( ! is_dir( $root ) ) {
			return;
		}

		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( filemtime( $dir ) < time() - self::BATCH_LIFE ) {
				self::remove_dir( $dir );
			}
		}
	}

	/**
	 * Delete a batch folder and its contents.
	 *
	 * @param string $dir Folder to delete.
	 * @return void
	 */
	private static function remove_dir( $dir ) {
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}

		if ( is_dir( $dir ) ) {
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * @param string $batch Batch ID from the request.
	 * @return string Hex batch ID, or an empty string.
	 */
	private static function sanitize_batch( $batch ) {
		$batch = is_string( $batch ) ? $batch : '';

		return preg_match( '/^[a-f0-9]{32}$/', $batch ) ? $batch : '';
	}

	/**
	 * The data version Elementor's importer expects in an export file.
	 *
	 * @return string
	 */
	private static function db_version() {
		if ( class_exists( '\Elementor\DB' ) && defined( '\Elementor\DB::DB_VERSION' ) ) {
			return (string) constant( '\Elementor\DB::DB_VERSION' );
		}

		return '0.4';
	}
}
