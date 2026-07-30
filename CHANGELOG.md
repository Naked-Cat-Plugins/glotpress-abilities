#### 0.4 - 2026-07-30

* [NEW] `nakedcat-glotpress/import-originals` ability — import a project's originals (source strings) from a .pot/.po file's contents, reusing GlotPress's own import/diff/fuzzy-match/obsolete machinery; intended for release automation (e.g. a GitHub Actions workflow updating originals whenever a version is tagged)


#### 0.3 - 2026-07-30

* [NEW] `nakedcat-glotpress/update-translations` now automatically mirrors successful "pt" translation writes to a project's "pt-ao" (Portuguese, Angola) translation set, if one exists, always overwriting whatever is currently there; each result item's new `pt_ao_mirror` field reports the mirror outcome


#### 0.2 - 2026-07-30

* [NEW] `nakedcat-glotpress/add-glossary-entries` ability — add terms to a locale's global glossary in batch, without ever overwriting an existing term's translation


#### 0.1 - 2026-07-29

* [NEW] Initial release: `list-projects-translation-status`, `get-glossary`, `get-strings`, `update-translations`, and `find-translations-in-other-projects` abilities, exposed via the WordPress Abilities API and discoverable through the mcp-adapter plugin
* [NEW] GlotPress-admin-only permission model shared across all abilities
* [DEV] README with full ability schemas and instructions for connecting Claude Code via MCP
* [DEV] Release GitHub Actions (build-release-zip, delete-release), .distignore, .gitignore, .phpcs.xml.dist
