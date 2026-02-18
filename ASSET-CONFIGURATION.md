# Asset & Image Configuration Guide

## Overview

The PastPerfect plugin stores references to images and attachments in the Dublin Core `relation_image` and `relation_attachment` fields. These fields contain **filenames only** (not full paths), and the plugin constructs full URLs using a configurable base URL.

**Important:** The plugin automatically converts periods (.) in identifiers to hyphens (-) for URL compatibility, following WordPress naming conventions. For example:
- XML: `<relation_image>98.3.6.jpg</relation_image>`
- Converted to: `98-3-6.jpg`
- Final URL: `https://example.com/assets/98-3-6.jpg`

## How Asset URLs Work

**Storage Pattern:**
```
XML: <relation_image>98.3.6.jpg</relation_image>
Converted: 98-3-6.jpg (periods → hyphens)
Final URL: [BASE_URL]/98-3-6.jpg
```

**Example:**
```
Base URL: https://example.com/assets/
Filename: 98.3.6.jpg (from XML)
Sanitized: 98-3-6.jpg (automatic conversion)
Result: https://example.com/assets/98-3-6.jpg
```

## Configuration Options

### Option 1: Local WordPress Uploads (Simple)

Store all images directly in your WordPress uploads directory without date-based folders.

**Step 1:** Add to `wp-config.php`:
```php
// Disable date-based upload folders
define( 'UPLOADS', 'wp-content/uploads/pastperfect-assets' );

// Set asset base URL to match
define( 'WPPP_ASSET_BASE_URL', home_url() . '/wp-content/uploads/pastperfect-assets/' );
```

**Step 2:** Create the directory:
```bash
mkdir -p wp-content/uploads/pastperfect-assets
chmod 755 wp-content/uploads/pastperfect-assets
```

**Step 3:** Upload your images to:
```
wp-content/uploads/pastperfect-assets/
├── 98-3-6.jpg
├── 2014-2.jpg
├── 2014-3-1.jpg
└── ...
```

**Pros:**
- ✅ Simple setup
- ✅ Fast access
- ✅ No external dependencies
- ✅ Works offline

**Cons:**
- ❌ Images stored locally (not cloud)
- ❌ Backup responsibility on you

---

### Option 2: Amazon S3 (Current Default)

The plugin currently defaults to S3 (legacy from Brooklyn Historical Society).

**Configuration:**
```php
// In wp-config.php
define( 'WPPP_ASSET_BASE_URL', 'https://s3.amazonaws.com/your-bucket-name/' );
```

**File Structure:**
```
your-bucket-name/
├── 98.3.6.jpg
├── 2014.2.jpg
└── ...
```

**Pros:**
- ✅ Cloud storage
- ✅ High availability
- ✅ CDN integration

**Cons:**
- ❌ AWS account required
- ❌ Costs money
- ❌ More complex setup

---

### Option 3: Google Drive (Requires Plugin)

Google Drive can work but requires special handling. Google Drive doesn't provide direct file URLs like S3, so you'll need a plugin or custom solution.

#### Option 3A: WP File Download + Google Drive

**Step 1:** Install [WP File Download](https://www.joomunited.com/wordpress-products/wp-file-download) (Premium)
- This plugin syncs Google Drive folders to WordPress
- Provides direct download/view URLs

**Step 2:** Configure in `wp-config.php`:
```php
define( 'WPPP_ASSET_BASE_URL', home_url() . '/wp-file-download/google-drive-folder/' );
```

#### Option 3B: Google Drive Direct Links (Manual)

Create public shareable links for each file, then map them.

**Issues:**
- Google Drive URLs look like: `https://drive.google.com/file/d/FILE_ID/view`
- Not compatible with simple filename mapping
- Would require custom lookup table

**Not Recommended** - Too complex for this use case.

#### Option 3C: Google Cloud Storage (Better Alternative)

Use Google Cloud Storage instead of Google Drive:

```php
define( 'WPPP_ASSET_BASE_URL', 'https://storage.googleapis.com/your-bucket-name/' );
```

**File Structure:**
```
your-bucket-name/
├── 98.3.6.jpg
├── 2014.2.jpg
└── ...
```

**Pros:**
- ✅ Google ecosystem
- ✅ Direct public URLs
- ✅ Similar to S3

**Cons:**
- ❌ Requires GCP account
- ❌ Costs money

---

### Option 4: External Server / CDN

Point to any web-accessible server:

```php
define( 'WPPP_ASSET_BASE_URL', 'https://cdn.example.com/pastperfect/' );
```

---

## Disabling WordPress Date-Based Folders

By default, WordPress organizes uploads into date-based folders (`/2024/02/file.jpg`). To disable this:

### Method 1: WordPress Admin UI
1. Go to **Settings → Media**
2. Uncheck **"Organize my uploads into month- and year-based folders"**
3. Click **Save Changes**

### Method 2: wp-config.php (Programmatic)
```php
// Add to wp-config.php
add_filter( 'upload_dir', function( $uploads ) {
    // Remove date-based structure
    $uploads['subdir'] = '';
    $uploads['path']   = $uploads['basedir'];
    $uploads['url']    = $uploads['baseurl'];
    return $uploads;
}, 10, 1 );
```

### Method 3: Plugin-Specific Filter
Create a custom plugin or add to theme's `functions.php`:

```php
add_filter( 'wppp_asset_base_url', function( $base_url ) {
    // Override with your custom path
    return home_url() . '/wp-content/uploads/pastperfect-assets/';
} );
```

---

## File Naming Convention

Your image files should be named using the PastPerfect identifier **with periods replaced by hyphens** (WordPress URL convention):

```
XML Identifier → Filename
98.3.6        → 98-3-6.jpg
2014.2        → 2014-2.jpg
2014.3.1      → 2014-3-1.jpg
```

**Important Notes:**
- Periods (.) in identifiers are automatically converted to hyphens (-) for URL compatibility
- The plugin handles this conversion automatically at runtime
- You don't need to store the original period-based names
- File extension period is preserved (e.g., `.jpg`)

**Examples:**
```
Identifier: 98.3.6
XML: <relation_image>98.3.6.jpg</relation_image>
File on disk: 98-3-6.jpg
Final URL: https://example.com/assets/98-3-6.jpg
```

The plugin extracts the basename only, so paths in XML are ignored:
```
C:\Photos\Archive\98.3.6.jpg  → 98-3-6.jpg
/mnt/files/2014.2.jpg         → 2014-2.jpg
```

---

## Recommended Setup for Your Use Case

Based on your requirements (no date folders, simple management):

### **Option: Local WordPress Storage**

```php
// Add to wp-config.php
define( 'WPPP_ASSET_BASE_URL', home_url() . '/wp-content/uploads/pastperfect-assets/' );
```

```bash
# Create directory
mkdir -p wp-content/uploads/pastperfect-assets
chmod 755 wp-content/uploads/pastperfect-assets

# Upload all images at once
# Structure: flat directory, filename = identifier (with hyphens) + extension
cp /path/to/your/images/*.jpg wp-content/uploads/pastperfect-assets/
```

**Access via URL:**
```
https://yoursite.com/wp-content/uploads/pastperfect-assets/98-3-6.jpg
```

---

## Testing Your Configuration

After configuring, test with:

```bash
# Check if asset base is set correctly
wp eval 'echo (new \WP\PastPerfect\Record())->convert_filename_to_asset_path("test.jpg");'

# Expected output:
# https://yoursite.com/wp-content/uploads/pastperfect-assets/test.jpg

# Test with period conversion
wp eval 'echo (new \WP\PastPerfect\Record())->convert_filename_to_asset_path("98-3-6.jpg");'

# Expected output:
# https://yoursite.com/wp-content/uploads/pastperfect-assets/98-3-6.jpg
```

Or view a record in WordPress admin and check if images display correctly in the meta box.

---

## Troubleshooting

### Images Not Displaying
1. Check `WPPP_ASSET_BASE_URL` constant value
2. Verify files exist in the target directory
3. Check file permissions (should be 644)
4. Check directory permissions (should be 755)
5. View page source to see actual URL WordPress is generating

### Wrong Base URL
Use the filter to override:
```php
add_filter( 'wppp_asset_base_url', function() {
    return 'https://correct-url.com/path/';
} );
```

### Mixed Environments
Different base URLs for dev/staging/production:
```php
// In wp-config.php
if ( defined( 'WP_ENV' ) && 'production' === WP_ENV ) {
    define( 'WPPP_ASSET_BASE_URL', 'https://cdn.production.com/assets/' );
} else {
    define( 'WPPP_ASSET_BASE_URL', home_url() . '/wp-content/uploads/pastperfect-assets/' );
}
```

---

## Summary

**Simplest Solution:**
1. Create `wp-content/uploads/pastperfect-assets/`
2. Add constant to `wp-config.php`
3. Upload all images with identifier-based filenames
4. No date folders, no cloud dependencies

**For Cloud Storage:**
- Use S3 or Google Cloud Storage (not Google Drive)
- Configure public bucket with flat structure
- Update `WPPP_ASSET_BASE_URL` constant
