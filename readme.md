# PastPerfect WP

A WordPress plugin for importing and serving archival metadata from PastPerfect exports and other valid XML sources.

Originally built for the Brooklyn Historical Society by Hard G and updated in 2026 for the Russian River Historical Society.

## Current status (2026)

The core plugin now supports production sync workflows for larger collections:

* Scheduled and manual sync via WP-Cron.
* Pluggable source providers (XML remains supported and default).
* Persistent media index table for scalable media lookups.
* Media index refresh controls in admin and CLI support.
* Import simulation tooling to preview outcomes before running full sync.

For DBF-as-source workflows, use the companion add-on plugin in `wp-content/plugins/pastperfect-wp-dbf-source`.

## Requirements

* PHP 8.0+
* WordPress 6.8+

## Core metadata behavior

Records are keyed by `identifier` during import.

* If `identifier` does not exist locally: create a new post.
* If `identifier` already exists: update the existing post.

Dublin Core-style fields are mapped into WordPress metadata in a plugin-friendly structure used by this codebase.

## How to use

### Import and sync

1. Go to Dashboard > PastPerfect Records > Import.
2. Provide a source XML URL or absolute file path.
3. Choose recurrence settings for scheduled sync (optional).
4. Run manually now or let WP-Cron execute on schedule.

### Media indexing direction

For larger collections, enable media import and keep the media index refreshed. This avoids repeated full directory scans during import and makes relation-to-media matching deterministic.

If you are changing media files frequently, configure periodic index refresh in the plugin settings.

### API access

Records are exposed at `/wp-json/wppp/v2`.

After first activation, flush permalinks at Dashboard > Settings > Permalinks > Save to ensure routes are registered.

## Operational guidance

* Start with import simulation before first large run.
* Keep source data paths stable between simulation and real sync.
* If using DBF source mode, install and configure the DBF add-on README instructions.
* Review simulator output for duplicate identifiers and missing media references before production import.
