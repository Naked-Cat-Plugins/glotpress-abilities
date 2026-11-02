<?php
/**
 * Tests for nakedcat-glotpress/update-glossary-entries and delete-glossary-entries.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Editing and deleting glossary entries by ID.
 */
class GlossaryEditDeleteTest extends Nakedcat_Glotpress_Abilities_TestCase {

	/**
	 * The test project, with a "pt" set and its own glossary.
	 *
	 * @var GP_Project
	 */
	private $project;

	/**
	 * A project glossary with "order" (noun) and "cart" (noun).
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->as_gp_admin();
		$this->project = $this->make_project( $this->prefix );
		$this->make_set( $this->project, 'pt' );
		$added = $this->run_ability(
			'nakedcat-glotpress/add-glossary-entries',
			array(
				'locale'       => 'pt',
				'project_path' => $this->project->path,
				'entries'      => array(
					array(
						'term'           => 'order',
						'part_of_speech' => 'noun',
						'translation'    => 'encomenda',
					),
					array(
						'term'           => 'order',
						'part_of_speech' => 'verb',
						'translation'    => 'encomendar',
					),
					array(
						'term'           => 'cart',
						'part_of_speech' => 'noun',
						'translation'    => 'cesto',
					),
				),
			)
		);
		$this->assertSame( array( 'created', 'created', 'created' ), wp_list_pluck( $added, 'result' ) );
	}

	/**
	 * The glossary entries, by "term/part of speech", as get-glossary returns them.
	 *
	 * @return array
	 */
	private function entries() {
		$glossary = $this->run_ability(
			'nakedcat-glotpress/get-glossary',
			array(
				'locale'       => 'pt',
				'project_path' => $this->project->path,
			)
		);
		$entries  = array();
		foreach ( $glossary['entries'] as $entry ) {
			$entries[ $entry['term'] . '/' . $entry['part_of_speech'] ] = $entry;
		}
		return $entries;
	}

	/**
	 * Runs an ability on the test project's glossary.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input More input.
	 * @return mixed
	 */
	private function on_glossary( $name, $input ) {
		return $this->run_ability(
			$name,
			array_merge(
				array(
					'locale'       => 'pt',
					'project_path' => $this->project->path,
				),
				$input
			)
		);
	}

	/**
	 * Get-glossary returns each entry's ID.
	 */
	public function test_get_glossary_returns_ids() {
		$entries = $this->entries();
		$this->assertIsInt( $entries['cart/noun']['id'] );
		$this->assertSame( 'cart', GP::$glossary_entry->get( $entries['cart/noun']['id'] )->term );
	}

	/**
	 * Translation and comment change; a resubmission of the same values is unchanged.
	 */
	public function test_update() {
		$id     = $this->entries()['cart/noun']['id'];
		$change = array(
			'entry_id'    => $id,
			'translation' => 'carrinho',
			'comment'     => 'Loja online',
		);

		$result = $this->on_glossary( 'nakedcat-glotpress/update-glossary-entries', array( 'entries' => array( $change ) ) );
		$this->assertSame( 'updated', $result[0]['result'], (string) $result[0]['error_message'] );
		$this->assertSame( 'carrinho', $result[0]['translation'] );

		$entry = $this->entries()['cart/noun'];
		$this->assertSame( 'carrinho', $entry['translation'] );
		$this->assertSame( 'Loja online', $entry['comment'] );

		$again = $this->on_glossary( 'nakedcat-glotpress/update-glossary-entries', array( 'entries' => array( $change ) ) );
		$this->assertSame( 'unchanged', $again[0]['result'] );
	}

	/**
	 * A part of speech change that would duplicate another entry is refused; a free one works.
	 */
	public function test_duplicate_refused() {
		$entries = $this->entries();

		$result = $this->on_glossary(
			'nakedcat-glotpress/update-glossary-entries',
			array(
				'entries' => array(
					array(
						'entry_id'       => $entries['order/verb']['id'],
						'part_of_speech' => 'noun',
					),
					array(
						'entry_id'       => $entries['cart/noun']['id'],
						'part_of_speech' => 'verb',
					),
				),
			)
		);

		$this->assertSame( 'error', $result[0]['result'] );
		$this->assertStringContainsString( (string) $entries['order/noun']['id'], $result[0]['error_message'] );
		$this->assertSame( 'updated', $result[1]['result'], (string) $result[1]['error_message'] );

		$after = $this->entries();
		$this->assertSame( 'encomendar', $after['order/verb']['translation'] );
		$this->assertSame( 'encomenda', $after['order/noun']['translation'] );
		$this->assertArrayHasKey( 'cart/verb', $after );
	}

	/**
	 * Delete removes only the IDs given; IDs from another glossary are refused and kept.
	 */
	public function test_delete_and_scope() {
		$entries   = $this->entries();
		$other     = $this->make_project( $this->prefix . '-other' );
		$other_set = $this->make_set( $other, 'pt' );
		$this->run_ability(
			'nakedcat-glotpress/add-glossary-entries',
			array(
				'locale'       => 'pt',
				'project_path' => $other->path,
				'entries'      => array(
					array(
						'term'           => 'stock',
						'part_of_speech' => 'noun',
						'translation'    => 'stock',
					),
				),
			)
		);
		$foreign = GP::$glossary_entry->find_one(
			array(
				'glossary_id' => GP::$glossary->by_set_id( $other_set->id )->id,
				'term'        => 'stock',
			)
		);

		$result = $this->on_glossary( 'nakedcat-glotpress/delete-glossary-entries', array( 'entry_ids' => array( $entries['cart/noun']['id'], (int) $foreign->id ) ) );

		$this->assertSame( array( 'deleted', 'error' ), wp_list_pluck( $result, 'result' ) );
		$this->assertSame( array( 'order/noun', 'order/verb' ), array_keys( $this->entries() ) );
		$this->assertInstanceOf( GP_Glossary_Entry::class, GP::$glossary_entry->get( $foreign->id ) );

		$update = $this->on_glossary(
			'nakedcat-glotpress/update-glossary-entries',
			array(
				'entries' => array(
					array(
						'entry_id'    => (int) $foreign->id,
						'translation' => 'existências',
					),
				),
			)
		);
		$this->assertSame( 'error', $update[0]['result'] );
		$this->assertSame( 'stock', GP::$glossary_entry->get( $foreign->id )->translation );
	}

	/**
	 * A project without a glossary of its own is refused, never created.
	 */
	public function test_missing_glossary_refused() {
		$bare = $this->make_project( $this->prefix . '-bare' );
		$set  = $this->make_set( $bare, 'pt' );

		$result = $this->run_ability(
			'nakedcat-glotpress/delete-glossary-entries',
			array(
				'locale'       => 'pt',
				'project_path' => $bare->path,
				'entry_ids'    => array( 1 ),
			)
		);

		$this->assertWPError( $result, 'nakedcat_glotpress_glossary_not_found' );
		$this->assertFalse( GP::$glossary->by_set_id( $set->id ) );
	}

	/**
	 * Without GlotPress admin, both abilities are refused.
	 */
	public function test_permissions() {
		$id = $this->entries()['cart/noun']['id'];
		$this->as_user( $this->make_user( 'subscriber' ) );

		$this->assertWPError(
			$this->on_glossary(
				'nakedcat-glotpress/update-glossary-entries',
				array(
					'entries' => array(
						array(
							'entry_id'    => $id,
							'translation' => 'x',
						),
					),
				)
			),
			'ability_invalid_permissions'
		);
		$this->assertWPError( $this->on_glossary( 'nakedcat-glotpress/delete-glossary-entries', array( 'entry_ids' => array( $id ) ) ), 'ability_invalid_permissions' );
		$this->assertSame( 'cesto', GP::$glossary_entry->get( $id )->translation );
	}
}
