<?php
/**
 * Isolated page-cache provider contract tests.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

/** Verify the provider delivery contract in separate PHP processes. */
final class StorefrontPageCacheTest extends TestCase {
	/**
	 * Provider globals must never leak into the other outbox tests.
	 *
	 * @param string $scenario Fixture scenario.
	 * @return array Fixture results.
	 */
	private function run_scenario( string $scenario ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/storefront-page-cache.php' ) . ' ' . escapeshellarg( $scenario );
		exec( $command, $output, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Local isolated test fixture.
		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( implode( "\n", $output ), true );
		$this->assertIsArray( $result );
		return $result;
	}

	/** Shared URLs and variation parents are invalidated exactly once. */
	public function test_batch_coalesces_products_parents_and_shared_archives(): void {
		$result = $this->run_scenario( 'batch' );
		$this->assertTrue( $result['result'] );
		$this->assertSame( array( 11, 10, 12 ), $result['planned'] );
		$this->assertSame( 1, $result['home_calls'] );
		$this->assertCount( 1, $result['files'] );
		$urls = $result['files'][0];
		$this->assertSame( $urls, array_values( array_unique( $urls ) ) );
		$this->assertContains( 'https://example.test/shop/', $urls );
		$this->assertContains( 'https://example.test/catalog/', $urls );
		$this->assertContains( 'https://example.test/product/11/', $urls );
		$this->assertContains( 'https://example.test/product/10/', $urls );
		$this->assertContains( 'https://example.test/product_cat/20/', $urls );
		$this->assertContains( 'https://example.test/product_cat/2/', $urls );
		$this->assertContains( 'https://example.test/custom/', $urls );
		$this->assertNotContains( 'https://example.test/', $urls );
		$this->assertNotContains( 'https://example.test/product_visibility/99/', $urls );
	}

	/** Fail closed when an enabled cache cannot be invalidated. */
	public function test_provider_and_lookup_failures_are_retryable(): void {
		foreach ( array( 'throw', 'false', 'term_error', 'missing_product', 'cache_enabled' ) as $scenario ) {
			$this->assertFalse( $this->run_scenario( $scenario )['result'], $scenario );
		}
		$this->assertTrue( $this->run_scenario( 'cache_disabled' )['result'] );
	}

	/** A later committed delivery must purge again in the same request. */
	public function test_repeated_delivery_is_not_suppressed_by_per_request_post_guard(): void {
		$result = $this->run_scenario( 'repeat' );
		$this->assertTrue( $result['result'] );
		$this->assertCount( 2, $result['files'] );
		$this->assertSame( 2, $result['home_calls'] );
	}
}
