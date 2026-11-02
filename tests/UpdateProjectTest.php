<?php
/**
 * Tests for nakedcat-glotpress/update-project.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * Changing a project's settings.
 */
class UpdateProjectTest extends Nakedcat_Glotpress_Abilities_TestCase {

	const ABILITY = 'nakedcat-glotpress/update-project';

	/**
	 * The parent project.
	 *
	 * @var GP_Project
	 */
	private $parent_project;

	/**
	 * The project to change, under the parent, with a "pt" set, one original and a sub project.
	 *
	 * @var GP_Project
	 */
	private $project;

	/**
	 * Builds the fixture.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->parent_project = $this->make_project( $this->prefix );
		$this->project        = $this->make_project( 'my-plugin', $this->parent_project, array( 'name' => 'My Plugin' ) );
		$this->make_project( 'sub', $this->project );
		$set = $this->make_set( $this->project, 'pt' );
		$this->import_originals( $this->project, array( 'Save changes' ) );
		$this->make_translation( $set, $this->original( $this->project, 'Save changes' ), 'Guardar alterações' );
	}

	/**
	 * Name, description, source URL template and active change; the rest is kept.
	 */
	public function test_each_field() {
		$this->as_gp_admin();

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'project_path'        => $this->project->path,
				'name'                => 'My Renamed Plugin',
				'description'         => 'Now with a description.',
				'source_url_template' => 'https://github.com/owner/repo/blob/main/%file%#L%line%',
				'active'              => false,
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertEqualsCanonicalizing( array( 'name', 'description', 'source_url_template', 'active' ), $result['changed_fields'] );
		$this->assertSame( $this->project->path, $result['project_path'] );

		$saved = GP::$project->get( $this->project->id );
		$this->assertSame( 'My Renamed Plugin', $saved->name );
		$this->assertSame( 'Now with a description.', $saved->description );
		$this->assertSame( 'https://github.com/owner/repo/blob/main/%file%#L%line%', $saved->source_url_template );
		$this->assertSame( 0, (int) $saved->active );
		$this->assertSame( 'my-plugin', $saved->slug );

		$again = $this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $this->project->path,
				'name'         => 'My Renamed Plugin',
			)
		);
		$this->assertSame( array(), $again['changed_fields'] );
	}

	/**
	 * A slug change reports the new path and moves the sub project with it.
	 */
	public function test_slug_change_moves_path() {
		$this->as_gp_admin();
		$old_path = $this->project->path;

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $old_path,
				'slug'         => 'my-plugin-renamed',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $this->parent_project->path . '/my-plugin-renamed', $result['project_path'] );
		$this->assertSame( $old_path, $result['previous_path'] );
		$this->assertFalse( GP::$project->by_path( $old_path ) );
		$this->assertInstanceOf( GP_Project::class, GP::$project->by_path( $result['project_path'] . '/sub' ) );
	}

	/**
	 * A slug that would collide with an existing path is refused and nothing changes.
	 */
	public function test_slug_collision_refused() {
		$this->as_gp_admin();
		$this->make_project( 'taken', $this->parent_project );

		$result = $this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $this->project->path,
				'slug'         => 'taken',
				'name'         => 'Should not be saved',
			)
		);

		$this->assertWPError( $result, 'nakedcat_glotpress_project_exists' );
		$saved = GP::$project->get( $this->project->id );
		$this->assertSame( 'my-plugin', $saved->slug );
		$this->assertSame( 'My Plugin', $saved->name );
	}

	/**
	 * An inactive project's strings and translations stay readable.
	 */
	public function test_inactive_project_still_readable() {
		$this->as_gp_admin();
		$this->run_ability(
			self::ABILITY,
			array(
				'project_path' => $this->project->path,
				'active'       => false,
			)
		);

		$strings = $this->run_ability(
			'nakedcat-glotpress/get-strings',
			array(
				'project_path' => $this->project->path,
				'locale'       => 'pt',
				'status'       => 'current',
			)
		);

		$this->assertIsArray( $strings, is_wp_error( $strings ) ? $strings->get_error_message() : '' );
		$this->assertSame( 1, $strings['total'] );
		$this->assertSame( 'Guardar alterações', $strings['strings'][0]['translations'][0] );
	}

	/**
	 * Write on the parent (as for creating a project) is enough; no permission is refused.
	 */
	public function test_permission() {
		$user  = $this->make_user( 'subscriber' );
		$input = array(
			'project_path' => $this->project->path,
			'name'         => 'Renamed by a coordinator',
		);
		$this->as_user( $user );

		$this->assertWPError( $this->run_ability( self::ABILITY, $input ), 'ability_invalid_permissions' );
		$this->assertSame( 'My Plugin', GP::$project->get( $this->project->id )->name );

		$this->grant( $user, 'write', 'project', $this->parent_project->id );
		$result = $this->run_ability( self::ABILITY, $input );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'Renamed by a coordinator', GP::$project->get( $this->project->id )->name );
	}
}
