<?php
/**
 * Ability: create a GlotPress project and its translation sets.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/create-project ability.
 *
 * Pairs with nakedcat-glotpress/import-originals: create a new plugin's project (with the same
 * translation sets as its siblings), then import its .pot file, without anyone visiting the
 * GlotPress admin UI.
 *
 * Unlike every other ability in this plugin, this one is not restricted to GlotPress
 * administrators. It applies the exact check GlotPress's own "New project" screen applies
 * (GP_Route_Project::new_post()): "write" permission on the parent project, which GlotPress grants
 * to its administrators, to anyone given "write" on that project or one of its ancestors, and, for
 * a top-level project, only to administrators (or a global "write" permission).
 *
 * Everything that can be refused is checked before anything is written: the path must be free,
 * the parent and copy_sets_from projects must exist, and every locale must be a GlotPress locale.
 * The project and its sets are then created through GlotPress's own GP_Project/GP_Translation_Set
 * API, so its hooks and caches behave as in the UI. If a set cannot be created, the project is
 * deleted again, so a failed call never leaves a half-created project behind.
 */
class Create_Project {

	const ABILITY_NAME = 'nakedcat-glotpress/create-project';

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Create GlotPress project', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Creates a GlotPress project, optionally under a parent project, together with its translation sets: either from an explicit list of locale slugs, or by copying the sets (locale, variant slug and name, but no translations) of another project, typically a sibling, so a new plugin gets the same sets as the others. Refuses when a project already exists at the resulting path, when the parent or copy_sets_from project does not exist, or when a locale is not a GlotPress locale; nothing is ever overwritten. Follow up with nakedcat-glotpress/import-originals to load the project\'s source strings.', 'nakedcat-glotpress-abilities' ),
				'category'            => 'glotpress',
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Builds the ability's input JSON schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'The project\'s display name, e.g. "InvoiceXpress Anonymous Delivery Notes".', 'nakedcat-glotpress-abilities' ),
				),
				'slug'                => array(
					'type'        => 'string',
					'description' => __( 'The project\'s slug, the last segment of its path, e.g. "woo-billing-with-invoicexpress-anonymous-guides". Sanitized the way GlotPress does it (lowercase, no accents or special characters); the resulting path is returned.', 'nakedcat-glotpress-abilities' ),
				),
				'parent_project_path' => array(
					'type'        => 'string',
					'description' => __( 'The path of the existing project to create this one under, e.g. "wp-plugins". Omit to create a top-level project.', 'nakedcat-glotpress-abilities' ),
				),
				'description'         => array(
					'type'        => 'string',
					'description' => __( 'Optional project description, shown on the project page.', 'nakedcat-glotpress-abilities' ),
				),
				'source_url_template' => array(
					'type'        => 'string',
					'description' => sprintf(
						/* translators: 1: file placeholder, 2: line placeholder, 3: example URL template. */
						__( 'Optional URL template for linking string references to the source code, with %1$s and %2$s placeholders, e.g. "%3$s".', 'nakedcat-glotpress-abilities' ),
						'%file%',
						'%line%',
						'https://github.com/owner/repo/blob/main/%file%#L%line%'
					),
				),
				'active'              => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the project is active (listed and open for translation).', 'nakedcat-glotpress-abilities' ),
					'default'     => true,
				),
				'locales'             => array(
					'type'        => 'array',
					'description' => __( 'GlotPress locale slugs to create a "default" translation set for, e.g. ["pt", "pt-ao90", "pt-br"]. Cannot be combined with copy_sets_from. Omit both to create the project with no translation sets.', 'nakedcat-glotpress-abilities' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'copy_sets_from'      => array(
					'type'        => 'string',
					'description' => __( 'The path of an existing project, typically a sibling, whose translation sets (locale, variant slug and name) are created on the new project. Translations are not copied. Cannot be combined with locales.', 'nakedcat-glotpress-abilities' ),
				),
			),
			'required'             => array( 'name', 'slug' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Builds the ability's output JSON schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'project_id'       => array(
					'type'        => 'integer',
					'description' => __( 'The new project\'s ID.', 'nakedcat-glotpress-abilities' ),
				),
				'project_path'     => array(
					'type'        => 'string',
					'description' => __( 'The new project\'s path, e.g. "wp-plugins/my-plugin", as used by every other ability.', 'nakedcat-glotpress-abilities' ),
				),
				'name'             => array(
					'type'        => 'string',
					'description' => __( 'The new project\'s name.', 'nakedcat-glotpress-abilities' ),
				),
				'active'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the new project is active.', 'nakedcat-glotpress-abilities' ),
				),
				'translation_sets' => array(
					'type'        => 'array',
					'description' => __( 'The translation sets created with the project, in the order they were created.', 'nakedcat-glotpress-abilities' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array(
								'type'        => 'integer',
								'description' => __( 'The translation set ID.', 'nakedcat-glotpress-abilities' ),
							),
							'locale' => array(
								'type'        => 'string',
								'description' => __( 'The GlotPress locale slug, e.g. "pt".', 'nakedcat-glotpress-abilities' ),
							),
							'slug'   => array(
								'type'        => 'string',
								'description' => __( 'The translation set variant slug, e.g. "default".', 'nakedcat-glotpress-abilities' ),
							),
							'name'   => array(
								'type'        => 'string',
								'description' => __( 'The translation set name.', 'nakedcat-glotpress-abilities' ),
							),
						),
						'required'   => array( 'id', 'locale', 'slug', 'name' ),
					),
				),
			),
			'required'   => array( 'project_id', 'project_path', 'name', 'active', 'translation_sets' ),
		);
	}

	/**
	 * Checks whether the current user may create a project under the requested parent.
	 *
	 * The same check GlotPress's "New project" screen applies: "write" on the parent project (null
	 * for a top-level project). A parent path that does not exist resolves to no ID, so only
	 * GlotPress administrators get past this to receive execute()'s "not found" error.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return bool
	 */
	public static function check_permission( $input = array() ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$input       = is_array( $input ) ? $input : array();
		$parent_path = isset( $input['parent_project_path'] ) ? trim( (string) $input['parent_project_path'] ) : '';
		$parent_id   = null;

		if ( '' !== $parent_path ) {
			$parent = \GP::$project->by_path( $parent_path );

			if ( ! $parent ) {
				return (bool) \GP::$permission->current_user_can( 'admin' );
			}

			$parent_id = (int) $parent->id;
		}

		return (bool) \GP::$permission->current_user_can( 'write', 'project', $parent_id );
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error The created project and its translation sets, or WP_Error if the call is rejected.
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$name           = isset( $input['name'] ) ? trim( (string) $input['name'] ) : '';
		$slug           = isset( $input['slug'] ) ? gp_sanitize_slug( (string) $input['slug'] ) : '';
		$parent_path    = isset( $input['parent_project_path'] ) ? trim( (string) $input['parent_project_path'] ) : '';
		$copy_sets_from = isset( $input['copy_sets_from'] ) ? trim( (string) $input['copy_sets_from'] ) : '';
		$locales        = isset( $input['locales'] ) && is_array( $input['locales'] ) ? array_values( array_unique( array_map( 'strval', $input['locales'] ) ) ) : array();
		$active         = isset( $input['active'] ) ? (bool) $input['active'] : true;

		if ( '' === $name || '' === $slug ) {
			return new \WP_Error(
				'nakedcat_glotpress_invalid_project',
				__( 'Both name and slug are required, and the slug must keep at least one letter or digit once sanitized.', 'nakedcat-glotpress-abilities' )
			);
		}

		if ( $locales && '' !== $copy_sets_from ) {
			return new \WP_Error(
				'nakedcat_glotpress_conflicting_sets_input',
				__( 'Pass either locales or copy_sets_from, not both.', 'nakedcat-glotpress-abilities' )
			);
		}

		$parent = null;

		if ( '' !== $parent_path ) {
			$parent = \GP::$project->by_path( $parent_path );

			if ( ! $parent ) {
				return self::project_not_found( $parent_path );
			}
		}

		$project_path = $parent ? $parent->path . '/' . $slug : $slug;

		if ( \GP::$project->by_path( $project_path ) ) {
			return new \WP_Error(
				'nakedcat_glotpress_project_exists',
				sprintf(
					/* translators: %s: GlotPress project path. */
					__( 'A GlotPress project already exists at path "%s". Nothing was changed.', 'nakedcat-glotpress-abilities' ),
					$project_path
				)
			);
		}

		$sets = '' !== $copy_sets_from ? self::sets_from_project( $copy_sets_from ) : self::sets_from_locales( $locales );

		if ( is_wp_error( $sets ) ) {
			return $sets;
		}

		$project = \GP::$project->create_and_select(
			new \GP_Project(
				array(
					'name'                => $name,
					'slug'                => $slug,
					'description'         => isset( $input['description'] ) ? (string) $input['description'] : '',
					'source_url_template' => isset( $input['source_url_template'] ) ? (string) $input['source_url_template'] : '',
					'active'              => $active ? 1 : 0,
					'parent_project_id'   => $parent ? (int) $parent->id : null,
				)
			)
		);

		if ( ! $project ) {
			return new \WP_Error(
				'nakedcat_glotpress_project_create_failed',
				__( 'GlotPress could not create the project.', 'nakedcat-glotpress-abilities' )
			);
		}

		$created_sets = array();

		foreach ( $sets as $set ) {
			$created = \GP::$translation_set->create_and_select( array_merge( $set, array( 'project_id' => $project->id ) ) );

			if ( ! $created ) {
				// Leave nothing half-created behind: this also deletes the sets created so far.
				$project->delete();

				return new \WP_Error(
					'nakedcat_glotpress_translation_set_create_failed',
					sprintf(
						/* translators: 1: GlotPress locale slug, 2: translation set variant slug. */
						__( 'GlotPress could not create the "%2$s" translation set for locale "%1$s". The project was not created.', 'nakedcat-glotpress-abilities' ),
						$set['locale'],
						$set['slug']
					)
				);
			}

			$created_sets[] = array(
				'id'     => (int) $created->id,
				'locale' => (string) $created->locale,
				'slug'   => (string) $created->slug,
				'name'   => (string) $created->name,
			);
		}

		return array(
			'project_id'       => (int) $project->id,
			'project_path'     => (string) $project->path,
			'name'             => (string) $project->name,
			'active'           => (bool) $project->active,
			'translation_sets' => $created_sets,
		);
	}

	/**
	 * Builds the translation sets to create from explicit locale slugs, all with the "default" variant.
	 *
	 * @param string[] $locales GlotPress locale slugs.
	 * @return array<int, array<string, string>>|\WP_Error Set fields (name, locale, slug), or WP_Error naming the unknown locales.
	 */
	private static function sets_from_locales( $locales ) {
		$sets    = array();
		$unknown = array();

		foreach ( $locales as $locale_slug ) {
			$locale = \GP_Locales::by_slug( $locale_slug );

			if ( ! $locale ) {
				$unknown[] = $locale_slug;
				continue;
			}

			$sets[] = array(
				'name'   => $locale->english_name,
				'locale' => $locale->slug,
				'slug'   => 'default',
			);
		}

		if ( $unknown ) {
			return new \WP_Error(
				'nakedcat_glotpress_locale_not_found',
				sprintf(
					/* translators: %s: comma-separated GlotPress locale slugs. */
					__( 'Not GlotPress locale slugs: %s. Nothing was created.', 'nakedcat-glotpress-abilities' ),
					implode( ', ', $unknown )
				)
			);
		}

		return $sets;
	}

	/**
	 * Builds the translation sets to create from another project's sets, the way GlotPress's own
	 * "mass create sets" screen does (name, locale and variant slug; no translations).
	 *
	 * @param string $project_path The source project path.
	 * @return array<int, array<string, string>>|\WP_Error Set fields (name, locale, slug), or WP_Error if the project does not exist.
	 */
	private static function sets_from_project( $project_path ) {
		$source = \GP::$project->by_path( $project_path );

		if ( ! $source ) {
			return self::project_not_found( $project_path );
		}

		$sets = array();

		foreach ( (array) \GP::$translation_set->by_project_id( $source->id ) as $set ) {
			$sets[] = array(
				'name'   => (string) $set->name,
				'locale' => (string) $set->locale,
				'slug'   => (string) $set->slug,
			);
		}

		return $sets;
	}

	/**
	 * Builds the "project not found" error used by every ability in this plugin.
	 *
	 * @param string $project_path The GlotPress project path that was not found.
	 * @return \WP_Error
	 */
	private static function project_not_found( $project_path ) {
		return new \WP_Error(
			'nakedcat_glotpress_project_not_found',
			sprintf(
				/* translators: %s: GlotPress project path. */
				__( 'No GlotPress project was found at path "%s".', 'nakedcat-glotpress-abilities' ),
				$project_path
			)
		);
	}
}
