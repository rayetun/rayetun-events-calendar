<?php
/**
 * Run the test suite without PHPUnit installed.
 *
 * The test files are ordinary PHPUnit test cases and `phpunit` runs them as
 * they are. This file is for the machine that has not got PHPUnit on it: it
 * defines just enough of PHPUnit\Framework\TestCase for these suites — the
 * assertions they actually use — and then calls every test method.
 *
 * It is a stand-in, not a replacement. If an assertion is added here that
 * PHPUnit spells differently, PHPUnit is the one that is right.
 *
 *   php tests/run.php
 *
 * @package RayEtun\EventsCalendar
 */

declare( strict_types = 1 );

if ( class_exists( 'PHPUnit\Framework\TestCase' ) ) {
	echo "PHPUnit is installed; run it directly with \"phpunit\".\n";
	exit( 1 );
}

require_once __DIR__ . '/stub-testcase.php';
require_once __DIR__ . '/bootstrap.php';

$files = glob( __DIR__ . '/*Test.php' );
$files = is_array( $files ) ? $files : array();
sort( $files );
foreach ( $files as $file ) {
	require_once $file;
}

$classes = array_filter(
	get_declared_classes(),
	static fn( string $name ): bool => is_subclass_of( $name, 'PHPUnit\Framework\TestCase' )
);

$passed   = 0;
$failures = array();

foreach ( $classes as $class ) {
	$short = substr( (string) strrchr( $class, '\\' ), 1 );
	echo "\n" . $short . "\n" . str_repeat( '-', strlen( $short ) ) . "\n";

	$methods = get_class_methods( $class );
	sort( $methods );
	foreach ( $methods as $method ) {
		if ( 0 !== strpos( $method, 'test_' ) ) {
			continue;
		}
		$case = new $class();
		try {
			$case->rayec_set_up();
			$case->$method();
			++$passed;
			echo '  .  ' . str_replace( '_', ' ', substr( $method, 5 ) ) . "\n";
		} catch ( \Throwable $e ) {
			$failures[] = $short . '::' . $method . ' — ' . $e->getMessage();
			echo '  F  ' . str_replace( '_', ' ', substr( $method, 5 ) ) . "\n";
		}
	}
}

echo "\n" . str_repeat( '=', 60 ) . "\n";
if ( $failures ) {
	echo count( $failures ) . " FAILED, {$passed} passed\n\n";
	foreach ( $failures as $failure ) {
		echo '  ' . $failure . "\n";
	}
	exit( 1 );
}

echo "ALL PASSED ({$passed} tests)\n";
