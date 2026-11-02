<?php
/**
 * Tests for the stale waiting translation flag of nakedcat-glotpress/get-strings.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Waiting translations made stale by a reworded original.
 */
class GetStringsStaleTest extends Nakedcat_Glotpress_Abilities_TestCase {

	const ABILITY = 'nakedcat-glotpress/get-strings';

	/**
	 * The test project.
	 *
	 * @var GP_Project
	 */
	private $project;

	/**
	 * Two originals with a waiting translation each from yesterday, then a re-import that rewords
	 * the first one and keeps the second, then a newer waiting translation of the reworded one.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->as_gp_admin();
		$this->project = $this->make_project( $this->prefix );
		$set           = $this->make_set( $this->project, 'pt' );
		$yesterday     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$tomorrow      = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$this->import_originals( $this->project, array( 'Delete the order permanently', 'Save changes' ) );
		$this->make_translation( $set, $this->original( $this->project, 'Delete the order permanently' ), 'Apagar a encomenda', 'waiting', 0, $yesterday );
		$this->make_translation( $set, $this->original( $this->project, 'Save changes' ), 'Guardar', 'waiting', 0, $yesterday );

		$counts = $this->import_originals( $this->project, array( 'Delete this order permanently', 'Save changes' ) );
		$this->assertSame( 1, $counts[2], 'The re-import should reword one original.' );

		$this->make_translation( $set, $this->original( $this->project, 'Delete this order permanently' ), 'Apagar esta encomenda', 'waiting', 1, $tomorrow );
	}

	/**
	 * Runs get-strings for the test project's waiting strings.
	 *
	 * @param array $more More input.
	 * @return array
	 */
	private function get_strings( $more = array() ) {
		$result = $this->run_ability(
			self::ABILITY,
			array_merge(
				array(
					'project_path' => $this->project->path,
					'locale'       => 'pt',
					'status'       => 'waiting',
				),
				$more
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * Only the translation submitted before the rewording is flagged.
	 */
	public function test_flag() {
		$flags = array();
		foreach ( $this->get_strings()['strings'] as $string ) {
			$flags[ $string['translations'][0] ] = $string['stale'];
		}
		ksort( $flags );

		$this->assertSame(
			array(
				'Apagar a encomenda'    => true,
				'Apagar esta encomenda' => false,
				'Guardar'               => false,
			),
			$flags
		);
	}

	/**
	 * The filter returns only the stale translation, whatever the status input.
	 */
	public function test_stale_only() {
		$result = $this->get_strings(
			array(
				'stale_only' => true,
				'status'     => 'current',
			)
		);

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Apagar a encomenda', $result['strings'][0]['translations'][0] );
		$this->assertSame( 'Delete this order permanently', $result['strings'][0]['singular'] );
		$this->assertTrue( $result['strings'][0]['stale'] );
	}

	/**
	 * Whatever the statuses asked for, only the stale waiting translation is flagged.
	 */
	public function test_flag_with_all_statuses() {
		$result = $this->get_strings( array( 'status' => 'current_or_waiting_or_fuzzy_or_untranslated' ) );
		$stale  = wp_list_filter( $result['strings'], array( 'stale' => true ) );

		$this->assertCount( 1, $stale );
		$this->assertSame( 'Apagar a encomenda', reset( $stale )['translations'][0] );
	}
}
