<?php

namespace WP\PastPerfect;

/**
 * Handles AJAX import functionality for PastPerfect records.
 *
 * @since 1.0.0
 */
class Ajax_Import_Handler {
	/**
	 * Name for top-level record element.
	 *
	 * @var string
	 */
	protected $record_element = 'dc-record';

	/**
	 * Process AJAX file upload and prepare for chunked import.
	 */
	public function process_ajax_submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to import records.', 'wp-pastperfect' ) );
		}

		$nonce = isset( $_POST['wppp-import-nonce'] ) ? wp_unslash( $_POST['wppp-import-nonce'] ) : '';

		if ( ! wp_verify_nonce( $nonce, 'wppp-import' ) ) {
			wp_send_json_error( __( 'Security check failed. Please refresh the page and try again.', 'wp-pastperfect' ) );
		}

		if ( empty( $_FILES ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: post_max_size value */
					__( 'No file received. The file may be too large. Check the "post_max_size" directive in php.ini (currently %s).', 'wp-pastperfect' ),
					ini_get( 'post_max_size' )
				)
			);
		}

		if ( empty( $_FILES['file-0'] ) ) {
			wp_send_json_error( __( 'No file was uploaded. Please select a file and try again.', 'wp-pastperfect' ) );
		}

		$file = $_FILES['file-0'];

		// Check for upload errors.
		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( $this->get_upload_error_message( $file['error'] ) );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( __( 'File upload failed. Please try again.', 'wp-pastperfect' ) );
		}

		// Validate file type.
		$filetype = wp_check_filetype( $file['name'], array( 'xml' => 'text/xml' ) );
		if ( ! $filetype['ext'] || 'xml' !== $filetype['ext'] ) {
			wp_send_json_error( __( 'Invalid file type. Only XML files are allowed.', 'wp-pastperfect' ) );
		}

		// Use WordPress upload handling.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$timestamp = time();
		$uploads   = wp_upload_dir();

		// Ensure the upload directory is writable.
		if ( ! empty( $uploads['error'] ) ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: error message */
					__( 'Upload directory error: %s', 'wp-pastperfect' ),
					$uploads['error']
				)
			);
		}

		// Create a unique filename.
		$dest = $uploads['basedir'] . '/wppp-import-' . $timestamp . '.xml';

		// Move uploaded file.
		$moved = @move_uploaded_file( $file['tmp_name'], $dest );
		if ( ! $moved ) {
			wp_send_json_error(
				sprintf(
					/* translators: %s: destination path */
					__( 'Could not move uploaded file to %s. Please check server permissions.', 'wp-pastperfect' ),
					$uploads['basedir']
				)
			);
		}

		// Ensure file is writable
		@chmod( $dest, 0644 );

		// Clean XML file (remove leading whitespace that can break XMLReader).
		$clean_result = $this->clean_xml_file( $dest );
		if ( is_wp_error( $clean_result ) ) {
			@unlink( $dest );
			wp_send_json_error( $clean_result->get_error_message() );
		}

		// Validate XML file.
		$x = new \XMLReader();
		if ( ! @$x->open( $dest ) ) {
			@unlink( $dest );
			wp_send_json_error( __( 'Could not parse XML file. The file may be corrupted or invalid.', 'wp-pastperfect' ) );
		}

		$doc = new \DOMDocument();

		// Move to the first record node and track what we see.
		$found_first   = false;
		$elements_seen = array();
		while ( $x->read() ) {
			if ( $x->nodeType === \XMLReader::ELEMENT ) {
				$elements_seen[ $x->name ] = true;
			}
			if ( $this->record_element === $x->name ) {
				$found_first = true;
				break;
			}
		}

		// Check if we found any records.
		if ( ! $found_first ) {
			$x->close();
			@unlink( $dest );
			wp_send_json_error(
				sprintf(
					/* translators: 1: expected element name, 2: list of found elements */
					__( 'No <%1$s> elements found in XML file. Found elements: %2$s. Please ensure this is a valid PastPerfect XML export.', 'wp-pastperfect' ),
					$this->record_element,
					implode( ', ', array_keys( $elements_seen ) )
				)
			);
		}

		$count = 0;
		while ( $this->record_element === $x->name ) {
			$count++;
			$x->next( $this->record_element );
		}

		$x->close();

		if ( 0 === $count ) {
			@unlink( $dest );
			wp_send_json_error( __( 'No records found in XML file.', 'wp-pastperfect' ) );
		}

		$run_key  = 'wppp_import_run_' . $timestamp;
		$run_data = array(
			'xml'   => $dest,
			'last'  => 0,
			'count' => $count,
		);
		update_option( $run_key, $run_data, false );

		$retval = array(
			'run'     => $timestamp,
			'pct'     => 0,
			'message' => sprintf(
				/* translators: %d: number of records */
				__( 'Found %d records. Starting import...', 'wp-pastperfect' ),
				$count
			),
		);

		wp_send_json_success( $retval );
	}

	/**
	 * Process a chunk of records during AJAX import.
	 */
	public function process_ajax_chunk() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'You do not have permission to import records.', 'wp-pastperfect' ) );
		}

		check_ajax_referer( 'wppp-import', 'nonce' );

		$run      = isset( $_POST['run'] ) ? sanitize_text_field( wp_unslash( $_POST['run'] ) ) : '';
		$run_key  = 'wppp_import_run_' . $run;
		$run_data = get_option( $run_key );
		if ( ! $run || ! $run_data ) {
			wp_send_json_error( __( 'Import session expired. Please upload the file again.', 'wp-pastperfect' ) );
		}

		// Verify the XML file still exists.
		if ( ! file_exists( $run_data['xml'] ) || ! is_readable( $run_data['xml'] ) ) {
			delete_option( $run_key );
			wp_send_json_error( __( 'XML file not found or not readable. Please upload the file again.', 'wp-pastperfect' ) );
		}

		$last = $run_data['last'];

		$x = new \XMLReader();
		if ( ! @$x->open( $run_data['xml'] ) ) {
			delete_option( $run_key );
			wp_send_json_error( __( 'Could not open XML file. The file may be corrupted.', 'wp-pastperfect' ) );
		}

		$doc = new \DOMDocument();

		// Move to the first record node.
		while ( $x->read() && $this->record_element !== $x->name );

		$results   = array();
		$current   = 0;
		$increment = 5;

		while ( $this->record_element === $x->name ) {
			if ( $current >= ( $last + $increment ) ) {
				break;
			}

			$current++;

			if ( $current <= $last ) {
				$x->next( $this->record_element );
				continue;
			}

			$node = simplexml_import_dom( $doc->importNode( $x->expand(), true ) );
			$atts = array();
			$id   = '';
			foreach ( $node->children() as $a => $b ) {
				if ( 'identifier' === $a && ! $id ) {
					$id = (string) $b;
				}

				$children = $b->children();
				if ( $children ) {
					$value = array();
					foreach ( $children as $ck => $cv ) {
						$atts[ $a ][ $ck ][] = (string) $cv;
					}
				} else {
					$atts[ $a ][] = (string) $b;
				}
			}

			$record = new Record();

			$exists = (bool) $record->get_post_id_by_identifier( $id );

			$record->set_up_from_raw_atts( $atts );

			$saved = $record->save();

			$result = array(
				'identifer' => $id,
				'status'    => '',
			);

			if ( $saved ) {
				if ( $exists ) {
					$result['status'] = 'updated';
				} else {
					$result['status'] = 'created';
				}
			} else {
				$result['status'] = 'failed';
			}

			$results[] = $result;

			$x->next( $this->record_element );
		}

		$x->close();

		$run_data['last'] = $current;
		update_option( $run_key, $run_data, false );

		$pct         = floor( 100 * ( $current / $run_data['count'] ) );
		$is_complete = $current >= $run_data['count'];

		// Clean up if complete.
		if ( $is_complete ) {
			@unlink( $run_data['xml'] );
			delete_option( $run_key );
		}

		$retval = array(
			'run'      => $run,
			'pct'      => $pct,
			'results'  => $results,
			'message'  => sprintf(
				/* translators: 1: current record number, 2: total records */
				__( 'Processed %1$d of %2$d records...', 'wp-pastperfect' ),
				$current,
				$run_data['count']
			),
			'complete' => $is_complete,
		);

		wp_send_json_success( $retval );
	}

	/**
	 * Get descriptive error message for file upload errors.
	 *
	 * @param int $error_code PHP upload error code.
	 * @return string
	 */
	protected function get_upload_error_message( $error_code ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
				return sprintf(
					/* translators: %s: upload_max_filesize value */
					__( 'The uploaded file exceeds the upload_max_filesize directive in php.ini (currently %s).', 'wp-pastperfect' ),
					ini_get( 'upload_max_filesize' )
				);
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'wp-pastperfect' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The uploaded file was only partially uploaded. Please try again.', 'wp-pastperfect' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'wp-pastperfect' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Missing a temporary folder on the server. Please contact your system administrator.', 'wp-pastperfect' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Failed to write file to disk. Please check server permissions.', 'wp-pastperfect' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'File upload stopped by a PHP extension.', 'wp-pastperfect' );
			default:
				return sprintf(
					/* translators: %d: error code */
					__( 'Unknown upload error (code: %d).', 'wp-pastperfect' ),
					$error_code
				);
		}
	}

	/**
	 * Clean XML file by removing leading whitespace.
	 *
	 * PastPerfect XML exports often have leading whitespace (CRLF) before the XML declaration,
	 * which can cause XMLReader to fail. This method removes any leading whitespace.
	 *
	 * @param string $file_path Path to XML file.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	protected function clean_xml_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new \WP_Error( 'file_not_found', __( 'XML file not found.', 'wp-pastperfect' ) );
		}

		if ( ! is_readable( $file_path ) ) {
			return new \WP_Error( 'file_not_readable', __( 'Cannot read XML file.', 'wp-pastperfect' ) );
		}

		$content = @file_get_contents( $file_path );
		if ( false === $content ) {
			return new \WP_Error( 'file_read_failed', __( 'Failed to read XML file content.', 'wp-pastperfect' ) );
		}

		// Remove leading whitespace (BOM, spaces, newlines, etc.).
		$cleaned_content = ltrim( $content );

		// Only write back if content changed
		if ( $cleaned_content === $content ) {
			return true; // No cleaning needed
		}

		// Check if file is writable before attempting to write
		if ( ! is_writable( $file_path ) ) {
			return new \WP_Error( 'file_not_writable', __( 'Cannot write to XML file. Please check file permissions.', 'wp-pastperfect' ) );
		}

		// Write cleaned content back to file.
		$result = @file_put_contents( $file_path, $cleaned_content );
		if ( false === $result ) {
			return new \WP_Error( 'file_write_failed', __( 'Failed to write cleaned XML file. Please check file permissions.', 'wp-pastperfect' ) );
		}

		return true;
	}
}
