( function( $ ) {
	var $errorDiv, $progressbar, $successDiv, $successMessageDiv;

	handleError = function( message ) {
		$successDiv.hide();
		$errorDiv.html( message ).show();
	}

	beginImport = function( data ) {
		$errorDiv.hide();
		$successDiv.show();

		$progressbar = $( '#bhs-import-progressbar' );
		$progressbar.progressbar({
			value: data.pct
		});

		// Next: kick off first step. Should happen in a separate method.
		importChunk( data.run, data.trialRun );
	}

	importChunk = function( run, trialRun ) {
		$.ajax( {
			url: ajaxurl + '?action=bhssh_import_chunk',
			data: {
				run: run,
				'trial-run': trialRun
			},
			type: 'POST',
			success: function( response ) {
				if ( response.success ) {
					$progressbar.progressbar( 'value', response.data.pct );
					printLog( response.data.results, trialRun );

					if ( response.data.pct < 100 ) {
						importChunk( response.data.run, trialRun );
					} else {
						if ( trialRun ) {
							$successMessageDiv.append( '<p><strong>Trial run complete! No records were created or updated.</strong></p>' );
						} else {
							$successMessageDiv.append( '<p>Complete!</p>' );
						}
					}
				} else {
					// todo
				}
			}
		} );
	}

	printLog = function( results, trialRun ) {
		var html = '';
		var r;

		for ( var i = 0; i < results.length; i++ ) {
			r = results[i];
			switch ( r.status ) {
				// todo localization
				case 'created' :
					if ( trialRun ) {
						html += '<span class="bhs-import-record-status bhs-import-record-success">Trial</span>: Would create record ' + r.identifer;
					} else {
						html += '<span class="bhs-import-record-status bhs-import-record-success">Success</span>: Created record ' + r.identifer;
					}
				break;

				case 'updated' :
					if ( trialRun ) {
						html += '<span class="bhs-import-record-status bhs-import-record-success">Trial</span>: Would update record ' + r.identifer;
					} else {
						html += '<span class="bhs-import-record-status bhs-import-record-success">Success</span>: Updated record ' + r.identifer;
					}
				break;

				case 'failed' :
					html += '<span class="bhs-import-record-status bhs-import-record-failure">Failure</span>: Could not create or update record ' + r.identifer;
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
		$errorDiv = $( '#bhs-error' );
		$successDiv = $( '#bhs-success' );
		$successMessageDiv = $( '#bhs-import-message' );

		$('#bhs-import-submit').click( function(e) {
			e.preventDefault();

			var data = new FormData();
			$.each( $( '#bhs-xml' )[0].files, function( i, file ) {
				data.append( 'file-' + i, file );
			} );

			data.append( 'bhs-import-nonce', $( '#bhs-import-nonce' ).val() );
			data.append( 'bhs-trial-run', $( '#bhs-trial-run' ).is(':checked') ? '1' : '0' );

			$.ajax( {
				url: ajaxurl + '?action=bhssh_import_upload',
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
