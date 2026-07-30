<?php
/**
 * Ability: add entries to a locale's global glossary, batched.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/add-glossary-entries ability.
 *
 * Targets the locale-wide global glossary only (project_id = 0), matching this plugin's
 * production usage: a single shared glossary per locale rather than per-project ones. The
 * underlying global translation set / glossary row is auto-vivified if missing, since (unlike
 * nakedcat-glotpress/get-glossary, which is strictly read-only) this ability's whole purpose is
 * to write to it.
 */
class Add_Glossary_Entries {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;

	const ABILITY_NAME = 'nakedcat-glotpress/add-glossary-entries';

	const MAX_ITEMS_PER_CALL = 50;

	const PARTS_OF_SPEECH = array( 'noun', 'verb', 'adjective', 'adverb', 'interjection', 'conjunction', 'preposition', 'pronoun', 'expression', 'abbreviation' );

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Add GlotPress glossary entries', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Adds one or more terms to a locale\'s global glossary, in a single batched call. Never overwrites an existing term\'s translation — an entry whose term and part of speech already exist with a different translation is reported as an error rather than replaced.', 'nakedcat-glotpress-abilities' ),
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
				'locale'               => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress locale slug to add glossary entries for, e.g. "pt", "pt-br".', 'nakedcat-glotpress-abilities' ),
				),
				'translation_set_slug' => array(
					'type'        => 'string',
					'description' => __( 'The global glossary\'s variant slug. Almost always the default.', 'nakedcat-glotpress-abilities' ),
					'default'     => 'default',
				),
				'entries'              => array(
					'type'        => 'array',
					'description' => __( 'The glossary entries to add.', 'nakedcat-glotpress-abilities' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_ITEMS_PER_CALL,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'term'           => array(
								'type'        => 'string',
								'description' => __( 'The source term or phrase, e.g. "checkout". Plain readable text (letters/numbers/standard punctuation), must start and end with a letter or digit. By existing convention on this site, this is the English source term, not the translation.', 'nakedcat-glotpress-abilities' ),
							),
							'part_of_speech' => array(
								'type'        => 'string',
								'enum'        => self::PARTS_OF_SPEECH,
								'description' => __( 'The grammatical part of speech.', 'nakedcat-glotpress-abilities' ),
							),
							'translation'    => array(
								'type'        => 'string',
								'description' => __( 'The required/suggested translation for this term in the target locale.', 'nakedcat-glotpress-abilities' ),
							),
							'comment'        => array(
								'type'        => 'string',
								'description' => __( 'Optional context or usage note for the term.', 'nakedcat-glotpress-abilities' ),
							),
						),
						'required'   => array( 'term', 'part_of_speech', 'translation' ),
					),
				),
			),
			'required'             => array( 'locale', 'entries' ),
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
					'term'           => array(
						'type'        => 'string',
						'description' => __( 'The term this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'part_of_speech' => array(
						'type'        => 'string',
						'description' => __( 'The part of speech this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'result'         => array(
						'type'        => 'string',
						'enum'        => array( 'created', 'unchanged', 'error' ),
						'description' => __( '"created" if a new glossary entry was added, "unchanged" if an identical entry (same term, part of speech, and translation) already existed, "error" if this item failed — including when the term/part-of-speech pair already exists with a DIFFERENT translation.', 'nakedcat-glotpress-abilities' ),
					),
					'entry_id'       => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The resulting (or pre-existing, for "unchanged") glossary entry ID, or null on error.', 'nakedcat-glotpress-abilities' ),
					),
					'error_message'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Why this item failed, or null if it did not.', 'nakedcat-glotpress-abilities' ),
					),
				),
				'required'   => array( 'term', 'part_of_speech', 'result', 'entry_id', 'error_message' ),
			),
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error Per-item results, or WP_Error if the whole call is rejected.
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$locale_slug          = isset( $input['locale'] ) ? (string) $input['locale'] : '';
		$translation_set_slug = ! empty( $input['translation_set_slug'] ) ? (string) $input['translation_set_slug'] : 'default';
		$items                = isset( $input['entries'] ) && is_array( $input['entries'] ) ? $input['entries'] : array();

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

		$glossary = self::get_or_create_global_glossary( $translation_set_slug, $locale_slug );

		if ( is_wp_error( $glossary ) ) {
			return $glossary;
		}

		$results = array();

		foreach ( $items as $item ) {
			$results[] = self::process_item( $glossary, $item );
		}

		return $results;
	}

	/**
	 * Resolves the global (locale-wide) glossary for a locale, creating the underlying
	 * translation set and/or glossary row if they don't exist yet.
	 *
	 * @param string $translation_set_slug The translation set variant slug.
	 * @param string $locale_slug          The GlotPress locale slug.
	 * @return \GP_Glossary|\WP_Error
	 */
	private static function get_or_create_global_glossary( $translation_set_slug, $locale_slug ) {
		// project_id 0 is GlotPress's virtual "locale glossary" project; this finder auto-creates
		// the translation set row for it if missing, which is intended here (this is a write ability).
		$translation_set = \GP::$translation_set->by_project_id_slug_and_locale( 0, $translation_set_slug, $locale_slug );

		if ( ! $translation_set ) {
			return new \WP_Error(
				'nakedcat_glotpress_translation_set_not_found',
				__( 'Could not resolve or create the global glossary\'s translation set for this locale.', 'nakedcat-glotpress-abilities' )
			);
		}

		$glossary = \GP::$glossary->by_set_id( $translation_set->id );

		if ( ! $glossary ) {
			$glossary = \GP::$glossary->create( array( 'translation_set_id' => $translation_set->id ) );
		}

		if ( ! $glossary ) {
			return new \WP_Error(
				'nakedcat_glotpress_glossary_not_found',
				__( 'Could not resolve or create the global glossary for this locale.', 'nakedcat-glotpress-abilities' )
			);
		}

		return $glossary;
	}

	/**
	 * Processes a single glossary entry item.
	 *
	 * @param \GP_Glossary $glossary The resolved global glossary.
	 * @param mixed        $item     The raw input item.
	 * @return array<string, mixed>
	 */
	private static function process_item( $glossary, $item ) {
		$item           = is_array( $item ) ? $item : array();
		$term           = isset( $item['term'] ) ? (string) $item['term'] : '';
		$part_of_speech = isset( $item['part_of_speech'] ) ? (string) $item['part_of_speech'] : '';
		$translation    = isset( $item['translation'] ) ? (string) $item['translation'] : '';
		$comment        = isset( $item['comment'] ) ? (string) $item['comment'] : '';

		// Stricter than GlotPress's own stock check (which only rejects an exact term+POS+translation
		// match): here, the same term+part_of_speech with a DIFFERENT translation is also rejected,
		// rather than silently creating a second, conflicting entry for the same term.
		$existing = \GP::$glossary_entry->find_one(
			array(
				'glossary_id'    => $glossary->id,
				'term'           => $term,
				'part_of_speech' => $part_of_speech,
			)
		);

		if ( $existing ) {
			if ( $existing->translation === $translation ) {
				return array(
					'term'           => $term,
					'part_of_speech' => $part_of_speech,
					'result'         => 'unchanged',
					'entry_id'       => (int) $existing->id,
					'error_message'  => null,
				);
			}

			return self::error_result(
				$term,
				$part_of_speech,
				sprintf(
					/* translators: 1: existing translation, 2: new translation. */
					__( 'An entry for this term and part of speech already exists with translation "%1$s" (you submitted "%2$s"). Not overwritten — update it manually if the new translation should replace it.', 'nakedcat-glotpress-abilities' ),
					$existing->translation,
					$translation
				)
			);
		}

		$new_entry = new \GP_Glossary_Entry(
			array(
				'glossary_id'    => $glossary->id,
				'term'           => $term,
				'part_of_speech' => $part_of_speech,
				'translation'    => $translation,
				'comment'        => $comment,
				'last_edited_by' => get_current_user_id(),
			)
		);

		if ( ! $new_entry->validate() ) {
			return self::error_result( $term, $part_of_speech, implode( ' ', (array) $new_entry->errors ) );
		}

		$created = \GP::$glossary_entry->create_and_select( $new_entry );

		if ( ! $created ) {
			return self::error_result( $term, $part_of_speech, __( 'Failed to save the glossary entry.', 'nakedcat-glotpress-abilities' ) );
		}

		return array(
			'term'           => $term,
			'part_of_speech' => $part_of_speech,
			'result'         => 'created',
			'entry_id'       => (int) $created->id,
			'error_message'  => null,
		);
	}

	/**
	 * Builds a per-item error result.
	 *
	 * @param string $term           The term.
	 * @param string $part_of_speech The part of speech.
	 * @param string $error_message  The error message.
	 * @return array<string, mixed>
	 */
	private static function error_result( $term, $part_of_speech, $error_message ) {
		return array(
			'term'           => $term,
			'part_of_speech' => $part_of_speech,
			'result'         => 'error',
			'entry_id'       => null,
			'error_message'  => $error_message,
		);
	}
}
