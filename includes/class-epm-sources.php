<?php
/**
 * Finds the documents the tool works with.
 *
 * Header and footer documents are detected by shape, not by plugin name: no
 * builder is named anywhere in this file, so a theme or add-on nobody has
 * written yet is found on the same terms as the ones that exist today.
 *
 * Whatever builds a header has to record somewhere that the document is a
 * header, and there are only three places to put that: the post type's own
 * name, a short meta value on a key that describes a type, or a taxonomy term.
 * The detector reads all three. Anything it still misses is listed at the
 * bottom of both dropdowns, so a document can always be picked by hand.
 *
 * @package ElementorPageMerger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EPM_Sources {

	const META_EDIT_MODE     = '_elementor_edit_mode';
	const META_DATA          = '_elementor_data';
	const META_TEMPLATE_TYPE = '_elementor_template_type';
	const META_PAGE_SETTINGS = '_elementor_page_settings';
	const META_VERSION       = '_elementor_version';
	const META_PAGE_TEMPLATE = '_wp_page_template';

	/** Link between a source document and the merged document made from it. */
	const META_TARGET = '_epm_merged_target';
	const META_SOURCE = '_epm_source';

	/** Statuses worth listing. Hard-coded, so safe to inline in SQL. */
	const STATUSES = "'publish','draft','private','pending','future'";

	/** Longest meta value that can still be a type label rather than content. */
	const MAX_LABEL_LENGTH = 40;

	/**
	 * The only documents kept out of the list, because there is no page in
	 * them to merge: a popup has no place in a page's flow, a kit holds the
	 * site's global settings rather than content, and a global widget is a
	 * fragment. Everything else is listed whatever post type it lives in, and
	 * the filter above the table decides what is shown.
	 */
	const NOT_A_PAGE = array( 'popup', 'kit', 'global-widget' );

	/** @var array|null id => document, built once per request. */
	private static $documents = null;

	/** @var array|null Detection result, built once per request. */
	private static $detection = null;

	/**
	 * Every document on the site that holds Elementor data, in any post type.
	 *
	 * @return array[] id => array( id, title, post_type, status, builder ).
	 */
	public static function get_documents() {
		if ( null !== self::$documents ) {
			return self::$documents;
		}

		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT p.ID, p.post_title, p.post_type, p.post_status, em.meta_value AS edit_mode
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} dm
				ON dm.post_id = p.ID AND dm.meta_key = '" . self::META_DATA . "'
				AND dm.meta_value NOT IN ( '', '[]' )
			LEFT JOIN {$wpdb->postmeta} em
				ON em.post_id = p.ID AND em.meta_key = '" . self::META_EDIT_MODE . "'
			WHERE p.post_status IN ( " . self::STATUSES . " )
			ORDER BY p.post_type ASC, p.post_title ASC"
		);

		self::$documents = array();

		foreach ( $rows as $row ) {
			$id = (int) $row->ID;

			self::$documents[ $id ] = array(
				'id'        => $id,
				'title'     => '' !== $row->post_title ? $row->post_title : sprintf( '(no title) #%d', $id ),
				'post_type' => $row->post_type,
				'status'    => $row->post_status,
				'builder'   => ( 'builder' === $row->edit_mode ),
			);
		}

		return self::$documents;
	}

	/**
	 * Does this text name a header or a footer, as a whole word?
	 *
	 * @param string $text Text to test.
	 * @param string $kind 'header' or 'footer'.
	 * @return bool
	 */
	private static function names( $text, $kind ) {
		$text = (string) $text;

		if ( ! preg_match( '/(?:^|[^a-z])' . $kind . '(?:[^a-z]|$)/i', $text ) ) {
			return false;
		}

		// A value such as no-header or hide-footer is a layout switch on an
		// ordinary page, and says the opposite of what it appears to say.
		return ! preg_match( '/(?:no|non|hide|hidden|without|disable|disabled|off|none|remove|removed|skip)[-_ ]*' . $kind . '/i', $text );
	}

	/**
	 * Work out which documents are headers and which are footers.
	 *
	 * Strong signals are the ones a builder writes on purpose, so a document
	 * carrying one is treated as a part and is kept out of the page list. A
	 * title alone is a weak signal: it puts the document in the dropdowns but
	 * leaves it in the page list too, because a page really can be called
	 * "Footer Showcase".
	 *
	 * @return array kind => array( 'strong' => id => reason, 'weak' => id => reason ).
	 */
	public static function detect() {
		if ( null !== self::$detection ) {
			return self::$detection;
		}

		$documents = self::get_documents();
		$found     = array(
			'header' => array(
				'strong' => array(),
				'weak'   => array(),
			),
			'footer' => array(
				'strong' => array(),
				'weak'   => array(),
			),
		);

		if ( ! $documents ) {
			self::$detection = $found;

			return $found;
		}

		$ids     = implode( ',', array_map( 'intval', array_keys( $documents ) ) );
		$kinds   = array( 'header', 'footer' );
		$signals = array();

		// Signal 1: the post type itself says what it holds, e.g. a theme that
		// registers its own header and footer post types.
		foreach ( $documents as $id => $document ) {
			foreach ( $kinds as $kind ) {
				if ( self::names( $document['post_type'], $kind ) ) {
					$signals[ $kind ][ $id ] = sprintf( 'post type %s', $document['post_type'] );
				}
			}
		}

		// Signal 2: a short meta value that says header or footer. Taken on its
		// own only when the value is exactly that word; otherwise the key also
		// has to look like it describes a type, so that a theme option such as
		// hide-header on a page is not mistaken for a header document.
		global $wpdb;

		$meta = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT post_id, meta_key, meta_value
			FROM {$wpdb->postmeta}
			WHERE post_id IN ( {$ids} )
			AND CHAR_LENGTH( meta_value ) BETWEEN 4 AND " . (int) self::MAX_LABEL_LENGTH
		);

		foreach ( $meta as $row ) {
			$id    = (int) $row->post_id;
			$value = trim( (string) $row->meta_value );

			foreach ( $kinds as $kind ) {
				if ( isset( $signals[ $kind ][ $id ] ) ) {
					continue;
				}

				$exact     = ( 0 === strcasecmp( $value, $kind ) );
				$type_key  = (bool) preg_match( '/type|kind|part|location|area/i', $row->meta_key );
				$mentioned = self::names( $value, $kind );

				if ( $exact || ( $type_key && $mentioned ) ) {
					$signals[ $kind ][ $id ] = sprintf( '%s = %s', $row->meta_key, $value );
				}
			}
		}

		// Signal 3: a taxonomy term named header or footer, which is how some
		// builders file their templates.
		$terms = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT tr.object_id, tt.taxonomy, t.slug, t.name
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tr.object_id IN ( {$ids} )"
		);

		foreach ( $terms as $row ) {
			$id = (int) $row->object_id;

			foreach ( $kinds as $kind ) {
				if ( isset( $signals[ $kind ][ $id ] ) ) {
					continue;
				}

				if ( 0 === strcasecmp( $row->slug, $kind ) || 0 === strcasecmp( $row->name, $kind ) ) {
					$signals[ $kind ][ $id ] = sprintf( '%s term %s', $row->taxonomy, $row->slug );
				}
			}
		}

		foreach ( $kinds as $kind ) {
			if ( ! empty( $signals[ $kind ] ) ) {
				$found[ $kind ]['strong'] = $signals[ $kind ];
			}
		}

		// Signal 4, weak: the title alone.
		foreach ( $documents as $id => $document ) {
			foreach ( $kinds as $kind ) {
				if ( isset( $found[ $kind ]['strong'][ $id ] ) ) {
					continue;
				}

				if ( self::names( $document['title'], $kind ) ) {
					$found[ $kind ]['weak'][ $id ] = 'title';
				}
			}
		}

		self::$detection = $found;

		return $found;
	}

	/**
	 * The option groups behind one header or footer dropdown.
	 *
	 * Detected documents come first, grouped by the post type they live in.
	 * Everything else Elementor built is listed underneath, so a document this
	 * detector did not recognise can still be picked by hand and the dropdown
	 * is never empty.
	 *
	 * @param string $kind 'header' or 'footer'.
	 * @return array[] Each: label, items (id, title, post_type).
	 */
	public static function get_part_groups( $kind ) {
		$documents = self::get_documents();
		$detection = self::detect();
		$strong    = $detection[ $kind ]['strong'];
		$weak      = $detection[ $kind ]['weak'];

		$detected = array();
		$titled   = array();
		$rest     = array();

		foreach ( $documents as $id => $document ) {
			$item = array(
				'id'        => $id,
				'title'     => $document['title'],
				'post_type' => $document['post_type'],
			);

			if ( isset( $strong[ $id ] ) ) {
				$detected[ $document['post_type'] ][] = $item;
			} elseif ( isset( $weak[ $id ] ) ) {
				$titled[] = $item;
			} else {
				$rest[] = $item;
			}
		}

		ksort( $detected );

		$groups = array();

		foreach ( $detected as $post_type => $items ) {
			$groups[] = array(
				/* translators: %1$s: kind, %2$s: post type. */
				'label' => sprintf( 'Detected %ss in %s', $kind, $post_type ),
				'items' => $items,
			);
		}

		if ( $titled ) {
			$groups[] = array(
				'label' => sprintf( 'Named like a %s, not confirmed', $kind ),
				'items' => $titled,
			);
		}

		if ( $rest ) {
			$groups[] = array(
				'label' => 'Every other Elementor document',
				'items' => $rest,
			);
		}

		return $groups;
	}

	/**
	 * A plain list of what the detector found, for the report on screen.
	 *
	 * @param string $kind 'header' or 'footer'.
	 * @return array[] Each: id, title, post_type, reason, confirmed.
	 */
	public static function get_detection_report( $kind ) {
		$documents = self::get_documents();
		$detection = self::detect();
		$report    = array();

		foreach ( array( 'strong', 'weak' ) as $level ) {
			foreach ( $detection[ $kind ][ $level ] as $id => $reason ) {
				if ( ! isset( $documents[ $id ] ) ) {
					continue;
				}

				$report[] = array(
					'id'        => $id,
					'title'     => $documents[ $id ]['title'],
					'post_type' => $documents[ $id ]['post_type'],
					'reason'    => $reason,
					'confirmed' => ( 'strong' === $level ),
				);
			}
		}

		return $report;
	}

	/**
	 * Every Elementor-built document that can be merged, across all post types.
	 * Documents the detector confirmed as a header or a footer are left out:
	 * they belong in the dropdowns, not in the list of pages to merge.
	 *
	 * @return array[] Each: id, title, post_type, status, chars, elements, target.
	 */
	public static function get_candidates() {
		$documents = self::get_documents();
		$detection = self::detect();
		$parts     = $detection['header']['strong'] + $detection['footer']['strong'];

		$candidates = array();

		foreach ( $documents as $id => $document ) {
			if ( ! $document['builder'] || isset( $parts[ $id ] ) ) {
				continue;
			}

			if ( get_post_meta( $id, self::META_SOURCE, true ) ) {
				continue; // A merged document this plugin made earlier.
			}

			$template_type = (string) get_post_meta( $id, self::META_TEMPLATE_TYPE, true );

			if ( in_array( $template_type, self::NOT_A_PAGE, true ) ) {
				continue;
			}

			$raw      = get_post_meta( $id, self::META_DATA, true );
			$raw      = is_string( $raw ) ? $raw : '';
			$elements = EPM_Merger::read_elements( $id );
			$target   = (int) get_post_meta( $id, self::META_TARGET, true );

			$candidates[] = array(
				'id'        => $id,
				'title'     => $document['title'],
				'post_type' => $document['post_type'],
				'kind'      => '' !== $template_type ? $template_type : '—',
				'status'    => $document['status'],
				'chars'     => strlen( $raw ),
				'elements'  => is_wp_error( $elements ) ? 0 : EPM_Merger::count_elements( $elements ),
				'images'    => is_wp_error( $elements ) ? 0 : EPM_Images::count( $elements ),
				'target'    => self::target_exists( $target ) ? $target : 0,
			);
		}

		return $candidates;
	}

	/**
	 * Whether a previously merged document is still there to be updated.
	 *
	 * @param int $target_id Merged document ID.
	 * @return bool
	 */
	public static function target_exists( $target_id ) {
		if ( ! $target_id ) {
			return false;
		}

		$post = get_post( $target_id );

		return $post && 'trash' !== $post->post_status && 'elementor_library' === $post->post_type;
	}

	/**
	 * Page settings of a document, as an array. Elementor stores these as an
	 * array, but a third party plugin may have left JSON behind.
	 *
	 * @param int $post_id Document ID.
	 * @return array
	 */
	public static function get_page_settings( $post_id ) {
		$settings = get_post_meta( $post_id, self::META_PAGE_SETTINGS, true );

		if ( is_string( $settings ) && '' !== $settings ) {
			$decoded  = json_decode( $settings, true );
			$settings = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $settings ) ? $settings : array();
	}
}
