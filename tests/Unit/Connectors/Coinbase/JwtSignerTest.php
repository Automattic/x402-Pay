<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit\Connectors\Coinbase;

use PHPUnit\Framework\TestCase;
use X402Press\Connectors\Coinbase\JwtSigner;

final class JwtSignerTest extends TestCase {

	private string $key_id;
	private string $secret_b64;
	private string $public_key;

	protected function setUp(): void {
		$keypair          = sodium_crypto_sign_keypair();
		$this->public_key = sodium_crypto_sign_publickey( $keypair );
		$this->secret_b64 = base64_encode( sodium_crypto_sign_secretkey( $keypair ) );
		$this->key_id     = '00000000-0000-0000-0000-000000000000';
	}

	public function test_sign_returns_a_bearer_authorization_header(): void {
		$headers = ( new JwtSigner( $this->key_id, $this->secret_b64 ) )
			->sign( 'POST', 'https://api.cdp.coinbase.com/platform/v2/x402/verify' );

		$this->assertArrayHasKey( 'Authorization', $headers );
		$this->assertStringStartsWith( 'Bearer ', $headers['Authorization'] );
	}

	public function test_signed_token_verifies_with_the_paired_public_key(): void {
		$headers = ( new JwtSigner( $this->key_id, $this->secret_b64 ) )
			->sign( 'POST', 'https://api.cdp.coinbase.com/platform/v2/x402/verify' );
		$jwt     = substr( $headers['Authorization'], strlen( 'Bearer ' ) );

		[ $header_b64, $claims_b64, $sig_b64 ] = explode( '.', $jwt );
		$signing_input                          = $header_b64 . '.' . $claims_b64;
		$signature                              = self::base64url_decode( $sig_b64 );

		$this->assertTrue(
			sodium_crypto_sign_verify_detached( $signature, $signing_input, $this->public_key ),
			'JWT signature must verify against the public half of the keypair.'
		);
	}

	public function test_claim_shape_matches_cdp_spec(): void {
		$headers = ( new JwtSigner( $this->key_id, $this->secret_b64 ) )
			->sign( 'GET', 'https://api.cdp.coinbase.com/platform/v2/x402/supported' );
		$jwt     = substr( $headers['Authorization'], strlen( 'Bearer ' ) );

		[ $header_b64, $claims_b64 ] = explode( '.', $jwt );
		$header                      = json_decode( self::base64url_decode( $header_b64 ), true );
		$claims                      = json_decode( self::base64url_decode( $claims_b64 ), true );

		$this->assertSame( 'EdDSA', $header['alg'] );
		$this->assertSame( 'JWT', $header['typ'] );
		$this->assertSame( $this->key_id, $header['kid'] );
		$this->assertNotEmpty( $header['nonce'] );

		$this->assertSame( $this->key_id, $claims['sub'] );
		$this->assertSame( 'cdp', $claims['iss'] );
		$this->assertSame( array( 'cdp_service' ), $claims['aud'] );
		$this->assertSame(
			'GET api.cdp.coinbase.com/platform/v2/x402/supported',
			$claims['uri']
		);
		$this->assertSame(
			JwtSigner::TOKEN_TTL_SECONDS,
			$claims['exp'] - $claims['nbf'],
			'CDP rejects exp - nbf > 120; the signer must clamp at exactly the documented TTL.'
		);
	}

	public function test_each_call_uses_a_fresh_nonce(): void {
		$signer = new JwtSigner( $this->key_id, $this->secret_b64 );
		$first  = self::header_of( $signer->sign( 'GET', 'https://example/a' ) );
		$second = self::header_of( $signer->sign( 'GET', 'https://example/a' ) );
		$this->assertNotSame( $first['nonce'], $second['nonce'] );
	}

	public function test_empty_credentials_raise_runtime_exception(): void {
		$this->expectException( \RuntimeException::class );
		( new JwtSigner( '', '' ) )->sign( 'GET', 'https://example/a' );
	}

	public function test_invalid_base64_secret_raises_runtime_exception(): void {
		$this->expectException( \RuntimeException::class );
		( new JwtSigner( $this->key_id, '!!!not-base64!!!' ) )->sign( 'GET', 'https://example/a' );
	}

	public function test_wrong_length_secret_raises_runtime_exception(): void {
		$this->expectException( \RuntimeException::class );
		( new JwtSigner( $this->key_id, base64_encode( 'too short' ) ) )->sign( 'GET', 'https://example/a' );
	}

	private static function base64url_decode( string $b ): string {
		$padded = str_pad( strtr( $b, '-_', '+/' ), strlen( $b ) % 4 === 0 ? strlen( $b ) : strlen( $b ) + ( 4 - strlen( $b ) % 4 ), '=' );
		return base64_decode( $padded, true ) ?: '';
	}

	private static function header_of( array $headers ): array {
		$jwt           = substr( $headers['Authorization'], strlen( 'Bearer ' ) );
		[ $header_b64 ] = explode( '.', $jwt );
		return json_decode( self::base64url_decode( $header_b64 ), true );
	}
}
