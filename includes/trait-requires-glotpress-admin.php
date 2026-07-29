<?php
/**
 * Shared permission gate for abilities restricted to GlotPress administrators.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

namespace NakedCatPlugins\GlotpressAbilities;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every ability in this plugin is restricted to GlotPress administrators.
 *
 * Trusts GlotPress's own permission system alone (GP::$permission->current_user_can('admin')),
 * matching how GlotPress core's own routes gate translation read/write actions. Deliberately does
 * NOT also require the WordPress manage_options capability: GlotPress's permission model exists
 * precisely so a translation coordinator can administer GlotPress without needing full WordPress
 * site administration rights.
 */
trait Requires_Glotpress_Admin {

	/**
	 * Checks whether the current user may run this ability.
	 *
	 * @return bool
	 */
	public static function check_permission() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return (bool) \GP::$permission->current_user_can( 'admin' );
	}
}
