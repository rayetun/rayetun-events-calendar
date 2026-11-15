<?php
/**
 * A stand-in for PHPUnit\Framework\TestCase.
 *
 * Only loaded by tests/run.php, and only when PHPUnit is not installed. It
 * carries the assertions these suites use and nothing else, so that the test
 * files themselves stay ordinary PHPUnit and need no changes when PHPUnit
 * arrives.
 *
 * @package RayEtun\EventsCalendar
 */

declare( strict_types = 1 );

namespace PHPUnit\Framework;

/**
 * Raised by a failed assertion.
 */
class AssertionFailedError extends \Exception {}

/**
 * Enough of a test case to run these suites.
 */
abstract class TestCase {

	/**
	 * Called before each test. Named so the runner can reach it whatever the
	 * visibility of the real setUp().
	 *
	 * @return void
	 */
	public function rayec_set_up(): void {
		if ( method_exists( $this, 'setUp' ) ) {
			$method = new \ReflectionMethod( $this, 'setUp' );
			$method->setAccessible( true );
			$method->invoke( $this );
		}
	}

	/**
	 * Fail with a message.
	 *
	 * @param string $message Message.
	 * @return void
	 * @throws AssertionFailedError Always.
	 */
	protected function fail( string $message ): void {
		throw new AssertionFailedError( $message );
	}

	/**
	 * Readable form of any value, for a failure message.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function show( $value ): string {
		return (string) wp_json_encode( $value );
	}

	/**
	 * Identical values, including type.
	 *
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return void
	 */
	public function assertSame( $expected, $actual, string $message = '' ): void {
		if ( $expected !== $actual ) {
			$this->fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected ' . $this->show( $expected ) . ', got ' . $this->show( $actual ) );
		}
	}

	/**
	 * Values that are not identical.
	 *
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return void
	 */
	public function assertNotSame( $expected, $actual, string $message = '' ): void {
		if ( $expected === $actual ) {
			$this->fail( ( '' !== $message ? $message . ' ' : '' ) . 'did not expect ' . $this->show( $expected ) );
		}
	}

	/**
	 * True.
	 *
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public function assertTrue( $actual, string $message = '' ): void {
		$this->assertSame( true, $actual, $message );
	}

	/**
	 * False.
	 *
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public function assertFalse( $actual, string $message = '' ): void {
		$this->assertSame( false, $actual, $message );
	}

	/**
	 * A countable of a given size.
	 *
	 * @param int    $expected Expected count.
	 * @param mixed  $actual   Actual.
	 * @param string $message  Message.
	 * @return void
	 */
	public function assertCount( int $expected, $actual, string $message = '' ): void {
		$this->assertSame( $expected, is_countable( $actual ) ? count( $actual ) : -1, $message );
	}

	/**
	 * Not empty.
	 *
	 * @param mixed  $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public function assertNotEmpty( $actual, string $message = '' ): void {
		if ( empty( $actual ) ) {
			$this->fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected something, got nothing' );
		}
	}

	/**
	 * A value present in an array.
	 *
	 * @param mixed  $needle   Needle.
	 * @param array  $haystack Haystack.
	 * @param string $message  Message.
	 * @return void
	 */
	public function assertContains( $needle, array $haystack, string $message = '' ): void {
		if ( ! in_array( $needle, $haystack, true ) ) {
			$this->fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected ' . $this->show( $needle ) . ' in ' . $this->show( $haystack ) );
		}
	}

	/**
	 * A value absent from an array.
	 *
	 * @param mixed  $needle   Needle.
	 * @param array  $haystack Haystack.
	 * @param string $message  Message.
	 * @return void
	 */
	public function assertNotContains( $needle, array $haystack, string $message = '' ): void {
		if ( in_array( $needle, $haystack, true ) ) {
			$this->fail( ( '' !== $message ? $message . ' ' : '' ) . 'did not expect ' . $this->show( $needle ) . ' in ' . $this->show( $haystack ) );
		}
	}

	/**
	 * A string starting with a prefix.
	 *
	 * @param string $prefix  Prefix.
	 * @param string $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public function assertStringStartsWith( string $prefix, string $actual, string $message = '' ): void {
		if ( 0 !== strpos( $actual, $prefix ) ) {
			$this->fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected ' . $this->show( $actual ) . ' to start with ' . $this->show( $prefix ) );
		}
	}

	/**
	 * A string ending with a suffix.
	 *
	 * @param string $suffix  Suffix.
	 * @param string $actual  Actual.
	 * @param string $message Message.
	 * @return void
	 */
	public function assertStringEndsWith( string $suffix, string $actual, string $message = '' ): void {
		if ( substr( $actual, -strlen( $suffix ) ) !== $suffix ) {
			$this->fail( ( '' !== $message ? $message . ' ' : '' ) . 'expected ' . $this->show( $actual ) . ' to end with ' . $this->show( $suffix ) );
		}
	}
}
