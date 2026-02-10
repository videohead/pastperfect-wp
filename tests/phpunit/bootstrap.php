<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin() {
	require dirname( dirname( __DIR__ ) ) . '/wp-pastperfect.php';
}

// Load plugin before running tests - ensures hooks are registered
if ( function_exists( 'tests_add_filter' ) ) {
	tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );
} else {
	// Fallback for older or custom test setups
	_manually_load_plugin();
}

require $_tests_dir . '/includes/bootstrap.php';
