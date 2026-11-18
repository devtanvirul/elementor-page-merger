<?php
/**
 * Writes a merged document into the Elementor Template Library and verifies
 * the write byte for byte.
 *
 * @package ElementorPageMerger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EPM_Saver {

	const LIBRARY_POST_TYPE = 'elementor_library';
	const LIBRARY_TAXONOMY  = 'elementor_library_type';

	/**
	 * Merge one row and save the result as a new Elementor Library document.
	 * The source document is never modified, apart from the pointer meta that
	 * records which merged document belongs to it.
	 *
	 * @param int  $source_id Source document ID.
	 * @param int  $header_id Header document ID, 0 for none.
	 * @param int  $footer_id Footer document ID, 0 for none.
	 * @param bool $localize  Pull images hosted elsewhere into this site first.
	 * @return array|WP_Error
	 */
	public static function save( $source_id, $header_id, $footer_id, $localize = true, $allow_svg = true ) {
		$merged = EPM_Merger::build( $source_id, $header_id, $footer_id );

		if ( is_wp_error( $merged ) ) {
			return $merged;
		}

		$images = EPM_Images::prepare( $merged, $localize, $allow_svg );
		$merged = $images['elements'];

		// The library document lives on this site, so attachment IDs still
		// point at the right media and Elementor's export pass is not run here.
		$json = wp_json_encode( $merged );

		if ( ! is_string( $json ) ) {
			return new WP_Error( 'epm_encode_failed', 'The merged data could not be encoded as JSON.' );
		}

		$title     = self::merged_title( $source_id );
		$target_id = (int) get_post_meta( $source_id, EPM_Sources::META_TARGET, true );

		if ( EPM_Sources::target_exists( $target_id ) ) {
			// Running a row again updates the document it made before.
			$result = wp_update_post(
				array(
					'ID'         => $target_id,
					'post_title' => $title,
				),
				true
			);
			$reused = true;
		} else {
			$result = wp_insert_post(
				array(
					'post_title'  => $title,
					'post_type'   => self::LIBRARY_POST_TYPE,
					'post_status' => 'publish',
				),
				true
			);
			$reused = false;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$target_id = (int) ( $reused ? $target_id : $result );

		// update_post_meta() runs wp_unslash() on the value it is given, which
		// strips a layer of backslashes out of Elementor's JSON: URLs come out
		// shortened and any text holding a quote mark breaks the JSON outright,
		// so the template opens as a blank canvas with no error anywhere.
		// Elementor's own code wraps the value in wp_slash() for this reason.
		update_post_meta( $target_id, EPM_Sources::META_DATA, wp_slash( $json ) );
		update_post_meta( $target_id, EPM_Sources::META_PAGE_SETTINGS, wp_slash( EPM_Merger::build_page_settings( $source_id ) ) );

		update_post_meta( $target_id, EPM_Sources::META_EDIT_MODE, 'builder' );
		update_post_meta( $target_id, EPM_Sources::META_TEMPLATE_TYPE, 'page' );
		update_post_meta( $target_id, EPM_Sources::META_PAGE_TEMPLATE, 'elementor_canvas' );
		update_post_meta( $target_id, EPM_Sources::META_VERSION, self::elementor_version() );
		update_post_meta( $target_id, EPM_Sources::META_SOURCE, $source_id );

		if ( taxonomy_exists( self::LIBRARY_TAXONOMY ) ) {
			// Puts the template under the library's Pages tab.
			wp_set_object_terms( $target_id, 'page', self::LIBRARY_TAXONOMY );
		}

		update_post_meta( $source_id, EPM_Sources::META_TARGET, $target_id );

		// Read the value back and compare it against what was written, rather
		// than assuming the write survived.
		$stored   = get_post_meta( $target_id, EPM_Sources::META_DATA, true );
		$stored   = is_string( $stored ) ? $stored : '';
		$verified = ( $stored === $json );

		return array(
			'target_id'     => $target_id,
			'reused'        => $reused,
			'title'         => $title,
			'chars'         => strlen( $json ),
			'stored_chars'  => strlen( $stored ),
			'elements'      => EPM_Merger::count_elements( $merged ),
			'images'        => EPM_Images::count( $merged ),
			'downloaded'    => $images['downloaded'],
			'failed'        => $images['failed'],
			'verified'      => $verified,
			'edit_url'      => admin_url( 'post.php?post=' . $target_id . '&action=elementor' ),
			'verify_detail' => $verified
				? 'Read back byte for byte identical.'
				: sprintf( 'Wrote %d characters, read back %d.', strlen( $json ), strlen( $stored ) ),
		);
	}

	/**
	 * Title for the merged document.
	 *
	 * @param int $source_id Source document ID.
	 * @return string
	 */
	public static function merged_title( $source_id ) {
		$title = get_the_title( $source_id );

		if ( '' === trim( (string) $title ) ) {
			$title = sprintf( 'Document #%d', (int) $source_id );
		}

		/**
		 * Filter the title given to a merged document.
		 *
		 * @param string $title     Proposed title.
		 * @param int    $source_id Source document ID.
		 */
		return apply_filters( 'epm_merged_title', $title . ' - Full Page', $source_id );
	}

	/**
	 * Elementor's version, for the document's own version meta.
	 *
	 * @return string
	 */
	public static function elementor_version() {
		return defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '';
	}

	/**
	 * Clear Elementor's generated CSS files. Called once after a whole batch:
	 * clearing per row rebuilds every CSS file on the site once per template,
	 * which on a batch of a dozen heavy templates alone pushes the request past
	 * PHP's time limit and kills the batch partway through.
	 *
	 * @return bool Whether the cache was cleared.
	 */
	public static function clear_file_cache() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( ! isset( $elementor->files_manager ) || ! method_exists( $elementor->files_manager, 'clear_cache' ) ) {
			return false;
		}

		$elementor->files_manager->clear_cache();

		return true;
	}
}
