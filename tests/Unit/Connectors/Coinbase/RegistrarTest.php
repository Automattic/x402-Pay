<?php
declare(strict_types=1);

namespace X402Pay\Tests\Unit\Connectors\Coinbase;

use PHPUnit\Framework\TestCase;
use X402Pay\Connectors\Coinbase\Registrar;

final class RegistrarTest extends TestCase {

	public function test_provide_admin_meta_returns_existing_for_other_connectors(): void {
		$registrar = new Registrar();
		$other     = array( 'something' => 'else' );
		$this->assertSame( $other, $registrar->provide_admin_meta( $other, 'x402_pay_test' ) );
	}

	public function test_provide_admin_meta_supplies_intro_docs_and_validation(): void {
		$registrar = new Registrar();
		$meta      = $registrar->provide_admin_meta( array(), Registrar::ID );

		// Intro copy + docs link target — the connector owns these strings,
		// the generic admin React app just renders them.
		$this->assertNotEmpty( $meta['introHeadline'] );
		$this->assertStringContainsString( '<docs/>', $meta['introBody'] );
		$this->assertStringStartsWith( 'https://', $meta['docsUrl'] );

		// Validation patterns must be flag-free strings the JS RegExp
		// constructor can accept directly. UUID hex char class lists both
		// cases so no `i` flag is needed.
		$this->assertSame(
			'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
			$meta['keyIdPattern']
		);
		$this->assertNotEmpty( $meta['keyIdInvalidMessage'] );
		$this->assertNotEmpty( $meta['keySecretPattern'] );
		$this->assertNotEmpty( $meta['keySecretInvalidMessage'] );
	}

	public function test_provide_admin_meta_patterns_compile_in_php(): void {
		// Sanity check: if the regex is invalid PHP could compile, JS most
		// likely can't either. Catches typos before they hit a browser.
		$meta = ( new Registrar() )->provide_admin_meta( array(), Registrar::ID );
		$this->assertSame( 1, preg_match( '/' . $meta['keyIdPattern'] . '/', '00000000-0000-0000-0000-000000000000' ) );
		$this->assertSame( 0, preg_match( '/' . $meta['keyIdPattern'] . '/', 'not-a-uuid' ) );
	}
}
