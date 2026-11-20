<?php
/**
 * Immutable release selection, permanent signer and recovery policy fixtures.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Isolated metadata/lock fixture; no real publication or signing process is invoked.

use Digitalogic\Laravel\Services\MobileRelease as Release;
use PHPUnit\Framework\TestCase;

final class MobileReleaseFixtureDatabase {
	public string $options='fixture_options',$last_error='';
	public array $values=[],$writes=[];
	public int $released=0;
	public function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
	public function get_var($q){
		if(str_contains($q['sql'],'GET_LOCK'))return 1;
		if(str_contains($q['sql'],'RELEASE_LOCK')){$this->released++;return 1;}
		if(str_contains($q['sql'],'SELECT option_value'))return isset($this->values[$q['args'][0]])?maybe_serialize($this->values[$q['args'][0]]):null;
		throw new LogicException('Unexpected release fixture query.');
	}
	public function get_results($q,$format=ARRAY_A){$rows=[];foreach($q['args'] as $name)if(array_key_exists($name,$this->values))$rows[]=['option_name'=>$name,'option_value'=>maybe_serialize($this->values[$name])];return $rows;}
	public function query($q){$this->writes[]=$q;throw new LogicException('Replay must never mutate issuance.');}
}

final class MobileReleaseTest extends TestCase {
	private array $saved=[];
	protected function setUp():void {
		foreach(['wpdb','digitalogic_test_options','digitalogic_test_option_cache','digitalogic_test_capabilities','wp_object_cache','digitalogic_test_cache_deletes']as$key)$this->saved[$key]=$GLOBALS[$key]??null;
		$GLOBALS['digitalogic_test_options']=[];$GLOBALS['digitalogic_test_option_cache']=[];
		$GLOBALS['digitalogic_test_capabilities']=['manage_options'=>true];
		$GLOBALS['wpdb']=new MobileReleaseFixtureDatabase();
		$GLOBALS['wp_object_cache']=new \Digitalogic\Laravel\Services\MobileMergeObjectCache(1);
		if(!defined('DIGITALOGIC_APP_RELEASE_LOCK'))define('DIGITALOGIC_APP_RELEASE_LOCK',sys_get_temp_dir().'/digitalogic-release-test-'.getmypid().'.lock');
		file_put_contents(DIGITALOGIC_APP_RELEASE_LOCK,'fixture');
	}
	protected function tearDown():void {
		foreach($this->saved as$key=>$value)$GLOBALS[$key]=$value;
		if(is_file(DIGITALOGIC_APP_RELEASE_LOCK))unlink(DIGITALOGIC_APP_RELEASE_LOCK);
	}
	private function manifest(int $version=16):array {
		return ['package'=>'ir.digitalogic.app','version_code'=>$version,'version_name'=>'0.1.0','min_sdk'=>24,'apk_url'=>Release::url($version,'apk'),'sha256'=>str_repeat('a',64),'bytes'=>2048,'signing_cert_sha256'=>Release::CERTIFICATE];
	}
	public function test_manifest_is_fixed_package_path_signer_and_bounded_types():void {
		self::assertSame($this->manifest(),Release::validate($this->manifest(),16));
		foreach([['package'=>'other'],['version_code'=>15],['apk_url'=>'https://attacker.test/app.apk'],['apk_url'=>Release::url(16,'apk').'?override=1'],['bytes'=>'2048'],['bytes'=>268435457],['min_sdk'=>23],['sha256'=>str_repeat('g',64)],['version_name'=>'../16'],['signing_cert_sha256'=>str_repeat('b',64)]]as$change){
			try{Release::validate(array_replace($this->manifest(),$change),16);self::fail('Invalid release accepted.');}catch(UnexpectedValueException $error){self::assertSame('invalid_release_manifest',$error->getMessage());}
		}
		$old=$this->manifest();unset($old['signing_cert_sha256']);self::assertSame($old,Release::validate($old,16,false));
	}
	public function test_signature_requires_success_and_exactly_one_permanent_signer():void {
		$valid='Signer #1 certificate SHA-256 digest: '.Release::CERTIFICATE;
		self::assertTrue(Release::signatureMatches($valid,0));self::assertTrue(Release::signatureMatches('Signer #1 certificate SHA-256 digest: '.strtoupper(Release::CERTIFICATE),0));
		self::assertFalse(Release::signatureMatches($valid,1));self::assertFalse(Release::signatureMatches($valid."\n".$valid,0));
		self::assertFalse(Release::signatureMatches('Signer #1 certificate SHA-256 digest: '.str_repeat('b',64),0));self::assertFalse(Release::signatureMatches('checksum only',0));
	}
	public function test_stale_admin_cannot_issue_below_current_authority():void {
		self::assertTrue(Release::mayIssue(16,15));self::assertTrue(Release::mayIssue(16,16));self::assertTrue(Release::mayIssue(17,16));
		self::assertFalse(Release::mayIssue(15,16));self::assertFalse(Release::mayIssue(16,17));
	}
	public function test_actual_apk_metadata_must_match_manifest_not_only_certificate():void {
		$output="package: name='ir.digitalogic.app' versionCode='16' versionName='0.1.0' platformBuildVersionName='15'\nsdkVersion:'24'\n";
		self::assertTrue(Release::packageMatches($output,0,$this->manifest()));
		foreach([['package'=>'wrong'],['version_code'=>17],['version_name'=>'0.2.0'],['min_sdk'=>25]]as$change)self::assertFalse(Release::packageMatches($output,0,array_replace($this->manifest(),$change)));
		self::assertFalse(Release::packageMatches($output,1,$this->manifest()));
		self::assertFalse(Release::packageMatches('',$exit_code=0,$this->manifest()));
	}
	public function test_only_valid_issued_state_is_projected_publicly():void {
		self::assertNull(Release::advertised());
		$GLOBALS['wpdb']->values[Release::OPTION]=$this->manifest()+['issued_at'=>'2026-11-15T00:00:00+00:00','manifest_sha256'=>str_repeat('b',64),'internal_field'=>'never-public'];
		$r=Release::advertised();self::assertSame(Release::url(16),$r['manifest_url']);self::assertSame(16,$r['version_code']);self::assertArrayNotHasKey('internal_field',$r);self::assertArrayNotHasKey('manifest_sha256',$r);
	}
	public function test_same_uuid_recovery_heals_cache_without_downgrading_newer_state():void {
		$id='1447c746-e7df-4821-89e4-c8080fe77e76';$key='_digitalogic_release_issue_'.hash('sha256',$id);$db=$GLOBALS['wpdb'];
		$receipt=['operation_id'=>$id,'issued'=>true,'release'=>$this->manifest(16)];$db->values[$key]=$receipt;$db->values[Release::OPTION]=$this->manifest(17);
		foreach([Release::OPTION,'digitalogic_mobile_update_manifest','alloptions','notoptions']as$cache)$GLOBALS['digitalogic_test_option_cache'][$cache]='stale';
		self::assertSame($receipt,Release::issue(16,$id));self::assertSame(17,$db->values[Release::OPTION]['version_code']);self::assertSame([],$db->writes);self::assertSame(1,$db->released);
		foreach([Release::OPTION,'digitalogic_mobile_update_manifest','alloptions','notoptions']as$cache)self::assertArrayNotHasKey($cache,$GLOBALS['digitalogic_test_option_cache']);
		$r=Release::issue(17,$id);self::assertInstanceOf(WP_Error::class,$r);self::assertSame('release_operation_conflict',$r->get_error_code());self::assertSame([],$db->writes);
	}
	public function test_publisher_lock_contention_fails_before_database_or_artifact_work():void {
		$lock=fopen(DIGITALOGIC_APP_RELEASE_LOCK,'r');flock($lock,LOCK_EX|LOCK_NB);
		try{$r=Release::issue(16,'1447c746-e7df-4821-89e4-c8080fe77e76');self::assertInstanceOf(WP_Error::class,$r);self::assertSame('release_busy',$r->get_error_code());self::assertSame(0,$GLOBALS['wpdb']->released);}finally{flock($lock,LOCK_UN);fclose($lock);}
	}
	public function test_native_client_cannot_dispatch_release_admin_or_device_report_reads():void {
		self::assertNull(Digitalogic_Mobile_API::route('/app-api/releases/issue'));
		self::assertSame('/_digitalogic/mobile/account/device-report',Digitalogic_Mobile_API::route('/app-api/account/device-report'));
		self::assertSame(['POST'],Digitalogic_Mobile_API::methods('/_digitalogic/mobile/account/device-report'));
	}
}
