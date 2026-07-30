<?php
/**
 * Ability: import a project's originals (source strings) from a .pot/.po file's contents.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities\Abilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the nakedcat-glotpress/import-originals ability.
 *
 * Intended for release automation: a GitHub Actions workflow can call this (over the REST API,
 * with a WordPress Application Password) whenever a new plugin/theme version is tagged, submitting
 * the freshly-built .pot file's contents so the GlotPress project's source strings stay in sync
 * with the codebase without anyone visiting the GlotPress admin UI to upload it by hand.
 *
 * Reuses GlotPress's own originals-import machinery (GP_Format_PO::read_originals_from_file() +
 * GP_Original::import_for_project(), the same pair GP_Route_Project::import_originals_post() and
 * WP-CLI's `glotpress import-originals` command call) rather than reimplementing diffing/fuzzy-
 * matching/obsoleting logic. Only originals (source strings) are affected; no translation is ever
 * written by this ability, though existing translations of a near-matched original are marked
 * fuzzy as a side effect, exactly as a manual upload through the GlotPress UI would do.
 */
class Import_Originals {

	use \NakedCatPlugins\GlotpressAbilities\Requires_Glotpress_Admin;

	const ABILITY_NAME = 'nakedcat-glotpress/import-originals';

	/**
	 * Registers the ability with the Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Import GlotPress originals', 'nakedcat-glotpress-abilities' ),
				'description'         => __( 'Imports a project\'s originals (source strings) from the raw contents of a .pot (or .po) file, diffing them against what the project already has: new strings are added, changed ones are updated, ones no longer present are marked obsolete, and near-matches (e.g. a lightly edited string) are updated in place with their existing translations marked fuzzy for re-review. Reuses GlotPress\'s own import machinery rather than writing rows directly. Intended for release automation (e.g. a GitHub Actions workflow submitting a newly-built .pot file whenever a version is tagged), but works for any caller.', 'nakedcat-glotpress-abilities' ),
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
				'project_path' => array(
					'type'        => 'string',
					'description' => __( 'The GlotPress project path to import originals into, e.g. "wp-plugins/my-plugin".', 'nakedcat-glotpress-abilities' ),
				),
				'pot_content'  => array(
					'type'        => 'string',
					'description' => __( 'The full raw text contents of a .pot (or .po) file, e.g. read directly from the built plugin\'s languages/*.pot file. Only source data (msgid/msgid_plural/msgctxt, extracted comments, references, and any "gp-priority:" flag) is read; any msgstr content is ignored, so submitting a .po file with real translations in it works identically to submitting a .pot template.', 'nakedcat-glotpress-abilities' ),
				),
			),
			'required'             => array( 'project_path', 'pot_content' ),
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
			'type'       => 'object',
			'properties' => array(
				'project_path'        => array(
					'type'        => 'string',
					'description' => __( 'The project this import targeted.', 'nakedcat-glotpress-abilities' ),
				),
				'originals_added'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of brand-new originals created.', 'nakedcat-glotpress-abilities' ),
				),
				'originals_updated'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of existing originals whose comment, references, status, or priority changed and were updated in place. An original present in both the project and the submitted file with no actual differences is not counted here, or anywhere else in this output — it is simply left untouched.', 'nakedcat-glotpress-abilities' ),
				),
				'originals_fuzzied'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of originals matched as a near-match of a string no longer present (e.g. a lightly edited msgid). The old original was updated to the new text/context, and any of its existing current translations were marked fuzzy for re-review, rather than the new string being treated as entirely new and untranslated.', 'nakedcat-glotpress-abilities' ),
				),
				'originals_obsoleted' => array(
					'type'        => 'integer',
					'description' => __( 'Number of existing originals no longer present in the submitted file (and not matched as a near-match of a new one) that were marked obsolete. Obsoleted originals are not deleted and their existing translations are preserved, but they stop appearing in the project\'s active string list.', 'nakedcat-glotpress-abilities' ),
				),
				'originals_error'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of new originals that failed to be created due to an error.', 'nakedcat-glotpress-abilities' ),
				),
			),
			'required'   => array( 'project_path', 'originals_added', 'originals_updated', 'originals_fuzzied', 'originals_obsoleted', 'originals_error' ),
		);
	}

	/**
	 * Executes the ability.
	 *
	 * @param array|null $input Ability input, per input_schema().
	 * @return array|\WP_Error The import summary, or WP_Error if the call is rejected.
	 */
	public static function execute( $input = array() ) {
		$input = is_array( $input ) ? $input : array();

		$project_path = isset( $input['project_path'] ) ? (string) $input['project_path'] : '';
		$pot_content  = isset( $input['pot_content'] ) ? (string) $input['pot_content'] : '';

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

		if ( '' === trim( $pot_content ) ) {
			return new \WP_Error(
				'nakedcat_glotpress_empty_pot_content',
				__( 'pot_content is empty.', 'nakedcat-glotpress-abilities' )
			);
		}

		$translations = self::parse_pot_content( $pot_content );

		if ( is_wp_error( $translations ) ) {
			return $translations;
		}

		list( $originals_added, $originals_updated, $originals_fuzzied, $originals_obsoleted, $originals_error ) = \GP::$original->import_for_project( $project, $translations );

		return array(
			'project_path'        => $project_path,
			'originals_added'     => (int) $originals_added,
			'originals_updated'   => (int) $originals_updated,
			'originals_fuzzied'   => (int) $originals_fuzzied,
			'originals_obsoleted' => (int) $originals_obsoleted,
			'originals_error'     => (int) $originals_error,
		);
	}

	/**
	 * Parses raw .pot/.po file contents into a GlotPress-readable Translations object.
	 *
	 * Writes the content to a temporary file since GlotPress's PO format reader (like the WordPress
	 * core PO parser it wraps) only reads from a real file path, never from a string directly.
	 *
	 * @param string $pot_content The raw file contents.
	 * @return \Translations|\WP_Error
	 */
	private static function parse_pot_content( $pot_content ) {
		$format = isset( \GP::$formats['po'] ) ? \GP::$formats['po'] : null;

		if ( ! $format ) {
			return new \WP_Error(
				'nakedcat_glotpress_po_format_unavailable',
				__( 'The GlotPress PO/POT format handler is not available.', 'nakedcat-glotpress-abilities' )
			);
		}

		if ( ! function_exists( '\WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		\WP_Filesystem();

		global $wp_filesystem;

		$tmp_file = wp_tempnam( 'nakedcat-glotpress-import.pot' );

		if ( ! $tmp_file || ! $wp_filesystem->put_contents( $tmp_file, $pot_content ) ) {
			return new \WP_Error(
				'nakedcat_glotpress_temp_file_failed',
				__( 'Could not write the submitted pot_content to a temporary file for parsing.', 'nakedcat-glotpress-abilities' )
			);
		}

		$translations = $format->read_originals_from_file( $tmp_file );

		wp_delete_file( $tmp_file );

		if ( ! $translations ) {
			return new \WP_Error(
				'nakedcat_glotpress_pot_parse_failed',
				__( 'Could not parse pot_content as a valid PO/POT file.', 'nakedcat-glotpress-abilities' )
			);
		}

		return $translations;
	}
}
