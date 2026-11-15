<?php
/**
 * Native RealtimeContractTest adapter and verification contracts.
 *
 * @package Digitalogic
 */

use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Memory-only streams exercise the real WebSocket frame encoder without network access.

/** Regression coverage for the native adapter. */
final class MobileRealtimeContractTest extends TestCase {
	/** Private support invalidations never expose another owner's messages or content. */
	public function test_support_events_are_owner_only_and_have_stable_transport_identity(): void {
		$event = array(
			'id'   => 100,
			'name' => 'mobile.message.created',
			'data' => array(
				'user_id'   => 7,
				'ticket_id' => 11,
				'body'      => 'private text',
				'email'     => 'private',
			),
		);
		self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 0 ) );
		self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 8 ) );
		$projected = Digitalogic_WebSocket_Server::project_shopper_event( $event, 7 );
		self::assertSame(
			array(
				'scope'     => 'messages',
				'ticket_id' => 11,
			),
			$projected['data']
		);
		self::assertSame( 'message.created', $projected['name'] );
		self::assertSame( 'wp-panel:100', $projected['event_id'] );
		self::assertSame( 100, $projected['id'] );
	}
	/**
	 * Verify public events never inherit panel payload or customer events.
	 *
	 * @return void Native adapter result.
	 */
	public function test_public_events_never_inherit_panel_payload_or_customer_events(): void {
		$project = Digitalogic_WebSocket_Server::project_shopper_event(
			array(
				'id'   => 42,
				'name' => 'product.updated',
				'time' => 'now',
				'data' => array(
					'id'             => 123,
					'cost'           => 'secret',
					'supplier_token' => 'private',
				),
			),
			0
		);
		self::assertSame(
			array(
				'scope'      => 'product',
				'product_id' => 123,
				'object_id'  => 123,
				'parent_id'  => 0,
				'change'     => 'updated',
			),
			$project['data']
		);
		foreach ( array( 'user.updated', 'panel.event', 'mobile.order.updated', 'admin.settings.updated' ) as $name ) {
			self::assertNull(
				Digitalogic_WebSocket_Server::project_shopper_event(
					array(
						'id'   => 43,
						'name' => $name,
						'data' => array(
							'id'      => 77,
							'user_id' => 77,
						),
					),
					0
				)
			);}
	}

	/**
	 * Verify native identity receives only own small invalidation.
	 *
	 * @return void Native adapter result.
	 */
	public function test_native_identity_receives_only_own_small_invalidation(): void {
		$event = array(
			'id'   => 44,
			'name' => 'mobile.order.updated',
			'data' => array(
				'user_id'  => 77,
				'order_id' => 123,
				'status'   => 'completed',
				'address'  => 'private',
			),
		);
		self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 78 ) );
		self::assertSame(
			array(
				'scope'    => 'orders',
				'order_id' => 123,
				'status'   => 'completed',
			),
			Digitalogic_WebSocket_Server::project_shopper_event( $event, 77 )['data']
		);
		self::assertNull(
			Digitalogic_WebSocket_Server::project_shopper_event(
				array(
					'id'   => 45,
					'name' => 'user.updated',
					'data' => array( 'id' => 78 ),
				),
				77
			)
		);
		self::assertSame(
			array( 'scope' => 'profile' ),
			Digitalogic_WebSocket_Server::project_shopper_event(
				array(
					'id'   => 45,
					'name' => 'user.updated',
					'data' => array(
						'id'    => 77,
						'email' => 'private',
					),
				),
				77
			)['data']
		);
	}

	/**
	 * Verify guest shopper auth cannot select a panel or machine principal.
	 *
	 * @return void Native adapter result.
	 */
	public function test_guest_shopper_auth_cannot_select_a_panel_or_machine_principal(): void {
		$context = Digitalogic_WebSocket_Auth::authenticate_context( array( 'sec-websocket-protocol' => 'digitalogic.shopper' ), array() );
		self::assertSame( 'mobile_shopper', $context['principal'] );
		self::assertSame( 0, $context['user_id'] );
		self::assertSame( '', $context['native_session_id'] );
		self::assertFalse( Digitalogic_WebSocket_Auth::authenticate_context( array( 'sec-websocket-protocol' => 'digitalogic.shopper' ), array( 'token' => 'anything' ) )['authenticated'] );
		self::assertFalse(
			Digitalogic_WebSocket_Auth::authenticate_context(
				array(
					'sec-websocket-protocol'       => 'digitalogic.shopper',
					'x-patris-product-sync-secret' => 'anything',
				),
				array()
			)['authenticated']
		);
	}

	/** Browser shopper sockets stay public even when the browser sends ambient cookies. */
	public function test_exact_browser_origins_do_not_inherit_cookie_authority(): void {
		$before = $_COOKIE;
		foreach ( array( 'https://pwa.digitalogic.ir', 'https://digitalogic.ir' ) as $origin ) {
			$context = Digitalogic_WebSocket_Auth::authenticate_context(
				array(
					'sec-websocket-protocol' => 'digitalogic.shopper',
					'origin'                 => $origin,
					'cookie'                 => 'wordpress_logged_in_fixture=untrusted; __Host-dgl-pwa=untrusted',
				),
				array()
			);
			self::assertTrue( $context['authenticated'] );
			self::assertSame( 'mobile_shopper', $context['principal'] );
			self::assertSame( 0, $context['user_id'] );
			self::assertSame( '', $context['native_session_id'] );
			self::assertSame( $before, $_COOKIE );
		}
	}

	/** Only the exact reviewed browser origins may join the public shopper stream. */
	public function test_foreign_opaque_insecure_and_malformed_browser_origins_are_rejected(): void {
		foreach ( array( 'https://foreign.example', 'null', 'http://pwa.digitalogic.ir', 'https://pwa.digitalogic.ir/', 'https://pwa.digitalogic.ir:443', 'https://pwa.digitalogic.ir.evil.example', '', null, array( 'https://pwa.digitalogic.ir' ) ) as $origin ) {
			$context = Digitalogic_WebSocket_Auth::authenticate_context(
				array(
					'sec-websocket-protocol' => 'digitalogic.shopper',
					'origin'                 => $origin,
				),
				array()
			);
			self::assertFalse( $context['authenticated'] );
			self::assertSame( '', $context['principal'] );
		}
	}

	/** A browser Origin can never turn this public stream into native bearer transport. */
	public function test_browser_bearer_and_ambiguous_security_headers_are_rejected(): void {
		foreach ( array( 'https://pwa.digitalogic.ir', 'https://digitalogic.ir' ) as $origin ) {
			foreach ( array( '', 'Bearer ' . str_repeat( 'a', 64 ) ) as $authorization ) {
				self::assertFalse(
					Digitalogic_WebSocket_Auth::authenticate_context(
						array(
							'sec-websocket-protocol' => 'digitalogic.shopper',
							'origin'                 => $origin,
							'authorization'          => $authorization,
						),
						array()
					)['authenticated']
				);
			}
		}
		foreach ( array( 'origin', 'authorization', 'sec-websocket-protocol', 'last-event-id' ) as $duplicate ) {
			self::assertFalse(
				Digitalogic_WebSocket_Auth::authenticate_context(
					array(
						'sec-websocket-protocol'          => 'digitalogic.shopper',
						'origin'                          => 'https://pwa.digitalogic.ir',
						'__digitalogic_duplicate_headers' => array( $duplicate ),
					),
					array()
				)['authenticated']
			);
		}
	}

	/** Protocol negotiation cannot combine shopper and privileged service capabilities. */
	public function test_shopper_protocol_is_exact_and_native_origin_omission_is_unchanged(): void {
		foreach ( array( 'digitalogic.shopper,digitalogic.pricing.v1', 'digitalogic.shopper,digitalogic.pricing.commands.v1', 'digitalogic.shopper,digitalogic.shopper', 'digitalogic.shopper,', ',digitalogic.shopper' ) as $protocol ) {
			self::assertFalse( Digitalogic_WebSocket_Auth::authenticate_context( array( 'sec-websocket-protocol' => $protocol ), array() )['authenticated'] );
		}
		$context = Digitalogic_WebSocket_Auth::authenticate_context( array( 'sec-websocket-protocol' => 'digitalogic.shopper' ), array() );
		self::assertTrue( $context['authenticated'] );
		self::assertSame( 0, $context['user_id'] );
		self::assertSame( '', $context['native_session_id'] );
	}

	/** Shopper keepalive is allowed, but legacy panel and call commands are never dispatched. */
	public function test_shopper_commands_are_read_only_including_call_status(): void {
		foreach ( array( 'ping', 'digitalogic_call_status', 'update_option', 'pricing_snapshot_request' ) as $command ) {
			$socket = fopen( 'php://temp', 'w+b' );
			$server = new Digitalogic_WebSocket_Server();
			$state  = new ReflectionProperty( $server, 'clients' );
			$state->setValue(
				$server,
				array(
					42 => array(
						'socket'        => $socket,
						'handshake'     => true,
						'principal'     => 'mobile_shopper',
						'user_id'       => 0,
						'last_event_id' => 0,
					),
				)
			);
			$handler = new ReflectionMethod( $server, 'handle_message' );
			$handler->invoke(
				$server,
				42,
				wp_json_encode(
					array(
						'id'      => 'fixture',
						'command' => $command,
					)
				)
			);
			rewind( $socket );
			$decode = new ReflectionMethod( $server, 'decode_frames' );
			$frames = $decode->invoke( $server, stream_get_contents( $socket ) );
			$reply  = json_decode( $frames['messages'][0]['payload'], true );
			self::assertSame( 'fixture', $reply['id'] );
			if ( 'ping' === $command ) {
				self::assertSame( 'pong', $reply['event'] );
				self::assertTrue( $reply['success'] );
			} else {
				self::assertFalse( $reply['success'] );
				self::assertSame( 'shopper_stream_read_only', $reply['error']['code'] );
			}
			fclose( $socket );
		}
	}

	/** Public and wrong-owner readers never receive cart contents or private invalidations. */
	public function test_cart_projection_is_owner_only_and_discards_private_payload(): void {
		$event = array(
			'id'   => 101,
			'name' => 'mobile.cart.updated',
			'data' => array(
				'user_id'  => 7,
				'revision' => 3,
				'items'    => array( 'private' ),
				'address'  => 'private',
			),
		);
		self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 0 ) );
		self::assertNull( Digitalogic_WebSocket_Server::project_shopper_event( $event, 8 ) );
		$own = Digitalogic_WebSocket_Server::project_shopper_event( $event, 7 );
		self::assertSame( 'cart.updated', $own['name'] );
		self::assertSame(
			array(
				'scope'    => 'cart',
				'revision' => 3,
			),
			$own['data']
		);
		self::assertSame( 'wp-panel:101', $own['event_id'] );
	}

	/** The actual upgrade response must negotiate the shopper protocol and provide its cursor. */
	public function test_shopper_handshake_negotiates_exact_protocol_and_public_cursor(): void {
		$previous_options = $GLOBALS['digitalogic_test_options'];
		$previous_cache   = $GLOBALS['digitalogic_test_option_cache'];
		$previous_db      = $GLOBALS['wpdb'];
		$socket           = fopen( 'php://temp', 'w+b' );
		try {
			$GLOBALS['wpdb'] = new Digitalogic_Test_WPDB();
			$GLOBALS['digitalogic_test_options']['digitalogic_panel_events']         = array();
			$GLOBALS['digitalogic_test_options']['digitalogic_panel_event_sequence'] = 102;
			$GLOBALS['digitalogic_test_option_cache']                                = array();
			$server = new Digitalogic_WebSocket_Server();
			$state  = new ReflectionProperty( $server, 'clients' );
			$state->setValue(
				$server,
				array(
					42 => array(
						'socket'        => $socket,
						'handshake'     => false,
						'headers'       => '',
						'buffer'        => '',
						'user_id'       => 0,
						'principal'     => '',
						'last_event_id' => 0,
					),
				)
			);
			$request = "GET /wordpress-ws HTTP/1.1\r\n"
				. "Host: digitalogic.ir\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
				. "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
				. "Sec-WebSocket-Protocol: digitalogic.shopper\r\n"
				. "Origin: https://pwa.digitalogic.ir\r\n"
				. "Cookie: wordpress_logged_in_fixture=untrusted\r\n\r\n";
			fwrite( $socket, $request );
			rewind( $socket );
			$handshake = new ReflectionMethod( $server, 'handshake' );
			$handshake->invoke( $server, 42 );
			fseek( $socket, strlen( $request ) );
			$parts = explode( "\r\n\r\n", stream_get_contents( $socket ), 2 );
			self::assertStringContainsString( 'HTTP/1.1 101 Switching Protocols', $parts[0] );
			self::assertStringContainsString( "Sec-WebSocket-Protocol: digitalogic.shopper\r\n", $parts[0] . "\r\n" );
			$decode = new ReflectionMethod( $server, 'decode_frames' );
			$frames = $decode->invoke( $server, $parts[1] );
			$hello  = json_decode( $frames['messages'][0]['payload'], true );
			self::assertSame( 'connected', $hello['event'] );
			self::assertSame( 'mobile_shopper', $hello['data']['principal'] );
			self::assertSame( 0, $hello['data']['user_id'] );
			self::assertSame( 102, $hello['data']['cursor'] );
			self::assertSame( 102, $hello['data']['latest_event_id'] );
			self::assertFalse( $hello['data']['cursor_reset_required'] );
			self::assertSame( '', $state->getValue( $server )[42]['headers'] );
			self::assertSame( '', $state->getValue( $server )[42]['native_session_id'] );
		} finally {
			fclose( $socket );
			$GLOBALS['digitalogic_test_options']      = $previous_options;
			$GLOBALS['digitalogic_test_option_cache'] = $previous_cache;
			$GLOBALS['wpdb']                          = $previous_db;
		}
	}
}
