<?php
/**
 * Ability: list GlotPress projects together with each locale's translation set and status.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/list-projects-translation-status ability.
 */
class List_Projects_Translation_Status {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;

	const ABILITY_NAME = 'nakedcat-glotpress/list-projects-translation-status';

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'List GlotPress projects, locales & translation status', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Returns GlotPress projects together with each locale\'s translation set and status counts (current, fuzzy, waiting, untranslated, percent translated).', 'nakedcat-glotpress-abilities' ),
				'category'            => 'glotpress',
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
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
				'project_path'              => array(
					'type'        => 'string',
					'description' => __( 'Limit results to this project (and, if include_sub_projects is true, its sub-projects) by GlotPress path, e.g. "wp-plugins/my-plugin". Omit to list all projects.', 'nakedcat-glotpress-abilities' ),
				),
				'include_sub_projects'      => array(
					'type'        => 'boolean',
					'description' => __( 'Only relevant when project_path is set. Whether to also include that project\'s sub-projects.', 'nakedcat-glotpress-abilities' ),
					'default'     => true,
				),
				'locale'                    => array(
					'type'        => 'string',
					'description' => __( 'Limit translation sets to this GlotPress locale slug (e.g. "pt", "pt-br"). Omit to include all locales.', 'nakedcat-glotpress-abilities' ),
				),
				'include_inactive_projects' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include inactive (disabled) GlotPress projects.', 'nakedcat-glotpress-abilities' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * Builds the ability's output JSON schema.
	 *
	 * @return array<string, mixed>
	 */
	private static function output_schema() {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'properties'           => array(
					'id'                => array(
						'type'        => 'integer',
						'description' => __( 'The project ID.', 'nakedcat-glotpress-abilities' ),
					),
					'name'              => array(
						'type'        => 'string',
						'description' => __( 'The project name.', 'nakedcat-glotpress-abilities' ),
					),
					'slug'              => array(
						'type'        => 'string',
						'description' => __( 'The project slug.', 'nakedcat-glotpress-abilities' ),
					),
					'path'              => array(
						'type'        => 'string',
						'description' => __( 'The full project path, e.g. "wp-plugins/my-plugin".', 'nakedcat-glotpress-abilities' ),
					),
					'parent_project_id' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The parent project ID, or null for a top-level project.', 'nakedcat-glotpress-abilities' ),
					),
					'active'            => array(
						'type'        => 'boolean',
						'description' => __( 'Whether the project is active.', 'nakedcat-glotpress-abilities' ),
					),
					'translation_sets'  => array(
						'type'        => 'array',
						'description' => __( 'The project\'s translation sets, one per locale (and, if applicable, variant).', 'nakedcat-glotpress-abilities' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'locale_slug'          => array(
									'type'        => 'string',
									'description' => __( 'The GlotPress locale slug, e.g. "pt", "pt-br".', 'nakedcat-glotpress-abilities' ),
								),
								'wp_locale'            => array(
									'type'        => 'string',
									'description' => __( 'The WordPress-style locale code, e.g. "pt_PT", "pt_BR".', 'nakedcat-glotpress-abilities' ),
								),
								'locale_english_name'  => array(
									'type'        => 'string',
									'description' => __( 'The English name of the locale.', 'nakedcat-glotpress-abilities' ),
								),
								'translation_set_slug' => array(
									'type'        => 'string',
									'description' => __( 'The translation set\'s variant slug, e.g. "default", "formal".', 'nakedcat-glotpress-abilities' ),
								),
								'name_with_locale'     => array(
									'type'        => 'string',
									'description' => __( 'A display name combining the locale name and, if not the default variant, the set name.', 'nakedcat-glotpress-abilities' ),
								),
								'current_count'        => array(
									'type'        => 'integer',
									'description' => __( 'Number of current (approved) translations.', 'nakedcat-glotpress-abilities' ),
								),
								'untranslated_count'   => array(
									'type'        => 'integer',
									'description' => __( 'Number of untranslated originals.', 'nakedcat-glotpress-abilities' ),
								),
								'fuzzy_count'          => array(
									'type'        => 'integer',
									'description' => __( 'Number of fuzzy translations.', 'nakedcat-glotpress-abilities' ),
								),
								'waiting_count'        => array(
									'type'        => 'integer',
									'description' => __( 'Number of translations waiting for review.', 'nakedcat-glotpress-abilities' ),
								),
								'all_count'            => array(
									'type'        => 'integer',
									'description' => __( 'Total number of originals in the project.', 'nakedcat-glotpress-abilities' ),
								),
								'percent_translated'   => array(
									'type'        => 'integer',
									'description' => __( 'Percentage of originals with a current translation (0-100).', 'nakedcat-glotpress-abilities' ),
								),
								'last_modified'        => array(
									'type'        => array( 'string', 'null' ),
									'description' => __( 'Date and time the translation set was last modified, or null if never.', 'nakedcat-glotpress-abilities' ),
								),
							),
						),
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error Array of projects with their translation sets, or WP_Error on failure.
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$project_path              = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$include_sub_projects      = ! isset( $input['include_sub_projects'] ) || (bool) $input['include_sub_projects'];
		$locale_filter             = isset( $input['locale'] ) ? (string) $input['locale'] : '';
		$include_inactive_projects = isset( $input['include_inactive_projects'] ) && (bool) $input['include_inactive_projects'];

		$projects = self::get_projects( $project_path, $include_sub_projects );

		if ( is_wp_error( $projects ) ) {
			return $projects;
		}

		$result = array();

		foreach ( $projects as $project ) {
			if ( ! $include_inactive_projects && ! $project->active ) {
				continue;
			}

			$result[] = self::build_project_data( $project, $locale_filter );
		}

		return $result;
	}

	/**
	 * Resolves the list of projects to report on.
	 *
	 * @param string $project_path         Optional project path to scope to.
	 * @param bool   $include_sub_projects Whether to include sub-projects when a project_path is given.
	 * @return \GP_Project[]|\WP_Error
	 */
	private static function get_projects( $project_path, $include_sub_projects ) {
		if ( '' === $project_path ) {
			return \GP::$project->all();
		}

		$root = \GP::$project->by_path( $project_path );

		if ( ! $root ) {
			return new \WP_Error(
				'nakedcat_glotpress_project_not_found',
				sprintf(
					/* translators: %s: GlotPress project path. */
					__( 'No GlotPress project was found at path "%s".', 'nakedcat-glotpress-abilities' ),
					$project_path
				)
			);
		}

		if ( ! $include_sub_projects ) {
			return array( $root );
		}

		return array_merge( array( $root ), $root->inclusive_sub_projects() );
	}

	/**
	 * Builds the output array for a single project.
	 *
	 * @param \GP_Project $project       The project.
	 * @param string      $locale_filter Optional GlotPress locale slug to limit translation sets to.
	 * @return array<string, mixed>
	 */
	private static function build_project_data( $project, $locale_filter ) {
		$translation_sets = \GP::$translation_set->by_project_id( $project->id );

		$sets_data = array();

		foreach ( $translation_sets as $set ) {
			if ( '' !== $locale_filter && $locale_filter !== $set->locale ) {
				continue;
			}

			$locale = \GP_Locales::by_slug( $set->locale );

			$last_modified = $set->last_modified();

			$sets_data[] = array(
				'locale_slug'          => $set->locale,
				'wp_locale'            => $locale ? $locale->wp_locale : '',
				'locale_english_name'  => $locale ? $locale->english_name : '',
				'translation_set_slug' => $set->slug,
				'name_with_locale'     => $set->name_with_locale(),
				'current_count'        => (int) $set->current_count(),
				'untranslated_count'   => (int) $set->untranslated_count(),
				'fuzzy_count'          => (int) $set->fuzzy_count(),
				'waiting_count'        => (int) $set->waiting_count(),
				'all_count'            => (int) $set->all_count(),
				'percent_translated'   => (int) $set->percent_translated(),
				'last_modified'        => $last_modified ? $last_modified : null,
			);
		}

		return array(
			'id'                => (int) $project->id,
			'name'              => $project->name,
			'slug'              => $project->slug,
			'path'              => $project->path,
			'parent_project_id' => $project->parent_project_id ? (int) $project->parent_project_id : null,
			'active'            => (bool) $project->active,
			'translation_sets'  => $sets_data,
		);
	}
}
