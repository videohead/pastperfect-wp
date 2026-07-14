<?php

namespace PastPerfect\Archive;

class App {
	public static function init() {
		SyncCoordinator::bootstrap();
		ImportSimulator::bootstrap();
		ImportCLI::bootstrap();
		MediaIndex::bootstrap();
		RestoreTaxonomiesCLI::bootstrap();

		$admin = new Admin();
		$admin->set_up_hooks();

		$schema = new Schema();
		$schema->set_up_hooks();

		$endpoint_v1 = new Endpoints\V1\Endpoint();
		$endpoint_v1->set_up_hooks();

		$endpoint_v2 = new Endpoints\V2\Endpoint();
		$endpoint_v2->set_up_hooks();
	}
}
