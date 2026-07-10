# PastPerfect WP

A WordPress plugin for importing and serving archival metadata from PastPerfect exports and other valid XML sources.
Additional plugin can build WordPress posts directly from you Past Perfect database files.

Originally built for the Brooklyn Historical Society by Hard G and updated in 2026 for the Russian River Historical Society.

## Current status

The core plugin now supports production sync workflows for larger collections:

* Scheduled and manual sync via WP-Cron.
* Pluggable source providers (XML remains supported and default), Past Perfect databases with additional plugin.
* Persistent media index table for scalable media lookups.
* Media index refresh controls in admin and CLI support.
* Import simulation tooling to preview outcomes before running full sync.
* full media import via WP CLI

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

### WP-CLI commands

#### Import simulation

Preview what an import would do without creating or updating any posts:

```bash
wp ppwp import-simulate --xml=/path/to/export.xml --format=table
```

Options:
- `--xml=<path>` - Absolute path to XML file (required)
- `--format=<format>` - Output format: table|json (default: table)
- `--report=<path>` - Optional file path to write full JSON report

#### Direct import (synchronous)

Run imports directly without relying on WP-Cron scheduling. This processes all records synchronously and shows a progress bar:

```bash
wp ppwp import-direct \
  --input=xml \
  --file-path=/path/to/export.xml \
  --media-path=/path/to/media
```

**Required options:**
- `--input=<type>` - Source type: xml or dbf (default: xml)
- `--file-path=<path>` - Absolute file path to XML or DBF file
- `--media-path=<path>` - Absolute path to directory containing referenced media files

**Optional options:**
- `--increment=<number>` - Records to process per chunk, 1-200 (default: 100)
- `--media-provider=<provider>` - wp_media_library|aws_s3|google_cloud_storage|google_drive (default: wp_media_library)
- `--media-base-url=<url>` - Base URL for cloud media providers
- `--dry-run=true|false` - Simulate without creating/updating posts (default: false)
- `--format=<format>` - Output format: table|json (default: table)

**Examples:**

XML import with 50 records per chunk:
```bash
wp ppwp import-direct \
  --input=xml \
  --file-path=/home/archive/exports/data.xml \
  --media-path=/home/archive/media \
  --increment=50
```

DBF import:
```bash
wp ppwp import-direct \
  --input=dbf \
  --file-path=/home/archive/ARCHIVES.DBF \
  --media-path=/home/archive/media
```

Dry-run to preview:
```bash
wp ppwp import-direct \
  --input=xml \
  --file-path=/path/to/export.xml \
  --media-path=/path/to/media \
  --dry-run=true \
  --format=json
```

#### Media index management

Rebuild the media index for faster media lookups:

```bash
wp ppwp media-index --source=/path/to/media --rebuild
```

Options:
- `--source=<path>` - Absolute path to media directory (required)
- `--rebuild` - Rebuild index from scratch
- `--prune` - Remove entries for missing files
- `--hash` - Compute file hashes for additional accuracy

## Operational guidance

* Start with import simulation before first large run.
* Keep source data paths stable between simulation and real sync.
* If using DBF source mode, install and configure the DBF add-on README instructions.
* Review simulator output for duplicate identifiers and missing media references before production import.
