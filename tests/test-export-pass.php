<?php
/**
 * Harness for the Elementor export pass. Stands in for Elementor itself, with
 * a media control that behaves the way Elementor's does, and checks that the
 * pass reaches every element and survives a widget it cannot instantiate.
 */

namespace Elementor {

	class Control_Media {
		/** Elementor drops the attachment ID on export, keeping the URL. */
		public function on_export( $settings ) {
			unset( $settings['id'] );

			return $settings;
		}
	}

	class Control_Text {
		// No on_export: the pass must leave this control alone.
	}

	class Fake_Element {
		private $controls;

		public function __construct( $controls ) {
			$this->controls = $controls;
		}

		public function get_controls() {
			return $this->controls;
		}
	}

	class Elements_Manager {
		public $instantiated = 0;

		public function create_element_instance( $data ) {
			++$this->instantiated;

			$type = isset( $data['widgetType'] ) ? $data['widgetType'] : $data['elType'];

			if ( 'widget-from-a-plugin-that-is-off' === $type ) {
				return null;
			}

			if ( 'widget-that-blows-up' === $type ) {
				throw new \Exception( 'This widget throws during instantiation.' );
			}

			return new Fake_Element(
				array(
					array(
						'name' => 'background_image',
						'type' => 'media',
					),
					array(
						'name' => 'image',
						'type' => 'media',
					),
					array(
						'name' => 'title',
						'type' => 'text',
					),
				)
			);
		}
	}

	class Controls_Manager {
		public function get_control( $type ) {
			if ( 'media' === $type ) {
				return new Control_Media();
			}

			return new Control_Text();
		}
	}

	class Plugin {
		public static $instance;
		public $elements_manager;
		public $controls_manager;

		public function __construct() {
			$this->elements_manager = new Elements_Manager();
			$this->controls_manager = new Controls_Manager();
		}
	}

	Plugin::$instance = new Plugin();
}

namespace {

	define( 'ABSPATH', __DIR__ );

	$GLOBALS['meta'] = array();

	function get_post_meta( $id, $key, $single = false ) {
		return '';
	}

	function get_post( $id ) {
		return null;
	}

	function wp_rand( $min = 0, $max = PHP_INT_MAX ) {
		return random_int( $min, $max );
	}

	function wp_unslash( $v ) {
		return is_string( $v ) ? stripslashes( $v ) : $v;
	}

	function apply_filters( $hook, $value ) {
		return $value;
	}

	class WP_Error {
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	class EPM_Sources {
		const META_DATA = '_elementor_data';
	}

	require_once dirname( __DIR__ ) . '/includes/class-epm-merger.php';

	$pass = 0;
	$fail = 0;

	function check( $label, $condition, $detail = '' ) {
		global $pass, $fail;
		if ( $condition ) {
			++$pass;
			echo "PASS  $label\n";
		} else {
			++$fail;
			echo "FAIL  $label $detail\n";
		}
	}

	$tree = array(
		array(
			'id'       => 'sec0001',
			'elType'   => 'container',
			'settings' => array(
				'background_image' => array(
					'id'  => 512,
					'url' => 'https://devtanvirul.com/wp-content/uploads/hero.jpg',
				),
			),
			'elements' => array(
				array(
					'id'         => 'wid0001',
					'elType'     => 'widget',
					'widgetType' => 'image',
					'settings'   => array(
						'image' => array(
							'id'  => 513,
							'url' => 'https://devtanvirul.com/wp-content/uploads/team.jpg',
						),
						'title' => 'Our team',
					),
					'elements'   => array(),
				),
				array(
					'id'         => 'wid0002',
					'elType'     => 'widget',
					'widgetType' => 'widget-from-a-plugin-that-is-off',
					'settings'   => array(
						'image' => array(
							'id'  => 514,
							'url' => 'https://devtanvirul.com/wp-content/uploads/pro.jpg',
						),
					),
					'elements'   => array(),
				),
				array(
					'id'         => 'wid0003',
					'elType'     => 'widget',
					'widgetType' => 'widget-that-blows-up',
					'settings'   => array( 'title' => 'Still here' ),
					'elements'   => array(),
				),
			),
		),
	);

	$out = EPM_Merger::export_pass( $tree );

	check(
		'attachment ID stripped from a top level background image',
		! isset( $out[0]['settings']['background_image']['id'] ),
		print_r( $out[0]['settings']['background_image'], true )
	);

	check(
		'the image URL is kept, which is what the importer downloads',
		'https://devtanvirul.com/wp-content/uploads/hero.jpg' === $out[0]['settings']['background_image']['url']
	);

	check(
		'the pass reaches nested widgets too',
		! isset( $out[0]['elements'][0]['settings']['image']['id'] )
		&& 'https://devtanvirul.com/wp-content/uploads/team.jpg' === $out[0]['elements'][0]['settings']['image']['url'],
		print_r( $out[0]['elements'][0]['settings']['image'], true )
	);

	check(
		'a control with no on_export is left alone',
		'Our team' === $out[0]['elements'][0]['settings']['title']
	);

	check(
		'a widget Elementor cannot instantiate is passed through, not dropped',
		isset( $out[0]['elements'][1] )
		&& 514 === $out[0]['elements'][1]['settings']['image']['id'],
		'element missing or altered'
	);

	check(
		'a widget that throws does not kill the export',
		isset( $out[0]['elements'][2] )
		&& 'Still here' === $out[0]['elements'][2]['settings']['title']
	);

	check( 'the tree keeps its shape', 1 === count( $out ) && 3 === count( $out[0]['elements'] ) );
	check( 'element IDs are not touched by the export pass', 'sec0001' === $out[0]['id'] );

	echo "\n$pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}
