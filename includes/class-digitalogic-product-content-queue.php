<?php
/**
 * Durable product content and search-readiness outbox.
 *
 * @package Digitalogic
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Lease coordination requires current authoritative rows, not cached reads.
defined( 'ABSPATH' ) || exit;

/** Owns lifecycle observation, fenced work claims and a limited publisher. */
class Digitalogic_Product_Content_Queue {
	/**
	 * Request-local wake hints, drained after source commits.
	 *
	 * @var array<int,bool>
	 */
	private static array $dirty = array();
	/** Register product and metadata wake hooks. */
	public static function boot(): void {
		add_action( 'save_post_product', array( __CLASS__, 'touch' ), 100 );
		add_action( 'save_post_product_variation', array( __CLASS__, 'touch' ), 100 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'touch' ), 100 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'touch' ), 100 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'touch' ), 100 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'touch' ), 100 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 100, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 100, 4 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
	}
	/** Resolve an internal queue table.
	 *
	 * @param string $name Internal table suffix.
	 * @return string Table name. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'digitalogic_content_' . $name;
	}
	/** Install private InnoDB queue tables without deleting existing jobs.
	 *
	 * @throws RuntimeException When schema installation fails. */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$state   = static::table( 'state' );
		$jobs    = static::table( 'jobs' );
		dbDelta(
			"CREATE TABLE $state (
		 product_id bigint unsigned NOT NULL,
		 generation bigint unsigned NOT NULL DEFAULT 1,
		 snapshot longtext NOT NULL,
		 observed_at datetime NOT NULL,
		 PRIMARY KEY  (product_id)
		) ENGINE=InnoDB $charset;"
		);
		dbDelta(
			"CREATE TABLE $jobs (
		 id bigint unsigned NOT NULL AUTO_INCREMENT,
		 product_id bigint unsigned NOT NULL,
		 generation bigint unsigned NOT NULL,
		 stage varchar(16) NOT NULL,
		 reason varchar(40) NOT NULL,
		 status varchar(20) NOT NULL DEFAULT 'queued',
		 priority int NOT NULL DEFAULT 50,
		 attempts int NOT NULL DEFAULT 0,
		 available_at datetime NOT NULL,
		 lease_until datetime DEFAULT NULL,
		 token varchar(64) NOT NULL DEFAULT '',
		 created_at datetime NOT NULL,
		 updated_at datetime NOT NULL,
		 result longtext NOT NULL,
		 PRIMARY KEY  (id),
		 UNIQUE KEY product_generation_stage (product_id,generation,stage),
		 KEY runnable (stage,status,available_at,priority),
		 KEY product_id (product_id)
		) ENGINE=InnoDB $charset;"
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs ) ) !== $jobs ) {
			throw new RuntimeException( 'Content queue installation failed.' );
		}
		update_option( 'digitalogic_content_queue_version', '1', false );
	}
	/** Remember a committed product wake hint.
	 *
	 * @param int $id Product or variation ID.
	 */
	public static function touch( $id ): void {
		self::$dirty[ (int) $id ] = true;
	}
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress metadata callback signature.
	/** Wake on direct stock or identity metadata writes.
	 *
	 * @param int    $meta_id Metadata row ID.
	 * @param int    $id Product ID.
	 * @param string $key Metadata key.
	 * @param mixed  $value New value; deliberately never transported.
	 */
	public static function meta_changed( $meta_id, $id, $key, $value ): void {
		if ( in_array( $key, array( '_stock_status', '_sku', '_digitalogic_patris_product_code', '_digitalogic_patris_owner_product_code' ), true ) ) {
			self::touch( $id );
		}
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	/** Observe final parent states after the source request commits. */
	public static function flush(): void {
		if ( get_option( 'digitalogic_content_queue_version' ) !== '1' || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$ids         = array_keys( self::$dirty );
		self::$dirty = array();
		foreach ( $ids as $id ) {
			try {
				static::observe( $id );
			} catch ( Throwable $error ) {
				// The periodic authoritative sweep recovers failed hook writes.
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Operational failure signal contains only a product ID.
				error_log( 'Digitalogic content queue: observation failed for product ' . (int) $id );
			}
		}
	}
	/** Read authoritative parent identity, stock and copy fingerprints.
	 *
	 * @param int $id Product or variation ID.
	 * @return array|null Current parent snapshot. */
	public static function snapshot( int $id ): ?array {
		$p = wc_get_product( $id );
		if ( ! $p ) {
			return null;
		}
		if ( $p->is_type( 'variation' ) ) {
			return static::snapshot( $p->get_parent_id() );
		}
		$children = array();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_children() as $child_id ) {
				$c = wc_get_product( $child_id );
				if ( $c ) {
					$children[] = array( $child_id, $c->get_sku(), $c->get_stock_status(), $c->get_status(), get_post_meta( $child_id, '_digitalogic_patris_product_code', true ) );
				}
			}
		}
		$post = get_post( $p->get_id() );
		return array(
			'id'           => $p->get_id(),
			'published'    => 'publish' === $post->post_status,
			'available'    => $p->is_in_stock(),
			'sku'          => $p->get_sku(),
			'patris'       => get_post_meta( $p->get_id(), '_digitalogic_patris_product_code', true ),
			'owner'        => get_post_meta( $p->get_id(), '_digitalogic_patris_owner_product_code', true ),
			'identity'     => hash( 'sha256', wp_json_encode( array( $p->get_sku(), get_post_meta( $p->get_id(), '_digitalogic_patris_product_code', true ), get_post_meta( $p->get_id(), '_digitalogic_patris_owner_product_code', true ), $post->post_title, $children ) ) ),
			'missing'      => '' === trim( wp_strip_all_tags( $post->post_content ) ),
			'content_hash' => hash( 'sha256', $post->post_content ),
			'copy_hash'    => hash( 'sha256', wp_json_encode( array( $post->post_content, $post->post_excerpt, get_post_meta( $p->get_id(), 'rank_math_title', true ), get_post_meta( $p->get_id(), 'rank_math_description', true ) ) ) ),
		);
	}
	/** One generation per meaningful transition; ordinary price/quantity updates are no-ops.
	 *
	 * @param array|null $old Previous snapshot.
	 * @param array      $now Current snapshot.
	 * @return string|null Controlled transition reason.
	 */
	public static function reason( ?array $old, array $now ): ?string {
		if ( ! $now['published'] ) {
			return null;
		}
		if ( ! $old ) {
			return $now['missing'] ? 'missing_description' : 'initial_seo_audit';
		}
		if ( ! $old['published'] ) {
			return 'published';
		}
		if ( ! $old['available'] && $now['available'] ) {
			return 'restocked';
		}
		if ( $old['identity'] !== $now['identity'] ) {
			return 'identity_changed';
		}
		if ( ! $old['missing'] && $now['missing'] ) {
			return 'description_removed';
		}
		if ( $old['copy_hash'] !== $now['copy_hash'] ) {
			return 'copy_changed';
		}
		return null;
	}
	/** Serialize a short queue operation with a MySQL connection lock.
	 *
	 * @param string   $name Lock name.
	 * @param callable $callback Protected operation.
	 * @return mixed Operation result.
	 * @throws RuntimeException When another owner holds the lock. */
	protected static function locked( string $name, callable $callback ) {
		global $wpdb;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $name ) ) ) {
			throw new RuntimeException( 'Content queue busy.' );
		}
		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
	/** Fail closed on a database write error.
	 *
	 * @param int|bool $result Database result.
	 * @throws RuntimeException On a failed database write. */
	protected static function write( $result ): void {
		if ( false === $result ) {
			throw new RuntimeException( 'Content queue database write failed.' );
		}
	}
	/** Atomically observe one parent and enqueue meaningful transitions.
	 *
	 * @param int $id Product or variation ID.
	 * @return array|null Transition summary. */
	public static function observe( int $id ): ?array {
		$parent = wp_get_post_parent_id( $id );
		if ( 'product_variation' === get_post_type( $id ) && $parent ) {
			$id = $parent;
		}
		if ( 'product' !== get_post_type( $id ) ) {
			return null;
		}
		return static::locked(
			'dgl-content-product-' . $id,
			static function () use ( $id ) {
				global $wpdb;
				$now = static::snapshot( $id );
				if ( ! $now ) {
					return null;
				}
				$s          = static::table( 'state' );
				$j          = static::table( 'jobs' );
				$row        = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE product_id=%d', $s, $id ), ARRAY_A );
				$old        = $row ? json_decode( $row['snapshot'], true ) : null;
				$reason     = static::reason( $old, $now );
				$generation = $row ? (int) $row['generation'] + ( $reason ? 1 : 0 ) : 1;
				static::write( $wpdb->query( 'START TRANSACTION' ) );
				try {
					static::write(
						$wpdb->replace(
							$s,
							array(
								'product_id'  => $id,
								'generation'  => $generation,
								'snapshot'    => wp_json_encode( $now ),
								'observed_at' => gmdate( 'Y-m-d H:i:s' ),
							)
						)
					);
					if ( $reason || ! $now['published'] ) {
						static::write( $wpdb->query( $wpdb->prepare( "UPDATE %i SET status='superseded',token='',lease_until=NULL,updated_at=UTC_TIMESTAMP() WHERE product_id=%d AND status NOT IN ('done','superseded')", $j, $id ) ) );
					}
					if ( $reason ) {
						$recent = get_post_field( 'post_date_gmt', $id ) >= gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS );
						$stages = ( $now['missing'] || in_array( $reason, array( 'published', 'restocked', 'identity_changed' ), true ) ) ? array( 'content', 'seo' ) : array( 'seo' );
						foreach ( $stages as $stage ) {
							static::write(
								$wpdb->insert(
									$j,
									array(
										'product_id'   => $id,
										'generation'   => $generation,
										'stage'        => $stage,
										'reason'       => $reason,
										'priority'     => ( 'restocked' === $reason || 'published' === $reason ? 200 : ( $now['available'] ? 100 : 50 ) ) + ( $recent ? 40 : 0 ),
										'status'       => 'queued',
										'available_at' => gmdate( 'Y-m-d H:i:s' ),
										'created_at'   => gmdate( 'Y-m-d H:i:s' ),
										'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
										'result'       => '{}',
									)
								)
							);
						}
					}
					static::write( $wpdb->query( 'COMMIT' ) );
				} catch ( Throwable $e ) {
					$wpdb->query( 'ROLLBACK' );
					throw $e;
				}
				return array(
					'product_id' => $id,
					'generation' => $generation,
					'reason'     => $reason,
				);
			}
		);
	}
	/** Recover missed hooks using a durable bounded catalog cursor.
	 *
	 * @param int $limit Maximum parent records.
	 * @return array Sweep progress. */
	public static function reconcile( int $limit = 100 ): array {
		return static::locked(
			'dgl-content-sweep',
			static function () use ( $limit ) {
				global $wpdb;
				$cursor = (int) get_option( 'digitalogic_content_sweep_cursor', 0 );
				$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND ID>%d ORDER BY ID LIMIT %d", $cursor, max( 1, min( 2000, $limit ) ) ) );
				$count  = 0;
				foreach ( $ids as $id ) {
					$r = static::observe( (int) $id );
					if ( $r && $r['reason'] ) {
								++$count;
					} $cursor = (int) $id;
				}
				$complete = count( $ids ) < $limit;
				update_option( 'digitalogic_content_sweep_cursor', $complete ? 0 : $cursor, false );
				if ( $complete ) {
					update_option( 'digitalogic_content_last_sweep', gmdate( 'c' ), false );
				}
				return array(
					'scanned'           => count( $ids ),
					'enqueued_products' => $count,
					'cursor'            => $complete ? 0 : $cursor,
					'complete'          => $complete,
				);
			}
		);
	}
	/** Exclusively claim a runnable job; expired leases can be reclaimed.
	 *
	 * @param string $stage Content or SEO.
	 * @param int    $id Optional exact product ID.
	 * @return array|null Private claim with fencing token.
	 * @throws InvalidArgumentException For an unknown stage. */
	public static function claim( string $stage, int $id = 0 ): ?array {
		if ( ! in_array( $stage, array( 'content', 'seo' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid stage.' );
		}
		return static::locked(
			'dgl-content-claim',
			static function () use ( $stage, $id ) {
				global $wpdb;
				$j   = static::table( 'jobs' );
				$s   = static::table( 'state' );
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT q.* FROM %i q JOIN %i s ON s.product_id=q.product_id AND s.generation=q.generation WHERE q.stage=%s AND ((q.status IN ('queued','retry','blocked','waiting_google') AND q.available_at<=UTC_TIMESTAMP()) OR (q.status='processing' AND q.lease_until<UTC_TIMESTAMP())) AND (%d=0 OR q.product_id=%d) AND NOT EXISTS (SELECT 1 FROM %i c WHERE c.product_id=q.product_id AND c.generation=q.generation AND c.stage='content' AND c.status<>'done' AND q.stage='seo') ORDER BY q.priority DESC,q.id LIMIT 1", $j, $s, $stage, $id, $id, $j ), ARRAY_A );
				if ( ! $row ) {
					return null;
				}
				$token = bin2hex( random_bytes( 24 ) );
				// Observation can commit a new generation between selection and reservation.
				$claimed = $wpdb->query( $wpdb->prepare( "UPDATE %i q JOIN %i s ON s.product_id=q.product_id AND s.generation=q.generation SET q.status='processing',q.token=%s,q.attempts=q.attempts+1,q.lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 45 MINUTE),q.updated_at=UTC_TIMESTAMP() WHERE q.id=%d AND ((q.status IN ('queued','retry','blocked','waiting_google') AND q.available_at<=UTC_TIMESTAMP()) OR (q.status='processing' AND q.lease_until<UTC_TIMESTAMP()))", $j, $s, $token, $row['id'] ) );
				static::write( $claimed );
				if ( 1 !== $claimed ) {
					return null; }
				$row['token']    = $token;
				$row['attempts'] = (int) $row['attempts'] + 1;
				$row['snapshot'] = static::snapshot( (int) $row['product_id'] );
				$p               = get_post( $row['product_id'] );
				$row['product']  = array(
					'id'                => (int) $row['product_id'],
					'title'             => $p->post_title,
					'description'       => $p->post_content,
					'short_description' => $p->post_excerpt,
					'url'               => get_permalink( $p->ID ),
					'source_name'       => get_post_meta( $p->ID, '_digitalogic_patris_name', true ),
					'seo_title'         => get_post_meta( $p->ID, 'rank_math_title', true ),
					'seo_description'   => get_post_meta( $p->ID, 'rank_math_description', true ),
					'copy_verified_at'  => get_post_meta( $p->ID, '_digitalogic_content_evidence', true )['verified_at'] ?? null,
				);
				return $row;
			}
		);
	}
	/** Require an unexpired claim in the current product generation.
	 *
	 * @param array $input Job ID and fencing token.
	 * @return array Reserved job.
	 * @throws RuntimeException For stale, expired or superseded claims. */
	public static function reserved( array $input ): array {
		global $wpdb;
		$j   = static::table( 'jobs' );
		$s   = static::table( 'state' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT q.* FROM %i q JOIN %i s ON s.product_id=q.product_id AND s.generation=q.generation WHERE q.id=%d AND q.token=%s AND q.status='processing' AND q.lease_until>UTC_TIMESTAMP()", $j, $s, $input['id'] ?? 0, $input['token'] ?? '' ), ARRAY_A );
		if ( ! $row ) {
			throw new RuntimeException( 'Stale or expired content claim.' );
		}
		return $row;
	}
	/** Persist a fenced outcome and bounded retry delay.
	 *
	 * @param array $input Claim, outcome and private result.
	 * @return array Outcome summary.
	 * @throws InvalidArgumentException For an unknown outcome.
	 * @throws RuntimeException For a lost claim. */
	public static function finish( array $input ): array {
		global $wpdb;
		$row    = static::reserved( $input );
		$status = $input['status'] ?? '';
		if ( ! in_array( $status, array( 'done', 'retry', 'blocked', 'waiting_google' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid outcome.' );
		}
		$delay = 'done' === $status ? 0 : max( 300, min( 2592000, (int) ( $input['retry_seconds'] ?? min( 86400, 300 * ( 2 ** min( 8, (int) $row['attempts'] ) ) ) ) ) );
		$j     = static::table( 'jobs' );
		$n     = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status=%s,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),lease_until=NULL,token='',result=%s,updated_at=UTC_TIMESTAMP() WHERE id=%d AND token=%s AND status='processing'", $j, $status, $delay, wp_json_encode( $input['result'] ?? array() ), $row['id'], $input['token'] ) );
		if ( 1 !== $n ) {
			throw new RuntimeException( 'Claim changed before completion.' );
		}
		return array(
			'id'     => (int) $row['id'],
			'status' => $status,
		);
	}
	/** Publish only missing descriptions and SEO metadata after compare-and-set validation.
	 *
	 * @param array $input Fenced claim, expected snapshot, copy and evidence.
	 * @return array Publication readback.
	 * @throws RuntimeException When identity, evidence, copy or readback fails.
	 */
	public static function publish( array $input ): array {
		$row = static::reserved( $input );
		$id  = (int) $row['product_id'];
		if ( 'content' !== $row['stage'] ) {
			throw new RuntimeException( 'Content claim required.' );
		}
		return static::locked(
			'dgl-content-product-' . $id,
			static function () use ( $input, $id ) {
				static::reserved( $input );
				$now      = static::snapshot( $id );
				$expected = $input['snapshot'] ?? array();
				if ( ! $now || ! $now['published'] || ( $expected['copy_hash'] ?? '' ) !== $now['copy_hash'] || ( $expected['identity'] ?? '' ) !== $now['identity'] ) {
					throw new RuntimeException( 'Product changed after claim; reconcile before publishing.' );
				}
				if ( '' === $now['sku'] || ( '' !== $now['patris'] && $now['sku'] !== $now['patris'] ) || ( '' !== $now['owner'] && $now['sku'] !== $now['owner'] ) ) {
					throw new RuntimeException( 'Exact product identity is not verified.' );
				}
				$copy = $input['copy'] ?? array();
				foreach ( array( 'description', 'short_description', 'seo_title', 'seo_description' ) as $field ) {
					if ( ! isset( $copy[ $field ] ) || ! is_string( $copy[ $field ] ) || strlen( $copy[ $field ] ) > 100000 ) {
						throw new RuntimeException( 'Invalid copy payload.' );
					}
				}
				$text = wp_strip_all_tags( $copy['description'] . ' ' . $copy['short_description'] );
				if ( preg_match( '/دیجیتال[ااآ]?جیک|دیجیتالوجیک|digitalogic|کد\s*کالا|کد\s*پاتریس|\bSKU\b/iu', $text ) || ( strlen( $now['sku'] ) >= 6 && str_contains( $text, $now['sku'] ) ) ) {
					throw new RuntimeException( 'Store identifiers are forbidden in product copy.' );
				}
				if ( $now['missing'] && mb_strlen( wp_strip_all_tags( $copy['description'] ) ) < 160 ) {
					throw new RuntimeException( 'Meaningful verified description required.' );
				}
				if ( empty( $input['evidence'] ) || ! is_array( $input['evidence'] ) ) {
					throw new RuntimeException( 'Manufacturer evidence required.' );
				}
				foreach ( $input['evidence'] as $e ) {
					if ( ! is_array( $e ) || 'https' !== wp_parse_url( $e['url'] ?? '', PHP_URL_SCHEME ) || empty( $e['facts'] ) ) {
						throw new RuntimeException( 'Invalid source evidence.' );
					}
				}
				$after = static::store_copy( $id, $input );
				if ( function_exists( 'rocket_clean_post' ) ) {
					rocket_clean_post( $id ); }
				if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) {
					\RankMath\Sitemap\Cache::invalidate_storage(); }
				return array(
					'product_id' => $id,
					'copy_hash'  => $after['copy_hash'],
					'url'        => get_permalink( $id ),
				);
			}
		);
	}
	/** Commit only copy fields with database row locks and current claim fencing.
	 *
	 * @param int   $id Product ID.
	 * @param array $input Expected snapshot and verified copy.
	 * @return array Authoritative stored snapshot.
	 * @throws RuntimeException When a concurrent edit or a write failure is detected.
	 * @throws Throwable When WordPress metadata storage fails.
	 */
	protected static function store_copy( int $id, array $input ): array {
		global $wpdb;
		static::write( $wpdb->query( 'START TRANSACTION' ) );
		try {
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID=%d FOR UPDATE", $id ) );
			$wpdb->get_results( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id=%d FOR UPDATE", $id ) );
			clean_post_cache( $id );
			static::reserved( $input );
			$now = static::snapshot( $id );
			if ( ! $now || ! $now['published'] || $now['copy_hash'] !== $input['snapshot']['copy_hash'] || $now['identity'] !== $input['snapshot']['identity'] ) {
				throw new RuntimeException( 'Concurrent product edit; publication cancelled.' );
			}
			$copy = $input['copy'];
			if ( $now['missing'] ) {
				$content = wp_kses_post( $copy['description'] );
				$excerpt = sanitize_textarea_field( $copy['short_description'] );
				$count   = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content=%s,post_excerpt=%s,post_modified=%s,post_modified_gmt=%s WHERE ID=%d AND BINARY post_content=BINARY %s AND BINARY post_excerpt=BINARY %s AND BINARY post_title=BINARY %s AND post_status='publish'", $content, $excerpt, current_time( 'mysql' ), gmdate( 'Y-m-d H:i:s' ), $id, $before->post_content, $before->post_excerpt, $before->post_title ) );
				if ( 1 !== $count ) {
					throw new RuntimeException( 'Conditional copy write rejected.' ); }
			}
			update_post_meta( $id, 'rank_math_title', sanitize_text_field( $copy['seo_title'] ) );
			update_post_meta( $id, 'rank_math_description', sanitize_text_field( $copy['seo_description'] ) );
			update_post_meta(
				$id,
				'_digitalogic_content_evidence',
				array(
					'verified_at' => gmdate( 'c' ),
					'sources'     => $input['evidence'],
					'unknowns'    => $input['unknowns'] ?? array(),
				)
			);
			clean_post_cache( $id );
			$after = static::snapshot( $id );
			if ( $after['missing'] || ( $now['missing'] && ( get_post_field( 'post_content', $id ) !== $content || get_post_field( 'post_excerpt', $id ) !== $excerpt ) ) || sanitize_text_field( $copy['seo_description'] ) !== get_post_meta( $id, 'rank_math_description', true ) || sanitize_text_field( $copy['seo_title'] ) !== get_post_meta( $id, 'rank_math_title', true ) || ( get_post_meta( $id, '_digitalogic_content_evidence', true )['sources'] ?? null ) !== $input['evidence'] ) {
				throw new RuntimeException( 'Publication readback failed.' );
			}
			static::write(
				$wpdb->update(
					static::table( 'state' ),
					array(
						'snapshot'    => wp_json_encode( $after ),
						'observed_at' => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'product_id' => $id )
				)
			);
			static::write( $wpdb->query( 'COMMIT' ) );
			return $after;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		} finally {
			clean_post_cache( $id ); }
	}
	/** Expose capability-gated operational counts without claim tokens.
	 *
	 * @return array Counts and recent job summaries. */
	public static function status(): array {
		global $wpdb;
		$j = static::table( 'jobs' );
		return array(
			'last_full_sweep' => get_option( 'digitalogic_content_last_sweep' ),
			'counts'          => $wpdb->get_results( $wpdb->prepare( 'SELECT stage,status,COUNT(*) count,MIN(created_at) oldest FROM %i GROUP BY stage,status', $j ), ARRAY_A ),
			'recent'          => $wpdb->get_results( $wpdb->prepare( 'SELECT id,product_id,generation,stage,reason,status,attempts,available_at,updated_at FROM %i ORDER BY updated_at DESC,id DESC LIMIT 20', $j ), ARRAY_A ),
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'digitalogic content-queue',
		static function ( $args, $assoc ) {
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers manage_woocommerce.
			if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'manage_woocommerce' ) ) {
				WP_CLI::error( 'Administrator capability required.' );
			}
			try {
				$command = $args[0] ?? 'status';
				if ( in_array( $command, array( 'finish', 'publish' ), true ) ) {
					$raw = stream_get_contents( STDIN, 1048577 );
					if ( strlen( $raw ) > 1048576 ) {
						throw new RuntimeException( 'Input too large.' );
					}
					$input = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
					$r     = Digitalogic_Product_Content_Queue::$command( $input );
				} elseif ( 'install' === $command ) {
					Digitalogic_Product_Content_Queue::install();
					$r = array( 'installed' => true );
				} elseif ( 'reconcile' === $command ) {
					$r = Digitalogic_Product_Content_Queue::reconcile( (int) ( $assoc['limit'] ?? 100 ) );
				} elseif ( 'observe' === $command ) {
					$r = Digitalogic_Product_Content_Queue::observe( (int) ( $assoc['id'] ?? 0 ) );
				} elseif ( 'claim' === $command ) {
					$r = Digitalogic_Product_Content_Queue::claim( $assoc['stage'] ?? 'content', (int) ( $assoc['id'] ?? 0 ) );
				} elseif ( 'status' === $command ) {
					$r = Digitalogic_Product_Content_Queue::status();
				} else {
					throw new InvalidArgumentException( 'Use install, reconcile, observe, claim, publish, finish or status.' );
				}
				WP_CLI::line( wp_json_encode( $r, JSON_UNESCAPED_UNICODE ) );
			} catch ( Throwable $e ) {
				WP_CLI::error( $e->getMessage() );
			}
		},
		array(
			'shortdesc' => 'Manage the private durable product content and Google readiness queue.',
			'synopsis'  => '[<operation>] [--stage=<stage>] [--id=<id>] [--limit=<limit>]',
		)
	);
}
