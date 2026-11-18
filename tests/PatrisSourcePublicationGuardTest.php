<?php
/**
 * Exact-object authorization for the fresh-source publication save.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- Logger fixture and test share the focused test file.

require_once dirname( __DIR__ ) . '/includes/class-patris-mapping-guard.php';

if ( ! function_exists( 'wc_get_logger' ) ) {
	/** Minimal logger for rejected mapping writes. */
	function wc_get_logger() {
		return new class() {
			/**
			 * Accept the expected rejection diagnostic.
			 *
			 * @param mixed ...$args Logger arguments.
			 */
			public function critical( ...$args ) {
				unset( $args ); }
		};
	}
}

/** Verify the temporary exception never changes public eligibility or leaks. */
final class PatrisSourcePublicationGuardTest extends TestCase {
	/** Reset lock owners and leave the applied ledger deliberately empty. */
	protected function setUp(): void {
		$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
		foreach ( array( 'digitalogic_test_posts', 'digitalogic_test_wc_products', 'digitalogic_test_options', 'digitalogic_test_option_cache', 'digitalogic_test_action_callbacks', 'digitalogic_test_filters', 'digitalogic_test_wc_save_failures', 'digitalogic_test_wc_save_fail_once', 'digitalogic_test_wc_product_saves' ) as $key ) {
			$GLOBALS[ $key ] = array();
		}
		$GLOBALS['digitalogic_test_wc_after_save'] = null;
		foreach ( array( Digitalogic_Patris_Price_Write_Guard::class, Digitalogic_Product_Sync_Receiver::class, Digitalogic_Product_Write_Lock::class ) as $class ) {
			( new ReflectionProperty( $class, 'instance' ) )->setValue( null, null );
		}
		Digitalogic_Patris_Mapping_Guard::clear_index();
	}

	/** Build the verified identity supplied by the transaction owner. */
	private function identity(): array {
		return array(
			'product_code'    => '123456',
			'source_id'       => 'patris-export',
			'dataset'         => 'kala',
			'source_revision' => 'sha256:' . str_repeat( 'a', 64 ),
		);
	}

	/**
	 * Build a priced product which is not yet in applied_products.
	 *
	 * @param int $id Fixture product ID.
	 */
	private function product( int $id = 741 ) {
		$GLOBALS['digitalogic_test_posts'][ $id ] = array(
			'post_type'    => 'product',
			'product_type' => 'simple',
			'meta'         => array(),
		);
		$product                                  = new class( $id ) extends WC_Product {
			/**
			 * Probe executed within the real publication scope.
			 *
			 * @var callable|null
			 */
			public $probe;
			/** Return a pending commercial change for the price guard. */
			public function get_changes() {
				return array( 'price' => '100' ); }
			/** Mirror Woo's before-save boundary missing from the lightweight fixture. */
			public function save() {
				if ( is_callable( $this->probe ) ) {
					call_user_func( $this->probe, $this ); }
				Digitalogic_Patris_Mapping_Guard::before_save( $this );
				Digitalogic_Patris_Price_Write_Guard::instance()->guard_product_save( $this );
				return parent::save();
			}
		};
		$product->set_sku( '123456' );
		$product->set_price( '100' );
		$product->set_regular_price( '100' );
		foreach ( array(
			'product_code'       => '123456',
			'owner_product_code' => '123456',
			'owner_source_id'    => 'patris-export',
			'owner_dataset'      => 'kala',
			'source_revision'    => $this->identity()['source_revision'],
			'record_hash'        => 'sha256:' . str_repeat( 'b', 64 ),
		) as $key => $value ) {
			$product->update_meta_data( '_digitalogic_patris_' . $key, $value );
		}
		return $product;
	}

	/**
	 * Execute under both actual lock services.
	 *
	 * @param WC_Product $product Product lock target.
	 * @param callable   $callback Protected test operation.
	 */
	private function locked( $product, callable $callback ) {
		return Digitalogic_Product_Sync_Receiver::instance()->with_coordinated_pricing_lock(
			static fn() => Digitalogic_Product_Write_Lock::instance()->with_product_lock( $product->get_id(), $callback )
		);
	}

	/** Only the exact product and metadata ID are authorized during its save. */
	public function test_unmapped_price_saves_only_inside_exact_scope(): void {
		$product        = $this->product();
		$other          = $this->product( 742 );
		$guard          = Digitalogic_Patris_Price_Write_Guard::instance();
		$product->probe = function ( $target ) use ( $guard, $other ) {
			$this->assertTrue( $guard->is_authorized_write( $target ) );
			$this->assertTrue( $guard->is_authorized_write( 741 ) );
			$this->assertFalse( $guard->is_authorized_write() );
			$this->assertFalse( $guard->is_authorized_write( $other ) );
			$this->assertFalse( $guard->is_authorized_write( clone $target ) );
			$this->assertFalse( Digitalogic_Patris_Mapping_Guard::connected( $target ) );
			$this->assertNull( Digitalogic_Patris_Mapping_Guard::price_metadata( null, 741, '_price', '100' ) );
			try {
				Digitalogic_Patris_Mapping_Guard::before_save( $other );
				$this->fail( 'Other product was authorized.' );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'no valid Patris mapping', $error->getMessage() ); }
		};
		$this->assertSame( 741, $this->locked( $product, fn() => $guard->save_verified_source_publication( $product, $this->identity(), $product->get_meta( '_digitalogic_patris_record_hash' ) ) ) );
		$this->assertFalse( $guard->is_authorized_write( $product ) );
		$this->assertFalse( Digitalogic_Patris_Mapping_Guard::connected( $product ) );
		$this->assertFalse( Digitalogic_Patris_Mapping_Guard::price_metadata( null, 741, '_price', '100' ) );
		$this->expectException( RuntimeException::class );
		Digitalogic_Patris_Mapping_Guard::before_save( $product );
	}

	/** Missing locks or mismatched identity fail before the save starts. */
	public function test_missing_locks_and_identity_mismatches_never_save(): void {
		$guard = Digitalogic_Patris_Price_Write_Guard::instance();
		foreach ( array( 'locks', 'product_code', 'source_id', 'dataset', 'source_revision', 'hash', 'sku' ) as $case ) {
			$product  = $this->product();
			$identity = $this->identity();
			$hash     = $product->get_meta( '_digitalogic_patris_record_hash' );
			if ( isset( $identity[ $case ] ) ) {
				$identity[ $case ] = 'mismatch'; }
			if ( 'hash' === $case ) {
				$hash = 'sha256:' . str_repeat( 'c', 64 ); }
			if ( 'sku' === $case ) {
				$product->set_sku( 'different' ); }
			$save = static fn() => $guard->save_verified_source_publication( $product, $identity, $hash );
			try {
				'locks' === $case ? $save() : $this->locked( $product, $save );
				$this->fail( 'Mismatch was accepted: ' . $case );
			} catch ( RuntimeException $error ) {
				$this->assertStringContainsString( 'could not be verified', $error->getMessage() ); }
			$this->assertFalse( $guard->is_authorized_write( $product ) );
		}
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** Identity mutation at a later guard boundary and save exceptions revoke scope. */
	public function test_save_failure_and_mid_save_identity_mutation_do_not_leak(): void {
		$guard = Digitalogic_Patris_Price_Write_Guard::instance();
		foreach ( array( 'exception', 'mutation' ) as $case ) {
			$product        = $this->product();
			$product->probe = static function ( $target ) use ( $case ) {
				if ( 'exception' === $case ) {
					throw new RuntimeException( 'Injected save failure' ); }
				$target->update_meta_data( '_digitalogic_patris_owner_dataset', 'changed' );
			};
			try {
				$this->locked( $product, fn() => $guard->save_verified_source_publication( $product, $this->identity(), $product->get_meta( '_digitalogic_patris_record_hash' ) ) );
				$this->fail( 'Failed save completed.' );
			} catch ( RuntimeException $error ) {
				$this->assertNotSame( '', $error->getMessage() ); }
			$this->assertFalse( $guard->is_authorized_write( $product ) );
			$this->assertFalse( $guard->is_authorized_write( 741 ) );
		}
		$this->assertSame( array(), $GLOBALS['digitalogic_test_wc_product_saves'] );
	}

	/** A later transaction rollback restores storage without reopening authorization. */
	public function test_successful_scoped_save_can_roll_back_without_scope_leak(): void {
		$product = $this->product();
		$before  = $GLOBALS['digitalogic_test_posts'];
		$guard   = Digitalogic_Patris_Price_Write_Guard::instance();
		$this->locked(
			$product,
			function () use ( $product, $guard, $before ) {
				$GLOBALS['wpdb']->query( 'START TRANSACTION' );
				$this->assertSame( 741, $guard->save_verified_source_publication( $product, $this->identity(), $product->get_meta( '_digitalogic_patris_record_hash' ) ) );
				$this->assertSame( '100', $GLOBALS['digitalogic_test_posts'][741]['meta']['_price'] );
				$this->assertFalse( $guard->is_authorized_write( $product ) );
				$GLOBALS['wpdb']->query( 'ROLLBACK' );
				$this->assertSame( $before, $GLOBALS['digitalogic_test_posts'] );
			}
		);
		$this->assertFalse( Digitalogic_Patris_Mapping_Guard::connected( $product ) );
		$this->assertFalse( $guard->is_authorized_write( $product ) );
		$this->expectException( RuntimeException::class );
		Digitalogic_Patris_Mapping_Guard::before_save( $this->product( 742 ) );
	}
}
