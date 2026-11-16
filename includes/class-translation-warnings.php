<?php
/**
 * GlotPress translation warnings, as the abilities return them.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns GlotPress's stored warnings into a flat list, and describes that list for output schemas.
 *
 * GlotPress keeps a translation's warnings (GP_Translation_Warnings::check()) as
 * array( plural_index => array( warning_key => message ) ), serialized in the translation row.
 */
class Translation_Warnings {

	/**
	 * Flattens GlotPress warnings into a list of { plural_index, key, message }.
	 *
	 * @param mixed $warnings Warnings as GlotPress stores them, serialized or not, or null.
	 * @return array<int, array{plural_index: int, key: string, message: string}>
	 */
	public static function to_list( $warnings ) {
		$warnings = maybe_unserialize( $warnings );
		$list     = array();

		if ( ! is_array( $warnings ) ) {
			return $list;
		}

		foreach ( $warnings as $plural_index => $problems ) {
			if ( ! is_array( $problems ) ) {
				continue;
			}
			foreach ( $problems as $key => $message ) {
				$list[] = array(
					'plural_index' => (int) $plural_index,
					'key'          => (string) $key,
					'message'      => (string) $message,
				);
			}
		}

		return $list;
	}

	/**
	 * The output schema of a warnings list.
	 *
	 * @param string $description What the list holds.
	 * @return array<string, mixed>
	 */
	public static function schema( $description ) {
		return array(
			'type'        => 'array',
			'description' => $description,
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'plural_index' => array(
						'type'        => 'integer',
						'description' => __( 'The plural form the warning is about, starting at 0 (the singular).', 'nakedcat-glotpress-abilities' ),
					),
					'key'          => array(
						'type'        => 'string',
						'description' => __( 'GlotPress\'s warning key, e.g. "placeholders", "tags", "missing_uppercase_beginning". Pass it to discard-warnings to discard a false alarm.', 'nakedcat-glotpress-abilities' ),
					),
					'message'      => array(
						'type'        => 'string',
						'description' => __( 'The warning, as GlotPress words it.', 'nakedcat-glotpress-abilities' ),
					),
				),
			),
		);
	}
}
