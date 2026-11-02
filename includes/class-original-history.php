<?php
/**
 * Records when originals are reworded and when a project's originals were last imported.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GlotPress keeps no modification date on originals: when an import rewords one (a near match of
 * a string that is no longer in the file), it rewrites the row in place, and only a current
 * translation shows a trace (it is demoted to fuzzy). This class records that moment as GlotPress
 * meta on the original, and each import's start and end as meta on the project, through
 * GlotPress's own import hooks, so get-strings can tell which originals are new or reworded and
 * which waiting translations describe English that no longer exists.
 *
 * History starts when this code first runs: rewordings and imports from before are unknown.
 * All dates are GMT, in MySQL format, like GlotPress's own date columns.
 */
class Original_History {

	const REWORDED_META_KEY = 'nakedcat_reworded_at';

	const IMPORT_META_KEY = 'nakedcat_last_import';

	/**
	 * When the import running in this request started, by project ID.
	 *
	 * @var array<int, string>
	 */
	private static $import_started = array();

	/**
	 * Hooks into GlotPress's originals import.
	 */
	public static function register_hooks() {
		add_filter( 'gp_import_original_array', array( __CLASS__, 'note_import_start' ), 999 );
		add_filter( 'gp_set_translations_for_original_to_fuzzy', array( __CLASS__, 'record_reworded' ), 999, 3 );
		add_action( 'gp_originals_imported', array( __CLASS__, 'record_import' ), 10, 6 );
	}

	/**
	 * Notes when an import starts: the first original GlotPress looks at, per project and request.
	 *
	 * @param array $data The original's data, with its project_id.
	 * @return array The same data.
	 */
	public static function note_import_start( $data ) {
		$project_id = isset( $data['project_id'] ) ? (int) $data['project_id'] : 0;

		if ( $project_id && ! isset( self::$import_started[ $project_id ] ) ) {
			self::$import_started[ $project_id ] = self::now();
		}

		return $data;
	}

	/**
	 * Records that an original is being reworded by an import. Runs whatever the filter decides
	 * about fuzzying its translations: the original's text changes either way.
	 *
	 * @param bool   $do_fuzzy Whether GlotPress will mark the original's current translations fuzzy.
	 * @param object $data     The new original data.
	 * @param object $original The original being reworded.
	 * @return bool The same decision.
	 */
	public static function record_reworded( $do_fuzzy, $data, $original ) {
		if ( isset( $original->id ) ) {
			gp_update_meta( (int) $original->id, self::REWORDED_META_KEY, self::now(), 'original' );
		}

		return $do_fuzzy;
	}

	/**
	 * Records a finished import on its project.
	 *
	 * @param int $project_id          The project ID.
	 * @param int $originals_added     Originals added.
	 * @param int $originals_existing  Originals updated.
	 * @param int $originals_obsoleted Originals obsoleted.
	 * @param int $originals_fuzzied   Originals reworded with their translations fuzzied.
	 * @param int $originals_error     Originals that failed.
	 */
	public static function record_import( $project_id, $originals_added, $originals_existing, $originals_obsoleted, $originals_fuzzied, $originals_error ) {
		$project_id = (int) $project_id;
		$finished   = self::now();
		$started    = isset( self::$import_started[ $project_id ] ) ? self::$import_started[ $project_id ] : $finished;

		unset( self::$import_started[ $project_id ] );

		gp_update_meta(
			$project_id,
			self::IMPORT_META_KEY,
			array(
				'started'   => $started,
				'finished'  => $finished,
				'added'     => (int) $originals_added,
				'updated'   => (int) $originals_existing,
				'fuzzied'   => (int) $originals_fuzzied,
				'obsoleted' => (int) $originals_obsoleted,
				'error'     => (int) $originals_error,
			),
			'project'
		);
	}

	/**
	 * When each of the given originals was last reworded, for those with a record.
	 *
	 * @param int[] $original_ids Original IDs.
	 * @return array<int, string> GMT dates, by original ID.
	 */
	public static function reworded_at( $original_ids ) {
		global $wpdb;

		$original_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $original_ids ) ) ) );

		if ( ! $original_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $original_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, meta_value FROM {$wpdb->gp_meta} WHERE object_type = 'original' AND meta_key = %s AND object_id IN ( $placeholders )",
				array_merge( array( self::REWORDED_META_KEY ), $original_ids )
			)
		);
		// phpcs:enable

		$dates = array();

		foreach ( (array) $rows as $row ) {
			$dates[ (int) $row->object_id ] = (string) $row->meta_value;
		}

		return $dates;
	}

	/**
	 * The last recorded import of a project's originals.
	 *
	 * @param int $project_id The project ID.
	 * @return array{started: string, finished: string}|null Null when no import was recorded.
	 */
	public static function last_import( $project_id ) {
		$import = gp_get_meta( 'project', (int) $project_id, self::IMPORT_META_KEY );

		return is_array( $import ) && isset( $import['started'], $import['finished'] ) ? $import : null;
	}

	/**
	 * The current GMT date, in GlotPress's format.
	 *
	 * @return string
	 */
	private static function now() {
		return \GP::$original->now_in_mysql_format();
	}
}
