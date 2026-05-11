<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\X402HeaderCodec;

final class X402HeaderCodecTest extends TestCase {

	public function test_encode_produces_base64_of_json(): void {
		$encoded = X402HeaderCodec::encode( array( 'scheme' => 'exact', 'price' => '0.01' ) );
		$this->assertSame(
			array( 'scheme' => 'exact', 'price' => '0.01' ),
			json_decode( base64_decode( $encoded ), true )
		);
	}

	public function test_decode_round_trips_encode(): void {
		$payload = array( 'a' => 1, 'b' => array( 'nested' => true ) );
		$this->assertSame( $payload, X402HeaderCodec::decode( X402HeaderCodec::encode( $payload ) ) );
	}

	public function test_decode_returns_null_for_invalid_base64(): void {
		$this->assertNull( X402HeaderCodec::decode( '!!!not-base64!!!' ) );
	}

	public function test_decode_returns_null_for_invalid_json(): void {
		$this->assertNull( X402HeaderCodec::decode( base64_encode( 'not json' ) ) );
	}
}
