<?php
/**
 * Tests for changed_since and since_import on nakedcat-glotpress/get-strings.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Originals added or reworded since a date or since the last import.
 */
class GetStringsChangedSinceTest extends Nakedcat_Glotpress_Abilities_TestCase {

	const ABILITY = 'nakedcat-glotpress/get-strings';

	/**
	 * The test project.
	 *
	 * @var GP_Project
	 */
	private $project;

	/**
	 * A first import (backdated to yesterday, the previous release), then a second one that keeps
	 * one original, rewords one and adds one; the added one is then translated.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->as_gp_admin();
		$this->project = $this->make_project( $this->prefix );
		$set           = $this->make_set( $this->project, 'pt' );

		$this->import_originals( $this->project, array( 'Show the order notes', 'Delete the order permanently' ) );
		GP::$original->update(
			array( 'date_added' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ),
			array( 'project_id' => $this->project->id )
		);

		$counts = $this->import_originals( $this->project, array( 'Show the order notes', 'Delete this order permanently', 'Export all invoices' ) );
		$this->assertSame( array( 1, 1 ), array( $counts[0], $counts[2] ), 'The second import should add one original and reword one.' );

		$this->make_translation( $set, $this->original( $this->project, 'Export all invoices' ), 'Exportar todas as faturas', 'current' );
	}

	/**
	 * Runs get-strings for the test project, every status by default.
	 *
	 * @param array $more More input.
	 * @return mixed
	 */
	private function get_strings( $more ) {
		return $this->run_ability(
			self::ABILITY,
			array_merge(
				array(
					'project_path' => $this->project->path,
					'locale'       => 'pt',
					'status'       => 'current_or_waiting_or_fuzzy_or_untranslated',
				),
				$more
			)
		);
	}

	/**
	 * The changes of a result, by singular, sorted.
	 *
	 * @param mixed $result A get-strings result.
	 * @return array
	 */
	private function changes( $result ) {
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$changes = array_column( $result['strings'], 'change', 'singular' );
		ksort( $changes );
		return $changes;
	}

	/**
	 * Since the last import: the added and the reworded originals, not the unchanged one.
	 */
	public function test_since_import() {
		$result = $this->get_strings( array( 'since_import' => true ) );

		$this->assertSame(
			array(
				'Delete this order permanently' => 'changed',
				'Export all invoices'           => 'added',
			),
			$this->changes( $result )
		);
		$this->assertSame( 2, $result['total'] );
		$this->assertNotNull( $result['since'] );
	}

	/**
	 * An import that takes time still counts from its start, not its end.
	 */
	public function test_since_import_counts_from_import_start() {
		$an_hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		GP::$original->update( array( 'date_added' => $an_hour_ago ), array( 'project_id' => $this->project->id ) );
		gp_update_meta( $this->original( $this->project, 'Delete this order permanently' )->id, \NakedCatPlugins\GlotpressAbilities\Original_History::REWORDED_META_KEY, $an_hour_ago, 'original' );

		$this->add_test_filter(
			'gp_originals_imported',
			function () {
				sleep( 1 );
			},
			5
		);
		$this->import_originals( $this->project, array( 'Show the order notes', 'Delete this order permanently', 'Export all invoices', 'Print the label' ) );

		$this->assertSame( array( 'Print the label' => 'added' ), $this->changes( $this->get_strings( array( 'since_import' => true ) ) ) );
	}

	/**
	 * Since a date between the two imports, and since a date before both.
	 */
	public function test_changed_since() {
		$this->assertSame(
			array(
				'Delete this order permanently' => 'changed',
				'Export all invoices'           => 'added',
			),
			$this->changes( $this->get_strings( array( 'changed_since' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ) )
		);

		$this->assertSame(
			array(
				'Delete this order permanently' => 'added',
				'Export all invoices'           => 'added',
				'Show the order notes'          => 'added',
			),
			$this->changes( $this->get_strings( array( 'changed_since' => gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ) ) ) )
		);
	}

	/**
	 * Combined with a status: the new or reworded originals still untranslated.
	 */
	public function test_with_status() {
		$this->assertSame(
			array( 'Delete this order permanently' => 'changed' ),
			$this->changes(
				$this->get_strings(
					array(
						'since_import' => true,
						'status'       => 'untranslated',
					)
				)
			)
		);
	}

	/**
	 * Without either input, change is null and nothing is filtered out.
	 */
	public function test_without_inputs() {
		$result = $this->get_strings( array() );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( array( null, null, null ), array_values( $this->changes( $result ) ) );
		$this->assertNull( $result['since'] );
	}

	/**
	 * Both inputs together, a bad date, and a project with no recorded import are refused.
	 */
	public function test_refusals() {
		$this->assertWPError(
			$this->get_strings(
				array(
					'since_import'  => true,
					'changed_since' => '2026-01-01',
				)
			),
			'nakedcat_glotpress_conflicting_since_input'
		);
		$this->assertWPError( $this->get_strings( array( 'changed_since' => 'not a date' ) ), 'nakedcat_glotpress_invalid_date' );

		$bare = $this->make_project( $this->prefix . '-bare' );
		$this->make_set( $bare, 'pt' );
		$this->assertWPError(
			$this->run_ability(
				self::ABILITY,
				array(
					'project_path' => $bare->path,
					'locale'       => 'pt',
					'since_import' => true,
				)
			),
			'nakedcat_glotpress_no_import_recorded'
		);
	}
}
