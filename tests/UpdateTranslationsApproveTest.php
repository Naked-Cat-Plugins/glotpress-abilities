<?php
/**
 * Tests for nakedcat-glotpress/update-translations: an identical text resubmitted with a higher status.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Approving an identical waiting or fuzzy translation, and never demoting one (issue #9).
 */
class UpdateTranslationsApproveTest extends Nakedcat_Glotpress_Abilities_TestCase {

	const ABILITY = 'nakedcat-glotpress/update-translations';

	/**
	 * The test project.
	 *
	 * @var GP_Project
	 */
	private $project;

	/**
	 * Its "pt" translation set.
	 *
	 * @var GP_Translation_Set
	 */
	private $set;

	/**
	 * A project with one original and a "pt" set.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->project = $this->make_project( $this->prefix );
		$this->set     = $this->make_set( $this->project, 'pt' );
		$this->import_originals( $this->project, array( 'Order' ) );
	}

	/**
	 * Submits one translation of "Order" as a GlotPress admin.
	 *
	 * @param string $text   The translation.
	 * @param string $status The status asked for.
	 * @param string $locale The locale.
	 * @return array The item's result.
	 */
	private function submit( $text, $status, $locale = 'pt' ) {
		$this->as_gp_admin();
		$result = $this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $this->project->path,
				'locale'       => $locale,
				'translations' => array(
					array(
						'original_id' => (int) $this->original( $this->project, 'Order' )->id,
						'translation' => array( $text ),
						'status'      => $status,
					),
				),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result[0];
	}

	/**
	 * Translations of "Order" in a set, keyed by text, each its status.
	 *
	 * @param GP_Translation_Set|null $set The set, the "pt" one when null.
	 * @return array<string, string>
	 */
	private function statuses( $set = null ) {
		$statuses = array();
		foreach ( GP::$translation->find_many(
			array(
				'original_id'        => $this->original( $this->project, 'Order' )->id,
				'translation_set_id' => ( $set ? $set : $this->set )->id,
			)
		) as $translation ) {
			$statuses[ $translation->translation_0 ] = $translation->status;
		}
		return $statuses;
	}

	/**
	 * An identical waiting or fuzzy translation asked as current is approved in place, through
	 * set_status() (gp_translation_saved fires for it), and the previous current becomes old.
	 *
	 * @param string $existing_status The identical translation's status.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'promotable_statuses' )]
	public function test_identical_approved( $existing_status ) {
		$original = $this->original( $this->project, 'Order' );
		$this->make_translation( $this->set, $original, 'Pedido', 'current' );
		$existing = $this->make_translation( $this->set, $original, 'Encomenda', $existing_status );

		$saved = array();
		$this->add_test_filter(
			'gp_translation_saved',
			function ( $translation ) use ( &$saved ) {
				$saved[] = array( (int) $translation->id, $translation->status );
			}
		);

		$result = $this->submit( 'Encomenda', 'current' );

		$this->assertSame( 'approved', $result['result'], wp_json_encode( $result ) );
		$this->assertSame( (int) $existing->id, $result['translation_id'] );
		$this->assertSame( 'current', $result['status'] );
		$this->assertSame(
			array(
				'Pedido'    => 'old',
				'Encomenda' => 'current',
			),
			$this->statuses(),
			'No new row, the previous current demoted.'
		);
		$this->assertContains( array( (int) $existing->id, 'current' ), $saved, 'gp_translation_saved did not fire for the approval.' );
	}

	/**
	 * Statuses an identical translation is approved from.
	 *
	 * @return array
	 */
	public static function promotable_statuses() {
		return array(
			'waiting' => array( 'waiting' ),
			'fuzzy'   => array( 'fuzzy' ),
		);
	}

	/**
	 * The same text at the same or a lower status changes nothing: never demoted, no new row.
	 *
	 * @param string $existing_status  The identical translation's status.
	 * @param string $requested_status The status asked for.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unchanged_cases' )]
	public function test_never_demoted( $existing_status, $requested_status ) {
		$existing = $this->make_translation( $this->set, $this->original( $this->project, 'Order' ), 'Encomenda', $existing_status );

		$result = $this->submit( 'Encomenda', $requested_status );

		$this->assertSame( 'unchanged', $result['result'], wp_json_encode( $result ) );
		$this->assertSame( (int) $existing->id, $result['translation_id'] );
		$this->assertSame( $existing_status, $result['status'] );
		$this->assertSame( array( 'Encomenda' => $existing_status ), $this->statuses() );
	}

	/**
	 * Existing and requested statuses that must leave the translation as it is.
	 *
	 * @return array
	 */
	public static function unchanged_cases() {
		return array(
			'current as current' => array( 'current', 'current' ),
			'current as waiting' => array( 'current', 'waiting' ),
			'current as fuzzy'   => array( 'current', 'fuzzy' ),
			'waiting as waiting' => array( 'waiting', 'waiting' ),
			'waiting as fuzzy'   => array( 'waiting', 'fuzzy' ),
			'fuzzy as fuzzy'     => array( 'fuzzy', 'fuzzy' ),
		);
	}

	/**
	 * Of several identical translations, the highest status decides: a current one wins over a
	 * waiting duplicate, so nothing is approved and nothing changes.
	 */
	public function test_identical_current_wins_over_waiting_duplicate() {
		$original = $this->original( $this->project, 'Order' );
		$current  = $this->make_translation( $this->set, $original, 'Encomenda', 'current' );
		$waiting  = $this->make_translation( $this->set, $original, 'Encomenda', 'waiting' );

		$result = $this->submit( 'Encomenda', 'current' );

		$this->assertSame( 'unchanged', $result['result'], wp_json_encode( $result ) );
		$this->assertSame( (int) $current->id, $result['translation_id'] );
		$this->assertSame( 'waiting', GP::$translation->get( $waiting->id )->status );
	}

	/**
	 * A "pt" approval is mirrored to "pt-ao", where the same waiting text is approved too.
	 */
	public function test_pt_ao_mirror_approved() {
		$pt_ao_set = $this->make_set( $this->project, 'pt-ao' );
		$original  = $this->original( $this->project, 'Order' );
		$this->make_translation( $this->set, $original, 'Encomenda', 'waiting' );
		$this->make_translation( $pt_ao_set, $original, 'Encomenda', 'waiting' );

		$result = $this->submit( 'Encomenda', 'current' );

		$this->assertSame( 'approved', $result['result'], wp_json_encode( $result ) );
		$this->assertSame( 'approved', $result['pt_ao_mirror']['result'], wp_json_encode( $result ) );
		$this->assertSame( array( 'Encomenda' => 'current' ), $this->statuses( $pt_ao_set ) );
	}

	/**
	 * With gp-convert-pt-ao90 active, approving a waiting "pt" translation this way creates its
	 * converted "pt-ao90" translation, as the admin's Approve button does.
	 */
	public function test_pt_approval_syncs_pt_ao90() {
		if ( ! class_exists( '\GP_Convert_PT_AO90\Portuguese_AO90' ) ) {
			$this->markTestSkipped( 'gp-convert-pt-ao90 is not active on this install.' );
		}
		$pt_ao90_set = $this->make_set( $this->project, 'pt-ao90' );
		$this->make_translation( $this->set, $this->original( $this->project, 'Order' ), 'Acção', 'waiting' );
		$this->assertSame( array(), $this->statuses( $pt_ao90_set ), 'A waiting translation is not converted.' );

		$result = $this->submit( 'Acção', 'current' );

		$this->assertSame( 'approved', $result['result'], wp_json_encode( $result ) );
		$this->assertSame( array( 'Ação' => 'current' ), $this->statuses( $pt_ao90_set ) );
	}
}
