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
site administration rights. There is currently no ability with a lighter permission requirement
(e.g. for ordinary translators without GlotPress admin/validator status) — every ability, read or
write, requires GlotPress admin.

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
will call the `mcp-adapter-discover-abilities` tool) and confirm the six `nakedcat-glotpress/*`
abilities listed above appear.

## Abilities

| Ability | Read/write | Purpose |
|---|---|---|
| [`nakedcat-glotpress/list-projects-translation-status`](#nakedcat-glotpresslist-projects-translation-status) | read | Project + locale + translation-set overview with status counts |
| [`nakedcat-glotpress/get-glossary`](#nakedcat-glotpressget-glossary) | read | Project-scoped or global locale glossary entries |
| [`nakedcat-glotpress/get-strings`](#nakedcat-glotpressget-strings) | read | Strings (originals + their translation) for a project/locale, filtered by status |
| [`nakedcat-glotpress/update-translations`](#nakedcat-glotpressupdate-translations) | write | Create/update translations for a project/locale, in batch |
| [`nakedcat-glotpress/find-translations-in-other-projects`](#nakedcat-glotpressfind-translations-in-other-projects) | read | Cross-project translation-memory lookup for terminology consistency |
| [`nakedcat-glotpress/add-glossary-entries`](#nakedcat-glotpressadd-glossary-entries) | write | Add terms to a locale's global glossary, in batch |

The intended workflow for translating a project/locale: `get-strings` (find what needs work) →
`get-glossary` and/or `find-translations-in-other-projects` (gather context/consistency
references) → `update-translations` (submit the results) → optionally `add-glossary-entries` for
any new terminology worth capturing for next time.

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
| `status` | string | no | `untranslated` | A single translation status, or several joined with `_or_`. See caveat below. |
| `page` | integer | no | `1` | The page of results to return, starting at 1. |
| `per_page` | integer | no | `50` (max `200`) | How many strings to return per page. |

**`status` values**: `current`, `waiting`, `rejected`, `fuzzy`, `old`, `changesrequested`,
`untranslated` (the original has no translation row at all).

> **GlotPress quirk to be aware of:** a single status on its own (e.g. `untranslated`, `fuzzy`,
> `current`) always returns accurate results. But combining `untranslated` with any other status
> that is **not** `current` (e.g. `untranslated_or_fuzzy`) is unreliable in GlotPress's own query
> engine and will incorrectly include already-translated strings too. To fetch multiple
> categories of strings needing work, either make one call per status, or include `current` in
> the combination — `current_or_waiting_or_fuzzy_or_untranslated_or_changesrequested` is safe and
> is exactly what GlotPress's own translate editor uses by default.

**Output**: object:

| Field | Type | Description |
|---|---|---|
| `total` | integer | Total number of matching strings across all pages. |
| `page` | integer | The page of results returned. |
| `per_page` | integer | How many strings were returned per page. |
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

One item failing does not abort the batch — only the `pt-ao90` guard rejects the whole call.

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

Adds one or more terms to a locale's **global** glossary (the locale-wide one, not a
project-scoped one — this plugin's abilities don't currently expose writing to project-scoped
glossaries), in a single batched call (up to 50 items). The underlying global translation set and
glossary row are created automatically if they don't exist yet for the locale — this is the one
ability in the plugin that's expected to do that, since (unlike `get-glossary`) writing is its
entire purpose.

**Never overwrites an existing term.** If a submitted `(term, part_of_speech)` pair already exists
with the *same* translation, it's reported as `unchanged`. If it already exists with a *different*
translation, it's reported as an `error` rather than silently replacing it — this is stricter than
GlotPress's own stock duplicate check (which only catches an exact term+part-of-speech+translation
match, and would otherwise happily create a second, conflicting entry for the same term).

**Input**:

| Field | Type | Required | Default | Description |
|---|---|---|---|---|
| `locale` | string | yes | – | The GlotPress locale slug, e.g. `pt`, `pt-br`. |
| `translation_set_slug` | string | no | `default` | The global glossary's variant slug. Almost always the default. |
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
