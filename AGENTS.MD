# AGENTS.MD - AI Agent Documentation

## Overview

This document provides guidance for AI agents (like GitHub Copilot, ChatGPT, Claude, etc.) working on the WP-PastPerfect plugin (formerly BHS Storehouse). It summarizes the plugin architecture, recent modernization changes, and best practices for future updates.

## Plugin Purpose

WP-PastPerfect is a WordPress plugin that imports and manages archival records exported from **PastPerfect Museum Software**, the most widely-used collection management system for museums, archives, libraries, and historical societies worldwide.

**About PastPerfect:**
PastPerfect (https://museumsoftware.com) is professional collection management software that handles all types of museum collections including archival, art, archaeology, historic, ethnographic, and photographic materials. Organizations use PastPerfect to catalog their collections with standardized metadata, manage accessions, track provenance, and generate reports.

**What This Plugin Does:**
- Imports PastPerfect XML export files into WordPress
- Creates WordPress posts for each archival record with full Dublin Core metadata
- Provides REST API access to collection data for public websites and integrations
- Maintains taxonomies for subjects, people, places, and genres
- Links to externally-hosted digital assets (images, documents, media files)

**Use Case:**
Museums and archives use PastPerfect internally for collection management, then use this plugin to make their collections discoverable and accessible via WordPress-powered public websites without exposing their internal PastPerfect database.

## Current Architecture

### File Structure

```
wp-pastperfect.php          # Main plugin file, bootstrap & version check
autoload.php                # PSR-4 autoloader for classes
load.php                    # Application initialization
classes/
  ├── App.php               # Main application controller
  ├── Admin.php             # Admin interface & import functionality
  ├── Schema.php            # Post types & taxonomies registration
  ├── Record.php            # Record object & metadata handling
  └── Endpoints/
      └── V2/Endpoint.php   # REST API v2
```

### Key Components

#### 1. **Post Type: `wppp_record`**
- Represents a single PastPerfect record
- Supports: title, editor, custom-fields
- REST API enabled at `/wp-json/wp/v2/pastperfect-records`
- Block Editor (Gutenberg) compatible

#### 2. **Taxonomies**
- `wppp_subject_subject` - Subject topics
- `wppp_subject_people` - People references
- `wppp_subject_places` - Location references  
- `wppp_subject_genre` - Genre classifications

All taxonomies are:
- REST API enabled
- Non-hierarchical
- Publicly accessible

#### 3. **REST API Endpoints**

**Custom Endpoints:**
- `/wp-json/wppp/v2/record/{identifier}` - Get record by identifier

**Standard WordPress REST API:**
- `/wp-json/wp/v2/pastperfect-records` - List/CRUD operations

**Note:** API v1 has been removed as of WordPress REST API v1 deprecation.

#### 4. **Dublin Core Metadata**

Records store Dublin Core fields as post meta with prefix `wppp_dc_*`:
- Singular fields: identifier, date, format, etc.
- Multi-value fields: contributor, creator, subject, etc.
- Special handling for: relation_image, relation_attachment, coverage_GIS

#### 5. **PastPerfect Data Import**

**Source Format:**
PastPerfect exports collection data as XML files containing structured metadata for each cataloged object. This plugin processes these XML exports and transforms them into WordPress posts.

**Import Process:**
1. User uploads PastPerfect XML export via admin dashboard
2. XML is parsed and chunked for processing (prevents timeout on large imports)
3. Each record is mapped to Dublin Core fields
4. Record identifiers determine create vs. update operation
5. Taxonomies are populated from subject fields
6. Asset URLs are constructed from filename references

**Key Data Mappings:**
- PastPerfect `ObjectID` → WordPress post meta `wppp_dc_identifier`
- PastPerfect subjects → WordPress taxonomies (`wppp_subject_*`)
- PastPerfect image filenames → External asset URLs via `$asset_base`
- PastPerfect descriptive fields → Dublin Core post meta

**Asset Handling:**
Digital assets (images, attachments) are NOT imported into WordPress Media Library. Instead, the plugin stores filename references and constructs URLs pointing to externally-hosted files (S3, Google Drive, CDN, etc.). This keeps WordPress lean and allows museums to manage large media collections separately.

## Recent Modernization (v0.2.0)

### WordPress 6.9 Compatibility Updates

#### Core Changes
1. **PHP Requirements**: Updated from PHP 5.3 to PHP 7.4+
2. **WordPress Version**: Tested up to 6.9 (from 4.6)
3. **Plugin Version**: Bumped to 0.2.0

#### Security Enhancements
- ✅ Added REST API `permission_callback` (required since WP 5.5)
- ✅ Proper input sanitization with `sanitize_text_field()`
- ✅ AJAX nonce verification with `check_ajax_referer()`
- ✅ Output escaping with `esc_html()`, `esc_url()`
- ✅ Secure URL building with `add_query_arg()`

#### API Improvements
- Added argument validation with `sanitize_callback` and `validate_callback`
- Enhanced REST API support with `show_in_rest` on post types/taxonomies
- Public taxonomies for better searchability

#### Deprecated Function Replacements
- ❌ `date()` → ✅ `wp_date()` (WordPress timezone-aware)
- ❌ `dirname(__FILE__)` → ✅ `__DIR__`
- ❌ String concatenation for URLs → ✅ `add_query_arg()`

#### Block Editor Support
- Added 'editor' and 'custom-fields' to post type supports
- Enabled REST API for all custom taxonomies
- Block Editor now works with records

## Guidelines for Future AI Agents

### Before Making Changes

1. **Check Current Standards**
   - PHP version requirement: 7.4+
   - WordPress version: 5.0+ (tested to 6.9)
   - Always verify against latest WordPress Coding Standards

2. **Understand the Data Flow**
   ```
   XML Import → Admin.php → Record.php → WordPress Post/Meta
   REST API Request → Endpoint.php → Record.php → JSON Response
   ```

3. **Review Existing Patterns**
   - Check how similar functionality is implemented
   - Follow established naming conventions (`wppp_*` prefix)
   - Maintain namespace structure (`WP\PastPerfect\`)

### Common Update Scenarios

#### Adding New Dublin Core Fields

1. Add field name to `Record::$dc_elements` array
2. If singular (single value), add to `Record::$singular_elements`
3. If taxonomy-based, add to `Record::$taxonomy_elements` and register taxonomy in `Schema.php`
4. Update import logic in `Admin.php` if special handling needed
5. Consider endpoint formatting in `Record::format_field_for_endpoint()`

#### Adding New REST API Endpoints

```php
// Always include these parameters:
register_rest_route(
    'namespace/version',
    '/route/(?P<param>[^/]+)',
    array(
        'methods'             => 'GET',
        'callback'            => array( $this, 'callback_method' ),
        'permission_callback' => '__return_true', // REQUIRED!
        'args'                => array(
            'param' => array(
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function( $param ) {
                    return ! empty( $param );
                },
            ),
        ),
    )
);
```

#### Modifying Admin Interface

1. **Always sanitize inputs:**
   ```php
   $value = isset( $_POST['field'] ) ? sanitize_text_field( wp_unslash( $_POST['field'] ) ) : '';
   ```

2. **Always verify nonces:**
   ```php
   check_admin_referer( 'action-name', 'nonce-name' );
   ```

3. **Always escape outputs:**
   ```php
   echo esc_html( $text );
   echo esc_url( $url );
   echo esc_attr( $attribute );
   ```

#### Security Checklist for All Changes

- [ ] All user inputs sanitized
- [ ] All outputs escaped
- [ ] Nonces verified for forms/AJAX
- [ ] Capability checks for privileged actions (`current_user_can()`)
- [ ] SQL queries use prepared statements (via WordPress functions)
- [ ] File uploads validated for type and size
- [ ] REST API endpoints have permission callbacks

### Code Style Guidelines

1. **Formatting**
   - Use tabs for indentation
   - Opening braces on same line for functions/classes
   - One statement per line

2. **WordPress Functions First**
   - Prefer `wp_date()` over `date()`
   - Use `wp_safe_redirect()` over `header()`
   - Use `add_query_arg()` for URL building

3. **Modern PHP**
   - Use `__DIR__` instead of `dirname(__FILE__)`
   - Type hints where possible (PHP 7.4+ features)
   - Array short syntax `[]` preferred over `array()`

4. **Documentation**
   - PHPDoc blocks for all classes and methods
   - `@since` tags to track when features added
   - Inline comments for complex logic

### Testing Requirements

When making changes, verify:

1. **Import Functionality**
   - Test XML import with sample PastPerfect export
   - Verify records created/updated correctly
   - Check metadata preservation

2. **REST API**
   - Test endpoints return proper JSON
   - Verify error handling (404, invalid params)
   - Check permission callbacks work

3. **Admin Interface**
   - All forms submit without errors
   - JavaScript/CSS loads correctly
   - No PHP warnings/notices in debug mode

4. **Compatibility**
   - Test with WordPress 5.0+ and 6.9
   - Verify PHP 7.4+ compatibility
   - Check Block Editor functionality

### Common Pitfalls to Avoid

❌ **Don't Do This:**
- Using deprecated WordPress functions
- Direct `$_POST`/`$_GET` access without sanitization
- Missing permission callbacks on REST routes
- Hardcoded file paths
- Direct SQL queries (use `$wpdb` with prepared statements)
- Missing text domain in translation functions

✅ **Do This Instead:**
- Check WordPress Core Trac for deprecated functions
- Always sanitize with `sanitize_*()` and escape with `esc_*()`
- Add `'permission_callback' => '__return_true'` (or custom function)
- Use `__DIR__`, `plugin_dir_path()`, `plugin_dir_url()`
- Use `get_posts()`, `wp_insert_post()`, `update_post_meta()`, etc.
- Use `__( 'text', 'wp-pastperfect' )`

### Performance Considerations

1. **Batch Operations**
   - Import processes in chunks (see `Admin::process_ajax_chunk()`)
   - Use transients for caching expensive queries
   - Avoid N+1 queries with `get_posts()` and proper args

2. **Asset Loading**
   - Only enqueue scripts/styles on relevant pages
   - Use `wp_register_*()` and conditional `wp_enqueue_*()`
   - Check page with `is_import_page()` pattern

3. **Database Queries**
   - Use `'fields' => 'ids'` when only IDs needed
   - Limit queries with `posts_per_page`
   - Use meta queries efficiently

## Version History

### v0.2.0 (2026-02-09)
- WordPress 6.9 compatibility
- PHP 7.4+ requirement
- REST API modernization with permission callbacks
- Security enhancements (sanitization, escaping, nonce verification)
- Block Editor support
- Deprecated function replacements
- Public taxonomies with REST API support

### v0.1-alpha (Original)
- Initial implementation for WordPress 5.3
- PHP 5.3 compatible
- Basic REST API endpoints
- PastPerfect XML import functionality
- Dublin Core metadata support

## External Dependencies

### WordPress Core Features Used
- Custom Post Types API
- Custom Taxonomies API
- REST API Framework
- Meta API (post_meta for Dublin Core fields)
- Admin Menu API
- AJAX API
- Options API (for import results)

### External Libraries
- XMLReader (PHP built-in) for parsing large XML files
- DOMDocument (PHP built-in) for XML processing
- SimpleXML (PHP built-in) for node handling

### Asset Dependencies
- jQuery (WordPress bundled)
- jQuery UI Progressbar (for import progress)

## Contact & Maintenance

**Plugin Name:** BHS Storehouse (PastPerfect WP)  
**Original Author:** Boone Gorges  
**Author URI:** https://boone.gorg.es  
**Plugin URI:** https://brooklynhistory.org  
**Text Domain:** wp-pastperfect  

For issues or questions:
- Check WordPress.org Plugin Repository guidelines
- Review WordPress Coding Standards
- Consult WordPress Developer Resources (developer.wordpress.org)

## Future Enhancement Suggestions

When working on this plugin, consider these potential improvements:

1. **Bulk Actions**: Add bulk edit/delete for records
2. **Search Integration**: Enhance WordPress search for Dublin Core fields
3. **Media Handling**: Improve attachment import and display
4. **Export Functionality**: Add ability to export records back to XML
5. **Advanced Filtering**: Filter records by Dublin Core fields in admin
6. **User Roles**: Custom capabilities for record management
7. **Internationalization**: Complete translation coverage
8. **Unit Tests**: Add PHPUnit tests for core functionality
9. **Documentation**: Add inline help text and tooltips
10. **Performance**: Add caching layer for frequently accessed records

## Agent Interaction Protocol

When an AI agent works on this plugin:

1. **Read this file first** to understand current state
2. **Check for WordPress updates** - verify no new deprecations
3. **Make incremental changes** - test after each modification
4. **Update version numbers** appropriately in:
   - `wp-pastperfect.php` (WPPP_VERSION constant and header)
   - `readme.txt` (Stable tag and changelog)
5. **Document changes** in readme.txt changelog
6. **Update this file** if architectural changes made
7. **Run compatibility checks** against current WordPress version
8. **Provide rollback information** if changes are significant

---

**Last Updated:** 2026-02-09  
**Document Version:** 1.0  
**Plugin Version:** 0.2.0  
**WordPress Compatibility:** 5.0 - 6.9  
**PHP Compatibility:** 7.4+
