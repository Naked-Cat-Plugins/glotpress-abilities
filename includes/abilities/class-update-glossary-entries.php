<?php
/**
 * Ability: change existing entries of a locale's global glossary, or of one project's glossary, batched.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/update-glossary-entries ability.
 *
 * Entries are addressed by ID, as nakedcat-glotpress/get-glossary returns them, and must belong to
 * the glossary the scope inputs resolve to (the same scope rules as add-glossary-entries), so an
 * ID from another locale's or project's glossary is refused rather than edited.
 *
 * The term itself is not editable: a different term is a different entry (add the new one, delete
 * the old one). Saves go through GlotPress's own validation and GP_Glossary_Entry::update(), as
 * the glossary screen does.
 */
class Update_Glossary_Entries {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;
	use \NakedCatPlugins\GlotpressAbilities\Resolves_Existing_Glossary;

	const ABILITY_NAME = 'nakedcat-glotpress/update-glossary-entries';

	const MAX_ITEMS_PER_CALL = 50;

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Update GlotPress glossary entries', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Changes the translation, part of speech and/or comment of existing glossary entries, by entry ID as get-glossary returns it, in a locale\'s global glossary or, with project_path, in that project\'s own glossary. Refuses a change that would make an entry duplicate another one (same term and part of speech) and IDs that are not in that glossary.', 'nakedcat-glotpress-abilities' ),
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
				'locale'               => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress locale slug of the glossary, e.g. "pt", "pt-br".', 'nakedcat-glotpress-abilities' ),
				),
				'project_path'         => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress project path whose own glossary holds the entries, e.g. "wp-plugins/my-plugin". Omit for the locale-wide global glossary.', 'nakedcat-glotpress-abilities' ),
				),
				'translation_set_slug' => array(
					'type'        => 'string',
					'description' => __( 'The glossary\'s translation set variant slug. Almost always the default.', 'nakedcat-glotpress-abilities' ),
					'default'     => 'default',
				),
				'entries'              => array(
					'type'        => 'array',
					'description' => __( 'The changes to make, one per entry. Fields left out keep their current value.', 'nakedcat-glotpress-abilities' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_ITEMS_PER_CALL,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'entry_id'       => array(
								'type'        => 'integer',
								'description' => __( 'The glossary entry ID, as get-glossary returns it.', 'nakedcat-glotpress-abilities' ),
							),
							'translation'    => array(
								'type'        => 'string',
								'description' => __( 'The new translation.', 'nakedcat-glotpress-abilities' ),
							),
							'part_of_speech' => array(
								'type'        => 'string',
								'enum'        => Add_Glossary_Entries::PARTS_OF_SPEECH,
								'description' => __( 'The new part of speech.', 'nakedcat-glotpress-abilities' ),
							),
							'comment'        => array(
								'type'        => 'string',
								'description' => __( 'The new comment. An empty string removes it.', 'nakedcat-glotpress-abilities' ),
							),
						),
						'required'   => array( 'entry_id' ),
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
					'entry_id'       => array(
						'type'        => 'integer',
						'description' => __( 'The entry this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'result'         => array(
						'type'        => 'string',
						'enum'        => array( 'updated', 'unchanged', 'error' ),
						'description' => __( '"updated" if the entry was changed, "unchanged" if it already had the values submitted, "error" if this item failed.', 'nakedcat-glotpress-abilities' ),
					),
					'term'           => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The entry\'s term, or null if the entry was not found.', 'nakedcat-glotpress-abilities' ),
					),
					'part_of_speech' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The entry\'s part of speech after this call, or null if the entry was not found.', 'nakedcat-glotpress-abilities' ),
					),
					'translation'    => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The entry\'s translation after this call, or null if the entry was not found.', 'nakedcat-glotpress-abilities' ),
					),
					'error_message'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Why this item failed, or null if it did not.', 'nakedcat-glotpress-abilities' ),
					),
				),
				'required'   => array( 'entry_id', 'result', 'term', 'part_of_speech', 'translation', 'error_message' ),
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
		$project_path         = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$translation_set_slug = ! empty( $input['translation_set_slug'] ) ? (string) $input['translation_set_slug'] : 'default';
		$items                = isset( $input['entries'] ) && is_array( $input['entries'] ) ? $input['entries'] : array();

		$glossary = self::resolve_existing_glossary( $locale_slug, $project_path, $translation_set_slug );

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
	 * Processes a single change.
	 *
	 * @param \GP_Glossary $glossary The resolved glossary.
	 * @param mixed        $item     The raw input item.
	 * @return array<string, mixed>
	 */
	private static function process_item( $glossary, $item ) {
		$item     = is_array( $item ) ? $item : array();
		$entry_id = isset( $item['entry_id'] ) ? (int) $item['entry_id'] : 0;
		$entry    = self::entry_in_glossary( $glossary, $entry_id );

		if ( ! $entry ) {
			return self::result( $entry_id, 'error', null, __( 'No entry with this ID in this glossary.', 'nakedcat-glotpress-abilities' ) );
		}

		$fields = array(
			'term'           => $entry->term,
			'part_of_speech' => isset( $item['part_of_speech'] ) ? (string) $item['part_of_speech'] : $entry->part_of_speech,
			'translation'    => isset( $item['translation'] ) ? (string) $item['translation'] : $entry->translation,
			'comment'        => isset( $item['comment'] ) ? (string) $item['comment'] : (string) $entry->comment,
		);

		if ( $fields['part_of_speech'] === $entry->part_of_speech && $fields['translation'] === $entry->translation && $fields['comment'] === (string) $entry->comment ) {
			return self::result( $entry_id, 'unchanged', $entry, null );
		}

		if ( $fields['part_of_speech'] !== $entry->part_of_speech ) {
			$duplicate = \GP::$glossary_entry->find_one(
				array(
					'glossary_id'    => $glossary->id,
					'term'           => $entry->term,
					'part_of_speech' => $fields['part_of_speech'],
				)
			);

			if ( $duplicate && (int) $duplicate->id !== $entry_id ) {
				return self::result(
					$entry_id,
					'error',
					$entry,
					sprintf(
						/* translators: 1: glossary entry ID, 2: its translation. */
						__( 'Entry %1$d already has this term and part of speech (translation "%2$s"). Not changed: edit or delete that entry instead.', 'nakedcat-glotpress-abilities' ),
						(int) $duplicate->id,
						$duplicate->translation
					)
				);
			}
		}

		$changed = new \GP_Glossary_Entry(
			array_merge(
				$fields,
				array(
					'glossary_id'    => $glossary->id,
					'last_edited_by' => get_current_user_id(),
				)
			)
		);

		if ( ! $changed->validate() ) {
			return self::result( $entry_id, 'error', $entry, implode( ' ', (array) $changed->errors ) );
		}

		if ( ! $entry->update( $changed->fields() ) ) {
			return self::result( $entry_id, 'error', $entry, __( 'Failed to save the glossary entry.', 'nakedcat-glotpress-abilities' ) );
		}

		return self::result( $entry_id, 'updated', \GP::$glossary_entry->get( $entry_id ), null );
	}

	/**
	 * Builds a per-item result.
	 *
	 * @param int                     $entry_id      The entry ID submitted.
	 * @param string                  $result        The result.
	 * @param \GP_Glossary_Entry|null $entry         The entry, as it now is.
	 * @param string|null             $error_message The error message.
	 * @return array<string, mixed>
	 */
	private static function result( $entry_id, $result, $entry, $error_message ) {
		return array(
			'entry_id'       => $entry_id,
			'result'         => $result,
			'term'           => $entry ? $entry->term : null,
			'part_of_speech' => $entry ? $entry->part_of_speech : null,
			'translation'    => $entry ? $entry->translation : null,
			'error_message'  => $error_message,
		);
	}
}
