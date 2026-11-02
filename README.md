# GlotPress Abilities

Exposes GlotPress translation project data to AI agents and MCP clients via the WordPress
[Abilities API](https://make.wordpress.org/core/2025/09/17/introducing-the-abilities-api/)
(`wp_register_ability()`). Requires [GlotPress](https://wordpress.org/plugins/glotpress/) to be
active.

Every ability registered by this plugin is discoverable and callable through:

- WordPress core's own REST routes (`wp-abilities/v1/abilities`, list/run/categories controllers).
- Any MCP server that bridges the Abilities API, such as the
  [`mcp-adapter`](https://github.com/WordPress/mcp-adapter) plugin (abilities are tagged
  `meta.mcp.public => true`, `meta.mcp.type => 'tool'`).

## Permission model

**Every ability in this plugin requires the current user to be a GlotPress administrator.** This
is checked identically for all abilities (`includes/trait-requires-glotpress-admin.php`):

1. The user must be logged in.
2. The user must have GlotPress's own `admin` permission
   (`GP::$permission->current_user_can('admin')`).

This trusts GlotPress's own permission system alone, matching how GlotPress core's own routes
gate translation read/write actions — it deliberately does **not** also require the WordPress
`manage_options` capability. GlotPress's permission model exists precisely so a translation
coordinator can administer GlotPress (and use these abilities) without needing full WordPress
site administration rights.

The exceptions are `create-project`, `update-project` and `review-translations`. `create-project`
applies the same check as GlotPress's own "New project" screen instead: GlotPress `write` permission
on the parent project (`GP::$permission->current_user_can('write', 'project', $parent_id)`).
GlotPress administrators always have it; other users have it when they were given `write` on that
project or one of its ancestors. A top-level project needs GlotPress admin (or a global `write`
permission), as in the UI. `update-project` applies the project edit screen's check: GlotPress
`write` on the project itself, which anyone with `write` on its parent (or the project, or any
ancestor) has.

`review-translations` applies GlotPress's own review permission instead: `approve` on every
translation set the call touches (`GP::$permission->current_user_can('approve', 'translation-set',
$set_id)`), so the validators of a project, locale and set can review its translations without being
GlotPress administrators.

Every other ability, read or write, requires GlotPress admin.

## Connecting Claude to these abilities

This plugin only *registers* abilities — something has to bridge them to an actual MCP client.
The [`mcp-adapter`](https://github.com/WordPress/mcp-adapter) plugin does that: it's a separate,
official WordPress package (part of the *AI Building Blocks for WordPress* initiative) that
exposes every ability tagged `meta.mcp.public => true` (which all of this plugin's abilities are)
through a default MCP server via two built-in proxy tools, `discover-abilities` and
`execute-ability`.

### 1. Install and activate `mcp-adapter` on the WordPress site

WordPress 6.9+ already has the Abilities API in core, so no separate dependency is needed there.
Pick one:

- **As a plugin, from a release** (simplest): download the latest `mcp-adapter.zip` from the
  [GitHub Releases page](https://github.com/WordPress/mcp-adapter/releases/latest) and install it
  like any other WordPress plugin (Plugins → Add New → Upload Plugin, or drop the extracted folder
  into `wp-content/plugins/`), then activate it.
- **As a plugin, from source** (for local dev sites):
  ```bash
  git clone https://github.com/WordPress/mcp-adapter.git wp-content/plugins/mcp-adapter
  cd wp-content/plugins/mcp-adapter
  composer install
  ```
  then activate it (`wp plugin activate mcp-adapter`).
- **As a Composer dependency of your own plugin** (`composer require wordpress/mcp-adapter`) if
  you're bundling MCP support into another plugin rather than installing it standalone — see the
  package's own README for that path; not needed just to use this plugin's abilities.

Once active, confirm it sees this plugin's abilities:

```bash
wp mcp-adapter list
```

### 2. Point Claude Code at it

Two connection methods, depending on where the site runs.

**Local development sites (recommended here): STDIO via WP-CLI.** No authentication setup needed
beyond an existing WordPress admin user with GlotPress admin permission — the command runs as
that user directly.

```bash
claude mcp add --scope project glotpress-mcp -- wp --path=/absolute/path/to/wordpress mcp-adapter serve --server=mcp-adapter-default-server --user=<admin-username>
```

**Remote or non-CLI-accessible sites: native HTTP transport**, authenticated with a
[WordPress Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
for a user with GlotPress admin permission (Users → Profile → Application Passwords). The
default server's HTTP transport is a standard authenticated REST route (`current_user_can()`
behind the same auth WordPress's REST API always accepts), so Application Passwords work via
plain HTTP Basic Auth — no extra proxy needed:

```bash
AUTH=$(printf '%s:%s' 'your-username' 'xxxx xxxx xxxx xxxx xxxx xxxx' | base64)
claude mcp add --transport http --scope project glotpress-mcp \
  https://your-site.example/wp-json/mcp/mcp-adapter-default-server \
  --header "Authorization: Basic $AUTH"
```

`--scope project` stores the config in this repo's `.mcp.json` (shared with anyone working on this
checkout); use `--scope local` instead for a personal-only, per-project config, or `--scope user`
for a personal config available in every project.

**Alternative to either of the above: edit the MCP config file directly.** Both methods above end
up as an entry under an `"mcpServers"` key in a JSON config file (`.mcp.json` for
project/local scope, `~/.claude.json` for user scope, or Claude Desktop's own config file if
you're using that client instead of Claude Code). Editing that entry by hand works just as well as
`claude mcp add` and is often easier to copy between machines/setups. This uses the
[`@automattic/mcp-wordpress-remote`](https://www.npmjs.com/package/@automattic/mcp-wordpress-remote)
proxy (a local STDIO process that forwards to the site's REST endpoint), authenticated with a
WordPress Application Password for a user with GlotPress admin permission:

```json
{
  "mcpServers": {
    "glotpress-mcp": {
      "command": "npx",
      "args": [
        "-y",
        "@automattic/mcp-wordpress-remote@latest"
      ],
      "env": {
        "WP_API_URL": "http://your-site.example/wp-json/mcp/mcp-adapter-default-server",
        "LOG_FILE": "glotpress-mcp-adapter.log",
        "WP_API_USERNAME": "your-wordpress-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    }
  }
}
```

- `WP_API_URL` — always the default server's REST route, `/wp-json/mcp/mcp-adapter-default-server`
  on whichever site is running `mcp-adapter`.
- `WP_API_USERNAME` / `WP_API_PASSWORD` — a WordPress user with GlotPress admin permission, and
  that user's [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/)
  (Users → Profile → Application Passwords) — not their normal login password.
- `LOG_FILE` — optional; where the proxy process writes its own logs, useful when debugging a
  connection that won't come up.
- Rename the `"glotpress-mcp"` key to whatever you'd like the server to show up as in `claude mcp
  list` / `/mcp`.

### 3. Verify

```bash
claude mcp list          # should show glotpress-mcp as ✔ Connected
```

Then, in a Claude Code session connected to this MCP server, ask it to discover abilities (it
will call the `mcp-adapter-discover-abilities` tool) and confirm the twelve `nakedcat-glotpress/*`
abilities listed above appear.

## Abilities

| Ability | Read/write | Purpose |
|---|---|---|
| [`nakedcat-glotpress/list-projects-translation-status`](#nakedcat-glotpresslist-projects-translation-status) | read | Project + locale + translation-set overview with status counts |
| [`nakedcat-glotpress/get-glossary`](#nakedcat-glotpressget-glossary) | read | Project-scoped or global locale glossary entries |
| [`nakedcat-glotpress/get-strings`](#nakedcat-glotpressget-strings) | read | Strings (originals + their translation) for a project/locale, filtered by status |
| [`nakedcat-glotpress/update-translations`](#nakedcat-glotpressupdate-translations) | write | Create/update translations for a project/locale, in batch |
| [`nakedcat-glotpress/find-translations-in-other-projects`](#nakedcat-glotpressfind-translations-in-other-projects) | read | Cross-project translation-memory lookup for terminology consistency |
| [`nakedcat-glotpress/add-glossary-entries`](#nakedcat-glotpressadd-glossary-entries) | write | Add terms to a locale's global glossary, or to one project's glossary, in batch |
| [`nakedcat-glotpress/import-originals`](#nakedcat-glotpressimport-originals) | write | Import a project's originals (source strings) from a .pot/.po file's contents |
| [`nakedcat-glotpress/create-project`](#nakedcat-glotpresscreate-project) | write | Create a project and its translation sets, from locale slugs or a sibling project's sets |
| [`nakedcat-glotpress/update-project`](#nakedcat-glotpressupdate-project) | write | Change a project's name, slug, description, source URL template or active flag |
| [`nakedcat-glotpress/update-glossary-entries`](#nakedcat-glotpressupdate-glossary-entries) | write | Change existing glossary entries (translation, part of speech, comment) by ID, in batch |
| [`nakedcat-glotpress/delete-glossary-entries`](#nakedcat-glotpressdelete-glossary-entries) | write | Delete glossary entries by ID, in batch |
| [`nakedcat-glotpress/review-translations`](#nakedcat-glotpressreview-translations) | write | Approve or reject waiting translations, in batch |

The intended workflow for translating a project/locale: `get-strings` (find what needs work) →
`get-glossary` and/or `find-translations-in-other-projects` (gather context/consistency
references) → `update-translations` (submit the results) → optionally `add-glossary-entries` for
any new terminology worth capturing for next time, and `update-glossary-entries` or
`delete-glossary-entries` for entries that turned out wrong or outdated.

`import-originals` is a separate, earlier step in a project's lifecycle: it keeps a project's
*source strings* in sync with the plugin/theme's actual codebase (typically from release
automation), independent of any locale or translation work. `create-project` comes before both: create a new
plugin's project with the same translation sets as its siblings, then `import-originals` its `.pot`.

---

### `nakedcat-glotpress/list-projects-translation-status`

Returns GlotPress projects together with each locale's translation set and status counts
(current, fuzzy, waiting, untranslated, percent translated).

**Input** (all optional):

| Field | Type | Default | Description |
|---|---|---|---|
| `project_path` | string | – | Limit to this project (and, if `include_sub_projects` is true, its sub-projects) by GlotPress path, e.g. `wp-plugins/my-plugin`. Omit to list all projects. |
| `include_sub_projects` | boolean | `true` | Only relevant when `project_path` is set. Whether to also include that project's sub-projects. |
| `locale` | string | – | Limit translation sets to this GlotPress locale slug (e.g. `pt`, `pt-br`). Omit to include all locales. |
| `include_inactive_projects` | boolean | `false` | Whether to include inactive (disabled) GlotPress projects. |

**Output**: array of project objects:

| Field | Type | Description |
|---|---|---|
| `id` | integer | The project ID. |
| `name` | string | The project name. |
| `slug` | string | The project slug. |
| `path` | string | The full project path, e.g. `wp-plugins/my-plugin`. |
| `parent_project_id` | integer\|null | The parent project ID, or null for a top-level project. |
| `active` | boolean | Whether the project is active. |
| `translation_sets` | array | One entry per locale (and, if applicable, variant); see below. |

Each `translation_sets` entry:

| Field | Type | Description |
|---|---|---|
| `locale_slug` | string | The GlotPress locale slug, e.g. `pt`, `pt-br`. |
| `wp_locale` | string | The WordPress-style locale code, e.g. `pt_PT`, `pt_BR`. |
| `locale_english_name` | string | The English name of the locale. |
| `translation_set_slug` | string | The translation set's variant slug, e.g. `default`, `formal`. |
| `name_with_locale` | string | Display name combining the locale name and, if not the default variant, the set name. |
| `current_count` | integer | Number of current (approved) translations. |
| `untranslated_count` | integer | Number of untranslated originals. |
| `fuzzy_count` | integer | Number of fuzzy translations. |
| `waiting_count` | integer | Number of translations waiting for review. |
| `all_count` | integer | Total number of originals in the project. |
| `percent_translated` | integer | Percentage of originals with a current translation (0-100). |
| `last_modified` | string\|null | Date and time the translation set was last modified, or null if never. |

---

### `nakedcat-glotpress/get-glossary`

Returns glossary terms for a locale, either scoped to a specific project (falling back to a
parent project's glossary, as GlotPress's own translation editor does) or the locale-wide global
glossary when no project is given.

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `locale` | string | yes | – | The GlotPress locale slug, e.g. `pt`, `pt-br`. |
| `project_path` | string | no | – | Scope the glossary to this project. If it has no glossary of its own, its nearest parent project's glossary is returned instead. Omit to get the locale-wide global glossary. |
| `translation_set_slug` | string | no | `default` | The translation set variant slug, e.g. `default`, `formal`. |

**Output**: object:

| Field | Type | Description |
|---|---|---|
| `locale_slug` | string | The GlotPress locale slug. |
| `wp_locale` | string | The WordPress-style locale code, e.g. `pt_PT`. |
| `scope` | `"project"` \| `"global"` | Which scope was actually resolved. |
| `requested_project_path` | string\|null | The project path that was requested, or null for the global scope. |
| `glossary_owner_project_path` | string\|null | The project the returned glossary actually belongs to (may differ from `requested_project_path` when inherited from a parent). Null for the global scope or no glossary found. |
| `translation_set_slug` | string | The translation set variant slug used. |
| `glossary_id` | integer\|null | The glossary ID, or null if none exists yet for this scope. |
| `entries` | array | The glossary entries, ordered by term; see below. |

Each `entries` item:

| Field | Type | Description |
|---|---|---|
| `id` | integer | The entry ID, for `update-glossary-entries` and `delete-glossary-entries`. |
| `term` | string | The term or phrase. |
| `part_of_speech` | string | The grammatical part of speech, e.g. `noun`, `verb`. |
| `translation` | string | The required/suggested translation for this term. |
| `comment` | string | An optional comment giving context or usage notes for the term. |

This ability never auto-creates a glossary or translation set as a side effect (unlike some of
GlotPress's own internal finder methods) — it is strictly read-only.

---

### `nakedcat-glotpress/get-strings`

Returns a project/locale's strings (each original together with its current translation, if
any), filtered by status and paginated. Use this to find strings that need translating, or to
review already-translated strings for consistency.

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `project_path` | string | yes | – | The GlotPress project path, e.g. `wp-plugins/my-plugin`. |
| `locale` | string | yes | – | The GlotPress locale slug, e.g. `pt`, `pt-br`. |
| `translation_set_slug` | string | no | `default` | The translation set variant slug. |
| `status` | array of string, or string | no | `untranslated` | A list of statuses, or a single status (several joined with `_or_` work only in the combinations described below). |
| `stale_only` | boolean | no | `false` | Return only stale waiting translations (see below). Replaces the `status` filter. |
| `changed_since` | string | no |  | Only originals added or reworded since this date, e.g. `2026-10-01` or `2026-10-01 14:30:00` (GMT unless a timezone is given). See below. |
| `since_import` | boolean | no | `false` | Only the originals the most recent originals import into the project added or reworded. See below. |
| `page` | integer | no | `1` | The page of results to return, starting at 1. |
| `per_page` | integer | no | `50` (max `200`) | How many strings to return per page. |

**`status` values**: `current`, `waiting`, `rejected`, `fuzzy`, `old`, `changesrequested`,
`untranslated` (the original has no translation, or only rejected or old ones, as GlotPress counts
it).

**Several statuses at once: pass a list**, e.g. `["untranslated", "fuzzy", "waiting"]`. The
ability runs one GlotPress query per status and returns the strings in any of them, merged in the
order of the list, each with its `translation_status` (null for untranslated). `total` and the
pagination cover the merged result. An unknown status in the list rejects the call.

**String form**: a single status, e.g. `fuzzy`, always returns accurate results. A string can also
join statuses with `_or_` as GlotPress's own URLs do, but a combination that includes `untranslated`
and leaves out `current` (e.g. `untranslated_or_fuzzy`) is **refused** (`WP_Error`
`nakedcat_glotpress_unreliable_status_combination`, naming the list form to use): GlotPress's query
answers it with every string in the project. Combinations without `untranslated` (e.g.
`fuzzy_or_waiting`), or with `current` (e.g. `current_or_waiting_or_fuzzy_or_untranslated`, the
default of GlotPress's own translate editor), work as before.

**Output**: object:

| Field | Type | Description |
|---|---|---|
| `total` | integer | Total number of matching strings across all pages. |
| `page` | integer | The page of results returned. |
| `per_page` | integer | How many strings were returned per page. |
| `since` | string\|null | With `changed_since` or `since_import`: the GMT date originals were compared against (for `since_import`, when that import started). Null otherwise. |
| `strings` | array | The matching strings for this page; see below. |

Each `strings` item:

| Field | Type | Description |
|---|---|---|
| `original_id` | integer | The original string's ID. |
| `translation_id` | integer\|null | The translation row's ID, or null if untranslated. |
| `singular` | string | The singular source string. |
| `plural` | string\|null | The plural source string, or null if none. |
| `context` | string\|null | Disambiguation string for otherwise-identical originals used in different contexts. |
| `translations` | array of string\|null | The current translation's plural forms, trimmed to the locale's actual plural count. Empty if untranslated. |
| `translation_status` | string\|null | The translation's status, or null if untranslated. |
| `extracted_comments` | string | Developer/translator context comment for this string, if any. |
| `references` | array of string | Source locations (`file:line`) where this string is used, if known. |
| `priority` | integer | The original's priority: `-2` hidden, `-1` low, `0` normal, `1` high. |
| `stale` | boolean | True for a stale waiting translation (see below); always false for other statuses. |
| `change` | `"added"` \| `"changed"` \| null | With `changed_since` or `since_import`: whether the original was added or reworded since then. Null otherwise. |

**Stale waiting translations.** When originals are re-imported after their English wording
changed, GlotPress rewrites a reworded original in place (`originals_fuzzied` in
`import-originals`). A `current` translation of it is demoted to `fuzzy`, but a `waiting` one stays
`waiting` while describing text that no longer exists, and is easy to approve by mistake. Such a
translation is flagged `stale`: its original was reworded after the translation was submitted.
`stale_only` returns just those (they are always waiting, so the `status` input is ignored).
GlotPress keeps no modification date on originals, so this plugin records each rewording itself, as
GlotPress meta on the original (`nakedcat_reworded_at`), through GlotPress's own import hooks: only
rewordings since this version of the plugin was installed are known.

**What changed since the last release.** After a release, the translation pass only needs the
originals that are new or reworded. `changed_since` returns the originals added or reworded since
a date; `since_import` those added or reworded by the most recent originals import into the project
(from the moment that import started). Each string is marked `change: "added"` or `"changed"`
(`added` wins if both apply), and both work together with `status`, e.g. `since_import: true` with
`status: "untranslated"` for the new or reworded originals nobody has translated yet. Pass one or
the other, not both.

GlotPress keeps an added date on originals but no modification date, so this plugin records each
rewording itself (GlotPress meta `nakedcat_reworded_at` on the original) and each import's start and
end (`nakedcat_last_import` on the project), through GlotPress's own import hooks. Only rewordings
and imports since this version of the plugin was installed are known: `since_import` on a project
with no recorded import is refused, and `changed_since` then only sees added originals.

---

### `nakedcat-glotpress/update-translations`

Creates or updates translations for one or more originals in a project/locale, in a single
batched call (up to 100 items). Replicates GlotPress's own submission behaviour rather than
writing rows directly:

- Runs GlotPress's own translation-error checks (e.g. catching an unescaped `%` in a string with
  `sprintf`-style placeholders) before saving — items that fail are reported as errors, not saved.
- Detects an identical existing `current`/`waiting` translation and reports it as `unchanged`
  instead of creating a redundant row.
- Creates the row, validates it (deleting it again on validation failure, matching GlotPress's own
  route), then sets its final status — this is the step that actually fires the
  `gp_translation_saved` action other GlotPress integrations (e.g. `gp-convert-pt-ao90`) depend on.
- **Refuses the whole call** if `locale` is `pt-ao90` and the project also has a `pt` translation
  set while `gp-convert-pt-ao90` is active, since that plugin auto-syncs `pt-ao90` from `pt` on
  every save — direct writes there would just be overwritten. Submit to `pt` instead.
- **Automatically mirrors `pt` writes to `pt-ao`**, if the project has a `pt-ao` set. See below.

#### Automatic `pt` → `pt-ao` mirroring

This plugin maintains a Portuguese (Portugal) locale (`pt`) and a separate Portuguese (Angola)
locale (`pt-ao`, `wp_locale` `pt_AO`) for these products. Angola's Portuguese is meant to always
read identical to Portugal's, so there is no dialectal content worth maintaining separately.

Whenever a call's `locale` is **exactly** `pt` (never `pt-ao` itself, `pt-ao90`, `pt-br`, or
anything matched by prefix/substring) and the target project already has a `pt-ao` translation set
for the same `translation_set_slug`, every item whose `pt` write succeeds (`created` or
`unchanged`, not `error`) is automatically written to that project's `pt-ao` set too, using the
same `original_id`, `translation`, and `status` that were submitted for `pt`. If a project has no
`pt-ao` set, mirroring is silently skipped for it — most projects don't have one.

**The mirror always overwrites `pt-ao`, with no exceptions** — including replacing an existing
`current` translation there — regardless of any per-call behaviour that otherwise applies to the
locale actually requested. This is intentional: `pt-ao` is meant to be a pure mirror of `pt`, never
an independently-curated set.

This mirroring is **one-way only**. A direct call with `locale: "pt-ao"` writes only to `pt-ao`,
exactly as any other locale would, and never touches `pt`.

Each result item's `pt_ao_mirror` field reports what happened on the `pt-ao` side (`result`,
`translation_id`, `status`, `error_message`), or `null` if no mirroring was attempted for that item
(non-`pt` locale, no `pt-ao` set for the project, or the item's own `pt` write did not succeed).

**Don't confuse `pt-ao` (Angola) with `pt-ao90` (Portugal, post-1990-orthographic-agreement
spelling)** — the two slugs are unrelated other than both unfortunately starting with `pt-a`.
`pt-ao90` is handled by the separate, read-only guard described above (backed by the
`gp-convert-pt-ao90` plugin); this mirroring logic is independent of it and does not touch
`pt-ao90` in any way.

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `project_path` | string | yes | – | The GlotPress project path. |
| `locale` | string | yes | – | The GlotPress locale slug to submit translations for. |
| `translation_set_slug` | string | no | `default` | The translation set variant slug. |
| `translations` | array (1-100 items) | yes | – | The translations to submit; see below. |

Each `translations` item:

| Field | Type | Required | Description |
|---|---|---|---|
| `original_id` | integer | yes | The original string's ID (from `get-strings`). |
| `translation` | array of string (min 1) | yes | The translated plural forms, one per plural form actually needed. Extra entries beyond the locale's plural count are ignored. |
| `status` | `"current"` \| `"waiting"` \| `"fuzzy"` | yes | **No default — must be stated explicitly for every item.** `current` makes it the live, approved translation immediately (this plugin's admin-only permission model always allows this). Use `fuzzy` or `waiting` for a translation that should be flagged for human review instead. |

**Output**: array, one entry per input item, in order:

| Field | Type | Description |
|---|---|---|
| `original_id` | integer | The original this result is for. |
| `result` | `"created"` \| `"unchanged"` \| `"error"` | What happened. |
| `translation_id` | integer\|null | The resulting (or pre-existing, for `unchanged`) translation row ID, or null on error. |
| `status` | string\|null | The translation's resulting status, or null on error. |
| `error_message` | string\|null | Why this item failed, or null if it did not. |
| `pt_ao_mirror` | object\|null | The `pt` → `pt-ao` mirror outcome for this item; see below. Null if no mirroring was attempted. |

Each non-null `pt_ao_mirror` object:

| Field | Type | Description |
|---|---|---|
| `result` | `"created"` \| `"unchanged"` \| `"error"` | Same meaning as the outer `result`, but for the `pt-ao` mirror write. |
| `translation_id` | integer\|null | The resulting (or pre-existing, for `unchanged`) `pt-ao` translation row ID, or null on error. |
| `status` | string\|null | The `pt-ao` translation's resulting status, or null on error. |
| `error_message` | string\|null | Why the `pt-ao` mirror write failed, or null if it did not. |

One item failing does not abort the batch — only the `pt-ao90` guard rejects the whole call. A
`pt_ao_mirror` error on one item likewise does not abort the batch or affect that item's own `pt`
result.

---

### `nakedcat-glotpress/find-translations-in-other-projects`

For a locale, looks up how each given source string was already translated (**accepted/current
translations only**) in other GlotPress projects on this site. Useful for keeping terminology
consistent across projects before translating new strings. Matching is exact and case-sensitive
(GlotPress's own convention for identifying "the same string"). Only strings that have at least
one match elsewhere are included in the result — strings with no cross-project matches are
omitted entirely, to keep the response proportional to actual hits rather than input size.

**Input**:

| Field | Type | Required | Description |
|---|---|---|---|
| `locale` | string | yes | The GlotPress locale slug to look up translations for. |
| `strings` | array of string (1-100 items) | yes | The exact singular source strings to look up. |
| `exclude_project_path` | string | no | A project path to exclude from results, typically the project currently being translated. |

**Output**: array, one entry per string that had at least one match:

| Field | Type | Description |
|---|---|---|
| `singular` | string | The source string this result is for. |
| `matches` | array | Accepted translations of this string found in other projects; see below. |

Each `matches` item:

| Field | Type | Description |
|---|---|---|
| `project_path` | string | The other project's path. |
| `project_name` | string | The other project's name. |
| `translation` | array of string\|null | The accepted translation's plural forms, trimmed to the locale's actual plural count. |

---

### `nakedcat-glotpress/add-glossary-entries`

Adds one or more terms to a locale's **global** glossary (the locale-wide one) or, with
`project_path`, to that **project's own glossary**, in a single batched call (up to 50 items).

A project glossary is for the terms where one project has to deviate from the global glossary: a
plugin that implements a law, for example, where the law's official wording in the target language
decides. GlotPress's translation editor, and `get-glossary` with a `project_path`, give a project's
glossary precedence over the global one for that project.

The glossary row is created automatically if it doesn't exist yet, since (unlike `get-glossary`)
writing is this ability's entire purpose. For the global glossary, the underlying translation set
is created too. A project's translation set is not: it has to exist already, as for every other
ability. A write with `project_path` always lands on that project's own glossary, never on a
parent project's glossary that `get-glossary` would fall back to.

**Never overwrites an existing term.** If a submitted `(term, part_of_speech)` pair already exists
with the *same* translation, it's reported as `unchanged`. If it already exists with a *different*
translation, it's reported as an `error` rather than silently replacing it — this is stricter than
GlotPress's own stock duplicate check (which only catches an exact term+part-of-speech+translation
match, and would otherwise happily create a second, conflicting entry for the same term).

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `locale` | string | yes | – | The GlotPress locale slug, e.g. `pt`, `pt-br`. |
| `project_path` | string | no | – | The project whose own glossary to add to, e.g. `wp-plugins/my-plugin`. Omit for the global glossary. |
| `translation_set_slug` | string | no | `default` | The glossary's translation set variant slug. Almost always the default. |
| `entries` | array (1-50 items) | yes | – | The glossary entries to add; see below. |

Each `entries` item:

| Field | Type | Required | Description |
|---|---|---|---|
| `term` | string | yes | The source term or phrase, e.g. `checkout`. Plain readable text (letters/numbers/standard punctuation), must start and end with a letter or digit. By existing convention on this site, this is the English source term, not the translation. |
| `part_of_speech` | `noun` \| `verb` \| `adjective` \| `adverb` \| `interjection` \| `conjunction` \| `preposition` \| `pronoun` \| `expression` \| `abbreviation` | yes | The grammatical part of speech. |
| `translation` | string | yes | The required/suggested translation for this term in the target locale. |
| `comment` | string | no | Optional context or usage note for the term. |

**Output**: array, one entry per input item, in order:

| Field | Type | Description |
|---|---|---|
| `term` | string | The term this result is for. |
| `part_of_speech` | string | The part of speech this result is for. |
| `result` | `"created"` \| `"unchanged"` \| `"error"` | What happened. |
| `entry_id` | integer\|null | The resulting (or pre-existing, for `unchanged`) glossary entry ID, or null on error. |
| `error_message` | string\|null | Why this item failed (including the "already exists with a different translation" case), or null if it did not. |

---

### `nakedcat-glotpress/update-glossary-entries`

Changes existing glossary entries, addressed by the `id` that `get-glossary` returns, in a batch
of up to 50. The scope inputs are the same as `add-glossary-entries`: the locale's **global**
glossary by default, or with `project_path` that **project's own glossary** (never a parent's).
Every entry ID must belong to that glossary; an ID from any other glossary is reported as an
`error` and left alone. Nothing is created: a scope with no glossary rejects the whole call.

Only `translation`, `part_of_speech` and `comment` can change; fields left out keep their value.
The term itself cannot: a different term is a different entry, so add the new one and delete the
old one. A part of speech change that would give the entry the same term and part of speech as
another entry of the glossary is refused (the error names that entry's ID). Saves go through
GlotPress's own validation and `GP_Glossary_Entry::update()`, as the glossary screen does.

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `locale` | string | yes |  | The GlotPress locale slug, e.g. `pt`. |
| `project_path` | string | no |  | The project whose own glossary holds the entries. Omit for the global glossary. |
| `translation_set_slug` | string | no | `default` | The glossary's translation set variant slug. |
| `entries` | array (1-50 items) | yes |  | Each with `entry_id` (integer, required) and any of `translation`, `part_of_speech` (same values as `add-glossary-entries`) and `comment` (an empty string removes it). |

**Output**: array, one entry per input item, in order:

| Field | Type | Description |
|---|---|---|
| `entry_id` | integer | The entry this result is for. |
| `result` | `"updated"` \| `"unchanged"` \| `"error"` | What happened. `unchanged` when the entry already had the values submitted. |
| `term` | string\|null | The entry's term, or null if the entry was not found in the glossary. |
| `part_of_speech` | string\|null | The entry's part of speech after the call. |
| `translation` | string\|null | The entry's translation after the call. |
| `error_message` | string\|null | Why this item failed, or null if it did not. |

---

### `nakedcat-glotpress/delete-glossary-entries`

Deletes glossary entries by the `id` that `get-glossary` returns, up to 50 per call, with the
same scope inputs and the same rule as `update-glossary-entries`: an ID that is not in the
resolved glossary is reported as an `error` and nothing else is touched. Deletes go through
`GP_Glossary_Entry::delete()`, as the glossary screen does.

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `locale` | string | yes |  | The GlotPress locale slug, e.g. `pt`. |
| `project_path` | string | no |  | The project whose own glossary holds the entries. Omit for the global glossary. |
| `translation_set_slug` | string | no | `default` | The glossary's translation set variant slug. |
| `entry_ids` | array of integer (1-50 items) | yes |  | The entry IDs to delete. |

**Output**: array, one entry per input ID, in order, each with `entry_id`, `result` (`"deleted"`
or `"error"`), `term` (the deleted entry's term, or null if not found) and `error_message`.

Both abilities require GlotPress admin, like `add-glossary-entries`.

---

### `nakedcat-glotpress/import-originals`

Imports a project's **originals** (source strings, e.g. from `__()`/`_e()` calls) from the raw
contents of a `.pot` (or `.po`) file, diffing them against what the project already has. Reuses
GlotPress's own import machinery (`GP_Format_PO::read_originals_from_file()` +
`GP_Original::import_for_project()`, the same pair the GlotPress admin's own "Import Originals"
page and WP-CLI's `wp glotpress import-originals` command call) rather than writing rows directly:

- New strings (by `msgid`/`msgid_plural`/`msgctxt`) are **added**.
- Strings that already exist with the same key but a changed comment, reference, status, or
  `gp-priority:` flag are **updated** in place. A string that's unchanged is left alone entirely
  and isn't counted anywhere in the output — GlotPress's own import doesn't track that case either.
- Strings present before but missing from the new file are marked **obsolete** (not deleted; their
  translations are preserved, but they drop out of the active string list) — *unless* a new
  string is a close enough textual match (a similarity heuristic GlotPress applies internally), in
  which case it's treated as a **fuzzy** rename: the old original is updated to the new text and its
  existing current translations are marked fuzzy for re-review, instead of the rename being treated
  as one brand-new untranslated string plus one obsoleted one.
- Only `msgid`-side data is read; any `msgstr` content in the submitted file is ignored. This means
  a `.po` file with real translations in it behaves identically to a `.pot` template for this
  ability's purposes.

This ability is **locale-independent** — there's no `locale` or `translation_set_slug` input,
because originals belong to the project itself, not to any particular translation set.

**Intended use: release automation.** A GitHub Actions workflow can call this over the REST API
whenever a new version is tagged, submitting the freshly-built `.pot` file's contents so the
GlotPress project's source strings stay in sync with the codebase automatically, e.g.:

```yaml
- name: Update originals on GlotPress
  run: |
    JSON=$(jq -n --arg pot "$(cat languages/my-plugin.pot)" \
      '{input: {project_path: "wp-plugins/my-plugin", pot_content: $pot}}')
    curl -sf -u "${{ secrets.GLOTPRESS_USER }}:${{ secrets.GLOTPRESS_APP_PASSWORD }}" \
      -X POST "https://your-glotpress-site.example/wp-json/wp-abilities/v1/abilities/nakedcat-glotpress/import-originals/run" \
      -H "Content-Type: application/json" \
      -d "$JSON"
```

(WordPress's own Abilities API REST route expects the ability's input nested under a top-level
`"input"` key, as above, not as bare top-level JSON fields — this is a core REST convention, not
specific to this plugin. `jq -n --arg` handles the JSON-encoding of the `.pot` file's raw text
safely. The Application Password user needs GlotPress admin permission, same as every other
ability in this plugin.) An MCP client like Claude Code can call it the same way any other ability
here is called.

**Input**:

| Field | Type | Required | Description |
|---|---|---|---|
| `project_path` | string | yes | The GlotPress project path to import originals into, e.g. `wp-plugins/my-plugin`. |
| `pot_content` | string | yes | The full raw text contents of a `.pot` (or `.po`) file. |

**Output**: object:

| Field | Type | Description |
|---|---|---|
| `project_path` | string | The project this import targeted. |
| `originals_added` | integer | Number of brand-new originals created. |
| `originals_updated` | integer | Number of existing originals whose comment/references/status/priority changed and were updated in place. |
| `originals_fuzzied` | integer | Number of originals matched as a near-rename of a string no longer present; the old original was updated and its existing current translations marked fuzzy. |
| `originals_obsoleted` | integer | Number of existing originals no longer present in the file (and not matched as a rename) that were marked obsolete. |
| `originals_error` | integer | Number of new originals that failed to be created due to an error. |

Rejects the whole call (`WP_Error`) if `project_path` doesn't resolve to a project, if
`pot_content` is empty, or if `pot_content` can't be parsed as a valid PO/POT file — there's no
per-item result list here, since this is a single-file operation rather than a batch of items.

---

### `nakedcat-glotpress/create-project`

Creates a GlotPress project, optionally under a parent project, together with its translation
sets, through GlotPress's own API (`GP::$project->create_and_select()`,
`GP::$translation_set->create_and_select()`), so GlotPress's hooks and caches behave as in the UI.

The translation sets come from one of two inputs, never both:

- `locales`: a list of GlotPress locale slugs, each getting a `default` translation set named after
  the locale's English name.
- `copy_sets_from`: the path of an existing project, typically a sibling, whose sets (locale,
  variant slug and name) are created on the new project, the same way GlotPress's own "Mass-create
  translation sets" screen does it. Translations are not copied.

Omit both to create the project with no translation sets.

**Never overwrites anything.** Every check runs before anything is written, and the whole call is
rejected (`WP_Error`) when a project already exists at the resulting path, when
`parent_project_path` or `copy_sets_from` does not resolve to a project, or when any locale slug is
not a GlotPress locale (the error lists every unknown slug). If GlotPress fails to create one of the
sets, the project is deleted again, so a failed call never leaves a half-created project behind.

**Permission**: GlotPress `write` on the parent project, the same check as GlotPress's "New project"
screen (see [Permission model](#permission-model)), not GlotPress admin.
### `nakedcat-glotpress/review-translations`

Approves or rejects **waiting** translations in batch (up to 100 reviews), the way translations are
reviewed after a release. Each review goes through `GP_Translation::set_status()`, the same call
as the approve and reject buttons of GlotPress's translate screen: approving makes the translation
current and demotes that original's previous current, waiting, fuzzy and changes-requested
translations in the set to `old`, exactly as in the UI.

A review names its translation either by `translation_id` (as `get-strings` returns it), or by
`original_id` together with the top-level `project_path` and `locale`, which picks that original's
waiting translation in the set (refused when the original has several waiting translations, with
their IDs in the error).

**Only waiting translations are reviewed.** A translation in any other status (fuzzy, old,
current, rejected) is reported as an `error` and left untouched, so an old or fuzzy translation is
never approved by mistake.

**Rejection reason**: GlotPress core has no review feedback channel, so a `reason` given with a
rejection is kept as GlotPress meta on the translation (`nakedcat_rejection_reason`) and returned
in the result.

**Permission**: GlotPress `approve` on every translation set the call touches (see
[Permission model](#permission-model)), not GlotPress admin.

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `name` | string | yes |  | The project's display name. |
| `slug` | string | yes |  | The last segment of the project's path. Sanitized the way GlotPress does it (lowercase, no accents or special characters); the resulting path is returned. |
| `parent_project_path` | string | no |  | The existing project to create this one under, e.g. `wp-plugins`. Omit for a top-level project. |
| `description` | string | no |  | Project description, shown on the project page. |
| `source_url_template` | string | no |  | URL template for linking string references to the source code, with `%file%` and `%line%` placeholders. |
| `active` | boolean | no | `true` | Whether the project is active. |
| `locales` | array of string | no |  | Locale slugs to create a `default` set for, e.g. `["pt", "pt-ao90", "pt-br"]`. |
| `copy_sets_from` | string | no |  | The path of a project whose translation sets to copy, e.g. `wp-plugins/my-other-plugin`. |

**Output**: object:

| Field | Type | Description |
|---|---|---|
| `project_id` | integer | The new project's ID. |
| `project_path` | string | The new project's path, as used by every other ability. |
| `name` | string | The new project's name. |
| `active` | boolean | Whether the new project is active. |
| `translation_sets` | array | The sets created, each with `id`, `locale`, `slug` and `name`. |

---

### `nakedcat-glotpress/update-project`

Changes a project's settings, the ones GlotPress's project edit screen changes except the parent:
`name`, `slug`, `description`, `source_url_template` (so string references link to the
repository) and `active`. Fields left out keep their value. Saves go through `GP_Project::save()`,
like the edit screen, so GlotPress's hooks run.

- A **slug** change moves the project to a new path (its sub projects move with it), returned in
  `project_path` next to `previous_path`. It is refused (`WP_Error`, nothing changed) if another
  project already has the new path.
- **`active: false`** retires a project, for example a discontinued plugin's, without deleting its
  originals or translations: they stay readable through every ability.

**Permission**: GlotPress `write` on the project, as the edit screen requires (see
[Permission model](#permission-model)), not GlotPress admin.

**Input**:

| Field | Type | Required | Description |
|---|---|---|---|
| `project_path` | string | yes | The project to change, e.g. `wp-plugins/my-plugin`. |
| `name` | string | no | The new display name. |
| `slug` | string | no | The new slug, sanitized the way GlotPress does it. |
| `description` | string | no | The new description; an empty string removes it. |
| `source_url_template` | string | no | The new source URL template, with `%file%` and `%line%` placeholders; an empty string removes it. |
| `active` | boolean | no | Whether the project is active. |

**Output**: object with the project's settings after the call: `project_id`, `project_path`,
`previous_path`, `name`, `slug`, `description`, `source_url_template`, `active`, and
`changed_fields` (the fields this call actually changed; empty if everything already had the values
submitted).
| `project_path` | string | for `original_id` reviews |  | The project, e.g. `wp-plugins/my-plugin`. |
| `locale` | string | for `original_id` reviews |  | The locale slug, e.g. `pt`. |
| `translation_set_slug` | string | no | `default` | The translation set variant slug, for `original_id` reviews. |
| `reviews` | array (1-100 items) | yes |  | Each with `action` (`approve` or `reject`, required), `translation_id` or `original_id`, and an optional `reason` for rejections. |

**Output**: array, one entry per review, in order:

| Field | Type | Description |
|---|---|---|
| `translation_id` | integer\|null | The translation reviewed, or null if none was found. |
| `original_id` | integer\|null | Its original. |
| `result` | `"approved"` \| `"rejected"` \| `"error"` | What happened. |
| `status` | string\|null | The translation's status after the call. |
| `reason` | string\|null | The rejection reason kept with the translation, or null. |
| `error_message` | string\|null | Why this review failed, or null if it did not. |
