<?php
/**
 * Tests for translation warnings across the abilities (issue #10).
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Warnings in update-translations, get-strings and list-projects-translation-status, and
 * discard-warnings.
 */
class WarningsTest extends Nakedcat_Glotpress_Abilities_TestCase {

	/**
	 * GlotPress's key for a translation whose initial letter case differs from the original's.
	 */
	const CASE_WARNING = 'missing_uppercase_beginning';

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
	 * A project with three originals and a "pt" set.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->project = $this->make_project( $this->prefix );
		$this->set     = $this->make_set( $this->project, 'pt' );
		$this->import_originals( $this->project, array( 'Order', 'list of orders', 'Cart' ) );
	}

	/**
	 * Submits translations through update-translations as a GlotPress admin.
	 *
	 * @param array  $texts  Singular => translation.
	 * @param string $status The status for all of them.
	 * @return array Results keyed by singular.
	 */
	private function submit( $texts, $status ) {
		$this->as_gp_admin();
		$items = array();
		foreach ( $texts as $singular => $text ) {
			$items[] = array(
				'original_id' => (int) $this->original( $this->project, $singular )->id,
				'translation' => array( $text ),
				'status'      => $status,
			);
		}
		$results = $this->run_ability(
			'nakedcat-glotpress/update-translations',
			array(
				'project_path' => $this->project->path,
				'locale'       => 'pt',
				'translations' => $items,
			)
		);
		$this->assertIsArray( $results, is_wp_error( $results ) ? $results->get_error_message() : '' );
		return array_combine( array_keys( $texts ), $results );
	}

	/**
	 * The keys of a warnings list.
	 *
	 * @param array $warnings Warnings, as the abilities return them.
	 * @return string[]
	 */
	private static function keys( $warnings ) {
		return wp_list_pluck( $warnings, 'key' );
	}

	/**
	 * Each update-translations result lists the warnings of the translation it saved, approved or
	 * left unchanged; a clean translation and an error have none.
	 */
	public function test_update_translations_returns_warnings() {
		$results = $this->submit(
			array(
				'Order'          => 'Encomenda',
				'list of orders' => 'Lista de encomendas',
			),
			'waiting'
		);
		$this->assertSame( 'created', $results['list of orders']['result'], wp_json_encode( $results ) );
		$this->assertSame( array(), $results['Order']['warnings'] );
		$this->assertSame(
			array(
				array(
					'plural_index' => 0,
					'key'          => self::CASE_WARNING,
					'message'      => 'The translation appears to be missing the initial lowercase.',
				),
			),
			$results['list of orders']['warnings']
		);

		$approved = $this->submit( array( 'list of orders' => 'Lista de encomendas' ), 'current' );
		$this->assertSame( 'approved', $approved['list of orders']['result'], wp_json_encode( $approved ) );
		$this->assertSame( array( self::CASE_WARNING ), self::keys( $approved['list of orders']['warnings'] ) );

		$unchanged = $this->submit( array( 'list of orders' => 'Lista de encomendas' ), 'current' );
		$this->assertSame( 'unchanged', $unchanged['list of orders']['result'], wp_json_encode( $unchanged ) );
		$this->assertSame( array( self::CASE_WARNING ), self::keys( $unchanged['list of orders']['warnings'] ) );

		$error = $this->submit( array( 'Cart' => '' ), 'current' );
		$this->assertSame( 'error', $error['Cart']['result'] );
		$this->assertSame( array(), $error['Cart']['warnings'] );
	}

	/**
	 * The get-strings ability returns each string's warnings, and with_warnings keeps only those that have some,
	 * with either form of status.
	 */
	public function test_get_strings_warnings() {
		$this->submit(
			array(
				'Order'          => 'Encomenda',
				'list of orders' => 'Lista de encomendas',
			),
			'waiting'
		);

		$all = $this->get_strings( array( 'status' => array( 'untranslated', 'waiting' ) ) );
		$this->assertSame( 3, $all['total'] );
		$by_singular = array_column( $all['strings'], 'warnings', 'singular' );
		$this->assertSame( array(), $by_singular['Order'] );
		$this->assertSame( array(), $by_singular['Cart'], 'Untranslated.' );
		$this->assertSame( array( self::CASE_WARNING ), self::keys( $by_singular['list of orders'] ) );

		foreach ( array( array( 'untranslated', 'waiting' ), 'waiting', 'current_or_waiting' ) as $status ) {
			$filtered = $this->get_strings(
				array(
					'status'        => $status,
					'with_warnings' => true,
				)
			);
			$this->assertSame( 1, $filtered['total'], 'Status ' . wp_json_encode( $status ) );
			$this->assertSame( 'list of orders', $filtered['strings'][0]['singular'] );
		}

		$none = $this->get_strings(
			array(
				'status'        => 'untranslated',
				'with_warnings' => true,
			)
		);
		$this->assertSame( 0, $none['total'], 'Untranslated strings have no warnings.' );
	}

	/**
	 * Runs get-strings on the test set as a GlotPress admin.
	 *
	 * @param array $input More input.
	 * @return array
	 */
	private function get_strings( $input ) {
		$this->as_gp_admin();
		$result = $this->run_ability(
			'nakedcat-glotpress/get-strings',
			array_merge(
				array(
					'project_path' => $this->project->path,
					'locale'       => 'pt',
					'per_page'     => 200,
				),
				$input
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * The list-projects-translation-status ability counts the current and waiting translations with warnings.
	 */
	public function test_list_projects_warnings_count() {
		$this->submit( array( 'list of orders' => 'Lista de encomendas' ), 'current' );
		$this->submit( array( 'Cart' => 'Carrinho' ), 'waiting' );
		$this->assertSame( 1, $this->warnings_count() );

		$this->submit( array( 'Order' => 'Encomenda (pedido feito pelo cliente na loja)' ), 'waiting' );
		$this->assertSame( 2, $this->warnings_count(), 'Length warning on a waiting translation.' );
	}

	/**
	 * The test set's warnings_count, as list-projects-translation-status reports it.
	 *
	 * @return int
	 */
	private function warnings_count() {
		$this->as_gp_admin();
		$result = $this->run_ability(
			'nakedcat-glotpress/list-projects-translation-status',
			array(
				'project_path' => $this->project->path,
				'locale'       => 'pt',
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result[0]['translation_sets'][0]['warnings_count'];
	}

	/**
	 * The discard-warnings ability removes the warning (firing gp_warning_discarded), leaves the text and status
	 * alone, and the string drops out of with_warnings; a key the translation does not have is an error.
	 */
	public function test_discard() {
		$results        = $this->submit( array( 'list of orders' => 'Lista de encomendas' ), 'current' );
		$translation_id = $results['list of orders']['translation_id'];

		$discarded = array();
		$this->add_test_filter(
			'gp_warning_discarded',
			function ( $project_id, $translation_set, $translation, $warning ) use ( &$discarded ) {
				$discarded[] = array( (int) $translation, $warning );
			},
			10,
			4
		);

		$result = $this->run_ability(
			'nakedcat-glotpress/discard-warnings',
			array(
				'warnings' => array(
					array(
						'translation_id' => $translation_id,
						'key'            => self::CASE_WARNING,
					),
					array(
						'translation_id' => $translation_id,
						'key'            => 'placeholders',
					),
					array(
						'translation_id' => 999999999,
						'key'            => self::CASE_WARNING,
					),
				),
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'discarded', $result[0]['result'], wp_json_encode( $result ) );
		$this->assertSame( array(), $result[0]['warnings'] );
		$this->assertSame( 'error', $result[1]['result'], 'Not a warning of this translation.' );
		$this->assertSame( 'error', $result[2]['result'], 'No such translation.' );
		$this->assertSame( array( array( $translation_id, self::CASE_WARNING ) ), $discarded );

		$translation = GP::$translation->get( $translation_id );
		$this->assertEmpty( $translation->warnings );
		$this->assertSame( 'Lista de encomendas', $translation->translation_0 );
		$this->assertSame( 'current', $translation->status );

		$remaining = $this->get_strings(
			array(
				'status'        => 'current',
				'with_warnings' => true,
			)
		);
		$this->assertSame( 0, $remaining['total'] );
		$this->assertSame( 0, $this->warnings_count() );
	}

	/**
	 * The discard-warnings ability needs approve permission on the translation's set.
	 */
	public function test_discard_permission() {
		$results        = $this->submit( array( 'list of orders' => 'Lista de encomendas' ), 'current' );
		$translation_id = $results['list of orders']['translation_id'];
		$input          = array(
			'warnings' => array(
				array(
					'translation_id' => $translation_id,
					'key'            => self::CASE_WARNING,
				),
			),
		);

		$user = $this->make_user( 'subscriber' );
		$this->as_user( $user );
		$this->assertWPError( $this->run_ability( 'nakedcat-glotpress/discard-warnings', $input ), 'ability_invalid_permissions' );
		$this->assertNotEmpty( GP::$translation->get( $translation_id )->warnings );

		$this->grant( $user, 'approve', GP::$validator_permission->object_type, $this->project->id . '|pt|default' );
		$result = $this->run_ability( 'nakedcat-glotpress/discard-warnings', $input );
		$this->assertSame( 'discarded', $result[0]['result'], (string) $result[0]['error_message'] );
	}
}
