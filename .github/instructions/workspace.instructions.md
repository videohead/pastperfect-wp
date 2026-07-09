---
description: "Use when working in this repository. Covers Lando, Pantheon, child theme location, secrets, CSS rules, and default repository workflow."
name: "Past Perfect Workspace Rules"
---

# Past Perfect Workspace Rules

- This is a WordPress version 7 plugin intended for PHP 8 and greater
- Use Lando for local WordPress workflows.
- Use `lando ...` for WordPress/appserver/WP-CLI work.
- Use `lando ssh --service cli --command "..."` for Node, npm, npx, Sass, Playwright, and Python (`python3`) work inside the CLI service.
- Revise any old PHP 5.6 - 7 code to PHP 8 syntax
- Node is for asset and browser-test tooling, not the PHP runtime.
- Do not hardcode secrets.
- Do not add inline CSS with `wp_add_inline_style()` or `<style>` tags.
- For Gutenberg editor parity work, update `theme.json` alongside Sass where appropriate; keep typography and block preset parity across templates.
- Save generated investigation, audit, and validation reports under `/documentation/reports/` instead of `tests/reports/`.


Execution context map:
- Host bash on the developer machine is for repo-local shell helpers and general tooling such as `git` and `lando`.
- `lando ...` is for WordPress, PHP, and appserver actions such as `lando wp ...`, `lando composer ...`, `lando php ...`, and `lando logs ...`.
- `lando ssh --service cli --command "cd /app && ..."` is for runtime commands that only exist in the CLI service, including `node`, `npm`, `npx`, `sass`, `playwright`, `python`, `python3`, and `rg`.
- `lando ssh --service appserver --command "..."` is for direct PHP/appserver shell access when `lando php ...` or `lando composer ...` is not the right fit.
- `lando ssh --service database --command "..."` is for direct database shell access and MySQL client commands.
- Do not run `lando start`, `lando stop`, or `lando rebuild` during an active agent session unless the user explicitly asks for one of those commands.
- Never assume `node`, `npm`, `npx`, `playwright`, `python`, `python3`, or `rg` are available from host bash.
- If a command starts with `node`, `npm`, `npx`, `sass`, `playwright`, `python`, `python3`, or `rg`, run it in the CLI service or through an existing wrapper script that already does that.

Quick routing rules:
- Need WordPress bootstrap or PHP code: use `lando ...`.
- Need browser tooling or front-end package commands: use `lando ssh --service cli --command "..."`.
- Need Python scripts (for test data/export tooling): use `lando ssh --service cli --command "..."`.
- Need direct appserver shell access: use `lando ssh --service appserver --command "..."`.
- Need direct database shell access: use `lando ssh --service database --command "..."`.
- Need a safe default for Playwright install or test runs: use `lando ssh --service cli --command "cd /app && npm exec playwright ..."` from host bash.

Useful commands:
- `lando start`
- `lando logs -s appserver -f`
- `lando logs -s appserver -f | grep 'WARNING\|PHP'`
- `lando ssh --service cli`
- `lando ssh --service cli --command "cd /app && npm exec playwright -- --version"`
- `lando ssh --service cli --command "cd /app && python3 --version"`
- `lando ssh --service appserver --command "php -v | head -n 1"`
- `lando ssh --service database --command "mysql --version"`
- `error_log(print_r($variable, true));`

Media Items
PastPerfect automatically assigns a file name to each attached image based on the record’s identifier, removing punctuation and replacing it with numbers pastperfect.zendesk.com.

For example, an Object ID 2003.2.2 becomes 200322.jpg for the first image, and 200322-2.jpg for the second pastperfect.zendesk.com.

These file names are generated so that the XML or import script can map the media file back to the record.

Implementation note (current plugin behavior):
- The importer now supports identifier-derived media discovery using this convention.
- Example: `2003.2.2` resolves to candidates like `200322.jpg`, `200322-2.jpg`, etc.
- During import/sync, files matching this pattern inside the configured media source directory are discovered and imported/mapped even when relation entries are missing.

Past Perfect uses a Data | Images | Multimedia directory structure. Users will need to map this into the uploads or maybe a temp directory structure.

FoxPro files such as DBF and FPT file can also be provided for sync
