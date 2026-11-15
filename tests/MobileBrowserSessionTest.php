<?php
/**
 * Browser cookie/origin/CSRF boundaries using encrypted fixtures and fake SQL only.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Focused transport fixture, no real browser account, network or provider side effects.

use Digitalogic\Laravel\Services\MobileBrowserSession as BrowserSession;
use Digitalogic\Laravel\Services\MobileIdentityStore as BrowserIdentity;
use Digitalogic\Laravel\Services\MobileRegistrationStore as BrowserSealing;
use Digitalogic\Laravel\Http\Controllers\MobileBrowserController;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

// Reuse the existing isolated canonical-user lookup double rather than replace authentication.
require_once __DIR__.'/MobileGoogleContinuationTest.php';

final class MobileBrowserSessionFixtureDatabase {
	public string $prefix='fixture_', $options='fixture_options', $last_error='';
	public array $sessions=[], $queries=[];
	public bool $lockAvailable=true, $rateAllowed=true;
	public int $acquired=0, $released=0;
	public function prepare($sql,...$args) { return ['sql'=>$sql,'args'=>$args]; }
	public function esc_like($value) { return $value; }
	public function get_var($query) {
		$this->queries[]=$query;
		if(str_contains($query['sql'],'GET_LOCK')) { if($this->lockAvailable)$this->acquired++;return $this->lockAvailable?1:0; }
		if(str_contains($query['sql'],'RELEASE_LOCK')) { $this->released++;return 1; }
		throw new LogicException('Unexpected fixture scalar query.');
	}
	public function get_row($query,$mode=null) {
		$this->queries[]=$query;
		if(!str_contains($query['sql'],'token_hash=%s'))throw new LogicException('Unexpected fixture session lookup.');
		foreach($this->sessions as$row) if($row['token_hash']===$query['args'][1]&&$row['expires_at']>$query['args'][2])return$row;
		return null;
	}
	public function query($query) {
		$this->queries[]=$query;
		if(str_starts_with($query['sql'],'UPDATE fixture_options'))return $this->rateAllowed?1:0;
		if(str_starts_with($query['sql'],'INSERT IGNORE INTO fixture_options')||str_starts_with($query['sql'],'DELETE FROM fixture_options'))return 1;
		throw new LogicException('Unexpected fixture mutation; no accounts may be written.');
	}
}

final class MobileBrowserSessionTest extends TestCase {
	private const UUID='a83d22e0-98e5-4be4-8dad-0be4c1ebc2ec';
	private array $saved=[];
	private MobileBrowserSessionFixtureDatabase $db;
	private Illuminate\Container\Container $previousContainer;
	protected function setUp():void {
		foreach(['wpdb','digitalogic_test_options','digitalogic_test_option_cache','digitalogic_test_user_meta','digitalogic_test_transients','digitalogic_test_transient_set_callback','continuation_fixture_users','_SERVER'] as$key)$this->saved[$key]=$GLOBALS[$key]??null;
		$this->db=new MobileBrowserSessionFixtureDatabase();$GLOBALS['wpdb']=$this->db;
		$GLOBALS['digitalogic_test_options']=['digitalogic_mobile_browser_auth'=>true,'digitalogic_mobile_native_auth'=>true];
		$GLOBALS['digitalogic_test_option_cache']=[];$GLOBALS['digitalogic_test_user_meta']=[];$GLOBALS['digitalogic_test_transients']=[];$GLOBALS['digitalogic_test_transient_set_callback']=null;
		$GLOBALS['continuation_fixture_users']=[7=>(object)['ID'=>7,'user_pass'=>'!unusable-browser-fixture-password-marker']];
		$this->previousContainer=Illuminate\Container\Container::getInstance();
		$container=new Illuminate\Container\Container();
		$factory=$this->createStub(Illuminate\Contracts\Routing\ResponseFactory::class);
		$factory->method('json')->willReturnCallback(static fn($data,$status=200,$headers=[],$options=0)=>new Illuminate\Http\JsonResponse($data,$status,$headers,$options));
		$container->instance(Illuminate\Contracts\Routing\ResponseFactory::class,$factory);Illuminate\Container\Container::setInstance($container);
	}
	protected function tearDown():void {
		self::assertSame($this->db->acquired,$this->db->released);
		foreach($this->saved as$key=>$value)$GLOBALS[$key]=$value;
		Illuminate\Container\Container::setInstance($this->previousContainer);
	}
	private function server(string $method='GET',string $path='/app-api/account/profile',array $changes=[]):array {
		return array_replace(['REQUEST_METHOD'=>$method,'REQUEST_URI'=>$path,'HTTP_X_DIGITALOGIC_PWA'=>'1','HTTP_ORIGIN'=>BrowserSession::ORIGIN,'REMOTE_ADDR'=>'192.0.2.40'],$changes);
	}
	private function error(string $code,$value,int $status=403):void {
		self::assertInstanceOf(WP_Error::class,$value);self::assertSame($code,$value->get_error_code());self::assertSame($status,$value->get_error_data()['status']);
	}
	private function inspect(array $context,array $changes=[],string $method='GET',string $path='/app-api/account/profile') {
		return BrowserSession::inspect($this->server($method,$path,array_replace(['HTTP_X_DIGITALOGIC_CSRF'=>$context['csrf']],$changes)),[BrowserSession::COOKIE=>BrowserSession::encode($context)],true);
	}
	private function request(array $context,string $path='/_digitalogic/mobile/auth/otp/verify',array $body=[]):Request {
		$request=Request::create($path,'POST',[],[],[],['CONTENT_TYPE'=>'application/json'],wp_json_encode($body));
		BrowserSession::apply($request,['context'=>$context,'discovery'=>false,'network'=>'192.0.2.40','authorization'=>isset($context['access_token'])?'Bearer '.$context['access_token']:'']);
		return$request;
	}
	private function seedSession():array {
		$token=str_repeat('c',64);$id=str_repeat('d',32);
		$this->db->sessions=[$id=>['id'=>$id,'user_id'=>7,'token_hash'=>hash('sha256',$token),'auth_epoch'=>BrowserIdentity::hash($GLOBALS['continuation_fixture_users'][7]->user_pass),'expires_at'=>time()+3600,'last_seen_at'=>time()]];
		return ['access_token'=>$token,'session_id'=>$id,'expires_at'=>gmdate('c',$this->db->sessions[$id]['expires_at']),'token_type'=>'Bearer','profile'=>['id'=>7]];
	}
	public function test_anonymous_cookie_round_trip_rejects_tampering_purpose_origin_and_expiry():void {
		$context=BrowserSession::anonymous();$encoded=BrowserSession::encode($context);
		self::assertSame($context,BrowserSession::decode($encoded));self::assertStringNotContainsString($context['csrf'],$encoded);
		$raw=base64_decode($encoded,true);$raw[0]=chr(ord($raw[0])^1);self::assertNull(BrowserSession::decode(base64_encode($raw)));
		self::assertNull(BrowserSession::decode(BrowserSealing::seal(wp_json_encode($context),'wrong-purpose')));
		foreach([null,[],42,'',str_repeat('a',3501)]as$value)self::assertNull(BrowserSession::decode($value));
		foreach([['origin'=>'https://digitalogic.ir'],['expires_at'=>time()-1],['expires_at'=>time()+32*DAY_IN_SECONDS],['expires_at'=>(string)(time()+60)],['id'=>'bad'],['csrf'=>[]],['access_token'=>'bad'],['access_token'=>str_repeat('a',64),'session_id'=>'bad']]as$change)self::assertNull(BrowserSession::decode(BrowserSession::encode(array_replace($context,$change))));
	}
	public function test_safe_reads_allow_exact_referer_but_writes_require_exact_origin():void {
		foreach(['GET','HEAD','OPTIONS']as$method)self::assertTrue(BrowserSession::trustedOrigin($this->server($method,'/app-api/bootstrap',['HTTP_ORIGIN'=>'','HTTP_REFERER'=>'https://pwa.digitalogic.ir:443/path?x=1'])));
		foreach(['POST','PUT','PATCH','DELETE']as$method)self::assertFalse(BrowserSession::trustedOrigin($this->server($method,'/app-api/cart',['HTTP_ORIGIN'=>'','HTTP_REFERER'=>BrowserSession::ORIGIN.'/cart'])));
		foreach(['https://digitalogic.ir','http://pwa.digitalogic.ir','https://pwa.digitalogic.ir/','https://pwa.digitalogic.ir:443','null',[],BrowserSession::ORIGIN.'.attacker.invalid']as$origin)self::assertFalse(BrowserSession::trustedOrigin($this->server('POST','/app-api/cart',['HTTP_ORIGIN'=>$origin,'HTTP_REFERER'=>BrowserSession::ORIGIN.'/cart'])));
		foreach(['https://pwa.digitalogic.ir.attacker.invalid/','https://user@pwa.digitalogic.ir/','https://pwa.digitalogic.ir:8443/','http://pwa.digitalogic.ir/']as$referer)self::assertFalse(BrowserSession::trustedOrigin($this->server('GET','/app-api/bootstrap',['HTTP_ORIGIN'=>'','HTTP_REFERER'=>$referer])));
		self::assertTrue(BrowserSession::trustedOrigin($this->server('POST')));
	}
	public function test_stale_generation_fails_both_private_reads_and_writes():void {
		$old=BrowserSession::anonymous();$current=BrowserSession::anonymous();
		foreach(['GET','POST']as$method) {
			self::assertIsArray($this->inspect($current,[],$method));
			$this->error('browser_csrf_invalid',$this->inspect($current,['HTTP_X_DIGITALOGIC_CSRF'=>$old['csrf']],$method));
			$this->error('browser_csrf_invalid',$this->inspect($current,['HTTP_X_DIGITALOGIC_CSRF'=>''],$method));
		}
		$this->error('browser_csrf_invalid',BrowserSession::inspect($this->server(),[],true));
		$this->error('browser_origin_invalid',BrowserSession::inspect($this->server(),[],false));
		$this->error('browser_route_invalid',$this->inspect($current,[],'GET','/panel/'));
	}
	public function test_native_requests_stay_native_and_mixed_authority_is_rejected():void {
		$native=['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/app-api/checkout','HTTP_AUTHORIZATION'=>'Bearer '.str_repeat('f',64),'REMOTE_ADDR'=>'192.0.2.40'];
		self::assertNull(BrowserSession::inspect($native,[],true));
		self::assertNull(BrowserSession::inspect($native,[BrowserSession::COOKIE=>BrowserSession::encode(BrowserSession::anonymous())],true));
		$context=BrowserSession::anonymous();
		foreach(['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION']as$header)$this->error('browser_credentials_conflict',$this->inspect($context,[$header=>'Bearer '.str_repeat('f',64)]));
		$this->error('browser_origin_invalid',BrowserSession::inspect($this->server('GET','/app-api/account/profile',['HTTP_X_DIGITALOGIC_PWA'=>'']),[],true));
		$request=Request::create('/app-api/auth/otp/verify','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],wp_json_encode(['digitalogic_browser'=>['authorization'=>'forged']]));
		self::assertFalse(BrowserSession::active($request));
		$response=new Illuminate\Http\JsonResponse(['access_token'=>'native-fixture-token']);self::assertSame($response,BrowserSession::finish($request,$response));self::assertSame('native-fixture-token',json_decode($response->getContent(),true)['access_token']);
	}
	public function test_only_loopback_uses_last_forwarded_address_and_native_network_is_unchanged():void {
		self::assertSame('192.0.2.99',BrowserSession::proxyNetwork(['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>'203.0.113.7, 198.51.100.8, 192.0.2.99']));
		self::assertSame('2001:db8::9',BrowserSession::proxyNetwork(['REMOTE_ADDR'=>'::1','HTTP_X_FORWARDED_FOR'=>'198.51.100.8, 2001:db8::9']));
		self::assertSame('192.0.2.40',BrowserSession::proxyNetwork(['REMOTE_ADDR'=>'192.0.2.40','HTTP_X_FORWARDED_FOR'=>['untrusted']]));
		foreach(['','192.0.2.1, unknown','192.0.2.1:443',str_repeat('x',1025),implode(',',array_fill(0,21,'192.0.2.1'))]as$chain)self::assertNull(BrowserSession::proxyNetwork(['REMOTE_ADDR'=>'127.0.0.1','HTTP_X_FORWARDED_FOR'=>$chain]));
		self::assertNull(BrowserSession::proxyNetwork(['REMOTE_ADDR'=>'unknown']));
		$this->error('browser_proxy_invalid',BrowserSession::inspect($this->server('GET','/app-api/bootstrap',['REMOTE_ADDR'=>'127.0.0.1']),[],true));
		$_SERVER['REMOTE_ADDR']='192.0.2.40';$native=Request::create('/app-api/auth/otp/request');$native->headers->set('X-Forwarded-For','203.0.113.1');self::assertSame('192.0.2.40',BrowserSession::network($native));
		$browser=$this->request(BrowserSession::anonymous());self::assertSame('192.0.2.40',BrowserSession::network($browser));
	}
	public function test_gate_and_public_cookie_free_reads_do_not_grant_identity():void {
		unset($GLOBALS['digitalogic_test_options']['digitalogic_mobile_browser_auth']);self::assertFalse(BrowserSession::enabled());
		$this->error('browser_auth_unavailable',BrowserSession::inspect($this->server('GET','/app-api/auth/browser/session'),[],true),503);
		$public=BrowserSession::inspect($this->server('GET','/app-api/products/42'),[],true);self::assertIsArray($public);self::assertNull($public['context']);self::assertSame('',$public['authorization']);
		$this->error('browser_csrf_invalid',BrowserSession::inspect($this->server('GET','/app-api/bootstrap'),[BrowserSession::COOKIE=>'tampered'],true));
		foreach([false,'false','true',[],0]as$value) {$GLOBALS['digitalogic_test_options']['digitalogic_mobile_browser_auth']=$value;self::assertFalse(BrowserSession::enabled());}
	}
	public function test_unmarked_public_get_and_head_ignore_pwa_cookie_authority():void {
		$context=array_replace(BrowserSession::anonymous(),['access_token'=>str_repeat('e',64),'session_id'=>str_repeat('f',32)]);
		$ciphertext=BrowserSession::encode($context);
		foreach(['/app-api/bootstrap','/app-api/categories','/app-api/products','/app-api/products/42','/app-api/products/42/?preview=0','/app-api/links/resolve','/app-api/forms']as$path) {
			foreach(['GET','HEAD']as$method) {
				$server=$this->server($method,$path);unset($server['HTTP_X_DIGITALOGIC_PWA']);
				foreach([$ciphertext,'tampered-cookie']as$cookie)self::assertNull(BrowserSession::inspect($server,[BrowserSession::COOKIE=>$cookie],true));
			}
		}
		self::assertNull(BrowserSession::inspect($this->server('GET','/app-api/bootstrap',['HTTP_X_DIGITALOGIC_PWA'=>'']),[BrowserSession::COOKIE=>$ciphertext],true));
		$GLOBALS['digitalogic_test_options']['digitalogic_mobile_browser_auth']=false;
		self::assertNull(BrowserSession::inspect($this->server('HEAD','/app-api/products/42',['HTTP_X_DIGITALOGIC_PWA'=>'']),[BrowserSession::COOKIE=>$ciphertext],true));
		self::assertSame([],$this->db->queries);
	}
	public function test_public_exemption_never_covers_protected_routes_writes_or_marked_calls():void {
		foreach(['/app-api/account/profile','/app-api/cart','/app-api/checkout','/app-api/auth/browser/session','/app-api/bootstrap-extra','/app-api/products/42/private']as$path) {
			foreach(['GET','HEAD']as$method) {
				$server=$this->server($method,$path);unset($server['HTTP_X_DIGITALOGIC_PWA']);
				$this->error('browser_origin_invalid',BrowserSession::inspect($server,[],true));
			}
		}
		foreach(['POST','PUT','PATCH','DELETE','OPTIONS']as$method)$this->error('browser_origin_invalid',BrowserSession::inspect($this->server($method,'/app-api/bootstrap',['HTTP_X_DIGITALOGIC_PWA'=>'']),[],true));
		$context=BrowserSession::anonymous();
		foreach(['GET','HEAD']as$method) {
			$this->error('browser_csrf_invalid',$this->inspect($context,['HTTP_X_DIGITALOGIC_CSRF'=>'stale'],$method,'/app-api/bootstrap'));
			$this->error('browser_origin_invalid',$this->inspect($context,['HTTP_X_DIGITALOGIC_PWA'=>'0'],$method,'/app-api/bootstrap'));
		}
	}
	public function test_discovery_creates_only_secure_host_cookie_and_public_csrf():void {
		$inspection=BrowserSession::inspect($this->server('GET','/app-api/auth/browser/session',['HTTP_ORIGIN'=>'','HTTP_REFERER'=>BrowserSession::ORIGIN.'/']),[],true);
		self::assertTrue($inspection['discovery']);self::assertNull($inspection['context']);
		$request=Request::create('/_digitalogic/mobile/auth/browser/session');BrowserSession::apply($request,$inspection);
		$response=(new MobileBrowserController())->session($request);$data=json_decode($response->getContent(),true);
		self::assertSame(200,$response->getStatusCode());self::assertFalse($data['authenticated']);self::assertSame('browser',$data['session_type']);self::assertArrayNotHasKey('access_token',$data);
		$cookies=$response->headers->getCookies();self::assertCount(1,$cookies);$cookie=$cookies[0];
		self::assertSame('__Host-dgl-pwa',$cookie->getName());self::assertTrue($cookie->isSecure());self::assertTrue($cookie->isHttpOnly());self::assertSame('strict',$cookie->getSameSite());self::assertSame('/',$cookie->getPath());self::assertNull($cookie->getDomain());
		$context=BrowserSession::decode($cookie->getValue());self::assertSame($data['csrf_token'],$context['csrf']);self::assertArrayNotHasKey('access_token',$context);self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
	}
	public function test_finish_hides_valid_native_credential_and_rotates_browser_generation():void {
		$old=BrowserSession::anonymous();$payload=$this->seedSession();$request=$this->request($old);
		$response=BrowserSession::finish($request,new Illuminate\Http\JsonResponse($payload));$data=json_decode($response->getContent(),true);
		self::assertTrue($data['authenticated']);self::assertSame('browser',$data['session_type']);self::assertArrayNotHasKey('access_token',$data);self::assertArrayNotHasKey('token_type',$data);self::assertStringNotContainsString($payload['access_token'],$response->getContent());
		$cookies=$response->headers->getCookies();self::assertCount(1,$cookies);$context=BrowserSession::decode($cookies[0]->getValue());self::assertSame($payload['access_token'],$context['access_token']);self::assertSame($payload['session_id'],$context['session_id']);self::assertNotSame($old['id'],$context['id']);self::assertNotSame($old['csrf'],$context['csrf']);self::assertSame($context['csrf'],$data['csrf_token']);
		$inspection=$this->inspect($context);self::assertSame('Bearer '.$payload['access_token'],$inspection['authorization']);
		$this->error('browser_csrf_invalid',$this->inspect($context,['HTTP_X_DIGITALOGIC_CSRF'=>$old['csrf']]));
	}
	public function test_finish_invalid_revoked_or_mismatched_credentials_never_reach_json_or_cookie():void {
		$payload=$this->seedSession();$request=$this->request(BrowserSession::anonymous());
		foreach([['access_token'=>'malformed'],['session_id'=>str_repeat('e',32)],['profile'=>['id'=>8]]]as$change) {
			$response=BrowserSession::finish($request,new Illuminate\Http\JsonResponse(array_replace($payload,$change)));self::assertSame(503,$response->getStatusCode());self::assertSame(['code'=>'browser_session_unavailable'],json_decode($response->getContent(),true));self::assertSame([],$response->headers->getCookies());
		}
		$this->db->sessions=[];$response=BrowserSession::finish($request,new Illuminate\Http\JsonResponse($payload));self::assertSame(503,$response->getStatusCode());self::assertStringNotContainsString($payload['access_token'],$response->getContent());self::assertSame([],$response->headers->getCookies());
	}
	public function test_discovery_replaces_revoked_or_expired_authority_with_anonymous_generation():void {
		foreach(['revoked','expired']as$state) {
			$payload=$this->seedSession();$context=array_replace(BrowserSession::anonymous(),['access_token'=>$payload['access_token'],'session_id'=>$payload['session_id']]);
			if($state==='revoked')$this->db->sessions=[];else$this->db->sessions[$payload['session_id']]['expires_at']=time()-1;
			$inspection=BrowserSession::inspect($this->server('GET','/app-api/auth/browser/session'),[BrowserSession::COOKIE=>BrowserSession::encode($context)],true);
			$request=Request::create('/_digitalogic/mobile/auth/browser/session');BrowserSession::apply($request,$inspection);
			$response=(new MobileBrowserController())->session($request);$data=json_decode($response->getContent(),true);$anonymous=BrowserSession::decode($response->headers->getCookies()[0]->getValue());
			self::assertSame(200,$response->getStatusCode());self::assertFalse($data['authenticated']);self::assertArrayNotHasKey('profile',$data);self::assertArrayNotHasKey('access_token',$data);self::assertArrayNotHasKey('access_token',$anonymous);self::assertNotSame($context['id'],$anonymous['id']);self::assertNotSame($context['csrf'],$data['csrf_token']);
		}
	}
	public function test_logout_rotates_only_current_browser_generation():void {
		$payload=$this->seedSession();$context=array_replace(BrowserSession::anonymous(),['access_token'=>$payload['access_token'],'session_id'=>$payload['session_id'],'expires_at'=>time()+3600]);
		$other=$this->request($context,'/_digitalogic/mobile/auth/logout',['session_id'=>str_repeat('a',32)]);
		$response=BrowserSession::finish($other,new Illuminate\Http\JsonResponse(['revoked'=>true]));self::assertSame([],$response->headers->getCookies());
		$current=$this->request($context,'/_digitalogic/mobile/auth/logout',['session_id'=>$payload['session_id']]);
		$response=BrowserSession::finish($current,new Illuminate\Http\JsonResponse(['revoked'=>true]));$anonymous=BrowserSession::decode($response->headers->getCookies()[0]->getValue());self::assertArrayNotHasKey('access_token',$anonymous);self::assertNotSame($context['csrf'],$anonymous['csrf']);
	}
	public function test_google_nonce_replay_is_cookie_generation_bound_and_expires():void {
		$context=BrowserSession::anonymous();$challenge=BrowserSession::googleChallenge($context,self::UUID,'192.0.2.40');self::assertIsArray($challenge);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$challenge['nonce']);
		$writes=count($this->db->queries);self::assertSame($challenge,BrowserSession::googleChallenge($context,self::UUID,'192.0.2.40'));self::assertSame($writes+2,count($this->db->queries));
		$proof=json_decode(BrowserSealing::open($challenge['challenge_id'],'pwa-google-v1'),true);self::assertSame($context['id'],$proof['context_id']);self::assertSame('pwa_google_login',$proof['purpose']);self::assertSame(300,$proof['expires_at']-$proof['created_at']);
		$verified=BrowserSession::verifyGoogleChallenge($context,$challenge['challenge_id'],['nonce'=>$challenge['nonce']]);self::assertSame($proof['created_at'],$verified['verified_at']);
		$this->error('browser_google_invalid_challenge',BrowserSession::verifyGoogleChallenge(BrowserSession::anonymous(),$challenge['challenge_id'],['nonce'=>$challenge['nonce']]));
		$this->error('browser_google_invalid_challenge',BrowserSession::verifyGoogleChallenge($context,$challenge['challenge_id'],['nonce'=>str_repeat('0',64)]));
		foreach([['purpose'=>'different'],['created_at'=>time()+30],['created_at'=>time()-301],['expires_at'=>time()-1],['expires_at'=>'invalid'],['nonce'=>null]]as$change)$this->error('browser_google_invalid_challenge',BrowserSession::verifyGoogleChallenge($context,BrowserSealing::seal(wp_json_encode(array_replace($proof,$change)),'pwa-google-v1'),['nonce'=>$challenge['nonce']]));
		$this->error('browser_google_invalid_challenge',BrowserSession::verifyGoogleChallenge($context,str_repeat('x',3001),['nonce'=>$challenge['nonce']]));
		foreach($GLOBALS['digitalogic_test_transients']as&$receipt)$receipt['value']['expires_at_epoch']=time()-1;unset($receipt);
		$this->error('browser_google_expired',BrowserSession::googleChallenge($context,self::UUID,'192.0.2.40'),410);
	}
	public function test_google_nonce_failure_paths_have_no_unbound_receipt():void {
		$context=BrowserSession::anonymous();$this->error('browser_google_invalid_request',BrowserSession::googleChallenge($context,'not-a-uuid','192.0.2.40'),400);self::assertSame([],$this->db->queries);
		$this->db->lockAvailable=false;$this->error('browser_google_busy',BrowserSession::googleChallenge($context,self::UUID,'192.0.2.40'),409);$this->db->lockAvailable=true;
		$this->db->rateAllowed=false;$this->error('rate_limited',BrowserSession::googleChallenge($context,self::UUID,'192.0.2.40'),429);self::assertSame([],$GLOBALS['digitalogic_test_transients']);
		$this->db->rateAllowed=true;$GLOBALS['digitalogic_test_transient_set_callback']=static fn()=>false;$this->error('browser_google_unavailable',BrowserSession::googleChallenge($context,self::UUID,'192.0.2.40'),503);self::assertSame([],$GLOBALS['digitalogic_test_transients']);
	}
}
