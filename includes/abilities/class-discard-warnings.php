<?php
/**
 * Ability: discard GlotPress warnings found to be false alarms, batched.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/discard-warnings ability.
 *
 * Does what the "Discard" link next to a warning on GlotPress's translate screen does
 * (GP_Route_Translation::discard_warning_edit_function()): fires gp_warning_discarded, removes the
 * warning from the translation and saves it with GP_Translation::save(), so gp_translation_saved
 * fires too. The translation text and status are never changed.
 *
 * Permission follows review-translations: GlotPress's "approve" permission on every translation set
 * the call touches (validators of that set, and GlotPress administrators).
 */
class Discard_Warnings {

	const ABILITY_NAME = 'nakedcat-glotpress/discard-warnings';

	const MAX_ITEMS_PER_CALL = 100;

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Discard GlotPress translation warnings', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Discards GlotPress warnings that were checked and found to be false alarms (for example a capitalised German noun flagged as a missing initial lowercase), as the Discard link on GlotPress\'s translate screen does. Each item names a translation_id and a warning key, as get-strings and update-translations return them. The translation\'s text and status are not changed. Requires GlotPress approve permission on the translation sets involved.', 'nakedcat-glotpress-abilities' ),
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
				'warnings' => array(
					'type'        => 'array',
					'description' => __( 'The warnings to discard, in order.', 'nakedcat-glotpress-abilities' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_ITEMS_PER_CALL,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'translation_id' => array(
								'type'        => 'integer',
								'description' => __( 'The translation the warning is on.', 'nakedcat-glotpress-abilities' ),
							),
							'key'            => array(
								'type'        => 'string',
								'description' => __( 'The warning key, e.g. "missing_uppercase_beginning".', 'nakedcat-glotpress-abilities' ),
							),
							'plural_index'   => array(
								'type'        => 'integer',
								'description' => __( 'The plural form the warning is on, starting at 0. Omit to discard this warning key on every plural form of the translation.', 'nakedcat-glotpress-abilities' ),
								'minimum'     => 0,
							),
						),
						'required'   => array( 'translation_id', 'key' ),
					),
				),
			),
			'required'             => array( 'warnings' ),
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
					'translation_id' => array(
						'type'        => 'integer',
						'description' => __( 'The translation this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'key'            => array(
						'type'        => 'string',
						'description' => __( 'The warning key this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'result'         => array(
						'type'        => 'string',
						'enum'        => array( 'discarded', 'error' ),
						'description' => __( '"discarded" if the warning was removed, "error" if this item failed.', 'nakedcat-glotpress-abilities' ),
					),
					'warnings'       => \NakedCatPlugins\GlotpressAbilities\Translation_Warnings::schema( __( 'The warnings the translation still has after this item (empty if none, or if the translation was not found).', 'nakedcat-glotpress-abilities' ) ),
					'error_message'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Why this item failed, or null if it did not.', 'nakedcat-glotpress-abilities' ),
					),
				),
				'required'   => array( 'translation_id', 'key', 'result', 'warnings', 'error_message' ),
			),
		);
	}

	/**
	 * Checks whether the current user may approve in every translation set the call touches.
	 *
	 * Items whose translation does not exist are left to execute(), which reports them; if none
	 * exists, only GlotPress administrators get through.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return bool
	 */
	public static function check_permission( $input = array() ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$sets = array();

		foreach ( self::items_from_input( $input ) as $item ) {
			$translation = ! empty( $item['translation_id'] ) ? \GP::$translation->get( (int) $item['translation_id'] ) : null;

			if ( $translation ) {
				$sets[ (int) $translation->translation_set_id ] = true;
			}
		}

		if ( ! $sets ) {
			return (bool) \GP::$permission->current_user_can( 'admin' );
		}

		foreach ( array_keys( $sets ) as $set_id ) {
			$set = \GP::$translation_set->get( $set_id );

			if ( ! $set || ! \GP::$permission->current_user_can( 'approve', 'translation-set', $set_id, array( 'set' => $set ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array Per-item results.
	 */
	public static function execute( $input = array() ) {
		$results = array();

		foreach ( self::items_from_input( $input ) as $item ) {
			$results[] = self::process_item( $item );
		}

		return $results;
	}

	/**
	 * The items of the input, as arrays.
	 *
	 * @param mixed $input Ability input.
	 * @return array<int, array<string, mixed>>
	 */
	private static function items_from_input( $input ) {
		$items = is_array( $input ) && isset( $input['warnings'] ) && is_array( $input['warnings'] ) ? $input['warnings'] : array();

		return array_map(
			function ( $item ) {
				return is_array( $item ) ? $item : array();
			},
			$items
		);
	}

	/**
	 * Discards one warning.
	 *
	 * @param array $item The item.
	 * @return array<string, mixed>
	 */
	private static function process_item( $item ) {
		$translation_id = isset( $item['translation_id'] ) ? (int) $item['translation_id'] : 0;
		$key            = isset( $item['key'] ) ? (string) $item['key'] : '';
		$plural_index   = isset( $item['plural_index'] ) ? (int) $item['plural_index'] : null;

		// Read again for every item, so several items on the same translation each see the last save
		$translation = $translation_id ? \GP::$translation->get( $translation_id ) : null;

		if ( ! $translation ) {
			return self::result( $translation_id, $key, 'error', null, __( 'No translation with this ID.', 'nakedcat-glotpress-abilities' ) );
		}

		$warnings = is_array( $translation->warnings ) ? $translation->warnings : array();
		$indexes  = array();

		foreach ( $warnings as $index => $problems ) {
			if ( is_array( $problems ) && isset( $problems[ $key ] ) && ( null === $plural_index || (int) $index === $plural_index ) ) {
				$indexes[] = $index;
			}
		}

		if ( ! $indexes ) {
			return self::result( $translation_id, $key, 'error', $warnings, __( 'This translation has no such warning. Nothing was changed.', 'nakedcat-glotpress-abilities' ) );
		}

		$set = \GP::$translation_set->get( $translation->translation_set_id );

		foreach ( $indexes as $index ) {
			$warning = array(
				'project_id'      => $set ? $set->project_id : null,
				'translation_set' => $translation->translation_set_id,
				'translation'     => $translation->id,
				'warning'         => $key,
				'user'            => get_current_user_id(),
			);

			/** This action is documented in glotpress/gp-includes/routes/translation.php */
			do_action_ref_array( 'gp_warning_discarded', $warning );

			unset( $warnings[ $index ][ $key ] );
			if ( empty( $warnings[ $index ] ) ) {
				unset( $warnings[ $index ] );
			}
		}

		$translation->warnings = $warnings;
		$saved                 = $translation->save();

		if ( false === $saved || null === $saved ) {
			return self::result( $translation_id, $key, 'error', \GP::$translation->get( $translation_id )->warnings, __( 'GlotPress could not save the translation.', 'nakedcat-glotpress-abilities' ) );
		}

		return self::result( $translation_id, $key, 'discarded', \GP::$translation->get( $translation_id )->warnings, null );
	}

	/**
	 * Builds a per-item result.
	 *
	 * @param int         $translation_id The translation ID.
	 * @param string      $key            The warning key.
	 * @param string      $result         "discarded" or "error".
	 * @param mixed       $warnings       The translation's warnings now, as GlotPress stores them.
	 * @param string|null $error_message  The error message.
	 * @return array<string, mixed>
	 */
	private static function result( $translation_id, $key, $result, $warnings, $error_message ) {
		return array(
			'translation_id' => $translation_id,
			'key'            => $key,
			'result'         => $result,
			'warnings'       => \NakedCatPlugins\GlotpressAbilities\Translation_Warnings::to_list( $warnings ),
			'error_message'  => $error_message,
		);
	}
}
