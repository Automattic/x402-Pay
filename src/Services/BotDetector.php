<?php
/**
 * Thin wrapper around jaybizzle/crawler-detect.
 *
 * @package X402Press
 */

declare(strict_types=1);

namespace X402Press\Services;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

/**
 * Detects common crawlers/bots from a User-Agent string.
 *
 * The caller supplies the header value (e.g. WordPress code reads
 * `$_SERVER['HTTP_USER_AGENT']` and passes it in). This class has no
 * dependency on WordPress or superglobals.
 */
final class BotDetector {

	private ?CrawlerDetect $engine = null;

	public function __construct( private readonly string $user_agent ) {}

	/**
	 * Whether the injected User-Agent looks like a bot.
	 */
	public function is_bot(): bool {
		if ( '' === $this->user_agent ) {
			return false;
		}
		if ( null === $this->engine ) {
			$this->engine = new CrawlerDetect();
		}
		return $this->engine->isCrawler( $this->user_agent );
	}
}
