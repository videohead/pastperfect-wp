<?php

namespace PastPerfect\Archive;

/**
 * WP-CLI command to restore taxonomy term relationships for archive items.
 */
class RestoreTaxonomiesCLI {
    public static function bootstrap(): void {
        if ( defined( 'WP_CLI' ) && true === constant( 'WP_CLI' ) && class_exists( '\\WP_CLI' ) ) {
            \WP_CLI::add_command( 'ppwp restore-terms', array( __CLASS__, 'cli_restore_terms' ) );
        }
    }

    /**
     * Restore term relationships for archive items.
     *
     * ## OPTIONS
     *
     * [--post-type=<post-type>]
     * : Post type to process. Default: archive_item.
     *
     * [--taxonomy=<taxonomy>]
     * : Taxonomy to restore. Default: archive_subject.
     *
     * [--batch=<n>]
     * : Number of posts to process per loop. Default: 100.
     *
     * [--dry-run=<bool>]
     * : If true, do not modify database. Default: false.
     *
     * [--format=<format>]
     * : table|json. Default: table.
    *
    * [--verbose=<bool>]
    * : If true, show per-post diagnostics. Default: false.
     */
    public static function cli_restore_terms( array $args, array $assoc_args ): void {
        unset( $args );

        if ( ! class_exists( '\\WP_CLI' ) ) {
            return;
        }

        $post_type = isset( $assoc_args['post-type'] ) ? sanitize_key( (string) $assoc_args['post-type'] ) : 'archive_item';
        $taxonomy = isset( $assoc_args['taxonomy'] ) ? sanitize_key( (string) $assoc_args['taxonomy'] ) : 'archive_subject';
        $batch = isset( $assoc_args['batch'] ) ? max( 1, absint( $assoc_args['batch'] ) ) : 100;
        $dry_run = isset( $assoc_args['dry-run'] ) ? filter_var( $assoc_args['dry-run'], FILTER_VALIDATE_BOOLEAN ) : false;
        $format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';
        $verbose = isset( $assoc_args['verbose'] ) ? filter_var( $assoc_args['verbose'], FILTER_VALIDATE_BOOLEAN ) : false;

        if ( '' === $post_type || '' === $taxonomy ) {
            \WP_CLI::error( 'Invalid post-type or taxonomy.' );
            return;
        }

        if ( ! taxonomy_exists( $taxonomy ) ) {
            \WP_CLI::error( "Taxonomy {$taxonomy} does not exist." );
            return;
        }

        $post_ids = get_posts(
            array(
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
            )
        );

        $total = is_array( $post_ids ) ? count( $post_ids ) : 0;
        $processed = 0;
        $restored = 0;
        $unchanged = 0;
        $failed = 0;

        $progress = \WP_CLI\Utils\make_progress_bar( 'Restoring terms', max( 1, $total ), 50 );

        $chunks = array_chunk( (array) $post_ids, $batch );
        foreach ( $chunks as $chunk ) {
            foreach ( $chunk as $post_id ) {
                $post_id = (int) $post_id;
                if ( $post_id <= 0 ) {
                    continue;
                }

                $processed++;

                // Fetch existing term names for comparison.
                $existing_names = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
                $existing_names = is_wp_error( $existing_names ) ? array() : (array) $existing_names;

                // Aggregate candidate terms from legacy meta.
                $meta_values = get_post_meta( $post_id, 'pastperfect_dc_subject', false );
                $candidates = array();
                if ( is_array( $meta_values ) && ! empty( $meta_values ) ) {
                    foreach ( $meta_values as $v ) {
                        $v = (string) $v;
                        if ( '' === trim( $v ) ) {
                            continue;
                        }
                        $parts = strpos( $v, ';' ) !== false ? array_map( 'trim', explode( ';', $v ) ) : array( trim( $v ) );
                        foreach ( $parts as $p ) {
                            if ( '' !== $p ) {
                                $candidates[] = $p;
                            }
                        }
                    }
                }

                // Also detect matches in content/title using taxonomy terms as candidates.
                $post = get_post( $post_id );
                $source_text = '';
                if ( $post ) {
                    $title = (string) $post->post_title;
                    $description_values = get_post_meta( $post->ID, 'pastperfect_dc_description', false );
                    $description = '';
                    if ( is_array( $description_values ) && ! empty( $description_values ) ) {
                        $description = is_array( $description_values ) ? reset( $description_values ) : (string) $description_values;
                    }
                    if ( '' === $description ) {
                        $description = (string) $post->post_content;
                    }
                    $source_text = trim( implode( "\n", array_filter( array( $title, $description ) ) ) );
                }

                if ( '' !== $source_text ) {
                    $text = $source_text;
                    $text = wp_strip_all_tags( $text );
                    $text = remove_accents( $text );
                    $text = mb_strtolower( $text );
                    $text = preg_replace( '/\s+/', ' ', $text );

                    $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
                    if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
                        $candidates_from_content = array();
                        foreach ( $terms as $term ) {
                            if ( ! $term instanceof \WP_Term ) {
                                continue;
                            }
                            $name = trim( (string) $term->name );
                            if ( mb_strlen( $name ) < 3 ) {
                                continue;
                            }
                            $needle = wp_strip_all_tags( $name );
                            $needle = remove_accents( $needle );
                            $needle = mb_strtolower( $needle );
                            $needle = preg_replace( '/\s+/', ' ', $needle );
                            if ( '' === $needle ) {
                                continue;
                            }
                            $pattern = '/(^|[^[:alnum:]])' . preg_quote( $needle, '/' ) . '([^[:alnum:]]|$)/ui';
                            if ( 1 === preg_match( $pattern, $text ) ) {
                                $candidates_from_content[] = $name;
                            }
                        }
                        if ( ! empty( $candidates_from_content ) ) {
                            $candidates = array_merge( $candidates, $candidates_from_content );
                        }
                    }
                }

                $candidates = array_values( array_unique( array_map( 'trim', array_filter( (array) $candidates ) ) ) );

                // If no candidates and nothing to do, mark unchanged.
                if ( empty( $candidates ) ) {
                    $unchanged++;
                    if ( $verbose ) {
                        \WP_CLI::log( "Post {$post_id}: no candidate terms found." );
                    }
                    $progress->tick();
                    continue;
                }

                // Merge with existing and update if different.
                $merged = array_values( array_unique( array_merge( $existing_names, $candidates ), SORT_REGULAR ) );

                sort( $merged );
                $sorted_existing = $existing_names;
                sort( $sorted_existing );

                if ( $merged === $sorted_existing ) {
                    $unchanged++;
                    if ( $verbose ) {
                        \WP_CLI::log( "Post {$post_id}: existing terms already include candidates." );
                    }
                    $progress->tick();
                    continue;
                }

                if ( ! $dry_run ) {
                    wp_set_object_terms( $post_id, $merged, $taxonomy );
                }

                $after = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
                if ( ! is_wp_error( $after ) && ! empty( $after ) ) {
                    $restored++;
                    if ( $verbose ) {
                        \WP_CLI::log( "Post {$post_id}: updated terms to: " . implode( ', ', $merged ) );
                    }
                } else {
                    $failed++;
                    if ( $verbose ) {
                        \WP_CLI::warning( "Post {$post_id}: attempted to set terms but none attached." );
                    }
                }

                $progress->tick();
            }
        }

        $progress->finish();

        $result = array(
            array(
                'post_type' => $post_type,
                'taxonomy' => $taxonomy,
                'scanned' => $total,
                'processed' => $processed,
                'restored' => $restored,
                'unchanged' => $unchanged,
                'failed' => $failed,
                'dry_run' => $dry_run ? 'true' : 'false',
            ),
        );

        if ( 'json' === $format ) {
            \WP_CLI::line( wp_json_encode( $result[0], JSON_PRETTY_PRINT ) );
        } else {
            \WP_CLI\Utils\format_items( 'table', $result, array_keys( $result[0] ) );
        }

        if ( $dry_run ) {
            \WP_CLI::success( 'Dry run complete.' );
        } else {
            \WP_CLI::success( 'Restore operation complete.' );
        }
    }
}
