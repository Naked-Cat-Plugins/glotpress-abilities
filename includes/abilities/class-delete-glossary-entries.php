<?php
/**
 * Ability: delete entries of a locale's global glossary, or of one project's glossary, batched.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/delete-glossary-entries ability.
 *
 * Entries are addressed by ID, as nakedcat-glotpress/get-glossary returns them, and must belong to
 * the glossary the scope inputs resolve to (the same scope rules as add-glossary-entries), so an
 * ID from another locale's or project's glossary is refused rather than deleted. Deletes go
 * through GP_Glossary_Entry::delete(), as the glossary screen does.
 */
class Delete_Glossary_Entries {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;
	use \NakedCatPlugins\GlotpressAbilities\Resolves_Existing_Glossary;

	const ABILITY_NAME = 'nakedcat-glotpress/delete-glossary-entries';

	const MAX_ITEMS_PER_CALL = 50;

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Delete GlotPress glossary entries', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Deletes glossary entries, by entry ID as get-glossary returns it, from a locale\'s global glossary or, with project_path, from that project\'s own glossary. IDs that are not in that glossary are reported as errors and nothing else is touched.', 'nakedcat-glotpress-abilities' ),
				'category'            => 'glotpress',
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
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
				'entry_ids'            => array(
					'type'        => 'array',
					'description' => __( 'The glossary entry IDs to delete, as get-glossary returns them.', 'nakedcat-glotpress-abilities' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_ITEMS_PER_CALL,
					'items'       => array(
						'type' => 'integer',
					),
				),
			),
			'required'             => array( 'locale', 'entry_ids' ),
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
					'entry_id'      => array(
						'type'        => 'integer',
						'description' => __( 'The entry this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'result'        => array(
						'type'        => 'string',
						'enum'        => array( 'deleted', 'error' ),
						'description' => __( '"deleted" if the entry was deleted, "error" if this item failed.', 'nakedcat-glotpress-abilities' ),
					),
					'term'          => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The deleted entry\'s term, or null if the entry was not found.', 'nakedcat-glotpress-abilities' ),
					),
					'error_message' => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Why this item failed, or null if it did not.', 'nakedcat-glotpress-abilities' ),
					),
				),
				'required'   => array( 'entry_id', 'result', 'term', 'error_message' ),
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
		$entry_ids            = isset( $input['entry_ids'] ) && is_array( $input['entry_ids'] ) ? array_map( 'intval', $input['entry_ids'] ) : array();

		$glossary = self::resolve_existing_glossary( $locale_slug, $project_path, $translation_set_slug );

		if ( is_wp_error( $glossary ) ) {
			return $glossary;
		}

		$results = array();

		foreach ( $entry_ids as $entry_id ) {
			$entry = self::entry_in_glossary( $glossary, $entry_id );

			if ( ! $entry ) {
				$results[] = self::result( $entry_id, 'error', null, __( 'No entry with this ID in this glossary.', 'nakedcat-glotpress-abilities' ) );
				continue;
			}

			if ( ! $entry->delete() ) {
				$results[] = self::result( $entry_id, 'error', $entry->term, __( 'Failed to delete the glossary entry.', 'nakedcat-glotpress-abilities' ) );
				continue;
			}

			$results[] = self::result( $entry_id, 'deleted', $entry->term, null );
		}

		return $results;
	}

	/**
	 * Builds a per-item result.
	 *
	 * @param int         $entry_id      The entry ID submitted.
	 * @param string      $result        The result.
	 * @param string|null $term          The entry's term.
	 * @param string|null $error_message The error message.
	 * @return array<string, mixed>
	 */
	private static function result( $entry_id, $result, $term, $error_message ) {
		return array(
			'entry_id'      => $entry_id,
			'result'        => $result,
			'term'          => $term,
			'error_message' => $error_message,
		);
	}
}
