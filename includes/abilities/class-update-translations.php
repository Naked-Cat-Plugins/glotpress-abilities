<?php
/**
 * Ability: create/update translations for a project/locale, batched.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/update-translations ability.
 *
 * Replicates GlotPress's own submission sequence (GP_Route_Translation::translations_post()):
 * create() with status 'waiting' -> validate() (deleting the row on failure) -> set_status(),
 * since only set_status() (via save()) fires the gp_translation_saved action that integrations
 * like gp-convert-pt-ao90 depend on.
 *
 * Also auto-mirrors successful "pt" writes to a project's "pt-ao" (Portuguese, Angola) set, if
 * one exists, since Angola's Portuguese is meant to always match Portugal's for these plugins.
 * This is unrelated to the "pt-ao90" guard above other than the unfortunate slug similarity: see
 * self::resolve_pt_ao_mirror_target().
 */
class Update_Translations {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;

	const ABILITY_NAME = 'nakedcat-glotpress/update-translations';

	const MAX_ITEMS_PER_CALL = 100;

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Update GlotPress translations', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Creates or updates translations for one or more originals in a project/locale, in a single batched call. Replicates GlotPress\'s own submission behaviour (validation, duplicate detection, and firing the same hooks other integrations rely on) rather than writing rows directly. When locale is exactly "pt" and the project also has a "pt-ao" (Portuguese, Angola) translation set, every successfully-written item is automatically mirrored to "pt-ao" too, unconditionally overwriting whatever is there (including an existing "current" translation), since Angola\'s Portuguese is meant to always match Portugal\'s. See each result\'s "pt_ao_mirror" field. This is unrelated to "pt-ao90" (pre/post-1990-orthographic-agreement Portugal spelling), handled separately below.', 'nakedcat-glotpress-abilities' ),
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
					'description' => __( 'The GlotPress project path, e.g. "wp-plugins/my-plugin".', 'nakedcat-glotpress-abilities' ),
				),
				'locale'               => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress locale slug to submit translations for, e.g. "pt", "pt-br".', 'nakedcat-glotpress-abilities' ),
				),
				'translation_set_slug' => array(
					'type'        => 'string',
					'description' => __( 'The translation set variant slug, e.g. "default", "formal".', 'nakedcat-glotpress-abilities' ),
					'default'     => 'default',
				),
				'translations'         => array(
					'type'        => 'array',
					'description' => __( 'The translations to submit.', 'nakedcat-glotpress-abilities' ),
					'minItems'    => 1,
					'maxItems'    => self::MAX_ITEMS_PER_CALL,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'original_id' => array(
								'type'        => 'integer',
								'description' => __( 'The original string\'s ID (from get-strings).', 'nakedcat-glotpress-abilities' ),
							),
							'translation' => array(
								'type'        => 'array',
								'description' => __( 'The translated plural forms, one entry per plural form actually needed (fewer than the locale\'s plural count is fine). Extra entries beyond the locale\'s plural count are ignored.', 'nakedcat-glotpress-abilities' ),
								'minItems'    => 1,
								'items'       => array( 'type' => 'string' ),
							),
							'status'      => array(
								'type'        => 'string',
								'description' => __( 'The status to submit this translation with. "current" makes it the live, approved translation immediately (this plugin\'s GlotPress-admin permission model always allows this). Use "fuzzy" or "waiting" instead for a translation that should be flagged for human review rather than going live immediately.', 'nakedcat-glotpress-abilities' ),
								'enum'        => array( 'current', 'waiting', 'fuzzy' ),
							),
						),
						'required'   => array( 'original_id', 'translation', 'status' ),
					),
				),
			),
			'required'             => array( 'project_path', 'locale', 'translations' ),
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
					'original_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The original string\'s ID this result is for.', 'nakedcat-glotpress-abilities' ),
					),
					'result'         => array(
						'type'        => 'string',
						'enum'        => array( 'created', 'unchanged', 'error' ),
						'description' => __( '"created" if a new translation row was written, "unchanged" if an identical current/waiting translation already existed, "error" if this item failed.', 'nakedcat-glotpress-abilities' ),
					),
					'translation_id' => array(
						'type'        => array( 'integer', 'null' ),
						'description' => __( 'The resulting (or pre-existing, for "unchanged") translation row ID, or null on error.', 'nakedcat-glotpress-abilities' ),
					),
					'status'         => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'The translation\'s resulting status, or null on error.', 'nakedcat-glotpress-abilities' ),
					),
					'error_message'  => array(
						'type'        => array( 'string', 'null' ),
						'description' => __( 'Why this item failed, or null if it did not.', 'nakedcat-glotpress-abilities' ),
					),
					'pt_ao_mirror'   => array(
						'type'        => array( 'object', 'null' ),
						'description' => __( 'The outcome of automatically mirroring this item to the project\'s "pt-ao" (Portuguese, Angola) translation set, when locale is exactly "pt" and a "pt-ao" set exists for this project. Null if no mirroring was attempted (locale was not exactly "pt", no "pt-ao" set exists for this project, or this item\'s own "pt" write did not succeed). The mirror always overwrites whatever is currently in "pt-ao" for this original, including an existing "current" translation.', 'nakedcat-glotpress-abilities' ),
						'properties'  => array(
							'result'         => array(
								'type'        => 'string',
								'enum'        => array( 'created', 'unchanged', 'error' ),
								'description' => __( 'Same meaning as the outer "result", but for the "pt-ao" mirror write.', 'nakedcat-glotpress-abilities' ),
							),
							'translation_id' => array(
								'type'        => array( 'integer', 'null' ),
								'description' => __( 'The resulting (or pre-existing, for "unchanged") "pt-ao" translation row ID, or null on error.', 'nakedcat-glotpress-abilities' ),
							),
							'status'         => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'The "pt-ao" translation\'s resulting status, or null on error.', 'nakedcat-glotpress-abilities' ),
							),
							'error_message'  => array(
								'type'        => array( 'string', 'null' ),
								'description' => __( 'Why the "pt-ao" mirror write failed, or null if it did not.', 'nakedcat-glotpress-abilities' ),
							),
						),
						'required'    => array( 'result', 'translation_id', 'status', 'error_message' ),
					),
				),
				'required'   => array( 'original_id', 'result', 'translation_id', 'status', 'error_message', 'pt_ao_mirror' ),
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

		$project_path         = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$locale_slug          = isset( $input['locale'] ) ? (string) $input['locale'] : '';
		$translation_set_slug = ! empty( $input['translation_set_slug'] ) ? (string) $input['translation_set_slug'] : 'default';
		$items                = isset( $input['translations'] ) && is_array( $input['translations'] ) ? $input['translations'] : array();

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

		$pt_ao90_guard = self::check_pt_ao90_guard( $project, $locale_slug, $translation_set_slug );

		if ( is_wp_error( $pt_ao90_guard ) ) {
			return $pt_ao90_guard;
		}

		$pt_ao_mirror_target = self::resolve_pt_ao_mirror_target( $project, $locale_slug, $translation_set_slug );

		$results = array();

		foreach ( $items as $item ) {
			$result = self::process_item( $project, $translation_set, $locale, $item );

			$result['pt_ao_mirror'] = null;

			if ( $pt_ao_mirror_target && 'error' !== $result['result'] ) {
				$mirror_result = self::process_item( $project, $pt_ao_mirror_target['translation_set'], $pt_ao_mirror_target['locale'], $item );

				$result['pt_ao_mirror'] = array(
					'result'         => $mirror_result['result'],
					'translation_id' => $mirror_result['translation_id'],
					'status'         => $mirror_result['status'],
					'error_message'  => $mirror_result['error_message'],
				);
			}

			$results[] = $result;
		}

		return $results;
	}

	/**
	 * Resolves the project's "pt-ao" (Portuguese, Angola) mirror target, if one applies.
	 *
	 * Only triggers when the caller's locale is exactly "pt" (never "pt-ao", "pt-ao90", "pt-br",
	 * or anything else via prefix/substring matching) and the project already has a "pt-ao"
	 * translation set for the same translation_set_slug. Unrelated to the "pt-ao90" guard.
	 *
	 * @param \GP_Project $project              The resolved project.
	 * @param string      $locale_slug          The requested locale slug.
	 * @param string      $translation_set_slug The requested translation set variant slug.
	 * @return array{translation_set: \GP_Translation_Set, locale: \GP_Locale}|null
	 */
	private static function resolve_pt_ao_mirror_target( $project, $locale_slug, $translation_set_slug ) {
		if ( 'pt' !== $locale_slug ) {
			return null;
		}

		$pt_ao_translation_set = \GP::$translation_set->by_project_id_slug_and_locale( $project->id, $translation_set_slug, 'pt-ao' );

		if ( ! $pt_ao_translation_set ) {
			return null;
		}

		$pt_ao_locale = \GP_Locales::by_slug( 'pt-ao' );

		if ( ! $pt_ao_locale ) {
			return null;
		}

		return array(
			'translation_set' => $pt_ao_translation_set,
			'locale'          => $pt_ao_locale,
		);
	}

	/**
	 * Rejects the whole call when targeting pt-ao90 on a project that gp-convert-pt-ao90 auto-syncs from pt.
	 *
	 * @param \GP_Project $project              The resolved project.
	 * @param string      $locale_slug          The requested locale slug.
	 * @param string      $translation_set_slug The requested translation set variant slug.
	 * @return true|\WP_Error
	 */
	private static function check_pt_ao90_guard( $project, $locale_slug, $translation_set_slug ) {
		if ( 'pt-ao90' !== $locale_slug ) {
			return true;
		}

		if ( ! class_exists( '\GP_Convert_PT_AO90\Portuguese_AO90' ) ) {
			return true;
		}

		$pt_set = \GP::$translation_set->find_one(
			array(
				'project_id' => $project->id,
				'slug'       => $translation_set_slug,
				'locale'     => 'pt',
			)
		);

		if ( ! $pt_set ) {
			return true;
		}

		return new \WP_Error(
			'nakedcat_glotpress_pt_ao90_readonly',
			__( 'This project\'s "pt-ao90" translations are generated automatically from its "pt" translations by the gp-convert-pt-ao90 plugin. Submit translations to locale "pt" instead; "pt-ao90" will be kept in sync automatically.', 'nakedcat-glotpress-abilities' )
		);
	}

	/**
	 * Processes a single translation item.
	 *
	 * @param \GP_Project         $project          The resolved project.
	 * @param \GP_Translation_Set $translation_set  The resolved translation set.
	 * @param \GP_Locale          $locale           The resolved locale.
	 * @param mixed               $item             The raw input item.
	 * @return array<string, mixed>
	 */
	private static function process_item( $project, $translation_set, $locale, $item ) {
		$item        = is_array( $item ) ? $item : array();
		$original_id = isset( $item['original_id'] ) ? (int) $item['original_id'] : 0;
		$status      = isset( $item['status'] ) ? (string) $item['status'] : '';
		$translation = isset( $item['translation'] ) && is_array( $item['translation'] ) ? array_values( $item['translation'] ) : array();

		$original = \GP::$original->get( $original_id );

		if ( ! $original || (int) $original->project_id !== (int) $project->id ) {
			return self::error_result( $original_id, __( 'This original_id does not exist, or does not belong to the requested project.', 'nakedcat-glotpress-abilities' ) );
		}

		// Only the locale's actual plural slots are ever read by GlotPress; ignore anything beyond that.
		$translation = array_slice( $translation, 0, $locale->nplurals );

		$errors = \GP::$translation_errors->check( $original, $translation, $locale );

		if ( $errors ) {
			$messages = array();
			foreach ( $errors as $translation_index => $problems ) {
				foreach ( $problems as $message ) {
					$messages[] = $message;
				}
			}
			return self::error_result( $original_id, implode( ' ', array_unique( $messages ) ) );
		}

		$existing = self::find_existing_current_or_waiting( $project, $translation_set, $original_id );

		foreach ( $existing as $entry ) {
			if ( array_pad( $translation, $locale->nplurals, null ) === $entry->translations ) {
				return array(
					'original_id'    => $original_id,
					'result'         => 'unchanged',
					'translation_id' => $entry->id ? (int) $entry->id : null,
					'status'         => $entry->translation_status,
					'error_message'  => null,
				);
			}
		}

		$data = array(
			'original_id'        => $original_id,
			'translation_set_id' => $translation_set->id,
			'user_id'            => get_current_user_id(),
			'status'             => 'waiting',
			'warnings'           => \GP::$translation_warnings->check( $original->singular, $original->plural, $translation, $locale ),
		);

		foreach ( $translation as $index => $value ) {
			$data[ "translation_$index" ] = $value;
		}

		$new_translation = \GP::$translation->create( $data );

		if ( ! $new_translation ) {
			return self::error_result( $original_id, __( 'Failed to save the translation.', 'nakedcat-glotpress-abilities' ) );
		}

		if ( ! $new_translation->validate() ) {
			$error_message = implode( ' ', (array) $new_translation->errors );
			$new_translation->delete();
			return self::error_result( $original_id, $error_message ? $error_message : __( 'The translation failed validation.', 'nakedcat-glotpress-abilities' ) );
		}

		if ( ! $new_translation->set_status( $status ) ) {
			$new_translation->delete();
			return self::error_result( $original_id, __( 'The translation was saved but its status could not be set.', 'nakedcat-glotpress-abilities' ) );
		}

		return array(
			'original_id'    => $original_id,
			'result'         => 'created',
			'translation_id' => (int) $new_translation->id,
			'status'         => $status,
			'error_message'  => null,
		);
	}

	/**
	 * Finds existing current/waiting translations for an original, for duplicate detection.
	 *
	 * @param \GP_Project         $project         The resolved project.
	 * @param \GP_Translation_Set $translation_set The resolved translation set.
	 * @param int                 $original_id     The original's ID.
	 * @return \Translation_Entry[]
	 */
	private static function find_existing_current_or_waiting( $project, $translation_set, $original_id ) {
		return \GP::$translation->for_translation(
			$project,
			$translation_set,
			'no-limit',
			array(
				'original_id' => $original_id,
				'status'      => 'current_or_waiting',
			)
		);
	}

	/**
	 * Builds a per-item error result.
	 *
	 * @param int    $original_id   The original's ID.
	 * @param string $error_message The error message.
	 * @return array<string, mixed>
	 */
	private static function error_result( $original_id, $error_message ) {
		return array(
			'original_id'    => $original_id,
			'result'         => 'error',
			'translation_id' => null,
			'status'         => null,
			'error_message'  => $error_message,
		);
	}
}
