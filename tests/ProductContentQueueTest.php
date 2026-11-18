<?php
/**
 * Product lifecycle transition policy.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/includes/class-digitalogic-product-content-queue.php';

/** Exercises durable lifecycle transition policy. */
final class ProductContentQueueTest extends TestCase {
	/** Return an unavailable published product snapshot.
	 *
	 * @return array Snapshot. */
	private function state(): array {
		return array(
			'published' => true,
			'available' => false,
			'identity'  => 'a',
			'missing'   => true,
			'copy_hash' => 'one',
		);
	}
	/** Repeated A-B-A restocks remain independent events. */
	public function test_new_products_and_restock_are_queued(): void {
		$old              = $this->state();
		$now              = $old;
		$now['available'] = true;
		$this->assertSame( 'missing_description', Digitalogic_Product_Content_Queue::reason( null, $old ) );
		$this->assertSame( 'restocked', Digitalogic_Product_Content_Queue::reason( $old, $now ) );
		$this->assertNull( Digitalogic_Product_Content_Queue::reason( $now, $now ) );
		$this->assertNull( Digitalogic_Product_Content_Queue::reason( $now, $old ) );
		$this->assertSame( 'restocked', Digitalogic_Product_Content_Queue::reason( $old, $now ) );
	}
	/** Publication and erased descriptions are recoverable. */
	public function test_publish_and_copy_removal_are_recoverable(): void {
		$old              = $this->state();
		$old['published'] = false;
		$now              = $old;
		$now['published'] = true;
		$this->assertNull( Digitalogic_Product_Content_Queue::reason( null, $old ) );
		$this->assertSame( 'published', Digitalogic_Product_Content_Queue::reason( $old, $now ) );
		$old            = $now;
		$old['missing'] = false;
		$this->assertSame( 'description_removed', Digitalogic_Product_Content_Queue::reason( $old, $now ) );
	}
	/** Identity and SEO changes queue once per state transition. */
	public function test_identity_and_seo_copy_changes_are_queued_without_continuous_requeue(): void {
		$old             = $this->state();
		$now             = $old;
		$now['identity'] = 'b';
		$this->assertSame( 'identity_changed', Digitalogic_Product_Content_Queue::reason( $old, $now ) );
		$now              = $old;
		$now['copy_hash'] = 'two';
		$this->assertSame( 'copy_changed', Digitalogic_Product_Content_Queue::reason( $old, $now ) );
		$this->assertNull( Digitalogic_Product_Content_Queue::reason( $now, $now ) );
		$now['missing'] = false;
		$this->assertSame( 'initial_seo_audit', Digitalogic_Product_Content_Queue::reason( null, $now ) );
	}
}
