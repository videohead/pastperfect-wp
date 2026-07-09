( function( $ ) {
	var $errorDiv, $progressbar, $successDiv, $successMessageDiv;

	handleError = function( message ) {
		$successDiv.hide();
		$errorDiv.html( message ).show();
	}

	beginImport = function( data ) {
		$errorDiv.hide();
		$successDiv.show();

		$progressbar = $( '#pastperfect-import-progressbar' );
		$progressbar.progressbar({
			value: data.pct
		});

		// Next: kick off first step. Should happen in a separate method.
		importChunk( data.run );
	}

	importChunk = function( run ) {
		$.ajax( {
			url: ajaxurl + '?action=ppwp_import_chunk',
			data: {
				run: run,
				'pastperfect-import-nonce': $( '#pastperfect-import-nonce' ).val()
			},
			type: 'POST',
			success: function( response ) {
				if ( response.success ) {
					$progressbar.progressbar( 'value', response.data.pct );
					printLog( response.data.results );

					if ( response.data.pct < 100 ) {
						importChunk( response.data.run );
					} else {
						$successMessageDiv.append( '<p>Complete!</p>' );
					}
				} else {
					// todo
				}
			}
		} );
	}

	printLog = function( results ) {
		var html = '';
		var r;

		for ( var i = 0; i < results.length; i++ ) {
			r = results[i];
			switch ( r.status ) {
				// todo localization
				case 'created' :
					html += '<span class="pastperfect-import-record-status pastperfect-import-record-success">Success</span>: Created record ' + r.identifier;
				break;

				case 'updated' :
					html += '<span class="pastperfect-import-record-status pastperfect-import-record-success">Success</span>: Updated record ' + r.identifier;
				break;

				case 'failed' :
					html += '<span class="pastperfect-import-record-status pastperfect-import-record-failure">Failure</span>: Could not create or update record ' + r.identifier;
				break;

				default:
				break;
			}

			html += '<br />';
		}

		$successMessageDiv.append( html ).animate({
			scrollTop: $successMessageDiv.prop( 'scrollHeight' )
		}, 500);
	}

	$(document).ready( function() {
		$errorDiv = $( '#pastperfect-error' );
		$successDiv = $( '#pastperfect-success' );
		$successMessageDiv = $( '#pastperfect-import-message' );

		$('#pastperfect-import-submit').click( function(e) {
			e.preventDefault();

			var data = new FormData();
			$.each( $( '#pastperfect-xml' )[0].files, function( i, file ) {
				data.append( 'file-' + i, file );
			} );

			data.append( 'pastperfect-import-nonce', $( '#pastperfect-import-nonce' ).val() );

			$.ajax( {
				url: ajaxurl + '?action=ppwp_import_upload',
				data: data,
				cache: false,
				contentType: false,
				processData: false,
				type: 'POST',
				success: function( response ) {
					if ( response.success ) {
						beginImport( response.data );
					} else {
						handleError( response.data );
					}
				}
			} );
		} );
	} );
} )( jQuery );
