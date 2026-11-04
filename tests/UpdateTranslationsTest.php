<?php
/**
 * Tests for nakedcat-glotpress/update-translations.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * What a submitted translation must look like to be saved.
 */
class UpdateTranslationsTest extends Nakedcat_Glotpress_Abilities_TestCase {

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
	 * Submit one translation item for "Order".
	 *
	 * @param array $item The item, without original_id.
	 * @return array|WP_Error
	 */
	private function submit( $item ) {
		$this->as_gp_admin();
		return $this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $this->project->path,
				'locale'       => 'pt',
				'translations' => array( array_merge( array( 'original_id' => (int) $this->original( $this->project, 'Order' )->id ), $item ) ),
			)
		);
	}

	/**
	 * Translations of "Order" in the test set.
	 *
	 * @return GP_Translation[]
	 */
	private function saved() {
		return GP::$translation->find_many(
			array(
				'original_id'        => $this->original( $this->project, 'Order' )->id,
				'translation_set_id' => $this->set->id,
			)
		);
	}

	/**
	 * A list of forms is saved as current.
	 */
	public function test_list_saved() {
		$result = $this->submit(
			array(
				'translation' => array( 'Encomenda' ),
				'status'      => 'current',
			)
		);
		$this->assertSame( 'created', $result[0]['result'], wp_json_encode( $result ) );
		$saved = $this->saved();
		$this->assertCount( 1, $saved );
		$this->assertSame( 'Encomenda', $saved[0]->translation_0 );
		$this->assertSame( 'current', $saved[0]->status );
	}

	/**
	 * A string is taken as the single form, not dropped (it used to be saved empty).
	 */
	public function test_string_taken_as_single_form() {
		$result = $this->submit(
			array(
				'translation' => 'Encomenda',
				'status'      => 'current',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'created', $result[0]['result'], wp_json_encode( $result ) );
		$this->assertSame( 'Encomenda', $this->saved()[0]->translation_0 );
	}

	/**
	 * Empty, blank or not text: refused, nothing saved.
	 *
	 * @param mixed $translation The submitted translation.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'empty_translations' )]
	public function test_empty_refused( $translation ) {
		$result = $this->submit(
			array(
				'translation' => $translation,
				'status'      => 'current',
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_message() ); // Refused by the schema check
		} else {
			$this->assertSame( 'error', $result[0]['result'], wp_json_encode( $result ) );
		}
		$this->assertCount( 0, $this->saved() );
	}

	/**
	 * Translations that must never be saved.
	 *
	 * @return array
	 */
	public static function empty_translations() {
		return array(
			'empty string'     => array( '' ),
			'blank string'     => array( '   ' ),
			'empty first form' => array( array( '' ) ),
			'blank first form' => array( array( ' ', 'Encomendas' ) ),
			'not text'         => array( array( array( 'Encomenda' ) ) ),
		);
	}
}
