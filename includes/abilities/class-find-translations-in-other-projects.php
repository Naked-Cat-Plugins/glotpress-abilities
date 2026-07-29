<?php
/**
 * Ability: find how strings were translated (accepted translations only) in other projects, for a locale.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/find-translations-in-other-projects ability.
 */
class Find_Translations_In_Other_Projects {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;

	const ABILITY_NAME = 'nakedcat-glotpress/find-translations-in-other-projects';

	const MAX_STRINGS_PER_CALL = 100;

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Find translations in other GlotPress projects', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'For a locale, looks up how each given source string was already translated (accepted/current translations only) in other GlotPress projects on this site. Useful for keeping terminology consistent across projects before translating new strings. Only strings that have at least one match elsewhere are included in the result.', 'nakedcat-glotpress-abilities' ),
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
					'description' => __( 'The GlotPress locale slug to look up translations for, e.g. "pt", "pt-br".', 'nakedcat-glotpress-abilities' ),
				),
				'strings'              => array(
					'type'        => 'array',
					'description' => __( 'The exact singular source strings to look up.', 'nakedcat-glotpress-abilities' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_STRINGS_PER_CALL,
					'items'       => array( 'type' => 'string' ),
				),
				'exclude_project_path' => array(
					'type'        => 'string',
					'description' => __( 'A GlotPress project path to exclude from results, typically the project currently being translated.', 'nakedcat-glotpress-abilities' ),
				),
			),
			'required'             => array( 'locale', 'strings' ),
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
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'singular' => array(
						'type'        => 'string',
						'description' => __( 'The source string this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'matches'  => array(
						'type'        => 'array',
						'description' => __( 'Accepted translations of this string found in other projects.', 'nakedcat-glotpress-abilities' ),
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'project_path' => array(
									'type'        => 'string',
									'description' => __( 'The other project\'s path.', 'nakedcat-glotpress-abilities' ),
								),
								'project_name' => array(
									'type'        => 'string',
									'description' => __( 'The other project\'s name.', 'nakedcat-glotpress-abilities' ),
								),
								'translation'  => array(
									'type'        => 'array',
									'description' => __( 'The accepted translation\'s plural forms, trimmed to the locale\'s actual plural count.', 'nakedcat-glotpress-abilities' ),
									'items'       => array( 'type' => array( 'string', 'null' ) ),
								),
							),
						),
					),
				),
				'required'   => array( 'singular', 'matches' ),
			),
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error The matches, or WP_Error on failure.
	 */
	public static function execute( $input = array() ) {
		global $wpdb;

		$input = is_array( $input ) ? $input : array();

		$locale_slug          = isset( $input['locale'] ) ? (string) $input['locale'] : '';
		$strings              = isset( $input['strings'] ) && is_array( $input['strings'] ) ? array_values( array_unique( array_map( 'strval', $input['strings'] ) ) ) : array();
		$exclude_project_path = isset( $input['exclude_project_path'] ) ? (string) $input['exclude_project_path'] : '';

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

		if ( empty( $strings ) ) {
			return array();
		}

		$exclude_project_id = 0;

		if ( '' !== $exclude_project_path ) {
			$exclude_project = \GP::$project->by_path( $exclude_project_path );

			if ( ! $exclude_project ) {
				return new \WP_Error(
					'nakedcat_glotpress_project_not_found',
					sprintf(
						/* translators: %s: GlotPress project path. */
						__( 'No GlotPress project was found at path "%s".', 'nakedcat-glotpress-abilities' ),
						$exclude_project_path
					)
				);
			}

			$exclude_project_id = (int) $exclude_project->id;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $strings ), '%s' ) );

		$sql = "
			SELECT o.singular, p.path AS project_path, p.name AS project_name,
			       t.translation_0, t.translation_1, t.translation_2, t.translation_3, t.translation_4, t.translation_5
			FROM {$wpdb->gp_originals} AS o
			INNER JOIN {$wpdb->gp_translations} AS t ON t.original_id = o.id AND t.status = 'current'
			INNER JOIN {$wpdb->gp_translation_sets} AS ts ON ts.id = t.translation_set_id AND ts.locale = %s
			INNER JOIN {$wpdb->gp_projects} AS p ON p.id = o.project_id
			WHERE o.status = '+active' AND BINARY o.singular IN ($placeholders)";

		$params = array_merge( array( $locale_slug ), $strings );

		if ( $exclude_project_id ) {
			$sql     .= ' AND o.project_id != %d';
			$params[] = $exclude_project_id;
		}

		$sql .= ' ORDER BY o.singular, p.path';

		$rows = \GP::$original->many_no_map( $sql, ...$params );

		$grouped = array();

		foreach ( $rows as $row ) {
			$translation = array();
			for ( $i = 0; $i < $locale->nplurals; $i++ ) {
				$field         = 'translation_' . $i;
				$translation[] = $row->$field;
			}

			$grouped[ $row->singular ][] = array(
				'project_path' => $row->project_path,
				'project_name' => $row->project_name,
				'translation'  => $translation,
			);
		}

		$result = array();

		foreach ( $grouped as $singular => $matches ) {
			$result[] = array(
				'singular' => $singular,
				'matches'  => $matches,
			);
		}

		return $result;
	}
}
