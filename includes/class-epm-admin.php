<?php
/**
 * The admin screen and the request handlers behind it.
 *
 * @package ElementorPageMerger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EPM_Admin {

	const MENU_SLUG  = 'elementor-page-merger';
	const CAPABILITY = 'manage_options';
	const NONCE      = 'epm_run';
	const NONCE_FILE = 'epm_download';

	/**
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_epm_save_row', array( __CLASS__, 'ajax_save_row' ) );
		add_action( 'wp_ajax_epm_export_row', array( __CLASS__, 'ajax_export_row' ) );
		add_action( 'wp_ajax_epm_inspect_row', array( __CLASS__, 'ajax_inspect_row' ) );
		add_action( 'wp_ajax_epm_clear_cache', array( __CLASS__, 'ajax_clear_cache' ) );
		add_action( 'admin_post_epm_download', array( __CLASS__, 'handle_download' ) );
	}

	/**
	 * Its own top level menu, not a child of Elementor's: Elementor rearranges
	 * that menu depending on whether Pro is active, so the link moves.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			'Elementor Page Merger',
			'Page Merger',
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-editor-insertmore',
			58
		);
	}

	/**
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'epm-admin', EPM_URL . 'assets/admin.css', array(), EPM_VERSION );
		wp_enqueue_script( 'epm-admin', EPM_URL . 'assets/admin.js', array(), EPM_VERSION, true );

		wp_localize_script(
			'epm-admin',
			'EPM',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE ),
				'downloadUrl' => add_query_arg(
					array(
						'action'   => 'epm_download',
						'_wpnonce' => wp_create_nonce( self::NONCE_FILE ),
					),
					admin_url( 'admin-post.php' )
				),
			)
		);
	}

	/**
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'You are not allowed to use this tool.' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			?>
			<div class="wrap epm-wrap">
				<h1>
					Elementor Page Merger
					<span class="epm-version">v<?php echo esc_html( EPM_VERSION ); ?></span>
				</h1>
				<div class="notice notice-error">
					<p>Elementor is not active. Activate Elementor and reload this page.</p>
				</div>
			</div>
			<?php
			return;
		}

		?>
		<div class="wrap epm-wrap">
			<h1>
				Elementor Page Merger
				<span class="epm-version">v<?php echo esc_html( EPM_VERSION ); ?></span>
			</h1>

			<p class="epm-intro">
				Each run creates a new Elementor Library document holding the header's
				elements, then the page's elements, then the footer's, with every
				element ID regenerated and the layout set to Elementor Canvas. Source
				pages are never modified.
			</p>

			<?php
			$headers    = EPM_Sources::get_part_groups( 'header' );
			$footers    = EPM_Sources::get_part_groups( 'footer' );
			$candidates = EPM_Sources::get_candidates();
			?>

			<div class="epm-globals">
				<h2>Header and footer for every ticked row</h2>
				<p class="epm-globals-note">
					These two pickers apply to every row you tick below. They are not
					row selectors. A row's own dropdown, if you set one, wins over the
					picker here for that row only. Nothing is pre-selected anywhere.
				</p>
				<div class="epm-globals-fields">
					<label>
						<span>Header</span>
						<?php self::dropdown( 'epm-global-header', $headers, 'Nothing selected' ); ?>
					</label>
					<label>
						<span>Footer</span>
						<?php self::dropdown( 'epm-global-footer', $footers, 'Nothing selected' ); ?>
					</label>
				</div>
				<p class="epm-refs-toggle">
					<label>
						<input type="checkbox" id="epm-localize-images" checked="checked" />
						Bring images hosted on another site into this Media Library first
					</label>
					<span class="epm-sub">
						A template carries no image files, only URLs, and the importer on
						the other side downloads from them. An image still pointing at a
						site that has moved or gone private arrives there as a grey
						placeholder. With this ticked, any such image is downloaded into
						this site's Media Library and the merged copy is pointed at the
						local file, so the exported file only asks for images this site
						serves. Source pages are not changed.
					</span>
				</p>

				<p class="epm-refs-toggle">
					<label>
						<input type="checkbox" id="epm-allow-svg" checked="checked" />
						Include SVG files
					</label>
					<span class="epm-sub">
						WordPress does not accept SVG uploads on its own, so icon sets
						stored as SVG are otherwise turned away and quietly go missing.
						With this ticked they are brought in, and any SVG carrying script
						is refused and reported rather than stored.
					</span>
				</p>

				<?php if ( ! $headers && ! $footers ) : ?>
					<p class="epm-warning">
						No Elementor documents were found on this site at all, so there is
						nothing to list.
					</p>
				<?php else : ?>
					<?php self::detection_report(); ?>
				<?php endif; ?>
			</div>

			<?php self::filter_bar( $candidates ); ?>

			<div class="epm-actions">
				<button type="button" class="button button-primary" id="epm-save">Save merged templates to library</button>
				<button type="button" class="button" id="epm-export">Export merged JSON</button>
				<span class="epm-progress" id="epm-progress"></span>
			</div>

			<table class="wp-list-table widefat fixed striped epm-table">
				<thead>
					<tr>
						<td class="check-column"><input type="checkbox" id="epm-check-all" /></td>
						<th class="epm-col-title">Document</th>
						<th class="epm-col-type">Post type</th>
						<th class="epm-col-kind">Elementor type</th>
						<th class="epm-col-counts">Source data</th>
						<th class="epm-col-part">Header (this row)</th>
						<th class="epm-col-part">Footer (this row)</th>
						<th class="epm-col-result">Result</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $candidates ) : ?>
					<tr>
						<td colspan="8">No Elementor-built documents were found. The tool lists posts whose <code>_elementor_edit_mode</code> meta is <code>builder</code>.</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $candidates as $row ) : ?>
					<tr
						data-id="<?php echo esc_attr( $row['id'] ); ?>"
						data-type="<?php echo esc_attr( $row['post_type'] ); ?>"
						data-title="<?php echo esc_attr( strtolower( $row['title'] ) ); ?>"
					>
						<th scope="row" class="check-column">
							<input type="checkbox" class="epm-row" value="<?php echo esc_attr( $row['id'] ); ?>" />
						</th>
						<td class="epm-col-title">
							<strong><?php echo esc_html( $row['title'] ); ?></strong>
							<div class="epm-sub">
								<a href="#" class="epm-inspect">Inspect</a> &middot;
								#<?php echo esc_html( $row['id'] ); ?> &middot; <?php echo esc_html( $row['status'] ); ?>
								<?php if ( $row['target'] ) : ?>
									&middot; <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $row['target'] . '&action=elementor' ) ); ?>" target="_blank" rel="noopener">merged template #<?php echo esc_html( $row['target'] ); ?></a>
								<?php endif; ?>
							</div>
						</td>
						<td><code><?php echo esc_html( $row['post_type'] ); ?></code></td>
						<td class="epm-sub"><?php echo esc_html( $row['kind'] ); ?></td>
						<td class="epm-counts">
							<?php echo esc_html( number_format_i18n( $row['chars'] ) ); ?> chars<br />
							<?php echo esc_html( number_format_i18n( $row['elements'] ) ); ?> elements<br />
							<?php echo esc_html( number_format_i18n( $row['images'] ) ); ?> images
						</td>
						<td><?php self::dropdown( '', $headers, 'Use picker above', 'epm-row-header' ); ?></td>
						<td><?php self::dropdown( '', $footers, 'Use picker above', 'epm-row-footer' ); ?></td>
						<td class="epm-result">&mdash;</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="epm-credit">
				Developed by <a href="https://devtanvirul.com" target="_blank" rel="noopener">devtanvirul</a>
			</p>
		</div>
		<?php
	}

	/**
	 * The filter above the table. Every post type found is listed with how
	 * many documents it holds, all ticked to begin with, so nothing is hidden
	 * until the choice is made here.
	 *
	 * @param array[] $candidates Rows in the table.
	 * @return void
	 */
	private static function filter_bar( $candidates ) {
		$counts = array();

		foreach ( $candidates as $row ) {
			$type            = $row['post_type'];
			$counts[ $type ] = isset( $counts[ $type ] ) ? $counts[ $type ] + 1 : 1;
		}

		ksort( $counts );

		if ( ! $counts ) {
			return;
		}
		?>
		<div class="epm-filter">
			<div class="epm-filter-head">
				<h2>Which documents to show</h2>
				<p class="epm-sub">
					Unticking a post type hides its rows and clears their tick boxes, so
					only what is shown can be run.
					<a href="#" class="epm-filter-all">All</a> &middot;
					<a href="#" class="epm-filter-none">None</a>
				</p>
			</div>

			<div class="epm-filter-types">
				<?php foreach ( $counts as $type => $count ) : ?>
					<label>
						<input type="checkbox" class="epm-type" value="<?php echo esc_attr( $type ); ?>" checked="checked" />
						<code><?php echo esc_html( $type ); ?></code>
						<span class="epm-sub">(<?php echo esc_html( number_format_i18n( $count ) ); ?>)</span>
					</label>
				<?php endforeach; ?>
			</div>

			<p class="epm-filter-search">
				<label>
					Search titles
					<input type="search" id="epm-search" placeholder="part of a title" />
				</label>
				<span class="epm-sub" id="epm-showing"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * A header or footer dropdown. Every dropdown starts with nothing selected:
	 * no header or footer is ever guessed from a title or anything else.
	 *
	 * @param string  $id          Element ID, empty for row dropdowns.
	 * @param array[] $groups      Option groups to list.
	 * @param string  $placeholder First, empty option's label.
	 * @param string  $class       Extra class.
	 * @return void
	 */
	private static function dropdown( $id, $groups, $placeholder, $class = '' ) {
		?>
		<select
			<?php echo $id ? 'id="' . esc_attr( $id ) . '"' : ''; ?>
			class="epm-select <?php echo esc_attr( $class ); ?>"
		>
			<option value="">&mdash; <?php echo esc_html( $placeholder ); ?> &mdash;</option>
			<?php foreach ( $groups as $group ) : ?>
				<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
					<?php foreach ( $group['items'] as $item ) : ?>
						<option value="<?php echo esc_attr( $item['id'] ); ?>">
							<?php echo esc_html( sprintf( '%s (%s #%d)', $item['title'], $item['post_type'], $item['id'] ) ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * What the detector found and why, so a site where it misses can be seen
	 * into rather than guessed at.
	 *
	 * @return void
	 */
	private static function detection_report() {
		$kinds = array(
			'header' => EPM_Sources::get_detection_report( 'header' ),
			'footer' => EPM_Sources::get_detection_report( 'footer' ),
		);

		$rows = array_merge( $kinds['header'], $kinds['footer'] );
		?>
		<details class="epm-detection">
			<summary>
				Detected <?php echo esc_html( count( $kinds['header'] ) ); ?> headers and
				<?php echo esc_html( count( $kinds['footer'] ) ); ?> footers.
				Every other Elementor document is listed at the bottom of both dropdowns.
			</summary>

			<?php if ( ! $rows ) : ?>
				<p>
					Nothing matched. Pick the header and footer by hand from the
					"Every other Elementor document" group in each dropdown.
				</p>
			<?php else : ?>
				<table class="widefat striped epm-detection-table">
					<thead>
						<tr>
							<th>Document</th>
							<th>Post type</th>
							<th>Found as</th>
							<th>Because of</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array( 'header', 'footer' ) as $kind ) : ?>
						<?php foreach ( $kinds[ $kind ] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['title'] ); ?> <span class="epm-sub">#<?php echo esc_html( $row['id'] ); ?></span></td>
								<td><code><?php echo esc_html( $row['post_type'] ); ?></code></td>
								<td><?php echo esc_html( $kind ); ?></td>
								<td>
									<code><?php echo esc_html( $row['reason'] ); ?></code>
									<?php if ( ! $row['confirmed'] ) : ?>
										<span class="epm-sub">(name only, still listed as a page below)</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Read and check the three IDs a row request carries.
	 *
	 * @return array source, header, footer.
	 */
	private static function request_ids() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to use this tool.' ) );
		}

		$source = isset( $_POST['source'] ) ? absint( $_POST['source'] ) : 0;

		if ( ! $source || ! get_post( $source ) ) {
			wp_send_json_error( array( 'message' => 'That document no longer exists.' ) );
		}

		return array(
			'source'   => $source,
			'header'   => isset( $_POST['header'] ) ? absint( $_POST['header'] ) : 0,
			'footer'   => isset( $_POST['footer'] ) ? absint( $_POST['footer'] ) : 0,
			'localize' => ! empty( $_POST['localize'] ),
			'svg'      => ! empty( $_POST['svg'] ),
		);
	}

	/**
	 * @return void
	 */
	public static function ajax_save_row() {
		$ids    = self::request_ids();
		$result = EPM_Saver::save( $ids['source'], $ids['header'], $ids['footer'], $ids['localize'], $ids['svg'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * @return void
	 */
	public static function ajax_export_row() {
		$ids    = self::request_ids();
		$batch  = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
		$result = EPM_Exporter::stage( $batch, $ids['source'], $ids['header'], $ids['footer'], $ids['localize'], $ids['svg'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Report what a merged row would actually hold, so a widget this plugin
	 * did not recognise can be looked at instead of guessed at.
	 *
	 * @return void
	 */
	public static function ajax_inspect_row() {
		$ids    = self::request_ids();
		$merged = EPM_Merger::build( $ids['source'], $ids['header'], $ids['footer'] );

		if ( is_wp_error( $merged ) ) {
			wp_send_json_error( array( 'message' => $merged->get_error_message() ) );
		}

		$urls    = EPM_Images::collect( $merged );
		$foreign = EPM_Images::foreign( $merged, EPM_Images::site_host() );

		$widgets = array();
		$samples = array();
		EPM_Merger::collect_widgets( $merged, $widgets );
		EPM_Merger::collect_widget_settings( $merged, $samples );
		arsort( $widgets );

		$types = array();

		foreach ( $widgets as $type => $count ) {
			$types[] = array(
				'type'     => $type,
				'count'    => $count,
				'settings' => isset( $samples[ $type ] ) ? $samples[ $type ] : array(),
			);
		}

		wp_send_json_success(
			array(
				'elements'      => EPM_Merger::count_elements( $merged ),
				'images'        => count( $urls ),
				'samples'       => array_slice( $urls, 0, 5 ),
				'foreign_count' => count( $foreign ),
				'foreign'       => EPM_Images::check( $foreign ),
				'widgets'       => $types,
			)
		);
	}

	/**
	 * Clears Elementor's file cache once, after a whole batch of saves.
	 *
	 * @return void
	 */
	public static function ajax_clear_cache() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => 'You are not allowed to use this tool.' ) );
		}

		wp_send_json_success( array( 'cleared' => EPM_Saver::clear_file_cache() ) );
	}

	/**
	 * @return void
	 */
	public static function handle_download() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( 'You are not allowed to use this tool.' );
		}

		check_admin_referer( self::NONCE_FILE );

		$batch  = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
		$result = EPM_Exporter::stream( $batch );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
	}
}
