<?php
/**
 * PHPUnit bootstrap: the shared Naked Cat Plugins test library does the work.
 *
 * @package NakedCatPlugins\GlotpressAbilities
 */

$nakedcat_gpa_tests_lib = getenv( 'NAKEDCAT_TESTS_LIB' ) ? getenv( 'NAKEDCAT_TESTS_LIB' ) : getenv( 'HOME' ) . '/.claude/skills/nakedcat-plugin-tests';
if ( ! file_exists( $nakedcat_gpa_tests_lib . '/php/bootstrap.php' ) ) {
	fwrite( STDERR, "The nakedcat-plugin-tests library was not found at {$nakedcat_gpa_tests_lib}. Set NAKEDCAT_TESTS_LIB.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
require $nakedcat_gpa_tests_lib . '/php/bootstrap.php';
require __DIR__ . '/TestCase.php';
