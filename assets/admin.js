( function () {
	'use strict';

	var cfg = window.EPM || {};

	var checkAll = document.getElementById( 'epm-check-all' );
	var saveBtn = document.getElementById( 'epm-save' );
	var exportBtn = document.getElementById( 'epm-export' );
	var progress = document.getElementById( 'epm-progress' );

	if ( ! saveBtn || ! exportBtn ) {
		return;
	}

	function rows() {
		return Array.prototype.slice.call( document.querySelectorAll( 'tr[data-id]' ) );
	}

	function visibleRows() {
		return rows().filter( function ( row ) {
			return 'none' !== row.style.display;
		} );
	}

	function selectedRows() {
		return visibleRows().filter( function ( row ) {
			var box = row.querySelector( '.epm-row' );
			return box && box.checked;
		} );
	}

	/* Post type tick boxes and the title search, applied together. */
	function applyFilter() {
		var wanted = {};
		var types = document.querySelectorAll( '.epm-type' );

		Array.prototype.forEach.call( types, function ( box ) {
			wanted[ box.value ] = box.checked;
		} );

		var search = document.getElementById( 'epm-search' );
		var needle = search ? search.value.trim().toLowerCase() : '';
		var shown = 0;

		rows().forEach( function ( row ) {
			var type = row.getAttribute( 'data-type' );
			var title = row.getAttribute( 'data-title' ) || '';
			var keep = wanted[ type ] !== false &&
				( '' === needle || title.indexOf( needle ) !== -1 );

			row.style.display = keep ? '' : 'none';

			/* A hidden row must not stay ticked, or it would run unseen. */
			var box = row.querySelector( '.epm-row' );
			if ( ! keep && box ) {
				box.checked = false;
			}

			/* Any inspect panel belonging to it follows it. */
			var panel = row.nextElementSibling;
			if ( panel && panel.classList.contains( 'epm-inspect-row' ) ) {
				panel.style.display = keep ? '' : 'none';
			}

			if ( keep ) {
				shown += 1;
			}
		} );

		var showing = document.getElementById( 'epm-showing' );
		if ( showing ) {
			showing.textContent = 'Showing ' + shown + ' of ' + rows().length + ' documents';
		}

		if ( checkAll ) {
			checkAll.checked = false;
		}
	}

	function globalValue( id ) {
		var select = document.getElementById( id );
		return select ? select.value : '';
	}

	/* A row's own dropdown wins over the picker above, for that row only. */
	function resolve( row, rowClass, globalId ) {
		var select = row.querySelector( '.' + rowClass );
		var value = select && select.value ? select.value : globalValue( globalId );
		return value ? parseInt( value, 10 ) : 0;
	}

	function setResult( row, html, className ) {
		var cell = row.querySelector( '.epm-result' );
		if ( cell ) {
			cell.className = 'epm-result' + ( className ? ' ' + className : '' );
			cell.innerHTML = html;
		}
	}

	function number( value ) {
		return String( value ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	/* What happened to the images that were hosted somewhere else. */
	function imageLines( result ) {
		var html = '';

		if ( result.downloaded ) {
			html += '<span class="epm-ok">' + number( result.downloaded ) +
				' brought into this site</span><br />';
		}

		if ( result.failed && result.failed.length ) {
			/* The count alone says nothing actionable: show why each failed,
			   grouped, because one cause usually explains all of them. */
			var reasons = {};
			result.failed.forEach( function ( failure ) {
				if ( ! reasons[ failure.reason ] ) {
					reasons[ failure.reason ] = [];
				}
				reasons[ failure.reason ].push( failure.url );
			} );

			// Open by default: an error the reader has to go looking for is an
			// error they will not read.
			html += '<details class="epm-failed" open><summary class="epm-bad">' +
				result.failed.length + ' could not be downloaded</summary>';

			Object.keys( reasons ).forEach( function ( reason ) {
				html += '<p class="epm-fail-reason"><strong>' + reasons[ reason ].length +
					' &times;</strong> ' + escapeHtml( reason ) + '</p>';
				html += '<ul class="epm-inspect-list">';
				reasons[ reason ].slice( 0, 4 ).forEach( function ( url ) {
					html += '<li>' + escapeHtml( url ) + '</li>';
				} );
				if ( reasons[ reason ].length > 4 ) {
					html += '<li class="epm-sub">and ' + ( reasons[ reason ].length - 4 ) + ' more</li>';
				}
				html += '</ul>';
			} );

			html += '</details>';
		}

		return html;
	}

	function post( action, body ) {
		var data = new FormData();
		data.append( 'action', action );
		data.append( 'nonce', cfg.nonce );

		Object.keys( body ).forEach( function ( key ) {
			data.append( key, body[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function busy( state ) {
		saveBtn.disabled = state;
		exportBtn.disabled = state;
	}

	function run( mode ) {
		var queue = selectedRows();

		if ( ! queue.length ) {
			progress.textContent = 'Tick at least one row first.';
			return;
		}

		busy( true );

		var index = 0;
		var done = 0;
		var failed = 0;
		var batch = '';

		function next() {
			if ( index >= queue.length ) {
				finish();
				return;
			}

			var row = queue[ index ];
			index += 1;

			var source = parseInt( row.getAttribute( 'data-id' ), 10 );
			var header = resolve( row, 'epm-row-header', 'epm-global-header' );
			var footer = resolve( row, 'epm-row-footer', 'epm-global-footer' );

			progress.textContent = 'Working on ' + index + ' of ' + queue.length + '…';
			setResult( row, 'Working…', 'epm-busy' );

			if ( ! header && ! footer ) {
				failed += 1;
				setResult( row, 'Pick a header or a footer for this row.', 'epm-bad' );
				next();
				return;
			}

			var localize = document.getElementById( 'epm-localize-images' );
			var svg = document.getElementById( 'epm-allow-svg' );
			var action = 'save' === mode ? 'epm_save_row' : 'epm_export_row';
			var body = {
				source: source,
				header: header,
				footer: footer,
				localize: localize && localize.checked ? 1 : '',
				svg: svg && svg.checked ? 1 : ''
			};

			if ( 'export' === mode ) {
				body.batch = batch;
			}

			post( action, body ).then( function ( response ) {
				if ( ! response || ! response.success ) {
					failed += 1;
					var message = response && response.data && response.data.message
						? response.data.message
						: 'The request failed.';
					setResult( row, escapeHtml( message ), 'epm-bad' );
					next();
					return;
				}

				done += 1;
				var result = response.data;

				var counts = number( result.chars ) + ' chars<br />' +
					number( result.elements ) + ' elements<br />' +
					number( result.images ) + ' images<br />' +
					imageLines( result );

				if ( 'save' === mode ) {
					setResult(
						row,
						counts +
						( result.verified
							? '<span class="epm-ok">Verified</span>'
							: '<span class="epm-bad">Not verified: ' + escapeHtml( result.verify_detail ) + '</span>' ) +
						'<br /><a href="' + result.edit_url + '" target="_blank" rel="noopener">' +
						( result.reused ? 'Updated template' : 'New template' ) + '</a>',
						''
					);
				} else {
					batch = result.batch;
					setResult(
						row,
						counts + '<span class="epm-ok">Staged</span> ' + escapeHtml( result.filename ),
						''
					);
				}

				next();
			} ).catch( function () {
				failed += 1;
				setResult( row, 'The request failed. Check the browser console.', 'epm-bad' );
				next();
			} );
		}

		function finish() {
			var summary = done + ' of ' + queue.length + ' done';

			if ( failed ) {
				summary += ', ' + failed + ' failed';
			}

			if ( 'save' === mode && done ) {
				/* Elementor's file cache is cleared once, after the whole batch. */
				progress.textContent = summary + '. Clearing Elementor’s file cache…';

				post( 'epm_clear_cache', {} ).then( function () {
					progress.textContent = summary + '. Elementor file cache cleared.';
					busy( false );
				} ).catch( function () {
					progress.textContent = summary + '. The file cache could not be cleared; use Elementor › Tools › Regenerate CSS.';
					busy( false );
				} );

				return;
			}

			if ( 'export' === mode && batch ) {
				progress.textContent = summary + '. Downloading…';
				window.location = cfg.downloadUrl + '&batch=' + encodeURIComponent( batch );
				busy( false );
				return;
			}

			progress.textContent = summary + '.';
			busy( false );
		}

		next();
	}

	/* Inspect: what the merged row would actually hold, widget by widget. */
	function inspect( row ) {
		var existing = row.nextElementSibling;

		if ( existing && existing.classList.contains( 'epm-inspect-row' ) ) {
			existing.parentNode.removeChild( existing );
			return;
		}

		var panel = document.createElement( 'tr' );
		panel.className = 'epm-inspect-row';
		panel.innerHTML = '<td colspan="8">Reading…</td>';
		row.parentNode.insertBefore( panel, row.nextSibling );

		post( 'epm_inspect_row', {
			source: parseInt( row.getAttribute( 'data-id' ), 10 ),
			header: resolve( row, 'epm-row-header', 'epm-global-header' ),
			footer: resolve( row, 'epm-row-footer', 'epm-global-footer' )
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				var message = response && response.data && response.data.message
					? response.data.message
					: 'The request failed.';
				panel.innerHTML = '<td colspan="8" class="epm-bad">' + escapeHtml( message ) + '</td>';
				return;
			}

			panel.innerHTML = '<td colspan="8">' + inspectHtml( response.data ) + '</td>';
		} ).catch( function () {
			panel.innerHTML = '<td colspan="8" class="epm-bad">The request failed.</td>';
		} );
	}

	function inspectHtml( data ) {
		var html = '<div class="epm-inspect-panel">';

		html += '<h4>Images: ' + number( data.images ) + '</h4>';

		if ( data.samples.length ) {
			html += '<p class="epm-sub">Open one of these in a browser on another' +
				' machine. If it will not load there, no importer on another site' +
				' can fetch it either.</p><ul class="epm-inspect-list">';
			data.samples.forEach( function ( url ) {
				html += '<li><a href="' + encodeURI( url ) + '" target="_blank" rel="noopener">' +
					escapeHtml( url ) + '</a></li>';
			} );
			html += '</ul>';
		} else {
			html += '<p class="epm-sub">No images were found in this merged document at all.</p>';
		}

		if ( data.foreign_count ) {
			html += '<h4 class="epm-bad">' + number( data.foreign_count ) +
				' image(s) hosted on another site</h4>';
			html += '<p class="epm-sub">Checked over plain HTTP, the same way an' +
				' importer elsewhere fetches them. Tick the box above and run the' +
				' row to bring these into this Media Library.</p>';
			html += '<table class="epm-inspect-table"><tbody>';
			data.foreign.forEach( function ( image ) {
				html += '<tr><td>' + escapeHtml( image.url ) + '</td><td class="' +
					( image.ok ? 'epm-ok' : 'epm-bad' ) + '">' + escapeHtml( image.status ) +
					'</td></tr>';
			} );
			html += '</tbody></table>';
		} else {
			html += '<p class="epm-ok">Every image is served by this site.</p>';
		}

		html += '<h4>Widgets used</h4>';
		html += '<p class="epm-sub">Open a widget to see its own settings under' +
			' their real names. Whatever a plugin calls the setting that holds' +
			' its form, it is in there.</p>';

		data.widgets.forEach( function ( widget ) {
			html += '<details class="epm-widget"><summary><code>' +
				escapeHtml( widget.type ) + '</code> &times;' + widget.count + '</summary>';

			if ( widget.settings.length ) {
				html += '<table class="epm-inspect-table"><tbody>';
				widget.settings.forEach( function ( setting ) {
					html += '<tr><td><code>' + escapeHtml( setting.key ) + '</code></td>' +
						'<td>' + escapeHtml( setting.value ) + '</td></tr>';
				} );
				html += '</tbody></table>';
			} else {
				html += '<p class="epm-sub">No settings of its own.</p>';
			}

			html += '</details>';
		} );

		html += '</div>';

		return html;
	}

	document.addEventListener( 'click', function ( event ) {
		if ( ! event.target.classList.contains( 'epm-inspect' ) ) {
			return;
		}

		event.preventDefault();
		inspect( event.target.closest( 'tr[data-id]' ) );
	} );

	if ( checkAll ) {
		checkAll.addEventListener( 'change', function () {
			/* Only what is on screen, so a hidden row is never run by accident. */
			visibleRows().forEach( function ( row ) {
				var box = row.querySelector( '.epm-row' );
				if ( box ) {
					box.checked = checkAll.checked;
				}
			} );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.epm-type' ), function ( box ) {
		box.addEventListener( 'change', applyFilter );
	} );

	var search = document.getElementById( 'epm-search' );
	if ( search ) {
		search.addEventListener( 'input', applyFilter );
	}

	function setAllTypes( checked ) {
		Array.prototype.forEach.call( document.querySelectorAll( '.epm-type' ), function ( box ) {
			box.checked = checked;
		} );
		applyFilter();
	}

	var allLink = document.querySelector( '.epm-filter-all' );
	if ( allLink ) {
		allLink.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			setAllTypes( true );
		} );
	}

	var noneLink = document.querySelector( '.epm-filter-none' );
	if ( noneLink ) {
		noneLink.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			setAllTypes( false );
		} );
	}

	applyFilter();

	saveBtn.addEventListener( 'click', function () {
		run( 'save' );
	} );

	exportBtn.addEventListener( 'click', function () {
		run( 'export' );
	} );
} )();
