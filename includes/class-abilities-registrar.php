<?php
/**
 * Registers this plugin's ability categories and abilities with the WordPress Abilities API.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registrar for all abilities this plugin provides.
 *
 * New abilities are added by dropping a class file into includes/abilities/ that
 * exposes a static register() method, and listing its class name in $ability_classes.
 */
class Abilities_Registrar {

	/**
	 * Singleton instance.
	 *
	 * @var Abilities_Registrar|null
	 */
	private static $instance = null;

	/**
	 * Fully-qualified class names of abilities to register, each providing a static register() method.
	 *
	 * @var string[]
	 */
	private $ability_classes = array(
		'\NakedCatPlugins\GlotpressAbilities\Abilities\List_Projects_Translation_Status',
		'\NakedCatPlugins\GlotpressAbilities\Abilities\Get_Glossary',
		'\NakedCatPlugins\GlotpressAbilities\Abilities\Get_Strings',
		'\NakedCatPlugins\GlotpressAbilities\Abilities\Update_Translations',
		'\NakedCatPlugins\GlotpressAbilities\Abilities\Find_Translations_In_Other_Projects',
		'\NakedCatPlugins\GlotpressAbilities\Abilities\Add_Glossary_Entries',
	);

	/**
	 * Retrieves the singleton instance, creating it on first use.
	 *
	 * @return Abilities_Registrar
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Hooks into the Abilities API init actions.
	 */
	private function __construct() {
		require_once NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_DIR . 'includes/trait-requires-glotpress-admin.php';

		foreach ( $this->ability_classes as $ability_class ) {
			require_once NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_DIR . 'includes/abilities/class-' . $this->class_to_filename( $ability_class ) . '.php';
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers this plugin's ability category.
	 */
	public function register_categories() {
		wp_register_ability_category(
			'glotpress',
			array(
				'label'       => __( 'GlotPress', 'nakedcat-glotpress-abilities' ),
				'description' => __( 'Abilities for inspecting and managing GlotPress translation projects.', 'nakedcat-glotpress-abilities' ),
			)
		);
	}

	/**
	 * Registers every ability this plugin provides.
	 */
	public function register_abilities() {
		foreach ( $this->ability_classes as $ability_class ) {
			$ability_class::register();
		}
	}

	/**
	 * Converts a fully-qualified ability class name to its file's basename (without the class- prefix/.php suffix).
	 *
	 * @param string $ability_class Fully-qualified class name, e.g. Namespace\Abilities\List_Projects_Translation_Status.
	 * @return string File basename fragment, e.g. list-projects-translation-status.
	 */
	private function class_to_filename( $ability_class ) {
		$parts     = explode( '\\', $ability_class );
		$classname = end( $parts );

		return str_replace( '_', '-', strtolower( $classname ) );
	}
}
