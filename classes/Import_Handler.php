<?php

namespace WP\PastPerfect;

/**
 * Handles XML import functionality for PastPerfect records.
 *
 * @since 1.0.0
 */
class Import_Handler {
	/**
	 * Name for top-level record element.
	 *
	 * @var string
	 */
	protected $record_element = 'dc-record';

	/**
	 * Process a full import from uploaded file.
	 *
	 * @param array $file Uploaded file data from $_FILES.
	 * @return int|\WP_Error Timestamp key on success, WP_Error on failure.
	 */
	public function process_import( $file ) {
		// Validate file upload.
		$validation = $this->validate_upload( $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$time = time();

		// Get cleaned XML content (uploaded files may not be writable).
		$xml_content = $this->get_cleaned_xml_content( $file['tmp_name'] );
		if ( is_wp_error( $xml_content ) ) {
			return $xml_content;
		}

		// Validate XML structure.
		$x = new \XMLReader();
		if ( ! @$x->xml( $xml_content ) ) {
			return new \WP_Error( 'xml_open_failed', __( 'Could not parse XML content. The file may be corrupted.', 'wp-pastperfect' ) );
		}

		$doc = new \DOMDocument();

		$results = array(
			'created' => array(),
			'updated' => array(),
			'failed'  => array(),
		);

		// Move to the first record node.
		$found_first = false;
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
			return new \WP_Error(
				'no_records',
				sprintf(
					/* translators: 1: expected element name, 2: list of found elements */
					__( 'No <%1$s> elements found in XML file. Found: %2$s. Please ensure this is a valid PastPerfect XML export.', 'wp-pastperfect' ),
					$this->record_element,
					implode( ', ', array_keys( $elements_seen ) )
				)
			);
		}

		$singular_elements = Record::get_singular_elements();

		while ( $this->record_element === $x->name ) {
			$node = simplexml_import_dom( $doc->importNode( $x->expand(), true ) );
			$atts = array();
			$id   = '';
			foreach ( $node->children() as $a => $b ) {
				if ( 'identifier' === $a && ! $id ) {
					$id = (string) $b;
				}

				if ( in_array( $a, $singular_elements, true ) ) {
					$atts[ $a ] = (string) $b;
				} else {
					$atts[ $a ][] = (string) $b;
				}
			}

			$record = new Record();

			$exists = (bool) $record->get_post_id_by_identifier( $id );

			$record->set_up_from_raw_atts( $atts );

			$saved = $record->save();

			if ( $saved ) {
				if ( $exists ) {
					$results['updated'][] = $id;
				} else {
					$results['created'][] = $id;
				}
			} else {
				$results['failed'][] = $id;
			}

			$x->next( $this->record_element );
		}

		$x->close();

		update_option( 'wppp_import_results_' . $time, $results );

		return $time;
	}

	/**
	 * Process a trial import (preview without saving).
	 *
	 * @param array $file Uploaded file data from $_FILES.
	 * @return int|\WP_Error Timestamp key on success, WP_Error on failure.
	 */
	public function process_trial_import( $file ) {
		// Validate file upload.
		$validation = $this->validate_upload( $file );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$time = time();

		// Get cleaned XML content (uploaded files may not be writable).
		$xml_content = $this->get_cleaned_xml_content( $file['tmp_name'] );
		if ( is_wp_error( $xml_content ) ) {
			return $xml_content;
		}

		// Validate XML structure.
		$x = new \XMLReader();
		if ( ! @$x->xml( $xml_content ) ) {
			return new \WP_Error( 'xml_open_failed', __( 'Could not parse XML content. The file may be corrupted.', 'wp-pastperfect' ) );
		}

		$doc = new \DOMDocument();

		$results = array(
			'total'        => 0,
			'would_create' => array(),
			'would_update' => array(),
			'would_fail'   => array(),
			'log'          => array(),
			'skipped'      => array(),
		);

		$results['log'][] = sprintf( 'Starting trial import at %s', date( 'Y-m-d H:i:s', $time ) );
		$results['log'][] = sprintf( 'File: %s (Size: %s bytes)', $file['name'], number_format( $file['size'] ) );
		$results['log'][] = sprintf( 'Looking for <%s> elements...', $this->record_element );

		// Move to the first record node.
		$results['log'][] = 'Scanning XML structure...';
		$found_first       = false;
		$elements_seen     = array();
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
			$results['log'][] = 'ERROR: No record elements found!';
			$results['log'][] = 'Elements found in XML: ' . implode( ', ', array_keys( $elements_seen ) );
			update_option( 'wppp_trial_results_' . $time, $results, false );
			return new \WP_Error(
				'no_records',
				sprintf(
					/* translators: 1: expected element name, 2: list of found elements */
					__( 'No <%1$s> elements found in XML file. Found: %2$s. Please ensure this is a valid PastPerfect XML export.', 'wp-pastperfect' ),
					$this->record_element,
					implode( ', ', array_keys( $elements_seen ) )
				)
			);
		}

		$results['log'][] = sprintf( 'Found first <%s> element', $this->record_element );

		$singular_elements = Record::get_singular_elements();

		while ( $this->record_element === $x->name ) {
			$results['total']++;

			$node          = simplexml_import_dom( $doc->importNode( $x->expand(), true ) );
			$atts          = array();
			$id            = '';
			$element_count = 0;
			foreach ( $node->children() as $a => $b ) {
				$element_count++;
				if ( 'identifier' === $a && ! $id ) {
					$id = (string) $b;
				}

				if ( in_array( $a, $singular_elements, true ) ) {
					$atts[ $a ] = (string) $b;
				} else {
					$atts[ $a ][] = (string) $b;
				}
			}

			// Check if record has an identifier.
			if ( empty( $id ) ) {
				$results['skipped'][] = array(
					'record_num' => $results['total'],
					'elements'   => $element_count,
					'reason'     => __( 'Missing identifier', 'wp-pastperfect' ),
				);
				$x->next( $this->record_element );
				continue;
			}

			$record = new Record();

			$exists = (bool) $record->get_post_id_by_identifier( $id );

			// Validate the record can be set up.
			try {
				$record->set_up_from_raw_atts( $atts );

				// Check if this would be a create or update.
				if ( $exists ) {
					$results['would_update'][] = $id;
				} else {
					$results['would_create'][] = $id;
				}
			} catch ( \Exception $e ) {
				$results['would_fail'][] = array(
					'id'         => $id,
					'reason'     => $e->getMessage(),
					'record_num' => $results['total'],
				);
			}

			$x->next( $this->record_element );
		}

		$x->close();

		$results['log'][] = sprintf(
			'Completed: %d total records, %d would create, %d would update, %d would fail, %d skipped',
			$results['total'],
			count( $results['would_create'] ),
			count( $results['would_update'] ),
			count( $results['would_fail'] ),
			count( $results['skipped'] )
		);

		update_option( 'wppp_trial_results_' . $time, $results, false );

		return $time;
	}

	/**
	 * Validate uploaded file.
	 *
	 * @param array $file Uploaded file data from $_FILES.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	protected function validate_upload( $file ) {
		if ( ! isset( $file['error'] ) ) {
			return new \WP_Error( 'invalid_upload', __( 'Invalid file upload.', 'wp-pastperfect' ) );
		}

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new \WP_Error( 'upload_error', $this->get_upload_error_message( $file['error'] ) );
		}

		// Validate file type.
		$filetype = wp_check_filetype( $file['name'], array( 'xml' => 'text/xml' ) );
		if ( ! $filetype['ext'] || 'xml' !== $filetype['ext'] ) {
			return new \WP_Error( 'invalid_file_type', __( 'File must be an XML file.', 'wp-pastperfect' ) );
		}

		// Validate file exists and is readable.
		if ( ! file_exists( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return new \WP_Error( 'file_not_readable', __( 'Uploaded file is not readable.', 'wp-pastperfect' ) );
		}

		return true;
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

	/**
	 * Get cleaned XML content from a file.
	 *
	 * Reads an XML file and returns content with leading whitespace removed.
	 * Use this when the source file might not be writable (e.g., uploaded files).
	 *
	 * @param string $file_path Path to XML file.
	 * @return string|\WP_Error Cleaned XML content on success, WP_Error on failure.
	 */
	protected function get_cleaned_xml_content( $file_path ) {
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
		return ltrim( $content );
	}
}
