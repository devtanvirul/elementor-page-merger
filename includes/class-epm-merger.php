<?php
/**
 * Reads Elementor data, concatenates header + page + footer at the top level,
 * and regenerates every element ID in the merged tree.
 *
 * @package ElementorPageMerger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EPM_Merger {

	/** Most settings shown for one widget type in the report. */
	const MAX_SAMPLE_SETTINGS = 30;

	/**
	 * A document's Elementor data as a PHP array of top level elements.
	 *
	 * @param int $post_id Document ID.
	 * @return array|WP_Error
	 */
	public static function read_elements( $post_id ) {
		$raw = get_post_meta( $post_id, EPM_Sources::META_DATA, true );

		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			// A document written without wp_slash() carries an extra layer of
			// backslashes. Try once more with them removed before giving up.
			$decoded = json_decode( wp_unslash( $raw ), true );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'epm_bad_json',
				sprintf( 'Document #%d holds Elementor data that is not valid JSON.', (int) $post_id )
			);
		}

		return $decoded;
	}

	/**
	 * Merge top level element arrays in order, then give every element in the
	 * resulting tree a fresh unique ID.
	 *
	 * _elementor_data is a JSON array of top level elements (section or
	 * container), so merging is plain array concatenation. Both element types
	 * nest their children under the same 'elements' key, so one recursion
	 * covers sections, columns, containers and widgets alike.
	 *
	 * @param array[] $groups Arrays of top level elements, in output order.
	 * @return array
	 */
	public static function merge( array $groups ) {
		$merged = array();

		foreach ( $groups as $group ) {
			if ( is_array( $group ) ) {
				$merged = array_merge( $merged, array_values( $group ) );
			}
		}

		return self::regenerate_ids( $merged );
	}

	/**
	 * Replace every element ID in the tree, recursively.
	 *
	 * Elementor requires element IDs to be unique inside a document. The header
	 * and the page can each hold an element with the same ID, which breaks the
	 * editor and the generated CSS in ways that are hard to trace. Elementor's
	 * own importer regenerates them, and so does this.
	 *
	 * @param array      $elements Elements to walk.
	 * @param array|null $used     IDs already handed out, by reference.
	 * @return array
	 */
	public static function regenerate_ids( array $elements, &$used = null ) {
		if ( ! is_array( $used ) ) {
			$used = array();
		}

		$out = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$element['id'] = self::new_id( $used );

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::regenerate_ids( $element['elements'], $used );
			}

			$out[] = $element;
		}

		return $out;
	}

	/**
	 * A fresh element ID in Elementor's format: seven hex characters.
	 *
	 * @param array $used IDs already handed out, by reference.
	 * @return string
	 */
	private static function new_id( array &$used ) {
		do {
			$id = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 7 );
		} while ( isset( $used[ $id ] ) );

		$used[ $id ] = true;

		return $id;
	}

	/**
	 * Every element in the tree, at every depth.
	 *
	 * @param array $elements Elements to count.
	 * @return int
	 */
	public static function count_elements( array $elements ) {
		$count = 0;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			++$count;

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += self::count_elements( $element['elements'] );
			}
		}

		return $count;
	}

	/**
	 * Run Elementor's own export pass over a tree.
	 *
	 * Elementor does this to everything it exports, and it is where a media
	 * control drops the attachment ID that only means something on the site the
	 * template came from, leaving the URL the importer downloads from. Writing
	 * the raw database value to a file skips it, and the images arrive pointing
	 * at attachment IDs that belong to somebody else's media library.
	 *
	 * Anything Elementor cannot instantiate, such as a widget from a plugin
	 * that is not active, is passed through untouched rather than dropped.
	 *
	 * @param array $elements Elements to process.
	 * @return array
	 */
	public static function export_pass( array $elements ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $elements;
		}

		$elementor = \Elementor\Plugin::$instance;

		if ( ! isset( $elementor->elements_manager ) || ! isset( $elementor->controls_manager ) ) {
			return $elements;
		}

		return self::walk_export( $elements, $elementor );
	}

	/**
	 * @param array  $elements  Elements to process.
	 * @param object $elementor Elementor's plugin instance.
	 * @return array
	 */
	private static function walk_export( array $elements, $elementor ) {
		$out = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$element = self::export_element( $element, $elementor );

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::walk_export( $element['elements'], $elementor );
			}

			$out[] = $element;
		}

		return $out;
	}

	/**
	 * @param array  $element   One element's data.
	 * @param object $elementor Elementor's plugin instance.
	 * @return array
	 */
	private static function export_element( array $element, $elementor ) {
		if ( empty( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
			return $element;
		}

		try {
			$instance = $elementor->elements_manager->create_element_instance( $element );

			if ( ! $instance ) {
				return $element;
			}

			foreach ( $instance->get_controls() as $control ) {
				if ( empty( $control['name'] ) || empty( $control['type'] ) ) {
					continue;
				}

				$name = $control['name'];

				if ( ! isset( $element['settings'][ $name ] ) ) {
					continue;
				}

				$control_object = $elementor->controls_manager->get_control( $control['type'] );

				if ( ! $control_object || ! method_exists( $control_object, 'on_export' ) ) {
					continue;
				}

				$element['settings'][ $name ] = $control_object->on_export( $element['settings'][ $name ], $control );
			}
		} catch ( \Exception $e ) {
			return $element;
		} catch ( \Error $e ) {
			return $element;
		}

		return $element;
	}

	/**
	 * Build the merged element tree for one row.
	 *
	 * @param int $source_id Source document ID.
	 * @param int $header_id Header document ID, 0 for none.
	 * @param int $footer_id Footer document ID, 0 for none.
	 * @return array|WP_Error Merged top level elements.
	 */
	public static function build( $source_id, $header_id, $footer_id ) {
		$page = self::read_elements( $source_id );

		if ( is_wp_error( $page ) ) {
			return $page;
		}

		if ( ! $page ) {
			return new WP_Error( 'epm_empty_source', 'This document holds no Elementor data.' );
		}

		if ( ! $header_id && ! $footer_id ) {
			return new WP_Error( 'epm_no_part', 'Pick a header or a footer for this row.' );
		}

		$groups = array();

		if ( $header_id ) {
			$header = self::read_elements( $header_id );

			if ( is_wp_error( $header ) ) {
				return $header;
			}

			if ( ! $header ) {
				return new WP_Error( 'epm_empty_header', 'The chosen header holds no Elementor data.' );
			}

			$groups[] = $header;
		}

		$groups[] = $page;

		if ( $footer_id ) {
			$footer = self::read_elements( $footer_id );

			if ( is_wp_error( $footer ) ) {
				return $footer;
			}

			if ( ! $footer ) {
				return new WP_Error( 'epm_empty_footer', 'The chosen footer holds no Elementor data.' );
			}

			$groups[] = $footer;
		}

		return self::merge( $groups );
	}

	/**
	 * Page settings for the merged document: the source page's own settings,
	 * forced to the Elementor Canvas layout so the theme does not wrap its own
	 * header and footer around a page that already carries both.
	 *
	 * @param int $source_id Source document ID.
	 * @return array
	 */
	public static function build_page_settings( $source_id ) {
		$settings             = EPM_Sources::get_page_settings( $source_id );
		$settings['template'] = 'elementor_canvas';

		return $settings;
	}

	/**
	 * Every widget type in the tree, with how many times it appears.
	 *
	 * @param array $elements Elements to scan.
	 * @param array $widgets  type => count, by reference.
	 * @return void
	 */
	public static function collect_widgets( $elements, array &$widgets ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['widgetType'] ) ) {
				$type             = (string) $element['widgetType'];
				$widgets[ $type ] = isset( $widgets[ $type ] ) ? $widgets[ $type ] + 1 : 1;
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_widgets( $element['elements'], $widgets );
			}
		}
	}

	/**
	 * One sample of each widget type's own settings, flattened and shortened,
	 * so a widget this plugin does not understand can still be read.
	 *
	 * @param array $elements Elements to scan.
	 * @param array $samples  type => settings, by reference.
	 * @return void
	 */
	public static function collect_widget_settings( $elements, array &$samples ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['widgetType'] ) && ! isset( $samples[ $element['widgetType'] ] ) ) {
				$flat = array();

				if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
					self::flatten_settings( $element['settings'], $flat );
				}

				$samples[ $element['widgetType'] ] = array_slice( $flat, 0, self::MAX_SAMPLE_SETTINGS );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_widget_settings( $element['elements'], $samples );
			}
		}
	}

	/**
	 * @param array  $settings Settings, or any part of them.
	 * @param array  $flat     Flattened key/value pairs, by reference.
	 * @param string $prefix   Path walked so far.
	 * @return void
	 */
	private static function flatten_settings( $settings, array &$flat, $prefix = '' ) {
		foreach ( $settings as $key => $value ) {
			$name = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				if ( count( $flat ) < self::MAX_SAMPLE_SETTINGS ) {
					self::flatten_settings( $value, $flat, $name );
				}
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$text = (string) $value;

			if ( '' === $text ) {
				continue;
			}

			$flat[] = array(
				'key'   => $name,
				'value' => mb_substr( $text, 0, 60 ),
			);
		}
	}
}
