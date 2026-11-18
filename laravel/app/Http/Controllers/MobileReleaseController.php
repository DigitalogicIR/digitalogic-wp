<?php
/**
 * Permanent download aliases over the single issued-release database authority.
 *
 * @package Digitalogic
 */

namespace Digitalogic\Laravel\Http\Controllers;

// phpcs:disable WordPress.Files.FileName, WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 Laravel contracts.

use Digitalogic\Laravel\Services\MobileRelease;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Public reads never select, issue, upload or install a release. */
final class MobileReleaseController {
	/**
	 * Return the current verified public manifest without stale intermediary caching.
	 *
	 * @param Request $request Current GET/HEAD request.
	 * @return Response The issued manifest or unavailable result.
	 */
	public function manifest( Request $request ): Response {
		$release = MobileRelease::advertised();
		if ( null === $release ) {
			return $this->unavailable( $request );
		}
		$data = MobileRelease::validate( $release, $release['version_code'] );
		return response()->json( $data, 200, $this->headers(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )->prepare( $request );
	}

	/**
	 * Redirect only to the exact immutable APK from the issued database row.
	 *
	 * @param Request $request Current GET/HEAD request; query parameters select nothing.
	 * @return Response Temporary redirect, preserving Range on the immutable download.
	 */
	public function apk( Request $request ): Response {
		$release = MobileRelease::advertised();
		if ( null === $release ) {
			return $this->unavailable( $request );
		}
		return ( new Response( '', 307, $this->headers() + array( 'Location' => $release['apk_url'] ) ) )->prepare( $request );
	}

	/** All outcomes, including redirects and errors, must bypass intermediary caches. */
	private function headers(): array {
		return array(
			'Cache-Control'          => 'private, no-store, max-age=0',
			'Pragma'                 => 'no-cache',
			'Expires'                => '0',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

	/**
	 * Missing, corrupt or unreadable authority never falls back to a static file.
	 *
	 * @param Request $request Current request.
	 * @return Response Retryable unavailable result.
	 */
	private function unavailable( Request $request ): Response {
		return response()->json( array( 'code' => 'release_unavailable' ), 503, $this->headers() + array( 'Retry-After' => '5' ) )->prepare( $request );
	}
}
