<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Plugin;

final class PluginHeadersTest extends TestCase {

	/**
	 * Regression test: $_SERVER delivers HTTP_* keys in ALL_CAPS with
	 * underscores. The collector normalises them to canonical HTTP
	 * title-case so downstream lookups (e.g. `X-Payment-Grant`,
	 * `Payment-Signature`) match what real traffic sends.
	 */
	public function test_collect_headers_normalises_to_title_case(): void {
		$_SERVER['HTTP_X_PAYMENT_GRANT']   = 'abc-grant-token';
		$_SERVER['HTTP_PAYMENT_SIGNATURE'] = 'abc';
		$_SERVER['HTTP_USER_AGENT']        = 'Mozilla/5.0';

		$reflection = new \ReflectionMethod( Plugin::class, 'collect_headers' );
		$reflection->setAccessible( true );
		$headers = $reflection->invoke( null );

		$this->assertArrayHasKey( 'X-Payment-Grant', $headers );
		$this->assertSame( 'abc-grant-token', $headers['X-Payment-Grant'] );
		$this->assertArrayHasKey( 'Payment-Signature', $headers );
		$this->assertArrayHasKey( 'User-Agent', $headers );

		unset( $_SERVER['HTTP_X_PAYMENT_GRANT'], $_SERVER['HTTP_PAYMENT_SIGNATURE'], $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_collect_headers_always_includes_accept_and_sec_fetch_keys(): void {
		$backup = $this->backup_and_remove_accept_sec_fetch_server_vars();
		try {
			$reflection = new \ReflectionMethod( Plugin::class, 'collect_headers' );
			$reflection->setAccessible( true );
			$headers = $reflection->invoke( null );

			$this->assertArrayHasKey( 'Accept', $headers );
			$this->assertArrayHasKey( 'Sec-Fetch-Mode', $headers );
			$this->assertArrayHasKey( 'Sec-Fetch-Dest', $headers );
			$this->assertSame( '', $headers['Accept'] );
			$this->assertSame( '', $headers['Sec-Fetch-Mode'] );
			$this->assertSame( '', $headers['Sec-Fetch-Dest'] );
		} finally {
			$this->restore_accept_sec_fetch_server_vars( $backup );
		}
	}

	public function test_collect_headers_maps_accept_and_sec_fetch_from_server(): void {
		$backup = $this->snapshot_accept_sec_fetch_server_vars();
		try {
			$_SERVER['HTTP_ACCEPT']         = 'text/html, application/json;q=0.9';
			$_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
			$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';

			$reflection = new \ReflectionMethod( Plugin::class, 'collect_headers' );
			$reflection->setAccessible( true );
			$headers = $reflection->invoke( null );

			$this->assertSame( 'text/html, application/json;q=0.9', $headers['Accept'] );
			$this->assertSame( 'navigate', $headers['Sec-Fetch-Mode'] );
			$this->assertSame( 'document', $headers['Sec-Fetch-Dest'] );
		} finally {
			$this->restore_accept_sec_fetch_server_vars( $backup );
		}
	}

	/** @var list<string> */
	private const ACCEPT_SEC_FETCH_SERVER_KEYS = array(
		'HTTP_ACCEPT',
		'HTTP_SEC_FETCH_MODE',
		'HTTP_SEC_FETCH_DEST',
	);

	/**
	 * Ensures Accept / Sec-Fetch-* are absent so collect_headers() default merge is deterministic.
	 *
	 * @return array<string, mixed> Prior values for keys that existed.
	 */
	private function backup_and_remove_accept_sec_fetch_server_vars(): array {
		$backup = $this->snapshot_accept_sec_fetch_server_vars();
		foreach ( self::ACCEPT_SEC_FETCH_SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
		return $backup;
	}

	/**
	 * @return array<string, mixed> Prior values for keys that existed.
	 */
	private function snapshot_accept_sec_fetch_server_vars(): array {
		$backup = array();
		foreach ( self::ACCEPT_SEC_FETCH_SERVER_KEYS as $key ) {
			if ( array_key_exists( $key, $_SERVER ) ) {
				$backup[ $key ] = $_SERVER[ $key ];
			}
		}
		return $backup;
	}

	/**
	 * @param array<string, mixed> $backup From {@see self::snapshot_accept_sec_fetch_server_vars()} or backup_and_remove_*.
	 */
	private function restore_accept_sec_fetch_server_vars( array $backup ): void {
		foreach ( self::ACCEPT_SEC_FETCH_SERVER_KEYS as $key ) {
			if ( array_key_exists( $key, $backup ) ) {
				$_SERVER[ $key ] = $backup[ $key ];
			} else {
				unset( $_SERVER[ $key ] );
			}
		}
	}
}
