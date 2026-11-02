<?php
/**
 * Tests for nakedcat-glotpress/review-translations.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Approving and rejecting waiting translations.
 */
class ReviewTranslationsTest extends Nakedcat_Glotpress_Abilities_TestCase {

	const ABILITY = 'nakedcat-glotpress/review-translations';

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
	 * A project with two originals, each with a current and a waiting translation, and one fuzzy.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->project = $this->make_project( $this->prefix );
		$this->set     = $this->make_set( $this->project, 'pt' );
		$this->import_originals( $this->project, array( 'Order', 'Cart' ) );
		foreach ( array( 'Order', 'Cart' ) as $singular ) {
			$original = $this->original( $this->project, $singular );
			$this->make_translation( $this->set, $original, $singular . ' atual', 'current' );
			$this->make_translation( $this->set, $original, $singular . ' em espera', 'waiting' );
		}
		$this->make_translation( $this->set, $this->original( $this->project, 'Cart' ), 'Cart difuso', 'fuzzy' );
		$this->on_teardown(
			function () {
				global $wpdb;
				foreach ( GP::$translation->find_many( array( 'translation_set_id' => $this->set->id ) ) as $translation ) {
					$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->gp_meta,
						array(
							'object_type' => 'translation',
							'object_id'   => $translation->id,
						)
					);
				}
			}
		);
	}

	/**
	 * A translation of an original in the test set, by status.
	 *
	 * @param string $singular The original's singular.
	 * @param string $status   The status.
	 * @return GP_Translation|false
	 */
	private function translation( $singular, $status ) {
		return GP::$translation->find_one(
			array(
				'original_id'        => $this->original( $this->project, $singular )->id,
				'translation_set_id' => $this->set->id,
				'status'             => $status,
			)
		);
	}

	/**
	 * Approve by translation ID demotes the old current; reject by original ID keeps the reason.
	 */
	public function test_approve_and_reject() {
		$this->as_gp_admin();
		$old_current = $this->translation( 'Order', 'current' );
		$waiting     = $this->translation( 'Order', 'waiting' );

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $this->project->path,
				'locale'       => 'pt',
				'reviews'      => array(
					array(
						'translation_id' => (int) $waiting->id,
						'action'         => 'approve',
					),
					array(
						'original_id' => (int) $this->original( $this->project, 'Cart' )->id,
						'action'      => 'reject',
						'reason'      => 'Wrong term',
					),
				),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 'approved', 'rejected' ), wp_list_pluck( $result, 'result' ), wp_json_encode( $result ) );
		$this->assertSame( 'current', GP::$translation->get( $waiting->id )->status );
		$this->assertSame( 'old', GP::$translation->get( $old_current->id )->status );
		$this->assertSame( 'Wrong term', $result[1]['reason'] );
		$this->assertSame( 'Wrong term', gp_get_meta( 'translation', $result[1]['translation_id'], \NakedCatPlugins\GlotpressAbilities\Abilities\Review_Translations::REASON_META_KEY ) );
		$this->assertSame( 'rejected', GP::$translation->get( $result[1]['translation_id'] )->status );
		$this->assertSame( 'Cart atual', $this->translation( 'Cart', 'current' )->translation_0 );
	}

	/**
	 * A translation that is not waiting is refused and left alone.
	 */
	public function test_non_waiting_refused() {
		$this->as_gp_admin();
		$fuzzy   = $this->translation( 'Cart', 'fuzzy' );
		$current = $this->translation( 'Cart', 'current' );

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'reviews' => array(
					array(
						'translation_id' => (int) $fuzzy->id,
						'action'         => 'approve',
					),
					array(
						'translation_id' => (int) $current->id,
						'action'         => 'reject',
					),
				),
			)
		);

		$this->assertSame( array( 'error', 'error' ), wp_list_pluck( $result, 'result' ) );
		$this->assertSame( 'fuzzy', GP::$translation->get( $fuzzy->id )->status );
		$this->assertSame( 'current', GP::$translation->get( $current->id )->status );
	}

	/**
	 * Without approve permission on the set the call is refused; with it (as a validator) it works.
	 */
	public function test_approve_permission_required() {
		$waiting = $this->translation( 'Order', 'waiting' );
		$input   = array(
			'reviews' => array(
				array(
					'translation_id' => (int) $waiting->id,
					'action'         => 'approve',
				),
			),
		);
		$user    = $this->make_user( 'subscriber' );
		$this->as_user( $user );

		$this->assertWPError( $this->run_ability( self::ABILITY, $input ), 'ability_invalid_permissions' );
		$this->assertSame( 'waiting', GP::$translation->get( $waiting->id )->status );

		$this->grant( $user, 'approve', GP::$validator_permission->object_type, $this->project->id . '|pt-br|default' );
		$this->assertWPError( $this->run_ability( self::ABILITY, $input ), 'ability_invalid_permissions', 'Validator of another locale.' );

		$this->grant( $user, 'approve', GP::$validator_permission->object_type, $this->project->id . '|pt|default' );
		$result = $this->run_ability( self::ABILITY, $input );
		$this->assertSame( 'approved', $result[0]['result'], (string) $result[0]['error_message'] );
	}
}
