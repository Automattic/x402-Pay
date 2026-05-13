<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Pay\Facilitator\RequestSigner;
use X402Pay\Services\FacilitatorProfile;
use X402Pay\Services\X402FacilitatorClient;

final class X402FacilitatorClientTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__x402_pay_http']       = null;
		$GLOBALS['__x402_pay_http_next']  = null;
		$GLOBALS['__x402_pay_http_queue'] = array();
	}

	private function test_client(): X402FacilitatorClient {
		return new X402FacilitatorClient( FacilitatorProfile::for_test() );
	}

	public function test_verify_posts_to_profile_facilitator_verify(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);
		$result = $this->test_client()->verify( array( 'scheme' => 'exact' ), array( 'signature' => 'x' ) );

		$this->assertSame( 'https://x402.org/facilitator/verify', $GLOBALS['__x402_pay_http']['url'] );
		$this->assertTrue( $result['isValid'] );
		$this->assertSame(
			wp_json_encode(
				array(
					'paymentRequirements' => array( 'scheme' => 'exact' ),
					'paymentPayload'      => array( 'signature' => 'x' ),
				)
			),
			$GLOBALS['__x402_pay_http']['args']['body']
		);
	}

	public function test_settle_posts_to_profile_facilitator_settle(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"success":true,"transaction":"0xabc"}',
		);
		$result = $this->test_client()->settle( array( 'scheme' => 'exact' ), array( 'signature' => 'x' ) );

		$this->assertSame( 'https://x402.org/facilitator/settle', $GLOBALS['__x402_pay_http']['url'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( '0xabc', $result['transaction'] );
	}

	public function test_wp_error_becomes_failure(): void {
		$GLOBALS['__x402_pay_http_next'] = new \WP_Error( 'http_fail', 'boom' );
		$result = $this->test_client()->verify( array(), array() );
		$this->assertFalse( $result['isValid'] );
		$this->assertSame( 'boom', $result['error'] );
	}

	public function test_non_2xx_becomes_failure(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{"error":"bad"}',
		);
		$result = $this->test_client()->settle( array(), array() );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'bad', $result['error'] );
	}

	public function test_profile_signer_headers_are_merged_into_outbound_requests(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);
		$signer = new class implements RequestSigner {
			public array $calls = array();
			public function sign( string $method, string $url ): array {
				$this->calls[] = array( 'method' => $method, 'url' => $url );
				return array( 'Authorization' => 'Bearer fake-' . $method );
			}
		};
		$profile = new FacilitatorProfile(
			network: 'base',
			asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
			asset_decimals: 6,
			facilitator_url: 'https://facil.example/',
			eip712_name: 'USD Coin',
			eip712_version: '2',
			environment_label: 'Live',
			signer: $signer,
		);
		( new X402FacilitatorClient( $profile ) )->verify( array(), array() );

		$this->assertSame( 'https://facil.example/verify', $GLOBALS['__x402_pay_http']['url'] );
		$this->assertSame( 'Bearer fake-POST', $GLOBALS['__x402_pay_http']['args']['headers']['Authorization'] );
		$this->assertCount( 1, $signer->calls );
		$this->assertSame( 'POST', $signer->calls[0]['method'] );
		$this->assertSame( 'https://facil.example/verify', $signer->calls[0]['url'] );
	}

	public function test_profile_signer_throw_aborts_call_with_error(): void {
		$signer = new class implements RequestSigner {
			public function sign( string $method, string $url ): array {
				throw new \RuntimeException( 'creds missing' );
			}
		};
		$profile = new FacilitatorProfile(
			network: 'base',
			asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
			asset_decimals: 6,
			facilitator_url: 'https://facil.example/',
			eip712_name: 'USD Coin',
			eip712_version: '2',
			environment_label: 'Live',
			signer: $signer,
		);
		$result = ( new X402FacilitatorClient( $profile ) )->verify( array(), array() );

		$this->assertFalse( $result['isValid'] );
		$this->assertSame( 'creds missing', $result['error'] );
		// No HTTP call should have been issued — the throw aborts before wp_remote_post.
		$this->assertNull( $GLOBALS['__x402_pay_http'] );
	}

	public function test_test_profile_omits_authorization_header(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);
		$this->test_client()->verify( array(), array() );
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['__x402_pay_http']['args']['headers'] );
	}

	public function test_test_connection_hits_base_url_with_head(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
		);
		$result = $this->test_client()->test_connection();

		$this->assertSame( 'https://x402.org/facilitator/', $GLOBALS['__x402_pay_http']['url'] );
		$this->assertSame( 'HEAD', $GLOBALS['__x402_pay_http']['method'] );
		$this->assertTrue( $result->ok );
		$this->assertSame( 200, $result->http_code );
	}

	public function test_test_connection_reports_wp_error_as_unreachable(): void {
		$GLOBALS['__x402_pay_http_next'] = new \WP_Error( 'dns_fail', 'nope' );
		$result = $this->test_client()->test_connection();
		$this->assertFalse( $result->ok );
		$this->assertSame( 'nope', $result->error );
	}

	public function test_test_connection_counts_5xx_as_down(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 502 ),
			'body'     => '',
		);
		$result = $this->test_client()->test_connection();
		$this->assertFalse( $result->ok );
		$this->assertSame( 502, $result->http_code );
	}

	public function test_test_connection_treats_4xx_as_reachable(): void {
		$GLOBALS['__x402_pay_http_next'] = array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
		);
		$result = $this->test_client()->test_connection();
		$this->assertTrue( $result->ok );
		$this->assertSame( 404, $result->http_code );
	}
}
