<?php
/**
 * Tests for the status filter of nakedcat-glotpress/get-strings.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Several statuses at once, and the refused "_or_" combinations.
 */
class GetStringsStatusesTest extends Nakedcat_Glotpress_Abilities_TestCase {

	const ABILITY = 'nakedcat-glotpress/get-strings';

	/**
	 * The test project.
	 *
	 * @var GP_Project
	 */
	private $project;

	/**
	 * A project with one string in each status: untranslated, fuzzy, waiting, current, rejected.
	 * As in GlotPress, a string whose only translation was rejected counts as untranslated.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->as_gp_admin();
		$this->project = $this->make_project( $this->prefix );
		$set           = $this->make_set( $this->project, 'pt' );
		$this->import_originals( $this->project, array( 'Untranslated one', 'Fuzzy one', 'Waiting one', 'Current one', 'Rejected one' ) );
		$this->make_translation( $set, $this->original( $this->project, 'Fuzzy one' ), 'Difuso', 'fuzzy' );
		$this->make_translation( $set, $this->original( $this->project, 'Waiting one' ), 'Em espera', 'waiting' );
		$this->make_translation( $set, $this->original( $this->project, 'Current one' ), 'Atual', 'current' );
		$this->make_translation( $set, $this->original( $this->project, 'Rejected one' ), 'Rejeitado', 'rejected' );
	}

	/**
	 * Runs get-strings for the test project.
	 *
	 * @param mixed $status The status input.
	 * @param array $more   More input.
	 * @return mixed
	 */
	private function get_strings( $status, $more = array() ) {
		return $this->run_ability(
			self::ABILITY,
			array_merge(
				array(
					'project_path' => $this->project->path,
					'locale'       => 'pt',
					'status'       => $status,
				),
				$more
			)
		);
	}

	/**
	 * The list form returns exactly the union, each string with its status.
	 */
	public function test_list_returns_exact_union() {
		$result = $this->get_strings( array( 'untranslated', 'fuzzy', 'waiting' ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 4, $result['total'] );
		$this->assertCount( 4, $result['strings'] );
		$by_singular = array_column( $result['strings'], 'translation_status', 'singular' );
		ksort( $by_singular );
		$this->assertSame(
			array(
				'Fuzzy one'        => 'fuzzy',
				'Rejected one'     => null,
				'Untranslated one' => null,
				'Waiting one'      => 'waiting',
			),
			$by_singular
		);
	}

	/**
	 * Pagination runs over the merged result.
	 */
	public function test_list_paginates_merged_result() {
		$statuses = array( 'untranslated', 'fuzzy', 'waiting' );
		$first    = $this->get_strings( $statuses, array( 'per_page' => 3 ) );
		$second   = $this->get_strings(
			$statuses,
			array(
				'per_page' => 3,
				'page'     => 2,
			)
		);

		$this->assertSame( 4, $first['total'] );
		$this->assertCount( 3, $first['strings'] );
		$this->assertSame( 4, $second['total'] );
		$this->assertCount( 1, $second['strings'] );
		$this->assertEqualsCanonicalizing(
			array( 'Untranslated one', 'Rejected one', 'Fuzzy one', 'Waiting one' ),
			array_merge( wp_list_pluck( $first['strings'], 'singular' ), wp_list_pluck( $second['strings'], 'singular' ) )
		);
	}

	/**
	 * A combination with "untranslated" and without "current" is refused, naming the list form.
	 */
	public function test_broken_or_values_refused() {
		foreach ( array( 'untranslated_or_fuzzy', 'fuzzy_or_untranslated_or_waiting' ) as $status ) {
			$result = $this->get_strings( $status );
			$this->assertWPError( $result, 'nakedcat_glotpress_unreliable_status_combination', $status );
			$this->assertStringContainsString( '["', $result->get_error_message() );
		}
	}

	/**
	 * The string values that work keep working as before.
	 */
	public function test_working_string_values_unchanged() {
		$this->assertSame( array( 'Fuzzy one' ), wp_list_pluck( $this->get_strings( 'fuzzy' )['strings'], 'singular' ) );
		$this->assertEqualsCanonicalizing( array( 'Untranslated one', 'Rejected one' ), wp_list_pluck( $this->get_strings( 'untranslated' )['strings'], 'singular' ) );
		$this->assertEqualsCanonicalizing(
			array( 'Fuzzy one', 'Waiting one' ),
			wp_list_pluck( $this->get_strings( 'fuzzy_or_waiting' )['strings'], 'singular' )
		);
		$this->assertSame( 5, $this->get_strings( 'current_or_waiting_or_fuzzy_or_untranslated' )['total'] );
	}

	/**
	 * Without a status, the documented default ("untranslated") applies.
	 */
	public function test_default_is_untranslated() {
		$result = $this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $this->project->path,
				'locale'       => 'pt',
			)
		);
		$this->assertEqualsCanonicalizing( array( 'Untranslated one', 'Rejected one' ), wp_list_pluck( $result['strings'], 'singular' ) );
	}

	/**
	 * An unknown status in the list is refused.
	 */
	public function test_unknown_status_in_list_refused() {
		$this->assertWPError( $this->get_strings( array( 'fuzzy', 'nope' ) ), 'ability_invalid_input' );
	}
}
