<?php
/**
 * Tests for nakedcat-glotpress/create-project.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Creating a project and its translation sets.
 */
class CreateProjectTest extends Nakedcat_Glotpress_Abilities_TestCase {

	const ABILITY = 'nakedcat-glotpress/create-project';

	/**
	 * Creates the project under the parent with two sets, and refuses a second call for the same path.
	 */
	public function test_creates_project_with_sets_and_refuses_existing_path() {
		$this->as_gp_admin();
		$parent = $this->make_project( $this->prefix );

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'name'                => 'Test Plugin',
				'slug'                => 'test-plugin',
				'parent_project_path' => $parent->path,
				'description'         => 'A test.',
				'source_url_template' => 'https://example.com/%file%#L%line%',
				'locales'             => array( 'pt', 'pt-br' ),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $parent->path . '/test-plugin', $result['project_path'] );
		$this->assertTrue( $result['active'] );

		$project = GP::$project->by_path( $result['project_path'] );
		$this->assertInstanceOf( GP_Project::class, $project );
		$this->assertSame( (int) $project->id, $result['project_id'] );
		$this->assertSame( (int) $parent->id, (int) $project->parent_project_id );
		$this->assertSame( 'A test.', $project->description );
		$this->assertSame( 'https://example.com/%file%#L%line%', $project->source_url_template );

		$sets = GP::$translation_set->by_project_id( $project->id );
		$this->assertCount( 2, $sets );
		$this->assertEqualsCanonicalizing( array( 'pt', 'pt-br' ), wp_list_pluck( $sets, 'locale' ) );
		$this->assertSame( array( 'default', 'default' ), wp_list_pluck( $sets, 'slug' ) );
		$this->assertSame( array( 'pt', 'pt-br' ), wp_list_pluck( $result['translation_sets'], 'locale' ) );

		$again = $this->run_ability(
			self::ABILITY,
			array(
				'name'                => 'Other name',
				'slug'                => 'test-plugin',
				'parent_project_path' => $parent->path,
				'locales'             => array( 'es' ),
			)
		);

		$this->assertWPError( $again, 'nakedcat_glotpress_project_exists' );
		$this->assertSame( 'Test Plugin', GP::$project->by_path( $result['project_path'] )->name );
		$this->assertCount( 2, GP::$translation_set->by_project_id( $project->id ) );
	}

	/**
	 * An unknown locale refuses the whole call: no project is left behind.
	 */
	public function test_unknown_locale_refused() {
		$this->as_gp_admin();
		$parent = $this->make_project( $this->prefix );

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'name'                => 'Test Plugin',
				'slug'                => 'test-plugin',
				'parent_project_path' => $parent->path,
				'locales'             => array( 'pt', 'xx-not-a-locale' ),
			)
		);

		$this->assertWPError( $result, 'nakedcat_glotpress_locale_not_found' );
		$this->assertStringContainsString( 'xx-not-a-locale', $result->get_error_message() );
		$this->assertFalse( GP::$project->by_path( $parent->path . '/test-plugin' ) );
	}

	/**
	 * A parent path that does not exist is refused.
	 */
	public function test_missing_parent_refused() {
		$this->as_gp_admin();
		$this->delete_project_at_teardown( $this->prefix . '-missing/test-plugin' );

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'name'                => 'Test Plugin',
				'slug'                => 'test-plugin',
				'parent_project_path' => $this->prefix . '-missing',
			)
		);

		$this->assertWPError( $result, 'nakedcat_glotpress_project_not_found' );
	}

	/**
	 * Without GlotPress write permission on the parent, the call is refused; with it, it works.
	 */
	public function test_permission_is_write_on_parent() {
		$parent = $this->make_project( $this->prefix );
		$other  = $this->make_project( $this->prefix . '-other' );
		$user   = $this->make_user( 'subscriber' );
		$input  = array(
			'name'                => 'Test Plugin',
			'slug'                => 'test-plugin',
			'parent_project_path' => $parent->path,
		);

		$this->assertWPError( $this->run_ability( self::ABILITY, $input ), 'ability_invalid_permissions', 'Logged out.' );

		$this->as_user( $user );
		$this->assertWPError( $this->run_ability( self::ABILITY, $input ), 'ability_invalid_permissions', 'No permission.' );

		$this->grant( $user, 'write', 'project', $other->id );
		$this->assertWPError( $this->run_ability( self::ABILITY, $input ), 'ability_invalid_permissions', 'Write on another project.' );

		$top_level = $input;
		unset( $top_level['parent_project_path'] );
		$top_level['slug'] = $this->prefix . '-top';
		$this->delete_project_at_teardown( $top_level['slug'] );
		$this->assertWPError( $this->run_ability( self::ABILITY, $top_level ), 'ability_invalid_permissions', 'Top level without admin.' );

		$this->assertFalse( GP::$project->by_path( $parent->path . '/test-plugin' ) );

		$this->grant( $user, 'write', 'project', $parent->id );
		$result = $this->run_ability( self::ABILITY, $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $parent->path . '/test-plugin', $result['project_path'] );
	}

	/**
	 * Using copy_sets_from creates the sibling's sets (variants included) without its translations.
	 */
	public function test_copy_sets_from_sibling() {
		$this->as_gp_admin();
		$parent  = $this->make_project( $this->prefix );
		$sibling = $this->make_project( 'sibling', $parent );
		$this->make_set( $sibling, 'pt' );
		$this->make_set( $sibling, 'pt-ao90' );
		$this->make_set( $sibling, 'de', 'formal' );

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'name'                => 'Test Plugin',
				'slug'                => 'test-plugin',
				'parent_project_path' => $parent->path,
				'copy_sets_from'      => $sibling->path,
				'active'              => false,
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $result['active'] );
		$project = GP::$project->by_path( $result['project_path'] );
		$this->assertSame( 0, (int) $project->active );

		$pairs = array_map(
			function ( $set ) {
				return $set->locale . '/' . $set->slug;
			},
			GP::$translation_set->by_project_id( $project->id )
		);
		$this->assertEqualsCanonicalizing( array( 'pt/default', 'pt-ao90/default', 'de/formal' ), $pairs );
	}

	/**
	 * Passing locales and copy_sets_from together, or a copy source that does not exist, are refused.
	 */
	public function test_bad_set_sources_refused() {
		$this->as_gp_admin();
		$parent  = $this->make_project( $this->prefix );
		$sibling = $this->make_project( 'sibling', $parent );
		$input   = array(
			'name'                => 'Test Plugin',
			'slug'                => 'test-plugin',
			'parent_project_path' => $parent->path,
		);

		$this->assertWPError(
			$this->run_ability(
				self::ABILITY,
				$input + array(
					'locales'        => array( 'pt' ),
					'copy_sets_from' => $sibling->path,
				)
			),
			'nakedcat_glotpress_conflicting_sets_input'
		);
		$this->assertWPError(
			$this->run_ability( self::ABILITY, $input + array( 'copy_sets_from' => $parent->path . '/nope' ) ),
			'nakedcat_glotpress_project_not_found'
		);
		$this->assertFalse( GP::$project->by_path( $parent->path . '/test-plugin' ) );
	}
}
