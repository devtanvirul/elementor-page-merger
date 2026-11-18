<?php
/**
 * Plugin Name:       Elementor Page Merger
 * Plugin URI:        https://devtanvirul.com
 * Description:       Merges an Elementor header, a page and a footer into one self-contained Elementor document, ready for Template Kit export. Never touches the source page.
 * Version:           2.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            devtanvirul
 * Author URI:        https://devtanvirul.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       elementor-page-merger
 *
 * @package ElementorPageMerger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EPM_VERSION', '2.2.0' );
define( 'EPM_FILE', __FILE__ );
define( 'EPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPM_URL', plugin_dir_url( __FILE__ ) );

require_once EPM_DIR . 'includes/class-epm-sources.php';
require_once EPM_DIR . 'includes/class-epm-merger.php';
require_once EPM_DIR . 'includes/class-epm-images.php';
require_once EPM_DIR . 'includes/class-epm-saver.php';
require_once EPM_DIR . 'includes/class-epm-exporter.php';
require_once EPM_DIR . 'includes/class-epm-admin.php';

EPM_Admin::init();
