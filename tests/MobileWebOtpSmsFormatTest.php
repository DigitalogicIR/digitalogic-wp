<?php
/**
 * Fixed-origin WebOTP format fixtures; fake provider only, never real SMS.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Isolated fake HTTP transport and contract assertions, never production provider traffic.

use Digitalogic\Laravel\Services\MobileOtpSmsFormat as WebOtpFormat;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/laravel/app/Services/MobileOtpSmsFormat.php';
require_once dirname(__DIR__).'/includes/integrations/class-smsir-integration.php';
require_once dirname(__DIR__).'/includes/integrations/class-digitalogic-shatel-sms.php';

if (!function_exists('wp_remote_request')) {
	define('DIGITALOGIC_NATIVE_SMS_FIXTURE_TRANSPORT',true);
	function wp_remote_request($url,$args=[]) {
		if (!isset($GLOBALS['native_sms_fixture'])) throw new LogicException('Unconfigured fixture HTTP is forbidden.');
		$GLOBALS['native_sms_fixture']['calls'][]=['url'=>$url,'args'=>$args];
		return $GLOBALS['native_sms_fixture']['response'];
	}
}

final class MobileWebOtpSmsFormatTest extends TestCase {
	private array $saved=[];
	protected function setUp():void {
		if (!defined('DIGITALOGIC_NATIVE_SMS_FIXTURE_TRANSPORT')) throw new LogicException('Real WordPress HTTP transport is forbidden.');
		foreach(['digitalogic_test_options','digitalogic_test_option_cache','native_sms_fixture'] as $key) $this->saved[$key]=$GLOBALS[$key]??null;
		$GLOBALS['digitalogic_test_option_cache']=[];
		$GLOBALS['digitalogic_test_options']=[
			'digitalogic_mobile_otp_format_schema'=>'1',
			'digit_smsir2'=>['apiKey'=>'fixture-only-not-a-provider-credential','template'=>'123','variables'=>'OTP:%OTP%'],
			WebOtpFormat::OPTION=>['enabled'=>true,'template_id'=>456,'app_hash'=>'q3HQy4X3L3v'],
			WebOtpFormat::WEBOTP_OPTION=>$this->configuration(),
		];
		$GLOBALS['native_sms_fixture']=['calls'=>[],'response'=>['response'=>['code'=>200],'body'=>'{"status":1}']];
	}
	protected function tearDown():void {
		foreach($this->saved as $key=>$value) $GLOBALS[$key]=$value;
	}
	private function configuration():array { return ['enabled'=>true,'template_id'=>789,'origin'=>'https://pwa.digitalogic.ir']; }
	private function send(string $format='web_otp') { return Digitalogic_Smsir_Integration::send_native_login_otp('+989123456789','123456',$format); }
	private function payload(int $index=0):array { return json_decode($GLOBALS['native_sms_fixture']['calls'][$index]['args']['body'],true,512,JSON_THROW_ON_ERROR); }
	private function unavailable():void {
		self::assertFalse(WebOtpFormat::available('web_otp'));
		$result=$this->send();self::assertInstanceOf(WP_Error::class,$result);self::assertSame('webotp_sms_unavailable',$result->get_error_code());
		self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_closed_selector_preserves_legacy_android_and_rejects_client_routing():void {
		self::assertSame('default',WebOtpFormat::requested([]));
		self::assertSame('android_hash',WebOtpFormat::requested(['sms_format'=>'android_hash']));
		self::assertSame('web_otp',WebOtpFormat::requested(['sms_format'=>'web_otp']));
		foreach(['app_hash','sms_hash','template','template_id','templateId','parameters','origin','host','domain','sms_origin','webotp_origin'] as $field) {
			try {WebOtpFormat::requested(['sms_format'=>'web_otp',$field=>'untrusted']);self::fail('Client routing was accepted.');}
			catch(InvalidArgumentException $error) {self::assertSame('invalid_sms_format',$error->getMessage());}
		}
		foreach([null,false,[],42,'default','pwa_webotp','web_otp ',''] as $format) {
			try {WebOtpFormat::requested(['sms_format'=>$format]);self::fail('Unknown selector was accepted.');}
			catch(InvalidArgumentException $error) {self::assertSame('invalid_sms_format',$error->getMessage());}
		}
		self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
	}
	public function test_configuration_is_closed_explicit_and_fixed_to_exact_pwa_origin():void {
		self::assertSame(['template_id'=>789,'origin'=>WebOtpFormat::WEBOTP_ORIGIN],WebOtpFormat::configuration($this->configuration(),'web_otp'));
		foreach([
			['enabled'=>false],['enabled'=>1],['template_id'=>'789'],['template_id'=>0],['template_id'=>2147483648],
			['origin'=>'http://pwa.digitalogic.ir'],['origin'=>'https://digitalogic.ir'],['origin'=>'https://pwa.digitalogic.ir/'],
			['origin'=>'https://pwa.digitalogic.ir:443'],['origin'=>'https://pwa.digitalogic.ir?x=1'],['origin'=>'https://pwa.digitalogic.ir.attacker.invalid'],
			['app_hash'=>'q3HQy4X3L3v'],['parameters'=>['CODE'=>'anything']],
		] as $change) self::assertNull(WebOtpFormat::configuration(array_replace($this->configuration(),$change),'web_otp'));
		foreach(array_keys($this->configuration()) as $key) {$configuration=$this->configuration();unset($configuration[$key]);self::assertNull(WebOtpFormat::configuration($configuration,'web_otp'));}
		self::assertNull(WebOtpFormat::configuration($this->configuration(),'unknown'));
		self::assertFalse(WebOtpFormat::available('unknown'));
	}
	public function test_unconfigured_disabled_schema_and_provider_cases_never_send():void {
		unset($GLOBALS['digitalogic_test_options'][WebOtpFormat::WEBOTP_OPTION]);$this->unavailable();
		$GLOBALS['digitalogic_test_options'][WebOtpFormat::WEBOTP_OPTION]=array_replace($this->configuration(),['enabled'=>false]);$this->unavailable();
		$GLOBALS['digitalogic_test_options'][WebOtpFormat::WEBOTP_OPTION]=$this->configuration();
		unset($GLOBALS['digitalogic_test_options']['digitalogic_mobile_otp_format_schema']);$this->unavailable();
		$GLOBALS['digitalogic_test_options']['digitalogic_mobile_otp_format_schema']='1';
		unset($GLOBALS['digitalogic_test_options']['digit_smsir2']['apiKey']);$this->unavailable();
	}
	public function test_webotp_template_must_be_distinct_from_default_and_android():void {
		foreach([123,456] as $template) {
			$GLOBALS['digitalogic_test_options'][WebOtpFormat::WEBOTP_OPTION]=array_replace($this->configuration(),['template_id'=>$template]);$this->unavailable();
		}
		$GLOBALS['digitalogic_test_options'][WebOtpFormat::OPTION]['enabled']=false;$this->unavailable();
		$GLOBALS['digitalogic_test_options'][WebOtpFormat::WEBOTP_OPTION]=$this->configuration();self::assertTrue(WebOtpFormat::available('web_otp'));
	}
	public function test_webotp_uses_one_fixed_code_and_bypasses_default_shatel_interceptor():void {
		self::assertTrue(WebOtpFormat::available('web_otp'));
		self::assertSame(['status'=>1],$this->send());
		self::assertCount(1,$GLOBALS['native_sms_fixture']['calls']);
		$call=$GLOBALS['native_sms_fixture']['calls'][0];$payload=$this->payload();
		self::assertSame('https://api.sms.ir/v1/send/verify',$call['url']);self::assertSame('POST',$call['args']['method']);
		self::assertSame(['mobile'=>'09123456789','templateId'=>789,'parameters'=>[['name'=>'CODE','value'=>'123456']]],$payload);
		self::assertSame(false,Digitalogic_Shatel_SMS_Primary::maybe_route_smsir(false,$call['args'],$call['url']));
		self::assertCount(1,$GLOBALS['native_sms_fixture']['calls']);
		self::assertStringNotContainsString('pwa.digitalogic.ir',$call['args']['body']);self::assertStringNotContainsString('q3HQy4X3L3v',$call['args']['body']);
	}
	public function test_default_and_android_configuration_and_sender_are_unchanged():void {
		unset($GLOBALS['digitalogic_test_options'][WebOtpFormat::WEBOTP_OPTION]);
		self::assertTrue(WebOtpFormat::available());self::assertTrue(WebOtpFormat::available('android_hash'));
		self::assertSame(['template_id'=>456,'app_hash'=>'q3HQy4X3L3v'],WebOtpFormat::configuration($GLOBALS['digitalogic_test_options'][WebOtpFormat::OPTION]));
		self::assertSame(['status'=>1],$this->send('default'));self::assertSame(['status'=>1],$this->send('android_hash'));
		self::assertSame(123,$this->payload(0)['templateId']);self::assertSame([['name'=>'OTP','value'=>'123456']],$this->payload(0)['parameters']);
		self::assertSame(456,$this->payload(1)['templateId']);self::assertSame([['name'=>'CODE','value'=>'123456']],$this->payload(1)['parameters']);
		unset($GLOBALS['digitalogic_test_options']['digitalogic_mobile_otp_format_schema'],$GLOBALS['digitalogic_test_options'][WebOtpFormat::OPTION]);
		self::assertSame(['status'=>1],$this->send('default'));self::assertSame(123,$this->payload(2)['templateId']);
	}
	public function test_invalid_code_or_uncertain_transport_never_triggers_fallback_retry():void {
		foreach(['#123456','12345','1234567','12a456'] as $code) self::assertInstanceOf(WP_Error::class,Digitalogic_Smsir_Integration::send_native_login_otp('+989123456789',$code,'web_otp'));
		self::assertSame([],$GLOBALS['native_sms_fixture']['calls']);
		$uncertain=new WP_Error('http_request_failed','Synthetic timeout.');$GLOBALS['native_sms_fixture']['response']=$uncertain;
		self::assertSame($uncertain,$this->send());self::assertCount(1,$GLOBALS['native_sms_fixture']['calls']);
		self::assertSame(789,$this->payload()['templateId']);
	}
}
