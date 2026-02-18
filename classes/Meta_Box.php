<?php

namespace WP\PastPerfect;

/**
 * Handles Dublin Core metadata meta box for PastPerfect records.
 *
 * @since 1.0.0
 */
class Meta_Box {
	/**
	 * Register meta boxes.
	 */
	public function register() {
		add_meta_box(
			'wppp-dc-metadata',
			__( 'Dublin Core Metadata', 'wp-pastperfect' ),
			array( $this, 'render' ),
			'wppp_record'
		);
	}

	/**
	 * Render the Dublin Core metadata meta box.
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render( $post ) {
		echo '<table class="form-table">';
		$record = new Record( $post->ID );
		foreach ( Record::get_dc_elements() as $element ) {
			$all_values       = $record->get_dc_metadata( $element, false );
			$values_formatted = array();
			foreach ( (array) $all_values as $key => $value ) {
				if ( is_array( $value ) ) {
					$this_item  = '<dl>';
					$this_item .= sprintf(
						'<dt>%s</dt><dd>%s</dd>',
						esc_html( $key ),
						implode( "\n", array_map( 'esc_html', $value ) )
					);

					$this_item .= '</dl>';

					$values_formatted[] = '<p>' . $this_item . '</p>';
				} else {
					if ( 'relation_image' === $element ) {
						$value = $record->convert_filename_to_asset_path( $value );
						$value = sprintf(
							'<img class="wppp-image-preview" src="%s" /><p>%s</p>',
							esc_url( $value ),
							esc_url( $value )
						);
					} else {
						$value = esc_html( $value );
					}

					$values_formatted[] = wpautop( $value );
				}
			}

			printf(
				'<tr>
					<th scope="row">%s</th>
					<td>%s</td>
				</tr>',
				esc_html( $element ),
				implode( "\n", $values_formatted )
			);
		}
		echo '</table>';
	}
}
