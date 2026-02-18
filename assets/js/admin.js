/**
 * WP-PastPerfect Admin Import Interface
 * Handles AJAX file upload and chunked record processing
 * 
 * @since 0.2.0
 */
( ( $ ) => {
	'use strict';

	let $errorDiv, $progressbar, $successDiv, $successMessageDiv;

	/**
	 * Display error message to user
	 */
	const handleError = ( message ) => {
		$successDiv.hide();
		$errorDiv.html( message ).show();
	};

	/**
	 * Initialize import process with progress bar
	 */
	const beginImport = ( data ) => {
		$errorDiv.hide();
		$successDiv.show();

		$progressbar = $( '#wppp-import-progressbar' );
		$progressbar.progressbar( {
			value: data.pct
		} );

		importChunk( data.run );
	};

	/**
	 * Process a chunk of records via AJAX
	 */
	const importChunk = ( run ) => {
		$.ajax( {
			url: `${ ajaxurl }?action=wppp_import_chunk`,
			data: { 
				run: run,
				nonce: $( '#wppp-import-nonce' ).val()
			},
			type: 'POST',
			success: ( response ) => {
				if ( response.success ) {
					$progressbar.progressbar( 'value', response.data.pct );
					printLog( response.data.results );

					if ( response.data.pct < 100 ) {
						importChunk( response.data.run );
					} else {
						$successMessageDiv.append( '<p>Complete!</p>' );
					}
				} else {
					handleError( response.data || 'Import failed' );
				}
			},
			error: ( jqXHR, textStatus, errorThrown ) => {
				handleError( `Error: ${ textStatus } - ${ errorThrown }` );
			}
		} );
	};

	/**
	 * Display import results for each record
	 */
	const printLog = ( results ) => {
		const fragments = results.map( ( record ) => {
			const { status, identifer } = record;
			let statusHtml = '';

			switch ( status ) {
				case 'created':
					statusHtml = `<span class="wppp-import-record-status wppp-import-record-success">Success</span>: Created record ${ identifer }`;
					break;

				case 'updated':
					statusHtml = `<span class="wppp-import-record-status wppp-import-record-success">Success</span>: Updated record ${ identifer }`;
					break;

				case 'failed':
					statusHtml = `<span class="wppp-import-record-status wppp-import-record-failure">Failure</span>: Could not create or update record ${ identifer }`;
					break;

				default:
					statusHtml = `<span>Unknown status for record ${ identifer }</span>`;
					break;
			}

			return `${ statusHtml }<br />`;
		} );

		$successMessageDiv
			.append( fragments.join( '' ) )
			.animate( {
				scrollTop: $successMessageDiv.prop( 'scrollHeight' )
			}, 500 );
	};

	/**
	 * Initialize on document ready
	 */
	$( document ).ready( () => {
		$errorDiv = $( '#wppp-error' );
		$successDiv = $( '#wppp-success' );
		$successMessageDiv = $( '#wppp-import-message' );

		$( '#wppp-import-submit' ).on( 'click', ( e ) => {
			e.preventDefault();

			const data = new FormData();
			const files = $( '#wppp-xml' )[ 0 ].files;

			$.each( files, ( i, file ) => {
				data.append( `file-${ i }`, file );
			} );

			data.append( 'wppp-import-nonce', $( '#wppp-import-nonce' ).val() );

			$.ajax( {
				url: `${ ajaxurl }?action=wppp_import_upload`,
				data,
				cache: false,
				contentType: false,
				processData: false,
				type: 'POST',
				success: ( response ) => {
					if ( response.success ) {
						beginImport( response.data );
					} else {
						handleError( response.data || 'Upload failed' );
					}
				},
				error: ( jqXHR, textStatus, errorThrown ) => {
					handleError( `Upload error: ${ textStatus } - ${ errorThrown }` );
				}
			} );
		} );
	} );
} )( jQuery );

