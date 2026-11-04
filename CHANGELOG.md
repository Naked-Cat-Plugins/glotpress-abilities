#### 0.5.1 - 2026-11-04

* [FIX] Submitting a translation as plain text instead of a list no longer saves it empty as the current translation, and an empty translation is now refused with an error
* [DEV] Tested up to WordPress 7.2-beta3-65031

#### 0.5 - 2026-11-03

* [NEW] Create a GlotPress project and its translation sets, either from a list of locales or copied from another project
* [NEW] Change a GlotPress project's name, slug, description, source code link or active status, for example to retire a discontinued plugin
* [NEW] Edit or delete existing glossary entries, in the global glossary or a project's own one
* [NEW] Approve or reject waiting translations in batch, open to the project's validators as well as GlotPress administrators
* [NEW] Get strings in several statuses in one call, such as untranslated, fuzzy and waiting together
* [NEW] Waiting translations whose English text was reworded after they were submitted are flagged as stale, and can be listed on their own
* [NEW] List only the strings added or reworded since a date or since the last originals import, to translate just what changed in a release
* [FIX] Asking for strings with a combination of statuses that GlotPress cannot answer correctly now returns a clear error instead of every string in the project
* [FIX] Asking for strings without a status now returns the untranslated ones, as documented, instead of every string in the project
* [DEV] Tested up to WordPress 7.2-beta3-65182


#### 0.4 - 2026-09-30

* [NEW] `nakedcat-glotpress/add-glossary-entries` takes an optional `project_path`, to add terms to that project's own glossary instead of the locale's global one
* [FIX] Stop translating the plugin's own header data (`get_plugin_data()`) during `plugins_loaded`, which triggered a "translation loading too early" notice on WordPress 6.7+


#### 0.3 - 2026-07-30

* [NEW] `nakedcat-glotpress/import-originals` ability: import a project's originals (source strings) from a .pot/.po file's contents, reusing GlotPress's own import/diff/fuzzy-match/obsolete machinery; intended for release automation (e.g. a GitHub Actions workflow updating originals whenever a version is tagged)
* [NEW] `nakedcat-glotpress/update-translations` now automatically mirrors successful "pt" translation writes to a project's "pt-ao" (Portuguese, Angola) translation set, if one exists, always overwriting whatever is currently there; each result item's new `pt_ao_mirror` field reports the mirror outcome


#### 0.2 - 2026-07-30

* [NEW] `nakedcat-glotpress/add-glossary-entries` ability: add terms to a locale's global glossary in batch, without ever overwriting an existing term's translation


#### 0.1 - 2026-07-29

* [NEW] Initial release: `list-projects-translation-status`, `get-glossary`, `get-strings`, `update-translations`, and `find-translations-in-other-projects` abilities, exposed via the WordPress Abilities API and discoverable through the mcp-adapter plugin
* [NEW] GlotPress-admin-only permission model shared across all abilities
* [DEV] README with full ability schemas and instructions for connecting Claude Code via MCP
* [DEV] Release GitHub Actions (build-release-zip, delete-release), .distignore, .gitignore, .phpcs.xml.dist
