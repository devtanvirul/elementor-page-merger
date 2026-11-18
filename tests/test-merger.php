<?php
/**
 * Harness for the merge logic: stubs just enough of WordPress to exercise
 * EPM_Merger against fixture data, outside WordPress.
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['meta'] = array();

function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : '';
}

function wp_rand( $min = 0, $max = PHP_INT_MAX ) {
	return random_int( $min, $max );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function apply_filters( $hook, $value ) {
	return $value;
}

// WordPress's own slashing helpers, verbatim in behaviour.
function wp_slash( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = is_array( $v ) || is_string( $v ) ? wp_slash( $v ) : $v;
		}
		return $value;
	}
	return is_string( $value ) ? addslashes( $value ) : $value;
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = is_array( $v ) || is_string( $v ) ? wp_unslash( $v ) : $v;
		}
		return $value;
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
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
	const META_DATA          = '_elementor_data';
	const META_PAGE_SETTINGS = '_elementor_page_settings';
	public static function get_page_settings( $id ) {
		$s = get_post_meta( $id, self::META_PAGE_SETTINGS, true );
		return is_array( $s ) ? $s : array();
	}
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

/* ---------------------------------------------------------------- fixtures */

// A header built with a flexbox container, holding a widget.
$header = array(
	array(
		'id'       => 'aaa1111',
		'elType'   => 'container',
		'settings' => array(
			'content_width'    => 'full',
			'background_image' => array(
				'id'  => 5,
				'url' => 'https://devtanvirul.com/wp-content/uploads/hero.jpg',
			),
		),
		'elements' => array(
			array(
				'id'         => 'bbb2222',
				'elType'     => 'widget',
				'widgetType' => 'nav-menu',
				'settings'   => array( 'menu' => 'main' ),
				'elements'   => array(),
			),
		),
	),
);

// A page built the old way, with a section and columns. Note the deliberate
// ID collision with the header, which is what breaks editing and CSS.
$page = array(
	array(
		'id'       => 'aaa1111',
		'elType'   => 'section',
		'elements' => array(
			array(
				'id'       => 'ccc3333',
				'elType'   => 'column',
				'elements' => array(
					array(
						'id'         => 'ddd4444',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array(
							'title' => 'He said "hello" \\ goodbye',
							// A link control: a url with no id, not an image.
							'link'  => array(
								'url'         => 'https://devtanvirul.com/about',
								'is_external' => '',
							),
						),
						'elements'   => array(),
					),
				),
			),
		),
	),
);

$footer = array(
	array(
		'id'       => 'eee5555',
		'elType'   => 'container',
		'elements' => array(
			array(
				'id'         => 'fff6666',
				'elType'     => 'widget',
				'widgetType' => 'text-editor',
				'settings'   => array(
					'editor' => '<a href="https://devtanvirul.com/some/deep/path">Site</a>',
					'image'  => array(
						'id'  => 9,
						'url' => 'https://devtanvirul.com/wp-content/uploads/logo.png',
					),
				),
				'elements'   => array(),
			),
		),
	),
);

$GLOBALS['meta'] = array(
	10 => array(
		'_elementor_data'          => json_encode( $page ),
		'_elementor_page_settings' => array( 'custom_css' => '.x{color:red}' ),
	),
	20 => array( '_elementor_data' => json_encode( $header ) ),
	30 => array( '_elementor_data' => json_encode( $footer ) ),
);

/* ------------------------------------------------------------------- tests */

$merged = EPM_Merger::build( 10, 20, 30 );
check( 'build returns an array', is_array( $merged ) );

check(
	'top level order is header, page, footer',
	3 === count( $merged )
	&& 'container' === $merged[0]['elType']
	&& 'section' === $merged[1]['elType']
	&& 'container' === $merged[2]['elType'],
	print_r( array_column( $merged, 'elType' ), true )
);

// Collect every ID in the merged tree.
$ids  = array();
$walk = function ( $elements ) use ( &$walk, &$ids ) {
	foreach ( $elements as $element ) {
		$ids[] = $element['id'];
		if ( ! empty( $element['elements'] ) ) {
			$walk( $element['elements'] );
		}
	}
};
$walk( $merged );

check( 'every element in the tree has an ID', ! in_array( '', $ids, true ) && 7 === count( $ids ), count( $ids ) );
check( 'all IDs unique after regeneration', count( $ids ) === count( array_unique( $ids ) ), implode( ',', $ids ) );
check( 'no original ID survives', ! array_intersect( $ids, array( 'aaa1111', 'bbb2222', 'ccc3333', 'ddd4444', 'eee5555', 'fff6666' ) ), implode( ',', $ids ) );
check( 'IDs are 7 hex chars like Elementor\'s', 0 === count( array_filter( $ids, function ( $id ) { return ! preg_match( '/^[0-9a-f]{7}$/', $id ); } ) ) );

check( 'element count counts every depth', 7 === EPM_Merger::count_elements( $merged ), EPM_Merger::count_elements( $merged ) );

// Nested settings must survive untouched.
check(
	'widget settings carried through',
	'He said "hello" \\ goodbye' === $merged[1]['elements'][0]['elements'][0]['settings']['title'],
	$merged[1]['elements'][0]['elements'][0]['settings']['title']
);

/* The reason for wp_slash(): update_post_meta() unslashes what it is given. */
$json      = wp_json_encode( $merged );
$naive     = wp_unslash( $json );                 // what lands in the DB without wp_slash()
$protected = wp_unslash( wp_slash( $json ) );     // what lands in the DB with it

check( 'without wp_slash the stored JSON is corrupted', $naive !== $json && null === json_decode( $naive, true ) );
check( 'with wp_slash the stored JSON is byte for byte identical', $protected === $json );
check( 'the URL survives wp_slash', false !== strpos( $protected, 'https:\/\/devtanvirul.com\/some\/deep\/path' ) );

/* Page settings: the page's own, forced to canvas. */
$settings = EPM_Merger::build_page_settings( 10 );
check( 'page settings forced to Elementor Canvas', 'elementor_canvas' === $settings['template'] );
check( 'existing page settings kept', '.x{color:red}' === $settings['custom_css'] );

/* Header only, footer only, neither. */
$header_only = EPM_Merger::build( 10, 20, 0 );
check( 'header only merges two groups', is_array( $header_only ) && 2 === count( $header_only ) );

$neither = EPM_Merger::build( 10, 0, 0 );
check( 'neither chosen is refused', is_wp_error( $neither ), is_wp_error( $neither ) ? $neither->get_error_message() : '' );

/* An unreadable source. */
$GLOBALS['meta'][40] = array( '_elementor_data' => '{not json' );
check( 'invalid JSON reported, not swallowed', is_wp_error( EPM_Merger::build( 40, 20, 30 ) ) );

/* A source written without wp_slash by some other tool: read it anyway. */
$GLOBALS['meta'][50] = array( '_elementor_data' => addslashes( json_encode( $page ) ) );
$recovered           = EPM_Merger::read_elements( 50 );
check( 'over-slashed source data still readable', is_array( $recovered ) && 1 === count( $recovered ) );

/* The export pass is a no-op when Elementor is not loaded, never a crash. */
$untouched = EPM_Merger::export_pass( $merged );
check( 'export pass passes data through when Elementor is absent', $untouched === $merged );

/* The widget report, which is what makes an unknown widget readable. */
$widgets = array();
EPM_Merger::collect_widgets( $merged, $widgets );

check( 'every widget type is counted', 3 === count( $widgets ) && 1 === $widgets['nav-menu'], print_r( $widgets, true ) );

$samples = array();
EPM_Merger::collect_widget_settings( $merged, $samples );
$keys = array_column( $samples['heading'], 'key' );

check( 'widget settings are reported under their real names', in_array( 'title', $keys, true ), implode( ',', $keys ) );
check( 'nested settings are reported with their full path', in_array( 'link.url', $keys, true ), implode( ',', $keys ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
