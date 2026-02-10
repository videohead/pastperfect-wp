# WP-PastPerfect

A modern WordPress plugin for managing and serving archival records exported from PastPerfect Museum Software, providing a standardized approach to digital collection management with WordPress.

Originally built for the [Brooklyn Historical Society](http://brooklynhistory.org) by [Hard G](https://hardg.com), now maintained as an open-source solution for museums, libraries, archives, and historical societies.

## Features

* **Standards-Based Import** - Process XML exports from PastPerfect and other valid XML sources
* **Dublin Core Metadata** - Full Dublin Core support with proper data structure for interoperability:
	title_collection,
	title_title,
	title_accession,
	identifier,
	type,
	publisher,
	description,
	date,
	coverage,
	coverage_GIS,
	creator,
	contributor,
	format,
	format_scale,
	format_size,
	rights,
	subject_people,
	subject_subject,
	subject_places,
	relation_ohms,
	relation_findingaid,
	rights_request,
	relation_image,
	relation_attachment,
	source,
	language,
	creator_alpha
* **RESTful API** - Modern REST API endpoints for external access and integrations
* **Flexible Asset Storage** - Configurable support for Amazon S3, Google Drive, and other storage backends
* **WordPress Block Editor** - Full compatibility with Gutenberg block editor
* **Custom Taxonomies** - Organized subject access points (people, places, topics, genres)

## Requirements

* *Installation

1. Upload the `wp-pastperfect` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Dashboard > Settings > Permalinks** and click Save to flush permalinks
4. Begin importing records at **Dashboard > PastPerfect Records > Import**

## Usage

### Importing Records

Upload PastPerfect XML export documents at **Dashboard > PastPerfect Records > Import**.

**How it works:**
- Records are identified by the `identifier` field
- New identifiers create new WordPress posts
- Existing identifiers update their corresponding posts
- All Dublin Core metadata is preserved and searchable

### REST API Access

Records are available via REST API endpoints:

**Custom Endpoint:**
```
GET /wp-json/wppp/v2/record/{identifier}
```

**WordPress REST API:**
```
GET /wp-json/wp/v2/pastperfect-records
```

**Note:** API v1 has been deprecated and removed. All integrations should use v2.

### Configuring Asset Storage

By default, assets are served from the legacy S3 bucket. To use custom storage:

**Option 1: wp-config.php constant**
```php
define( 'WPPP_ASSET_BASE_URL', 'https://your-cdn.example.com/assets/' );
```

**Option 2: Filter hook**
```php
add_filter( 'wppp_asset_base_url', function( $base_url ) {
    return 'https://drive.google.com/uc?export=view&id=YOUR_FOLDER_ID/';
} );
```

Supports Amazon S3, Google Drive, CloudFront CDN, and any public URL-accessible storage.

## Integration

Third-party developers can access and display archival records using the REST API endpoints or by directly querying the `wppp_record` custom post type. A legacy companion plugin for shortcode-based display is available at [bhs-client](https://github.com/bhslibrary/bhs-client).

## Support

For bug reports, feature requests, or contributions, please visit the plugin repository or contact the maintainers
**Note:** API v1 has been removed. Please migrate to v2.

There is a companion plugin that enables WordPress authors to access and display Storehouse data via shortcodes or template functions. See [bhs-client](https://github.com/bhslibrary/bhs-client).
