<?php
/**
 * Base class for this plugin's tests: GlotPress fixtures with guaranteed cleanup.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

/**
 * GlotPress users, permissions and projects, all removed in tearDown().
 */
abstract class Nakedcat_Glotpress_Abilities_TestCase extends NakedCat_Tests_TestCase {

	/**
	 * Unique prefix for the projects a test creates.
	 *
	 * @var string
	 */
	protected $prefix;

	/**
	 * Starts every test logged out, with a prefix no install already holds.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->prefix = 'nakedcat-tests-' . wp_rand( 100000, 999999 );
		$this->as_user( 0 );
	}

	/**
	 * A user with GlotPress admin permission, logged in.
	 *
	 * @return WP_User
	 */
	protected function as_gp_admin() {
		$user = $this->make_user( 'subscriber' );
		$this->grant( $user, 'admin' );
		$this->as_user( $user );
		return $user;
	}

	/**
	 * Grants a GlotPress permission for this test only.
	 *
	 * @param WP_User         $user        The user.
	 * @param string          $action      The action, e.g. "admin", "write", "approve".
	 * @param string|null     $object_type The object type, e.g. "project", "translation-set".
	 * @param int|string|null $object_id   The object ID.
	 */
	protected function grant( $user, $action, $object_type = null, $object_id = null ) {
		$args = array(
			'user_id'     => $user->ID,
			'action'      => $action,
			'object_type' => $object_type,
			'object_id'   => null === $object_id ? null : (string) $object_id,
		);
		GP::$permission->create( $args );
		$this->on_teardown(
			function () use ( $args ) {
				GP::$permission->delete_many( $args );
			}
		);
	}

	/**
	 * A project, deleted in tearDown() with everything under it.
	 *
	 * @param string          $slug           Slug.
	 * @param GP_Project|null $parent_project Parent project.
	 * @param array           $args           More project fields.
	 * @return GP_Project
	 */
	protected function make_project( $slug, $parent_project = null, $args = array() ) {
		$project = GP::$project->create_and_select(
			new GP_Project(
				array_merge(
					array(
						'name'                => $slug,
						'slug'                => $slug,
						'description'         => '',
						'source_url_template' => '',
						'active'              => 1,
						'parent_project_id'   => $parent_project ? $parent_project->id : null,
					),
					$args
				)
			)
		);
		$this->assertInstanceOf( GP_Project::class, $project, 'Could not create the test project.' );
		$this->delete_project_at_teardown( $project->path );
		return $project;
	}

	/**
	 * A translation set on a project (deleted with the project).
	 *
	 * @param GP_Project $project The project.
	 * @param string     $locale  Locale slug.
	 * @param string     $slug    Variant slug.
	 * @return GP_Translation_Set
	 */
	protected function make_set( $project, $locale, $slug = 'default' ) {
		$set = GP::$translation_set->create_and_select(
			array(
				'project_id' => $project->id,
				'locale'     => $locale,
				'slug'       => $slug,
				'name'       => GP_Locales::by_slug( $locale )->english_name,
			)
		);
		$this->assertInstanceOf( GP_Translation_Set::class, $set, 'Could not create the test translation set.' );
		return $set;
	}

	/**
	 * Deletes whatever project is at a path in tearDown(), with its sets, originals and sub projects.
	 *
	 * @param string $path Project path.
	 */
	protected function delete_project_at_teardown( $path ) {
		$this->on_teardown(
			function () use ( $path ) {
				$project = GP::$project->by_path( $path );
				if ( $project ) {
					foreach ( $project->inclusive_sub_projects() as $sub ) {
						$sub->delete();
					}
					$project->delete();
				}
			}
		);
	}

	/**
	 * Runs an ability the way the REST API and MCP do: input validation, permission, execution.
	 *
	 * @param string $name  Ability name.
	 * @param array  $input Input.
	 * @return mixed
	 */
	protected function run_ability( $name, $input ) {
		$ability = wp_get_ability( $name );
		$this->assertNotNull( $ability, "Ability {$name} is not registered." );
		return $ability->execute( $input );
	}

	/**
	 * Asserts a WP_Error with a given code.
	 *
	 * @param mixed  $value   The value.
	 * @param string $code    Expected error code.
	 * @param string $message Failure message.
	 */
	protected function assertWPError( $value, $code, $message = '' ) {
		$this->assertInstanceOf( WP_Error::class, $value, $message );
		$this->assertSame( $code, $value->get_error_code(), $message . ' ' . $value->get_error_message() );
	}
}
