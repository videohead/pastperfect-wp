# The intent of this file is to capture some of the rules about record creation so that they can be kept somewhat distinct from the pure import business logic. Capture RRHS specific rules here.

## Date Rules

### Format Standards
- Dates are singular Dublin Core elements (one date value per record).
- Acceptable formats include:
  - **Year only**: `1961`, `1920`
  - **Year with uncertainty**: `1950?`, `1958?`
  - **Full dates**: `April 8, 1905`, `June 20, 1992`
  - **Approximate dates**: `c. 1930's`, `1920's`
  - **Decade notation**: `1920's`
  - **Date ranges**: `1930-1940`, `1940-1950`, `1962-1963`, or multiple ranges: `1930-1940, 1955-1957`
  - **Not dated notation**: `n.d.`

### Parsing Rules
- No strict date validation or normalization is performed during import.
- Dates are stored as-is from the source XML without conversion to a standardized format.
- Date ranges may contain multiple year pairs separated by commas.
- Uncertainty markers (?) and approximate markers (c.) are preserved in the stored value.

### Handling Incomplete Dates
- Incomplete dates (e.g., year-only, partial ranges) are stored without modification.
- "Not dated" records are explicitly marked as `n.d.` in the date field.
- Missing or empty date values are skipped during record creation (field is optional).

## Name Rules

### Creator Field
- **Format**: Individual creator names, typically as full names or organization names (e.g., `Turner Photo`, `Melinda Broman`).
- **Multiplicity**: `creator` is a repeating element (array of values).
- **Parsing**: Multiple creator entries in source XML are collected into an array.
- **Empty values**: Single-character placeholders like `.` are treated as missing data and filtered out.

### Contributor Field
- **Format**: Individual contributor names, typically formatted as `Last, First` (e.g., `Garrett, Mike`) or as full values.
- **Multiplicity**: `contributor` is a repeating element (array of values).
- **Parsing**: Multiple contributor entries in source XML are collected into an array.
- **Empty values**: Single-character placeholders like `.` are treated as missing data and filtered out.

### General Name Handling
- Names are not normalized or deduplicated across records.
- Leading and trailing whitespace is trimmed from name values.
- Names containing special characters (e.g., `&`, `'`) may be XML-escaped in source files and are decoded during import.
- Empty name fields are skipped during record creation.

## Title Rules

### Format Standards
- `title` is a singular Dublin Core element in the schema (one per record).
- Title values are stored as plain text with no special formatting.
- Titles may contain special characters (e.g., `&`, `'`, numbers) which are XML-escaped in source files and decoded during import.

### Multiplicity and Selection
- Source XML files may contain multiple `<title>` entries per record.
- Only the **first title entry** is used for the WordPress post title (combined with identifier).
- Additional title entries are ignored during import.

### WordPress Post Title Generation
- The WordPress post title uses the title value directly (no identifier prefix).
- If title is missing, the post title defaults to the identifier.
- The post slug (URL-friendly name) is derived from the post title and sanitized.
- Example: `Sandy Beach, Monte Rio, California 2315`

### Single-Word Title Enhancement
- When a title is a single word (e.g., "photo", "photograph", "menu", "calendar"), it is enhanced with the first 12 characters from the description.
- The enhanced title format becomes: **`title (first 12 chars of description)`**
- Single-word titles include common archival item type descriptors.
- Example: If title is "photograph" and description is "Black and white photo...", the post title becomes: `photograph (Black and )`
- The Dublin Core metadata remains unchanged (description is NOT modified, only the post title display).
- If no description exists for a single-word title record, the title is used as-is.

### Special Cases
- Titles consisting only of a type designation (e.g., "postcard") when present as a second entry are skipped.
- Leading and trailing whitespace is trimmed from title values.
- Empty title values are skipped.

## Description Rules

### Format Standards
- `description` is a singular Dublin Core element (one per record).
- Descriptions are stored as plain text and become the WordPress post content.
- Descriptions may contain:
  - Multi-line text (line breaks are preserved as-is from source)
  - Special characters and punctuation (XML entities are decoded)
  - Detailed narrative text (e.g., "Many men and women sunbathing on Sandy Beach...")

### Line Break Handling
- Line breaks are encoded as `/n` in the XML source (non-standard encoding from PastPerfect).
- During import, `/n` sequences are converted to actual newline characters (`\n`).
- The converted descriptions are stored as post content with proper line breaks.

### WordPress Content Storage
- The first description value is used as the WordPress post content (post_content field).
- If description is missing, the post content defaults to empty string.
- Descriptions become searchable and indexed within WordPress.

### Special Character Handling
- XML entities are properly decoded (e.g., `&amp;` → `&`, `&quot;` → `"`, `&apos;` → `'`).
- HTML special characters within descriptions are preserved as text, not interpreted as markup.
- Leading and trailing whitespace is preserved in descriptions.

### Multiplicity Handling
- Source XML files typically contain one description per record.
- If multiple descriptions appear, only the first is used for post content.
- The first scalar value is extracted via `get_dc_metadata('description')` with single=true.

### Empty Values
- Empty or missing description values are skipped during record creation.
- Records without descriptions will have empty post content.

