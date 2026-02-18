<?php

namespace WP\PastPerfect;

class App {
	public static function init() {
		if ( is_admin() ) {
			$admin = new Admin();
			$admin->set_up_hooks();

			$taxonomy_admin = new Taxonomy_Admin();
			$taxonomy_admin->set_up_hooks();
		}

		$schema = new Schema();
		$schema->set_up_hooks();

		$endpoint_v2 = new Endpoints\V2\Endpoint();
		$endpoint_v2->set_up_hooks();
	}
}
