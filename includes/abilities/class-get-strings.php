<?php
/**
 * Ability: get a project/locale's strings (originals + their translation), filtered by status.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/get-strings ability.
 */
class Get_Strings {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;

	const ABILITY_NAME = 'nakedcat-glotpress/get-strings';

	const MAX_PER_PAGE = 200;

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get GlotPress strings', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Returns a project/locale\'s strings (each original together with its current translation, if any), filtered by status and paginated. Use this to find strings that need translating, or to review already-translated strings for consistency.', 'nakedcat-glotpress-abilities' ),
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
				'project_path'         => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress project path, e.g. "wp-plugins/my-plugin".', 'nakedcat-glotpress-abilities' ),
				),
				'locale'               => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress locale slug, e.g. "pt", "pt-br".', 'nakedcat-glotpress-abilities' ),
				),
				'translation_set_slug' => array(
					'type'        => 'string',
					'description' => __( 'The translation set variant slug, e.g. "default", "formal".', 'nakedcat-glotpress-abilities' ),
					'default'     => 'default',
				),
				'status'               => array(
					'type'        => 'string',
					'description' => __( 'A single translation status, or several joined with "_or_". Valid values: current, waiting, rejected, fuzzy, old, changesrequested, untranslated (meaning the original has no translation row at all). A single status on its own is always accurate (e.g. "untranslated", "fuzzy", "current" each reliably return only matching strings). Important GlotPress quirk: combining "untranslated" with another status that is NOT "current" (e.g. "untranslated_or_fuzzy") is unreliable and will incorrectly include already-translated strings too. To fetch multiple categories of strings needing work, make one call per status instead of combining them, or include "current" in the combination (e.g. "current_or_waiting_or_fuzzy_or_untranslated_or_changesrequested" is safe and is what GlotPress\'s own translate editor uses by default).', 'nakedcat-glotpress-abilities' ),
					'default'     => 'untranslated',
				),
				'page'                 => array(
					'type'        => 'integer',
					'description' => __( 'The page of results to return, starting at 1.', 'nakedcat-glotpress-abilities' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'per_page'             => array(
					'type'        => 'integer',
					'description' => __( 'How many strings to return per page.', 'nakedcat-glotpress-abilities' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => self::MAX_PER_PAGE,
				),
			),
			'required'             => array( 'project_path', 'locale' ),
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
				'total'    => array(
					'type'        => 'integer',
					'description' => __( 'Total number of matching strings across all pages.', 'nakedcat-glotpress-abilities' ),
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'The page of results returned.', 'nakedcat-glotpress-abilities' ),
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'How many strings were returned per page.', 'nakedcat-glotpress-abilities' ),
				),
				'strings'  => array(
					'type'        => 'array',
					'description' => __( 'The matching strings for this page.', 'nakedcat-glotpress-abilities' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'original_id'        => array(
								'type'        => 'integer',
								'description' => __( 'The original string\'s ID.', 'nakedcat-glotpress-abilities' ),
							),
							'translation_id'     => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'The translation row\'s ID, or null if the original is untranslated.', 'nakedcat-glotpress-abilities' ),
							),
							'singular'           => array(
								'type'        => 'string',
								'description' => __( 'The singular source string.', 'nakedcat-glotpress-abilities' ),
							),
							'plural'             => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'The plural source string, or null if the original has no plural form.', 'nakedcat-glotpress-abilities' ),
							),
							'context'            => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'A disambiguation string for otherwise-identical originals used in different contexts.', 'nakedcat-glotpress-abilities' ),
							),
							'translations'       => array(
								'type'        => 'array',
								'description' => __( 'The current translation\'s plural forms, already trimmed to the locale\'s actual plural count. Empty if untranslated.', 'nakedcat-glotpress-abilities' ),
								'items'       => array( 'type' => array( 'string', 'null' ) ),
							),
							'translation_status' => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'The translation\'s status (current, waiting, fuzzy, etc.), or null if untranslated.', 'nakedcat-glotpress-abilities' ),
							),
							'extracted_comments' => array(
								'type'        => 'string',
								'description' => __( 'Developer/translator context comment for this string, if any.', 'nakedcat-glotpress-abilities' ),
							),
							'references'         => array(
								'type'        => 'array',
								'description' => __( 'Source locations ("file:line") where this string is used, if known.', 'nakedcat-glotpress-abilities' ),
								'items'       => array( 'type' => 'string' ),
							),
							'priority'           => array(
								'type'        => 'integer',
								'description' => __( 'The original\'s priority: -2 hidden, -1 low, 0 normal, 1 high.', 'nakedcat-glotpress-abilities' ),
							),
						),
					),
				),
			),
			'required'             => array( 'total', 'page', 'per_page', 'strings' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error The strings for the requested page, or WP_Error on failure.
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$project_path         = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$locale_slug          = isset( $input['locale'] ) ? (string) $input['locale'] : '';
		$translation_set_slug = ! empty( $input['translation_set_slug'] ) ? (string) $input['translation_set_slug'] : 'default';
		$status               = ! empty( $input['status'] ) ? (string) $input['status'] : 'untranslated_or_fuzzy';
		$page                 = ! empty( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$per_page             = ! empty( $input['per_page'] ) ? min( self::MAX_PER_PAGE, max( 1, (int) $input['per_page'] ) ) : 50;

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

		if ( ! \GP_Locales::by_slug( $locale_slug ) ) {
			return new \WP_Error(
				'nakedcat_glotpress_locale_not_found',
				sprintf(
					/* translators: %s: GlotPress locale slug. */
					__( 'No GlotPress locale was found with slug "%s".', 'nakedcat-glotpress-abilities' ),
					$locale_slug
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

		\GP::$translation->per_page = $per_page;

		$entries = \GP::$translation->for_translation( $project, $translation_set, $page, array( 'status' => $status ) );
		$total   = (int) \GP::$translation->found_rows;

		$strings = array();

		foreach ( $entries as $entry ) {
			$strings[] = array(
				'original_id'        => (int) $entry->original_id,
				'translation_id'     => $entry->id ? (int) $entry->id : null,
				'singular'           => $entry->singular,
				'plural'             => ( isset( $entry->plural ) && '' !== $entry->plural ) ? $entry->plural : null,
				'context'            => ( isset( $entry->context ) && '' !== $entry->context ) ? $entry->context : null,
				'translations'       => $entry->translations,
				'translation_status' => $entry->translation_status ? $entry->translation_status : null,
				'extracted_comments' => $entry->extracted_comments ? $entry->extracted_comments : '',
				'references'         => $entry->references,
				'priority'           => isset( $entry->priority ) ? (int) $entry->priority : 0,
			);
		}

		return array(
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'strings'  => $strings,
		);
	}
}
