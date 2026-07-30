<?php
/**
 * Plugin Name:          GlotPress Abilities
 * Plugin URI:           https://nakedcatplugins.com/product/glotpress-abilities/
 * Description:          Exposes GlotPress translation project data to AI agents and MCP clients via the WordPress Abilities API.
 * Version:              0.4
 * Author:               Naked Cat Plugins (by Webdados)
 * Author URI:           https://nakedcatplugins.com
 * Text Domain:          nakedcat-glotpress-abilities
 * Requires at least:    6.2
 * Tested up to:         7.1
 * Requires PHP:         7.4
 * Update URI:           false
 * Requires Plugins:     glotpress
 */

namespace NakedCatPlugins\GlotpressAbilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants.
define( 'NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_FILE', __FILE__ );
define( 'NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_SLUG', basename( __FILE__ ) );
define( 'NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Add plugin initialization hook.
add_action( 'plugins_loaded', '\NakedCatPlugins\GlotpressAbilities\init' );

/**
 * Plugin initialization function.
 */
function init() {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		include ABSPATH . '/wp-admin/includes/plugin.php';
	}
	$plugin_data = get_plugin_data( NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_FILE );
	define( 'NAKEDCAT_GLOTPRESS_ABILITIES_VERSION', $plugin_data['Version'] );
	define( 'NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_NAME', $plugin_data['Name'] );

	if ( class_exists( 'GP' ) ) {
		require_once NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_DIR . 'includes/class-abilities-registrar.php';
		$GLOBALS['nakedcat_glotpress_abilities'] = Abilities_Registrar::instance();
	} else {
		add_action( 'admin_notices', '\NakedCatPlugins\GlotpressAbilities\admin_notices_dependencies' );
	}
}

/**
 * Admin notice shown when GlotPress is missing or inactive.
 */
function admin_notices_dependencies() {
	?>
	<div class="notice notice-error is-dismissible">
		<p>
			<?php
			echo wp_kses_post(
				sprintf(
					/* translators: %s: Plugin name. */
					__( '%s is installed and active but <strong>GlotPress</strong> is not.', 'nakedcat-glotpress-abilities' ),
					'<strong>' . esc_html( NAKEDCAT_GLOTPRESS_ABILITIES_PLUGIN_NAME ) . '</strong>'
				)
			);
			?>
		</p>
	</div>
	<?php
}
