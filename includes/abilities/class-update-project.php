<?php
/**
 * Ability: change a GlotPress project's settings.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/update-project ability.
 *
 * Changes what GlotPress's own project edit screen changes (name, slug, description, source URL
 * template, active), except the parent project. Saves go through GP_Project::save(), like that
 * screen, so a slug change also moves the paths of every sub project, and GlotPress's hooks run.
 *
 * Like nakedcat-glotpress/create-project, this is not restricted to GlotPress administrators: it
 * applies the edit screen's check, GlotPress "write" permission on the project, which GlotPress
 * grants to its administrators and to anyone given "write" on the project or one of its ancestors
 * (so whoever may create a project under a parent may also edit it).
 */
class Update_Project {

	const ABILITY_NAME = 'nakedcat-glotpress/update-project';

	const EDITABLE_FIELDS = array( 'name', 'slug', 'description', 'source_url_template', 'active' );

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Update GlotPress project', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Changes a GlotPress project\'s name, slug, description, source URL template and/or active flag. A slug change moves the project (and its sub projects) to a new path, which is returned; it is refused if another project already has that path. Setting active to false retires a project without deleting its strings or translations. Fields left out keep their value.', 'nakedcat-glotpress-abilities' ),
				'category'            => 'glotpress',
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
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
				'project_path'        => array(
					'type'        => 'string',
					'description' => __( 'The path of the project to change, e.g. "wp-plugins/my-plugin".', 'nakedcat-glotpress-abilities' ),
				),
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'The new display name.', 'nakedcat-glotpress-abilities' ),
				),
				'slug'                => array(
					'type'        => 'string',
					'description' => __( 'The new slug, the last segment of the path. Sanitized the way GlotPress does it; the resulting path is returned.', 'nakedcat-glotpress-abilities' ),
				),
				'description'         => array(
					'type'        => 'string',
					'description' => __( 'The new description. An empty string removes it.', 'nakedcat-glotpress-abilities' ),
				),
				'source_url_template' => array(
					'type'        => 'string',
					'description' => sprintf(
						/* translators: 1: file placeholder, 2: line placeholder, 3: example URL template. */
						__( 'The new URL template for linking string references to the source code, with %1$s and %2$s placeholders, e.g. "%3$s". An empty string removes it.', 'nakedcat-glotpress-abilities' ),
						'%file%',
						'%line%',
						'https://github.com/owner/repo/blob/main/%file%#L%line%'
					),
				),
				'active'              => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the project is active. False retires it: its strings and translations are kept and stay readable.', 'nakedcat-glotpress-abilities' ),
				),
			),
			'required'             => array( 'project_path' ),
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
				'project_id'          => array(
					'type'        => 'integer',
					'description' => __( 'The project\'s ID.', 'nakedcat-glotpress-abilities' ),
				),
				'project_path'        => array(
					'type'        => 'string',
					'description' => __( 'The project\'s path after this call (new if the slug changed).', 'nakedcat-glotpress-abilities' ),
				),
				'previous_path'       => array(
					'type'        => 'string',
					'description' => __( 'The project\'s path before this call.', 'nakedcat-glotpress-abilities' ),
				),
				'name'                => array(
					'type'        => 'string',
					'description' => __( 'The project\'s name.', 'nakedcat-glotpress-abilities' ),
				),
				'slug'                => array(
					'type'        => 'string',
					'description' => __( 'The project\'s slug.', 'nakedcat-glotpress-abilities' ),
				),
				'description'         => array(
					'type'        => 'string',
					'description' => __( 'The project\'s description.', 'nakedcat-glotpress-abilities' ),
				),
				'source_url_template' => array(
					'type'        => 'string',
					'description' => __( 'The project\'s source URL template.', 'nakedcat-glotpress-abilities' ),
				),
				'active'              => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the project is active.', 'nakedcat-glotpress-abilities' ),
				),
				'changed_fields'      => array(
					'type'        => 'array',
					'description' => __( 'The fields this call actually changed. Empty if everything already had the values submitted.', 'nakedcat-glotpress-abilities' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'project_id', 'project_path', 'previous_path', 'name', 'slug', 'description', 'source_url_template', 'active', 'changed_fields' ),
		);
	}

	/**
	 * Checks whether the current user may edit the project: GlotPress "write" on it, as the edit
	 * screen requires. A path that does not exist leaves only GlotPress administrators through, to
	 * receive execute()'s "not found" error.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return bool
	 */
	public static function check_permission( $input = array() ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$input   = is_array( $input ) ? $input : array();
		$project = isset( $input['project_path'] ) ? \GP::$project->by_path( (string) $input['project_path'] ) : null;

		if ( ! $project ) {
			return (bool) \GP::$permission->current_user_can( 'admin' );
		}

		return (bool) \GP::$permission->current_user_can( 'write', 'project', (int) $project->id );
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error The project's settings after the change, or WP_Error if the call is rejected.
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$project_path = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$project      = \GP::$project->by_path( $project_path );

		if ( ! $project ) {
			return new \WP_Error(
				'nakedcat_glotpress_project_not_found',
				sprintf(
					/* translators: %s: GlotPress project path. */
					__( 'No GlotPress project was found at path "%s".', 'nakedcat-glotpress-abilities' ),
					$project_path
				)
			);
		}

		$previous_path = (string) $project->path;
		$changes       = array();

		if ( isset( $input['name'] ) ) {
			$changes['name'] = trim( (string) $input['name'] );
		}

		if ( isset( $input['slug'] ) ) {
			$changes['slug'] = gp_sanitize_slug( (string) $input['slug'] );
		}

		if ( isset( $input['description'] ) ) {
			$changes['description'] = (string) $input['description'];
		}

		if ( isset( $input['source_url_template'] ) ) {
			$changes['source_url_template'] = (string) $input['source_url_template'];
		}

		if ( isset( $input['active'] ) ) {
			$changes['active'] = $input['active'] ? 1 : 0;
		}

		if ( ( isset( $changes['name'] ) && '' === $changes['name'] ) || ( isset( $changes['slug'] ) && '' === $changes['slug'] ) ) {
			return new \WP_Error(
				'nakedcat_glotpress_invalid_project',
				__( 'The name and slug cannot be empty, and the slug must keep at least one letter or digit once sanitized.', 'nakedcat-glotpress-abilities' )
			);
		}

		$changes = array_filter(
			$changes,
			function ( $value, $field ) use ( $project ) {
				return (string) $value !== (string) $project->$field;
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( isset( $changes['slug'] ) ) {
			$parent   = $project->parent_project_id ? \GP::$project->get( $project->parent_project_id ) : null;
			$new_path = $parent ? $parent->path . '/' . $changes['slug'] : $changes['slug'];
			$existing = \GP::$project->by_path( $new_path );

			if ( $existing && (int) $existing->id !== (int) $project->id ) {
				return new \WP_Error(
					'nakedcat_glotpress_project_exists',
					sprintf(
						/* translators: %s: GlotPress project path. */
						__( 'A GlotPress project already exists at path "%s". Nothing was changed.', 'nakedcat-glotpress-abilities' ),
						$new_path
					)
				);
			}
		}

		if ( $changes && ! $project->save( $changes ) ) {
			return new \WP_Error(
				'nakedcat_glotpress_project_save_failed',
				__( 'GlotPress could not save the project.', 'nakedcat-glotpress-abilities' )
			);
		}

		$project = \GP::$project->get( $project->id );

		return array(
			'project_id'          => (int) $project->id,
			'project_path'        => (string) $project->path,
			'previous_path'       => $previous_path,
			'name'                => (string) $project->name,
			'slug'                => (string) $project->slug,
			'description'         => (string) $project->description,
			'source_url_template' => (string) $project->source_url_template,
			'active'              => (bool) $project->active,
			'changed_fields'      => array_keys( $changes ),
		);
	}
}
