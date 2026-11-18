<?php
/**
 * Harness for the image handling: which URLs get rewritten to this site's own
 * address, which get downloaded into the Media Library, and which are left
 * alone. Downloads are faked, so nothing leaves the machine.
 */

define( 'ABSPATH', __DIR__ . '/fake-wp/' );

// media_sideload_image() and friends live in these files; the harness creates
// them empty so the require_once calls inside the plugin are satisfied.
@mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
foreach ( array( 'file.php', 'media.php', 'image.php' ) as $stub ) {
	if ( ! file_exists( ABSPATH . 'wp-admin/includes/' . $stub ) ) {
		file_put_contents( ABSPATH . 'wp-admin/includes/' . $stub, "<?php\n" );
	}
}

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$GLOBALS['site'] = 'https://mysite.example';

function home_url() {
	return $GLOBALS['site'];
}

function wp_upload_dir() {
	return array(
		'baseurl' => $GLOBALS['site'] . '/wp-content/uploads',
		'basedir' => sys_get_temp_dir() . '/epm-uploads',
	);
}

/* A stand-in Media Library and HTTP client. */
$GLOBALS['library']   = array();
$GLOBALS['meta']      = array();
$GLOBALS['next_id']   = 100;
$GLOBALS['requests']  = array();
$GLOBALS['offline']   = array();
$GLOBALS['bodies']    = array();

class Fake_WPDB {
	public $postmeta = 'wp_postmeta';
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%s/', "'" . $arg . "'", $sql, 1 );
		}
		return $sql;
	}
	public function get_var( $sql ) {
		if ( preg_match( "/meta_value = '([^']+)'/", $sql, $m ) ) {
			foreach ( $GLOBALS['meta'] as $id => $fields ) {
				if ( isset( $fields['_epm_source_url'] ) && $fields['_epm_source_url'] === $m[1] ) {
					return $id;
				}
			}
		}
		return null;
	}
}

$wpdb = new Fake_WPDB();

function get_post( $id ) {
	return isset( $GLOBALS['library'][ $id ] ) ? (object) array( 'ID' => $id ) : null;
}

function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	return true;
}

function sanitize_file_name( $name ) {
	return preg_replace( '/[^A-Za-z0-9._-]/', '', $name );
}

function add_filter() {}
function remove_filter() {}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['requests'][] = $url;

	if ( in_array( $url, $GLOBALS['offline'], true ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
	}

	$body = isset( $GLOBALS['bodies'][ $url ] ) ? $GLOBALS['bodies'][ $url ] : 'binary-image-bytes';

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => $body,
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_tempnam( $name ) {
	return tempnam( sys_get_temp_dir(), 'epm' );
}

function wp_handle_sideload( $file, $overrides ) {
	return array(
		'file' => '/fake/path/' . $file['name'],
		'url'  => $GLOBALS['site'] . '/wp-content/uploads/imported/' . $file['name'],
		'type' => 'image/png',
	);
}

function wp_insert_attachment( $args, $file ) {
	$id                        = ++$GLOBALS['next_id'];
	$GLOBALS['library'][ $id ] = $GLOBALS['site'] . '/wp-content/uploads/imported/' . basename( $file );
	return $id;
}

function wp_generate_attachment_metadata( $id, $file ) {
	return array();
}

function wp_update_attachment_metadata( $id, $data ) {
	return true;
}

function wp_get_attachment_url( $id ) {
	return isset( $GLOBALS['library'][ $id ] ) ? $GLOBALS['library'][ $id ] : false;
}

require_once dirname( __DIR__ ) . '/includes/class-epm-images.php';

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

// One file really is in this site's uploads folder, under an older domain.
@mkdir( sys_get_temp_dir() . '/epm-uploads/2026/09', 0777, true );
file_put_contents( sys_get_temp_dir() . '/epm-uploads/2026/09/Logo.png', 'x' );

$tree = array(
	array(
		'id'       => 'sec0001',
		'elType'   => 'container',
		'settings' => array(
			// Same site, older domain, file present on disk.
			'background_image' => array(
				'id'  => 5,
				'url' => 'https://old-domain.example/wp-content/uploads/2026/09/Logo.png',
			),
		),
		'elements' => array(
			array(
				'id'         => 'wid0001',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array(
					// Genuinely somebody else's image, and reachable.
					'image' => array(
						'id'  => 6,
						'url' => 'https://someone-else.example/uploads/team.jpg',
					),
					// A link control: not an image, must never be touched.
					'link'  => array( 'url' => 'https://old-domain.example/about' ),
				),
				'elements'   => array(),
			),
			array(
				'id'         => 'wid0002',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array(
					// The same foreign image again: must be fetched once.
					'image' => array(
						'id'  => 7,
						'url' => 'https://someone-else.example/uploads/team.jpg',
					),
				),
				'elements'   => array(),
			),
			array(
				'id'         => 'wid0003',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array(
					// A site that has gone away.
					'image' => array(
						'id'  => 8,
						'url' => 'https://gone.example/uploads/missing.png',
					),
				),
				'elements'   => array(),
			),
			array(
				'id'         => 'wid0004',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array(
					// Already ours: nothing to do.
					'image' => array(
						'id'  => 9,
						'url' => 'https://mysite.example/wp-content/uploads/2026/09/fine.png',
					),
				),
				'elements'   => array(),
			),
		),
	),
);

$GLOBALS['offline'] = array( 'https://gone.example/uploads/missing.png' );

/* ------------------------------------------------------------------- tests */

// Five media controls, but one image is used twice, so four distinct files.
check( 'distinct images counted, links not mistaken for images', 4 === EPM_Images::count( $tree ), EPM_Images::count( $tree ) );

$foreign = EPM_Images::foreign( $tree, 'mysite.example' );
sort( $foreign );

check(
	'every image not served by this site is listed',
	array(
		'https://gone.example/uploads/missing.png',
		'https://old-domain.example/wp-content/uploads/2026/09/Logo.png',
		'https://someone-else.example/uploads/team.jpg',
	) === $foreign,
	print_r( $foreign, true )
);

/* Normalize alone: rewrite what is ours, leave the rest. */
$normalized = EPM_Images::prepare( $tree, false );
$out        = $normalized['elements'];

check(
	'an old domain is rewritten when the file is really ours',
	'https://mysite.example/wp-content/uploads/2026/09/Logo.png' === $out[0]['settings']['background_image']['url'],
	$out[0]['settings']['background_image']['url']
);

check(
	'an image that really is on another site is left for the download step',
	'https://someone-else.example/uploads/team.jpg' === $out[0]['elements'][0]['settings']['image']['url']
);

check(
	'a link is never rewritten as though it were an image',
	'https://old-domain.example/about' === $out[0]['elements'][0]['settings']['link']['url'],
	$out[0]['elements'][0]['settings']['link']['url']
);

check( 'with downloading off, nothing is downloaded', 0 === count( $GLOBALS['requests'] ) && 0 === $normalized['downloaded'] );

/* The whole thing: rewrite, then download what is left. */
$result = EPM_Images::prepare( $tree, true );
$out    = $result['elements'];

check( 'the reachable foreign image was downloaded', 1 === $result['downloaded'], $result['downloaded'] );
check( 'the same image used twice is fetched once', 1 === $result['reused'], $result['reused'] );
check( 'it was really requested only once', 2 === count( $GLOBALS['requests'] ), implode( ', ', $GLOBALS['requests'] ) );

check(
	'the downloaded image now points at this site',
	'https://mysite.example/wp-content/uploads/imported/team.jpg' === $out[0]['elements'][0]['settings']['image']['url'],
	$out[0]['elements'][0]['settings']['image']['url']
);

check(
	'and carries the new attachment ID, not the old one',
	101 === $out[0]['elements'][0]['settings']['image']['id'],
	$out[0]['elements'][0]['settings']['image']['id']
);

check(
	'the second use of that image points at the same local file',
	$out[0]['elements'][1]['settings']['image']['url'] === $out[0]['elements'][0]['settings']['image']['url']
);

check( 'a download that fails is reported, not swallowed', 1 === count( $result['failed'] ), print_r( $result['failed'], true ) );
check( 'the failure names the URL that could not be fetched', 'https://gone.example/uploads/missing.png' === $result['failed'][0]['url'] );
check( 'the failure carries the reason, with its code', false !== strpos( $result['failed'][0]['reason'], 'Could not resolve host' ), $result['failed'][0]['reason'] );

check(
	'an image that failed keeps its original URL rather than breaking',
	'https://gone.example/uploads/missing.png' === $out[0]['elements'][2]['settings']['image']['url']
);

check(
	'an image already served by this site is untouched',
	'https://mysite.example/wp-content/uploads/2026/09/fine.png' === $out[0]['elements'][3]['settings']['image']['url']
	&& 9 === $out[0]['elements'][3]['settings']['image']['id']
);

check( 'after the run every image but the dead one is ours', 1 === count( EPM_Images::foreign( $out, 'mysite.example' ) ), print_r( EPM_Images::foreign( $out, 'mysite.example' ), true ) );

/* ----------------------------------------------- SVG, and repeat runs */

$svg_tree = array(
	array(
		'id'       => 'svg00001',
		'elType'   => 'container',
		'settings' => array(),
		'elements' => array(
			array(
				'id'         => 'svg00002',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array(
					'image' => array(
						'id'  => 20,
						'url' => 'https://someone-else.example/uploads/icon-3.svg',
					),
				),
				'elements'   => array(),
			),
			array(
				'id'         => 'svg00003',
				'elType'     => 'widget',
				'widgetType' => 'image',
				'settings'   => array(
					'image' => array(
						'id'  => 21,
						'url' => 'https://someone-else.example/uploads/nasty.svg',
					),
				),
				'elements'   => array(),
			),
		),
	),
);

$GLOBALS['bodies']['https://someone-else.example/uploads/icon-3.svg'] = '<svg xmlns="http://www.w3.org/2000/svg"><circle r="4"/></svg>';
$GLOBALS['bodies']['https://someone-else.example/uploads/nasty.svg']  = '<svg onload="steal()"><script>alert(1)</script></svg>';

$svg_off = EPM_Images::prepare( $svg_tree, true, false );

check( 'with SVG off, no SVG is downloaded', 0 === $svg_off['downloaded'], $svg_off['downloaded'] );
check( 'and the reason says it plainly', false !== strpos( $svg_off['failed'][0]['reason'], 'SVG files were left out' ), $svg_off['failed'][0]['reason'] );

$svg_on = EPM_Images::prepare( $svg_tree, true, true );

check( 'with SVG on, a plain SVG is brought in', 1 === $svg_on['downloaded'], $svg_on['downloaded'] );
check(
	'the icon now points at this site',
	false !== strpos( $svg_on['elements'][0]['elements'][0]['settings']['image']['url'], 'mysite.example' ),
	$svg_on['elements'][0]['elements'][0]['settings']['image']['url']
);

check( 'an SVG carrying script is refused', 1 === count( $svg_on['failed'] ), print_r( $svg_on['failed'], true ) );
check( 'and says why', false !== strpos( $svg_on['failed'][0]['reason'], 'carries script' ), $svg_on['failed'][0]['reason'] );
check(
	'the refused SVG keeps its original URL rather than breaking',
	'https://someone-else.example/uploads/nasty.svg' === $svg_on['elements'][0]['elements'][1]['settings']['image']['url']
);

/* A second row, a second request: the image must not be fetched again. */
$before = count( $GLOBALS['requests'] );
$again  = EPM_Images::prepare( $svg_tree, true, true );

check( 'an image already brought in is not downloaded a second time', $before + 1 === count( $GLOBALS['requests'] ), count( $GLOBALS['requests'] ) - $before );
check(
	'and the row still points at the local copy',
	false !== strpos( $again['elements'][0]['elements'][0]['settings']['image']['url'], 'mysite.example' )
);

/* Something that is not an image at all. */
$pdf = EPM_Images::prepare(
	array(
		array(
			'id'       => 'pdf00001',
			'elType'   => 'widget',
			'settings' => array(
				'file' => array(
					'id'  => 30,
					'url' => 'https://someone-else.example/uploads/brochure.pdf',
				),
			),
			'elements' => array(),
		),
	),
	true,
	true
);

check( 'a file that is not an image is refused by extension', false !== strpos( $pdf['failed'][0]['reason'], 'Not an image file' ), $pdf['failed'][0]['reason'] );

/* The source tree handed in must never be altered in place. */
check(
	'the tree passed in is not modified',
	'https://old-domain.example/wp-content/uploads/2026/09/Logo.png' === $tree[0]['settings']['background_image']['url']
);

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
