<?php
/**
 * Ability: approve or reject waiting translations, batched.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/review-translations ability.
 *
 * Reviews go through GP_Translation::set_status(), the same call as the approve/reject buttons of
 * GlotPress's translate screen: approving makes the translation current and demotes the original's
 * previous current, waiting, fuzzy and changes-requested translations in that set to "old", exactly
 * as in the UI.
 *
 * Only "waiting" translations are reviewed: anything else (fuzzy, old, already current, rejected)
 * is refused per item, so an old or fuzzy translation can never be approved by mistake.
 *
 * Permission follows GlotPress rather than this plugin's admin-only gate: the calling user must
 * have GlotPress's "approve" permission on every translation set the call touches (validators of
 * that project/locale/set, and GlotPress administrators). GlotPress core has no review feedback
 * channel, so a rejection reason is kept as GlotPress meta on the translation
 * (nakedcat_rejection_reason) and returned in the result.
 */
class Review_Translations {

	const ABILITY_NAME = 'nakedcat-glotpress/review-translations';

	const MAX_ITEMS_PER_CALL = 100;

	const REASON_META_KEY = 'nakedcat_rejection_reason';

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Review GlotPress translations', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Approves or rejects waiting translations, in batch. Each review names a translation by translation_id, or by original_id together with the top-level project_path and locale (which picks that original\'s waiting translation). Approving makes it the current translation and demotes the previous one, as GlotPress\'s own approve button does. Translations that are not waiting are refused. Requires GlotPress approve permission on the translation sets involved.', 'nakedcat-glotpress-abilities' ),
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
				'project_path'         => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress project path, e.g. "wp-plugins/my-plugin". Required only for reviews given by original_id.', 'nakedcat-glotpress-abilities' ),
				),
				'locale'               => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress locale slug, e.g. "pt". Required only for reviews given by original_id.', 'nakedcat-glotpress-abilities' ),
				),
				'translation_set_slug' => array(
					'type'        => 'string',
					'description' => __( 'The translation set variant slug, for reviews given by original_id.', 'nakedcat-glotpress-abilities' ),
					'default'     => 'default',
				),
				'reviews'              => array(
					'type'        => 'array',
					'description' => __( 'The reviews to apply, in order.', 'nakedcat-glotpress-abilities' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_ITEMS_PER_CALL,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'translation_id' => array(
								'type'        => 'integer',
								'description' => __( 'The waiting translation\'s ID, as get-strings returns it.', 'nakedcat-glotpress-abilities' ),
							),
							'original_id'    => array(
								'type'        => 'integer',
								'description' => __( 'Instead of translation_id: the original whose waiting translation to review, in the project_path/locale set. Refused if the original has several waiting translations.', 'nakedcat-glotpress-abilities' ),
							),
							'action'         => array(
								'type'        => 'string',
								'enum'        => array( 'approve', 'reject' ),
								'description' => __( '"approve" makes the translation current; "reject" marks it rejected.', 'nakedcat-glotpress-abilities' ),
							),
							'reason'         => array(
								'type'        => 'string',
								'description' => __( 'Optional reason for a rejection, kept with the translation.', 'nakedcat-glotpress-abilities' ),
							),
						),
						'required'   => array( 'action' ),
					),
				),
			),
			'required'             => array( 'reviews' ),
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
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The translation reviewed, or null if none was found.', 'nakedcat-glotpress-abilities' ),
					),
					'original_id'    => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The translation\'s original, or null if unknown.', 'nakedcat-glotpress-abilities' ),
					),
					'result'         => array(
						'type'        => 'string',
						'enum'        => array( 'approved', 'rejected', 'error' ),
						'description' => __( 'What happened to this review.', 'nakedcat-glotpress-abilities' ),
					),
					'status'         => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The translation\'s status after this call, or null if none was found.', 'nakedcat-glotpress-abilities' ),
					),
					'reason'         => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The rejection reason kept with the translation, or null.', 'nakedcat-glotpress-abilities' ),
					),
					'error_message'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Why this review failed, or null if it did not.', 'nakedcat-glotpress-abilities' ),
					),
				),
				'required'   => array( 'translation_id', 'original_id', 'result', 'status', 'reason', 'error_message' ),
			),
		);
	}

	/**
	 * Checks whether the current user may approve in every translation set the call touches.
	 *
	 * Reviews whose translation cannot be resolved are left to execute(), which reports them per
	 * item; if none can be resolved, only GlotPress administrators get through.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return bool
	 */
	public static function check_permission( $input = array() ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$input = is_array( $input ) ? $input : array();
		$sets  = array();

		foreach ( self::reviews_from_input( $input ) as $review ) {
			$translation = self::resolve_translation( $input, $review );

			if ( ! is_wp_error( $translation ) ) {
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
		$input   = is_array( $input ) ? $input : array();
		$results = array();

		foreach ( self::reviews_from_input( $input ) as $review ) {
			$results[] = self::process_review( $input, $review );
		}

		return $results;
	}

	/**
	 * The reviews of the input, as arrays.
	 *
	 * @param array $input Ability input.
	 * @return array<int, array<string, mixed>>
	 */
	private static function reviews_from_input( $input ) {
		$reviews = isset( $input['reviews'] ) && is_array( $input['reviews'] ) ? $input['reviews'] : array();

		return array_map(
			function ( $review ) {
				return is_array( $review ) ? $review : array();
			},
			$reviews
		);
	}

	/**
	 * Applies one review.
	 *
	 * @param array $input  Ability input.
	 * @param array $review The review.
	 * @return array<string, mixed>
	 */
	private static function process_review( $input, $review ) {
		$action      = isset( $review['action'] ) ? (string) $review['action'] : '';
		$reason      = isset( $review['reason'] ) ? trim( (string) $review['reason'] ) : '';
		$translation = self::resolve_translation( $input, $review );

		if ( is_wp_error( $translation ) ) {
			$original_id = isset( $review['original_id'] ) ? (int) $review['original_id'] : null;
			return self::result( null, $original_id, 'error', null, null, $translation->get_error_message() );
		}

		if ( 'waiting' !== $translation->status ) {
			return self::result(
				$translation,
				null,
				'error',
				$translation->status,
				null,
				sprintf(
					/* translators: %s: translation status. */
					__( 'Only waiting translations can be reviewed; this one is "%s". Nothing was changed.', 'nakedcat-glotpress-abilities' ),
					$translation->status
				)
			);
		}

		$new_status = 'approve' === $action ? 'current' : 'rejected';

		if ( ! $translation->set_status( $new_status ) ) {
			return self::result( $translation, null, 'error', $translation->status, null, __( 'GlotPress refused the status change.', 'nakedcat-glotpress-abilities' ) );
		}

		if ( 'rejected' === $new_status && '' !== $reason ) {
			gp_update_meta( $translation->id, self::REASON_META_KEY, $reason, 'translation' );
		}

		$translation = \GP::$translation->get( $translation->id );

		return self::result(
			$translation,
			null,
			'current' === $new_status ? 'approved' : 'rejected',
			$translation->status,
			'rejected' === $new_status && '' !== $reason ? $reason : null,
			null
		);
	}

	/**
	 * Finds the translation a review is about.
	 *
	 * @param array $input  Ability input (project_path, locale, translation_set_slug for original_id).
	 * @param array $review The review.
	 * @return \GP_Translation|\WP_Error
	 */
	private static function resolve_translation( $input, $review ) {
		if ( ! empty( $review['translation_id'] ) ) {
			$translation = \GP::$translation->get( (int) $review['translation_id'] );

			return $translation ? $translation : new \WP_Error( 'nakedcat_glotpress_translation_not_found', __( 'No translation with this ID.', 'nakedcat-glotpress-abilities' ) );
		}

		if ( empty( $review['original_id'] ) ) {
			return new \WP_Error( 'nakedcat_glotpress_review_target_missing', __( 'Each review needs a translation_id or an original_id.', 'nakedcat-glotpress-abilities' ) );
		}

		$project_path         = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$locale_slug          = isset( $input['locale'] ) ? (string) $input['locale'] : '';
		$translation_set_slug = ! empty( $input['translation_set_slug'] ) ? (string) $input['translation_set_slug'] : 'default';
		$project              = '' !== $project_path ? \GP::$project->by_path( $project_path ) : null;
		$set                  = $project ? \GP::$translation_set->by_project_id_slug_and_locale( $project->id, $translation_set_slug, $locale_slug ) : null;

		if ( ! $set ) {
			return new \WP_Error( 'nakedcat_glotpress_translation_set_not_found', __( 'A review by original_id needs project_path and locale naming an existing translation set.', 'nakedcat-glotpress-abilities' ) );
		}

		$waiting = \GP::$translation->find_many(
			array(
				'original_id'        => (int) $review['original_id'],
				'translation_set_id' => $set->id,
				'status'             => 'waiting',
			)
		);

		if ( ! $waiting ) {
			return new \WP_Error( 'nakedcat_glotpress_translation_not_found', __( 'This original has no waiting translation in this translation set.', 'nakedcat-glotpress-abilities' ) );
		}

		if ( count( $waiting ) > 1 ) {
			return new \WP_Error(
				'nakedcat_glotpress_several_waiting',
				sprintf(
					/* translators: %s: comma-separated translation IDs. */
					__( 'This original has several waiting translations (%s): review them by translation_id.', 'nakedcat-glotpress-abilities' ),
					implode( ', ', wp_list_pluck( $waiting, 'id' ) )
				)
			);
		}

		return $waiting[0];
	}

	/**
	 * Builds a per-item result.
	 *
	 * @param \GP_Translation|null $translation   The translation, if found.
	 * @param int|null             $original_id   The original ID, when no translation was found.
	 * @param string               $result        The result.
	 * @param string|null          $status        The translation's status now.
	 * @param string|null          $reason        The rejection reason kept.
	 * @param string|null          $error_message The error message.
	 * @return array<string, mixed>
	 */
	private static function result( $translation, $original_id, $result, $status, $reason, $error_message ) {
		return array(
			'translation_id' => $translation ? (int) $translation->id : null,
			'original_id'    => $translation ? (int) $translation->original_id : $original_id,
			'result'         => $result,
			'status'         => $status,
			'reason'         => $reason,
			'error_message'  => $error_message,
		);
	}
}
