<?php
/**
 * Native SMS format fixtures: fake provider transport, no real SMS or credentials.
 *
 * @package Digitalogic
 */

// phpcs:disable -- This isolated SQL/HTTP test double intentionally mirrors only the exercised contract.

use Digitalogic\Laravel\Services\MobileOtpSmsFormat as Format;
use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use Digitalogic\Laravel\Http\Controllers\MobileIdentityController as Controller;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/laravel/app/Services/MobileOtpSmsFormat.php';
require_once dirname(__DIR__).'/includes/integrations/class-smsir-integration.php';

if (!function_exists('wp_remote_request')) {
	define('DIGITALOGIC_NATIVE_SMS_FIXTURE_TRANSPORT',true);
	function wp_remote_request($url, $args=[]) {
		if (!isset($GLOBALS['native_sms_fixture'])) throw new LogicException('Unconfigured fixture HTTP is forbidden.');
		$GLOBALS['native_sms_fixture']['calls'][]=['url'=>$url,'args'=>$args];
		return $GLOBALS['native_sms_fixture']['response'];
	}
}

final class NativeSmsFormatFixtureDatabase {
	public string $prefix='fixture_', $options='fixture_options', $usermeta='fixture_usermeta', $users='fixture_users', $last_error='';
	public array $rows=[], $queries=[];
	public bool $rateAllowed=true, $insertAllowed=true;
	public function prepare($sql,...$args) { return ['sql'=>$sql,'args'=>$args]; }
	public function esc_like($value) { return $value; }
	public function get_row($sql,$mode=null) {
		foreach ($this->rows as $row) if ($row['request_hash']===$sql['args'][1]) return $row;
		return null;
	}
	public function get_results($sql,$mode=null) { return []; }
	public function query($sql) {
		$this->queries[]=$sql;
		return str_starts_with($sql['sql'],'UPDATE fixture_options') && !$this->rateAllowed ? 0 : 1;
	}
	public function insert($table,$row) {
		if (!$this->insertAllowed) return false;
		$this->rows[$row['id']]=$row; return 1;
	}
	public function update($table,$values,$where) {
		$this->rows[$where['id']]=array_replace($this->rows[$where['id']],$values); return 1;
	}
}

final class MobileOtpSmsFormatTest extends TestCase {
	private array $saved;
	private NativeSmsFormatFixtureDatabase $db;
	private \Illuminate\Container\Container $previousContainer;
	protected function setUp(): void {
		if (!defined('DIGITALOGIC_NATIVE_SMS_FIXTURE_TRANSPORT')) throw new LogicException('Real WordPress HTTP transport is forbidden for this test.');
		$this->saved=[];
		foreach (['wpdb','digitalogic_test_options','digitalogic_test_option_cache','native_sms_fixture'] as $key) $this->saved[$key]=$GLOBALS[$key]??null;
		$GLOBALS['digitalogic_test_option_cache']=[];
		$GLOBALS['digitalogic_test_options']=[
			'digitalogic_mobile_identity_schema'=>'2', 'digitalogic_mobile_native_auth'=>true,
			'digitalogic_mobile_otp_format_schema'=>'1',
			'digit_smsir2'=>['apiKey'=>'fixture-only-not-a-credential','template'=>'123','variables'=>'OTP:%OTP%'],
			Format::OPTION=>['enabled'=>true,'template_id'=>456,'app_hash'=>'q3HQy4X3L3v'],
		];
		$GLOBALS['native_sms_fixture']=['calls'=>[],'response'=>['response'=>['code'=>200],'body'=>'{"status":1}']];
		$this->db=new NativeSmsFormatFixtureDatabase(); $GLOBALS['wpdb']=$this->db;
		$this->previousContainer=\Illuminate\Container\Container::getInstance();
		$container=new \Illuminate\Container\Container();
		$factory=$this->createStub(\Illuminate\Contracts\Routing\ResponseFactory::class);
		$factory->method('json')->willReturnCallback(static fn($data,$status=200,$headers=[],$options=0)=>new \Illuminate\Http\JsonResponse($data,$status,$headers,$options));
		$container->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,$factory);
		\Illuminate\Container\Container::setInstance($container);
	}
	protected function tearDown(): void {
		foreach ($this->saved as $key=>$value) $GLOBALS[$key]=$value;
		\Illuminate\Container\Container::setInstance($this->previousContainer);
	}
	private function request(array $overrides=[],?array $actor=null) {
		$body=array_replace(['phone'=>'+989123456789','request_id'=>'4037971b-65f7-49b2-beca-12775e1a667e','sms_format'=>'android_hash'],$overrides);
		if (isset($body['_legacy'])) { unset($body['_legacy'],$body['sms_format']); }
		$request=Request::create('/app-api/auth/otp/request','POST',[],[],[],['CONTENT_TYPE'=>'application/json'],wp_json_encode($body));
		return $actor===null ? (new Controller())->requestOtp($request) : (new ReflectionMethod(Controller::class,'requestChallenge'))->invoke(new Controller(),$request,$actor);
	}
	private function payload(): array { return json_decode($GLOBALS['native_sms_fixture']['calls'][0]['args']['body'],true); }
	public function test_configuration_is_explicit_and_never_accepts_client_routing(): void {
		self::assertTrue(Format::available());
		foreach ([[],['enabled'=>false,'template_id'=>456,'app_hash'=>'q3HQy4X3L3v'],['enabled'=>true,'template_id'=>'456','app_hash'=>'q3HQy4X3L3v'],['enabled'=>true,'template_id'=>456,'app_hash'=>'bad']] as $config) self::assertNull(Format::configuration($config));
		foreach (['app_hash','sms_hash','template','template_id','parameters'] as $field) {
			$r=$this->request([$field=>'untrusted']); self::assertSame(400,$r->getStatusCode()); self::assertSame('invalid_sms_format',$r->getData(true)['code']);
		}
		foreach ([null,false,[],42,'default','android',''] as $value) self::assertSame(400,$this->request(['sms_format'=>$value])->getStatusCode());
		self::assertSame([],$this->db->queries); self::assertSame([],$this->db->rows); self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_android_uses_only_approved_template_and_code_parameter(): void {
		$r=$this->request(); self::assertSame(202,$r->getStatusCode());
		self::assertSame('android_hash',$r->getData(true)['sms_format']); self::assertSame('accepted',$r->getData(true)['delivery_status']);
		$p=$this->payload(); self::assertSame(456,$p['templateId']); self::assertSame(['mobile','templateId','parameters'],array_keys($p));
		self::assertSame('CODE',$p['parameters'][0]['name']); self::assertCount(1,$p['parameters']); self::assertMatchesRegularExpression('/^[0-9]{6}$/D',$p['parameters'][0]['value']);
		self::assertArrayNotHasKey('code',$r->getData(true)); self::assertStringNotContainsString($p['parameters'][0]['value'],$r->getContent());
		self::assertCount(1,$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_legacy_uses_unchanged_template_and_mapping_without_format_schema(): void {
		unset($GLOBALS['digitalogic_test_options']['digitalogic_mobile_otp_format_schema'],$GLOBALS['digitalogic_test_options'][Format::OPTION]);
		$r=$this->request(['_legacy'=>true]); self::assertSame(202,$r->getStatusCode()); self::assertSame('default',$r->getData(true)['sms_format']);
		self::assertSame(123,$this->payload()['templateId']); self::assertSame('OTP',$this->payload()['parameters'][0]['name']);
		self::assertArrayNotHasKey('sms_format',array_values($this->db->rows)[0]);
	}
	public function test_unconfigured_android_stops_before_rate_counter_or_reservation(): void {
		unset($GLOBALS['digitalogic_test_options'][Format::OPTION]);
		$r=$this->request(); self::assertSame(503,$r->getStatusCode()); self::assertSame('android_sms_unavailable',$r->getData(true)['code']);
		self::assertSame([],$this->db->queries); self::assertSame([],$this->db->rows); self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_uncertain_transport_replay_never_sends_again_and_format_change_conflicts(): void {
		$GLOBALS['native_sms_fixture']['response']=new WP_Error('http_request_failed','Synthetic timeout.');
		$a=$this->request(); $b=$this->request(); self::assertSame($a->getData(true),$b->getData(true)); self::assertSame('pending',$a->getData(true)['delivery_status']);
		self::assertSame(409,$this->request(['_legacy'=>true])->getStatusCode());
		unset($GLOBALS['digitalogic_test_options'][Format::OPTION]);
		self::assertSame(202,$this->request()->getStatusCode()); self::assertCount(1,$GLOBALS['native_sms_fixture']['calls']); self::assertCount(1,$this->db->rows);
	}
	public function test_rate_limit_and_failed_reservation_preserve_no_send_boundary(): void {
		$this->db->rateAllowed=false; self::assertSame(429,$this->request()->getStatusCode());
		$this->db->rateAllowed=true; $this->db->insertAllowed=false; self::assertSame(409,$this->request()->getStatusCode());
		self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_phone_link_and_merge_proof_keep_actor_scope_and_one_shot_delivery(): void {
		$actor=['user_id'=>7,'id'=>str_repeat('a',32)];
		$r=$this->request([],$actor); self::assertSame(202,$r->getStatusCode());
		$row=array_values($this->db->rows)[0]; self::assertSame('phone_link',$row['purpose']); self::assertSame(7,$row['actor_user_id']); self::assertSame($actor['id'],$row['actor_session_id']);
		self::assertSame('android_hash',$row['sms_format']); self::assertSame(202,$this->request([],$actor)->getStatusCode()); self::assertCount(1,$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_direct_sender_rejects_unknown_or_unconfigured_format_without_http(): void {
		self::assertInstanceOf(WP_Error::class,Digitalogic_Smsir_Integration::send_native_login_otp('+989123456789','123456','other'));
		unset($GLOBALS['digitalogic_test_options'][Format::OPTION]);
		self::assertInstanceOf(WP_Error::class,Digitalogic_Smsir_Integration::send_native_login_otp('+989123456789','123456','android_hash'));
		self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_fixed_template_fits_retriever_byte_limit(): void {
		$message="کد ورود دیجیتالاجیک: 123456\nq3HQy4X3L3v";
		self::assertLessThanOrEqual(140,strlen($message)); self::assertLessThanOrEqual(140,strlen(mb_convert_encoding($message,'UTF-16BE','UTF-8')));
	}
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function test_unavailable_format_preserves_top_level_error_through_actual_http_kernel(): void {
		unset($GLOBALS['digitalogic_test_options'][Format::OPTION]);
		$app=\Digitalogic\Laravel\Application::bootShared();
		$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
		$request=Request::create('/_digitalogic/mobile/auth/otp/request','POST',[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],wp_json_encode(['phone'=>'+989123456789','request_id'=>'4037971b-65f7-49b2-beca-12775e1a667e','sms_format'=>'android_hash']));
		$response=$kernel->handle($request);
		self::assertSame(503,$response->getStatusCode());
		$body=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
		self::assertSame('android_sms_unavailable',$body['code']); self::assertArrayNotHasKey('data',$body);
		self::assertSame([],$this->db->rows); self::assertSame([],$this->db->queries); self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
	}
}
