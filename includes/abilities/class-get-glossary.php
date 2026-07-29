<?php
/**
 * Ability: get the glossary for a project/locale, or the global glossary for a locale.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/get-glossary ability.
 */
class Get_Glossary {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;

	const ABILITY_NAME = 'nakedcat-glotpress/get-glossary';

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get GlotPress glossary', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Returns glossary terms for a locale, either scoped to a specific project (falling back to a parent project\'s glossary, as GlotPress\'s own translation editor does) or the locale-wide global glossary when no project is given. Intended to supply glossary context before translating or updating strings.', 'nakedcat-glotpress-abilities' ),
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
				'locale'               => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress locale slug to get the glossary for, e.g. "pt", "pt-br".', 'nakedcat-glotpress-abilities' ),
				),
				'project_path'         => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress project path to scope the glossary to, e.g. "wp-plugins/my-plugin". If the project has no glossary of its own, its nearest parent project\'s glossary is returned instead (matching GlotPress\'s own translation editor). Omit to get the locale-wide global glossary instead.', 'nakedcat-glotpress-abilities' ),
				),
				'translation_set_slug' => array(
					'type'        => 'string',
					'description' => __( 'The translation set variant slug, e.g. "default", "formal".', 'nakedcat-glotpress-abilities' ),
					'default'     => 'default',
				),
			),
			'required'             => array( 'locale' ),
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
			'type'                 => 'object',
			'properties'           => array(
				'locale_slug'                 => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress locale slug.', 'nakedcat-glotpress-abilities' ),
				),
				'wp_locale'                   => array(
					'type'        => 'string',
					'description' => __( 'The WordPress-style locale code, e.g. "pt_PT".', 'nakedcat-glotpress-abilities' ),
				),
				'scope'                       => array(
					'type'        => 'string',
					'enum'        => array( 'project', 'global' ),
					'description' => __( '"project" when project_path was given, "global" when the locale-wide glossary was requested.', 'nakedcat-glotpress-abilities' ),
				),
				'requested_project_path'      => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The project path that was requested, or null for the global scope.', 'nakedcat-glotpress-abilities' ),
				),
				'glossary_owner_project_path' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'The project path the returned glossary actually belongs to. May differ from requested_project_path when inherited from a parent project. Null for the global scope or when no glossary was found.', 'nakedcat-glotpress-abilities' ),
				),
				'translation_set_slug'        => array(
					'type'        => 'string',
					'description' => __( 'The translation set variant slug used.', 'nakedcat-glotpress-abilities' ),
				),
				'glossary_id'                 => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'The glossary ID, or null if no glossary exists yet for this scope.', 'nakedcat-glotpress-abilities' ),
				),
				'entries'                     => array(
					'type'        => 'array',
					'description' => __( 'The glossary entries, ordered by term.', 'nakedcat-glotpress-abilities' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'term'           => array(
								'type'        => 'string',
								'description' => __( 'The term or phrase.', 'nakedcat-glotpress-abilities' ),
							),
							'part_of_speech' => array(
								'type'        => 'string',
								'description' => __( 'The grammatical part of speech, e.g. "noun", "verb".', 'nakedcat-glotpress-abilities' ),
							),
							'translation'    => array(
								'type'        => 'string',
								'description' => __( 'The required/suggested translation for this term.', 'nakedcat-glotpress-abilities' ),
							),
							'comment'        => array(
								'type'        => 'string',
								'description' => __( 'An optional comment giving context or usage notes for the term.', 'nakedcat-glotpress-abilities' ),
							),
						),
					),
				),
			),
			'required'             => array( 'locale_slug', 'wp_locale', 'scope', 'translation_set_slug', 'glossary_id', 'entries' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error The glossary data, or WP_Error on failure.
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$locale_slug          = isset( $input['locale'] ) ? (string) $input['locale'] : '';
		$project_path         = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$translation_set_slug = ! empty( $input['translation_set_slug'] ) ? (string) $input['translation_set_slug'] : 'default';

		$locale = \GP_Locales::by_slug( $locale_slug );

		if ( ! $locale ) {
			return new \WP_Error(
				'nakedcat_glotpress_locale_not_found',
				sprintf(
					/* translators: %s: GlotPress locale slug. */
					__( 'No GlotPress locale was found with slug "%s".', 'nakedcat-glotpress-abilities' ),
					$locale_slug
				)
			);
		}

		if ( '' !== $project_path ) {
			return self::get_project_scoped_glossary( $project_path, $locale_slug, $translation_set_slug, $locale );
		}

		return self::get_global_glossary( $locale_slug, $translation_set_slug, $locale );
	}

	/**
	 * Resolves a project-scoped glossary, falling back to a parent project's glossary.
	 *
	 * @param string     $project_path         The requested project path.
	 * @param string     $locale_slug          The GlotPress locale slug.
	 * @param string     $translation_set_slug The translation set variant slug.
	 * @param \GP_Locale $locale                The resolved locale object.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function get_project_scoped_glossary( $project_path, $locale_slug, $translation_set_slug, $locale ) {
		$project = \GP::$project->by_path( $project_path );

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

		$translation_set = \GP::$translation_set->by_project_id_slug_and_locale( $project->id, $translation_set_slug, $locale_slug );

		if ( ! $translation_set ) {
			return new \WP_Error(
				'nakedcat_glotpress_translation_set_not_found',
				sprintf(
					/* translators: 1: GlotPress project path, 2: GlotPress locale slug, 3: translation set variant slug. */
					__( 'Project "%1$s" has no "%3$s" translation set for locale "%2$s".', 'nakedcat-glotpress-abilities' ),
					$project_path,
					$locale_slug,
					$translation_set_slug
				)
			);
		}

		// Safe to call directly: for a real (non-zero-id) project this never auto-creates rows,
		// it only walks up existing parent projects looking for an existing glossary.
		$glossary = \GP::$glossary->by_set_or_parent_project( $translation_set, $project );

		$glossary_owner_project_path = null;

		if ( $glossary ) {
			$owner_translation_set       = \GP::$translation_set->get( $glossary->translation_set_id );
			$owner_project               = $owner_translation_set ? \GP::$project->get( $owner_translation_set->project_id ) : null;
			$glossary_owner_project_path = $owner_project ? $owner_project->path : null;
		}

		return array(
			'locale_slug'                 => $locale_slug,
			'wp_locale'                   => $locale->wp_locale,
			'scope'                       => 'project',
			'requested_project_path'      => $project_path,
			'glossary_owner_project_path' => $glossary_owner_project_path,
			'translation_set_slug'        => $translation_set_slug,
			'glossary_id'                 => $glossary ? (int) $glossary->id : null,
			'entries'                     => self::format_entries( $glossary ),
		);
	}

	/**
	 * Resolves the locale-wide global glossary, without ever auto-creating rows.
	 *
	 * @param string     $locale_slug          The GlotPress locale slug.
	 * @param string     $translation_set_slug The translation set variant slug.
	 * @param \GP_Locale $locale                The resolved locale object.
	 * @return array<string, mixed>
	 */
	private static function get_global_glossary( $locale_slug, $translation_set_slug, $locale ) {
		// Deliberately avoids GP_Translation_Set::by_project_id_slug_and_locale() and
		// GP_Glossary::by_set_or_parent_project(), which both auto-create rows for the
		// virtual project_id=0 case. A read-only ability should never write.
		$translation_set = \GP::$translation_set->find_one(
			array(
				'project_id' => 0,
				'slug'       => $translation_set_slug,
				'locale'     => $locale_slug,
			)
		);

		$glossary = $translation_set ? \GP::$glossary->by_set_id( $translation_set->id ) : null;

		return array(
			'locale_slug'                 => $locale_slug,
			'wp_locale'                   => $locale->wp_locale,
			'scope'                       => 'global',
			'requested_project_path'      => null,
			'glossary_owner_project_path' => null,
			'translation_set_slug'        => $translation_set_slug,
			'glossary_id'                 => $glossary ? (int) $glossary->id : null,
			'entries'                     => self::format_entries( $glossary ),
		);
	}

	/**
	 * Formats a glossary's entries for output.
	 *
	 * @param \GP_Glossary|false|null $glossary The glossary, or a falsy value if none exists.
	 * @return array<int, array<string, string>>
	 */
	private static function format_entries( $glossary ) {
		if ( ! $glossary ) {
			return array();
		}

		$entries = \GP::$glossary_entry->by_glossary_id( $glossary->id );

		$formatted = array();

		foreach ( $entries as $entry ) {
			$formatted[] = array(
				'term'           => $entry->term,
				'part_of_speech' => $entry->part_of_speech,
				'translation'    => $entry->translation,
				'comment'        => $entry->comment ? $entry->comment : '',
			);
		}

		return $formatted;
	}
}
