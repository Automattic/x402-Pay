<?php
declare(strict_types=1);

namespace X402Press\Tests\Unit;

use PHPUnit\Framework\TestCase;
use X402Press\Services\PaywallClientProfile;

final class PaywallClientProfileTest extends TestCase {

	private const GOOGLEBOT_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

	public function test_human_ua_empty_headers_not_bot_no_intents(): void {
		$p = PaywallClientProfile::classify(
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
			'',
			'',
			'',
			null
		);
		$this->assertFalse( $p->is_bot );
		$this->assertFalse( $p->document_navigation_intent );
		$this->assertFalse( $p->json_accept_intent );
		$this->assertFalse( $p->xml_http_request );
	}

	public function test_sec_fetch_navigate_and_document_sets_document_navigation_intent(): void {
		$p = PaywallClientProfile::classify(
			'Mozilla/5.0',
			'text/html',
			'navigate',
			'document',
			null
		);
		$this->assertTrue( $p->document_navigation_intent );
	}

	public function test_sec_fetch_values_are_case_insensitive(): void {
		$p = PaywallClientProfile::classify( 'Mozilla/5.0', '', 'NAVIGATE', 'Document', null );
		$this->assertTrue( $p->document_navigation_intent );
	}

	public function test_navigate_without_document_dest_no_document_intent(): void {
		$p = PaywallClientProfile::classify( 'Mozilla/5.0', '', 'navigate', 'empty', null );
		$this->assertFalse( $p->document_navigation_intent );
	}

	public function test_accept_application_json_sets_json_accept_intent(): void {
		$p = PaywallClientProfile::classify( 'curl/8.0', 'application/json', '', '', null );
		$this->assertTrue( $p->json_accept_intent );
	}

	public function test_accept_json_subtype_with_plus_json_suffix(): void {
		$p = PaywallClientProfile::classify(
			'curl/8.0',
			'application/vnd.github+json',
			'',
			'',
			null
		);
		$this->assertTrue( $p->json_accept_intent );
	}

	public function test_accept_application_json_with_charset_parameter(): void {
		$p = PaywallClientProfile::classify(
			'curl/8.0',
			'application/json; charset=utf-8',
			'',
			'',
			null
		);
		$this->assertTrue( $p->json_accept_intent );
	}

	public function test_accept_text_html_only_no_json_intent(): void {
		$p = PaywallClientProfile::classify( 'Mozilla/5.0', 'text/html, */*', '', '', null );
		$this->assertFalse( $p->json_accept_intent );
	}

	public function test_application_json_seq_does_not_match_json_intent(): void {
		$p = PaywallClientProfile::classify( 'curl/8.0', 'application/json-seq', '', '', null );
		$this->assertFalse( $p->json_accept_intent );
	}

	public function test_googlebot_is_bot(): void {
		$p = PaywallClientProfile::classify( self::GOOGLEBOT_UA, '', '', '', null );
		$this->assertTrue( $p->is_bot );
	}

	public function test_bot_with_navigate_document_and_json_accept(): void {
		$p = PaywallClientProfile::classify(
			self::GOOGLEBOT_UA,
			'application/json',
			'navigate',
			'document',
			null
		);
		$this->assertTrue( $p->is_bot );
		$this->assertTrue( $p->document_navigation_intent );
		$this->assertTrue( $p->json_accept_intent );
	}

	public function test_x_requested_with_xmlhttprequest_sets_xml_http_request(): void {
		$p = PaywallClientProfile::classify( 'Mozilla/5.0', '', '', '', 'XMLHttpRequest' );
		$this->assertTrue( $p->xml_http_request );
	}

	public function test_x_requested_with_other_value_false(): void {
		$p = PaywallClientProfile::classify( 'Mozilla/5.0', '', '', '', 'fetch' );
		$this->assertFalse( $p->xml_http_request );
	}
}
