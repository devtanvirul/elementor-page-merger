<?php
/**
 * Harness for header/footer detection. Stubs $wpdb with fixture rows standing
 * in for the ways different builders record a header or a footer, and checks
 * that detection works without any plugin being named in the plugin code.
 */

define( 'ABSPATH', __DIR__ );

/* ----------------------------------------------------------- WP stand-ins */

$GLOBALS['meta'] = array();

function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['meta'][ $id ][ $key ] ) ? $GLOBALS['meta'][ $id ][ $key ] : '';
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

/* ------------------------------------------------------------ $wpdb stub */

class Fake_WPDB {
	public $posts              = 'wp_posts';
	public $postmeta           = 'wp_postmeta';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $terms              = 'wp_terms';

	public $documents = array();
	public $metarows  = array();
	public $termrows  = array();
	public $queries   = array();

	public function get_results( $sql ) {
		$this->queries[] = $sql;

		if ( false !== strpos( $sql, 'term_relationships' ) ) {
			return $this->rows( $this->termrows );
		}

		if ( false !== strpos( $sql, 'CHAR_LENGTH' ) ) {
			// Honour the length filter the real query applies.
			$kept = array();
			foreach ( $this->metarows as $row ) {
				$len = strlen( $row['meta_value'] );
				if ( $len >= 4 && $len <= 40 ) {
					$kept[] = $row;
				}
			}
			return $this->rows( $kept );
		}

		return $this->rows( $this->documents );
	}

	private function rows( $rows ) {
		return array_map(
			function ( $row ) {
				return (object) $row;
			},
			$rows
		);
	}
}

$wpdb = new Fake_WPDB();

require_once dirname( __DIR__ ) . '/includes/class-epm-merger.php';
require_once dirname( __DIR__ ) . '/includes/class-epm-images.php';
require_once dirname( __DIR__ ) . '/includes/class-epm-sources.php';

/* ----------------------------------------------------------------- checks */

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

/* --------------------------------------------------------------- fixtures */

$doc = function ( $id, $type, $title, $builder = true ) {
	return array(
		'ID'          => $id,
		'post_title'  => $title,
		'post_type'   => $type,
		'post_status' => 'publish',
		'edit_mode'   => $builder ? 'builder' : '',
	);
};

$wpdb->documents = array(
	$doc( 1, 'page', 'About' ),
	$doc( 2, 'elementskit_template', 'Header' ),
	$doc( 3, 'elementskit_template', 'Footer' ),
	$doc( 4, 'jeg_kit_header', 'Main Nav' ),
	$doc( 5, 'elementor_library', 'Site Footer' ),
	$doc( 6, 'page', 'Footer Showcase' ),
	$doc( 7, 'page', 'Contact' ),
	$doc( 8, 'eael_template', 'Top Bar' ),
	$doc( 9, 'page', 'Home' ),
	// A custom post type, which is the sort of thing that used to be dropped.
	$doc( 10, 'portfolio', 'Project One' ),
	$doc( 11, 'service', 'Roof Repair' ),
	// Theme builder documents: an archive template is content and is listed,
	// a popup is not and never is.
	$doc( 12, 'elementor_library', 'Blog Archive' ),
	$doc( 13, 'elementor_library', 'Sale Popup' ),
);

$wpdb->metarows = array(
	// A builder that records the type in its own meta key.
	array(
		'post_id'    => 2,
		'meta_key'   => 'elementskit_template_type',
		'meta_value' => 'header',
	),
	array(
		'post_id'    => 3,
		'meta_key'   => 'elementskit_template_type',
		'meta_value' => 'footer',
	),
	// A builder that writes a prefixed label on a type-ish key.
	array(
		'post_id'    => 8,
		'meta_key'   => 'eael_tmpl_location',
		'meta_value' => 'eael_header',
	),
	// Elementor's own Theme Builder.
	array(
		'post_id'    => 5,
		'meta_key'   => '_elementor_template_type',
		'meta_value' => 'footer',
	),
	// Traps: ordinary pages carrying header/footer layout switches.
	array(
		'post_id'    => 7,
		'meta_key'   => 'layout_type',
		'meta_value' => 'no-header',
	),
	array(
		'post_id'    => 7,
		'meta_key'   => '_header_type',
		'meta_value' => 'transparent',
	),
	array(
		'post_id'    => 9,
		'meta_key'   => 'page_footer_display',
		'meta_value' => 'hide-footer',
	),
	array(
		'post_id'    => 1,
		'meta_key'   => '_elementor_template_type',
		'meta_value' => 'wp-page',
	),
	array(
		'post_id'    => 12,
		'meta_key'   => '_elementor_template_type',
		'meta_value' => 'archive',
	),
	array(
		'post_id'    => 13,
		'meta_key'   => '_elementor_template_type',
		'meta_value' => 'popup',
	),
);

$wpdb->termrows = array(
	// A builder that files templates under a taxonomy term instead.
	array(
		'object_id' => 5,
		'taxonomy'  => 'elementor_library_type',
		'slug'      => 'footer',
		'name'      => 'Footer',
	),
	array(
		'object_id' => 1,
		'taxonomy'  => 'category',
		'slug'      => 'news',
		'name'      => 'News',
	),
);

$GLOBALS['meta'] = array(
	1 => array( '_elementor_data' => '[{"id":"a1","elType":"section","elements":[]}]', '_elementor_template_type' => 'wp-page' ),
	6 => array( '_elementor_data' => '[{"id":"a2","elType":"container","elements":[]}]' ),
	7 => array( '_elementor_data' => '[{"id":"a3","elType":"container","elements":[]}]' ),
	9  => array( '_elementor_data' => '[{"id":"a4","elType":"container","elements":[]}]' ),
	10 => array( '_elementor_data' => '[{"id":"a5","elType":"container","elements":[]}]' ),
	11 => array( '_elementor_data' => '[{"id":"a6","elType":"container","elements":[]}]' ),
	12 => array(
		'_elementor_data'          => '[{"id":"a7","elType":"container","elements":[]}]',
		'_elementor_template_type' => 'archive',
	),
	13 => array(
		'_elementor_data'          => '[{"id":"a8","elType":"container","elements":[]}]',
		'_elementor_template_type' => 'popup',
	),
);

/* ------------------------------------------------------------------ tests */

$detection = EPM_Sources::detect();

$strong_h = array_keys( $detection['header']['strong'] );
$strong_f = array_keys( $detection['footer']['strong'] );
$weak_h   = array_keys( $detection['header']['weak'] );
$weak_f   = array_keys( $detection['footer']['weak'] );

sort( $strong_h );
sort( $strong_f );

check( 'own meta key detected as header', in_array( 2, $strong_h, true ), implode( ',', $strong_h ) );
check( 'own meta key detected as footer', in_array( 3, $strong_f, true ), implode( ',', $strong_f ) );
check( 'post type name detected as header', in_array( 4, $strong_h, true ), implode( ',', $strong_h ) );
check( 'prefixed value on a type key detected as header', in_array( 8, $strong_h, true ), implode( ',', $strong_h ) );
check( 'taxonomy term detected as footer', in_array( 5, $strong_f, true ), implode( ',', $strong_f ) );

check( 'no-header layout switch is not a header', ! in_array( 7, $strong_h, true ), implode( ',', $strong_h ) );
check( 'hide-footer layout switch is not a footer', ! in_array( 9, $strong_f, true ), implode( ',', $strong_f ) );
check( 'transparent header_type is not a header', ! in_array( 7, $strong_h, true ) );
check( 'an ordinary page is not a part', ! in_array( 1, $strong_h, true ) && ! in_array( 1, $strong_f, true ) );

check( 'a page merely titled Footer is weak only', in_array( 6, $weak_f, true ) && ! in_array( 6, $strong_f, true ), implode( ',', $weak_f ) );
check( 'weak header list stays empty here', ! $weak_h || ! in_array( 7, $weak_h, true ) );

/* Dropdown groups: detected first, then every other document, never empty. */
$groups = EPM_Sources::get_part_groups( 'header' );
$listed = array();

foreach ( $groups as $group ) {
	foreach ( $group['items'] as $item ) {
		$listed[] = $item['id'];
	}
}

sort( $listed );

check( 'every document appears somewhere in the header dropdown', range( 1, 13 ) === $listed, implode( ',', $listed ) );
check( 'detected headers are grouped first', isset( $groups[0] ) && false !== strpos( $groups[0]['label'], 'Detected header' ), isset( $groups[0] ) ? $groups[0]['label'] : 'none' );
check( 'a catch-all group exists so nothing is hidden', false !== strpos( end( $groups )['label'], 'Every other' ), end( $groups )['label'] );

/* Candidates: parts removed, pages kept. */
$candidates = array_column( EPM_Sources::get_candidates(), 'id' );
sort( $candidates );

check( 'confirmed parts are not listed as pages', ! array_intersect( $candidates, array( 2, 3, 4, 5, 8 ) ), implode( ',', $candidates ) );
check( 'custom post types are listed, not only pages', in_array( 10, $candidates, true ) && in_array( 11, $candidates, true ), implode( ',', $candidates ) );
check( 'a theme builder archive template is listed too', in_array( 12, $candidates, true ), implode( ',', $candidates ) );
check( 'a popup is never listed, there is no page in it', ! in_array( 13, $candidates, true ), implode( ',', $candidates ) );
check( 'everything else is listed', array( 1, 6, 7, 9, 10, 11, 12 ) === $candidates, implode( ',', $candidates ) );

$types = array();
foreach ( EPM_Sources::get_candidates() as $row ) {
	$types[ $row['post_type'] ] = true;
}

check( 'the filter can be built from four different post types', 4 === count( $types ), implode( ',', array_keys( $types ) ) );

/* The detector must not need a plugin name anywhere in the plugin. */
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-epm-sources.php' );
$names  = array( 'elementskit', 'jeg_kit', 'jkit', 'eael', 'essential', 'wpmet' );
$found  = array();

foreach ( $names as $name ) {
	if ( false !== stripos( $source, $name ) ) {
		$found[] = $name;
	}
}

check( 'no builder plugin is hardcoded in the detector', ! $found, implode( ',', $found ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
