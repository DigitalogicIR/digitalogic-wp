<?php
/**
 * Operational compatibility, legacy consent and report lifetime fixtures without real devices.
 *
 * @package Digitalogic
 */

// phpcs:disable -- Isolated SQL fixture intentionally models only the tested contract.

use Digitalogic\Laravel\Services\MobileDeviceReport as Reports;
use Digitalogic\Laravel\Services\MobileIdentityStore as Identity;
use PHPUnit\Framework\TestCase;

final class DigitalogicDeviceReportFixtureDatabase {
	public string $prefix='fixture_', $users='fixture_users', $usermeta='fixture_usermeta', $options='fixture_options', $last_error='';
	public bool $active=true, $current=true, $allowRate=true, $allowLock=true, $allowReplace=true, $allowCommit=true;
	public bool $revokeWhileWaiting=false;
	public int $acquired=0, $released=0, $replacements=0;
	public array $rows=[], $queries=[];
	private ?array $snapshot=null;
	public function prepare($sql,...$args) { return ['sql'=>$sql,'args'=>$args]; }
	public function esc_like($text) { return $text; }
	public function get_var($query) {
		$sql=is_array($query)?$query['sql']:$query;
		$args=is_array($query)?$query['args']:[];
		$this->queries[]=$query;
		if (str_contains($sql,'GET_LOCK')) { if(!$this->allowLock) return 0; $this->acquired++; if($this->revokeWhileWaiting)$this->active=false; return 1; }
		if (str_contains($sql,'RELEASE_LOCK')) { $this->released++; return 1; }
		if (str_contains($sql,'SELECT ID')) return 7;
		if (str_contains($sql,'SELECT id')) return $this->active?$args[1]:null;
		if (str_contains($sql,"option_name='digitalogic_mobile_native_auth'")) return '1';
		throw new LogicException('Unexpected fixture scalar query.');
	}
	public function get_row($query,$format=null) {
		$this->queries[]=$query;
		if (str_contains($query['sql'],'s.auth_epoch')) return $this->current?['auth_epoch'=>Identity::hash('fixture-unusable-marker'),'user_pass'=>'fixture-unusable-marker']:null;
		throw new LogicException('Unexpected fixture row query.');
	}
	public function get_results($query,$format=null) {
		$this->queries[]=$query;
		if (!str_contains($query['sql'],'HAVING COUNT(*)>=5') || !str_contains($query['sql'],'LIMIT 100')) throw new LogicException('Unbounded fixture report.');
		$groups=[];
		foreach($this->rows as $row) {
			if($row['expires_at']<=$query['args'][1])continue;
			$data=array_intersect_key($row,array_fill_keys(Reports::FIELDS,true)); $key=json_encode($data);
			$groups[$key]??=$data+['session_days'=>0]; $groups[$key]['session_days']++;
		}
		return array_values(array_filter($groups,static fn($row)=>$row['session_days']>=5));
	}
	public function query($query) {
		$this->queries[]=$query;
		$sql=is_array($query)?$query['sql']:$query;
		if($sql==='START TRANSACTION') { $this->snapshot=$this->rows; return 1; }
		if($sql==='ROLLBACK') { if($this->snapshot!==null)$this->rows=$this->snapshot; $this->snapshot=null; return 1; }
		if($sql==='COMMIT') { if(!$this->allowCommit)return false; $this->snapshot=null; return 1; }
		if(str_starts_with($sql,'UPDATE fixture_options'))return $this->allowRate?1:0;
		if(str_starts_with($sql,'DELETE FROM %i WHERE expires_at')) {
			$before=count($this->rows); $this->rows=array_filter($this->rows,static fn($row)=>$row['expires_at']>$query['args'][1]); return $before-count($this->rows);
		}
		if(str_starts_with($sql,'INSERT IGNORE INTO fixture_options') || str_starts_with($sql,'DELETE FROM fixture_options'))return 1;
		throw new LogicException('Unexpected fixture write.');
	}
	public function replace($table,$row) {
		$this->replacements++;
		if(!$this->allowReplace)return false;
		$this->rows[$row['contribution_key']]=$row; return 1;
	}
}

final class MobileDeviceReportTest extends TestCase {
	private array $saved;
	private DigitalogicDeviceReportFixtureDatabase $db;
	protected function setUp(): void {
		$this->saved=[];
		foreach(['wpdb','digitalogic_test_options','digitalogic_test_option_cache','digitalogic_test_capabilities','digitalogic_test_current_user_can_calls'] as $name)$this->saved[$name]=$GLOBALS[$name]??null;
		$this->db=new DigitalogicDeviceReportFixtureDatabase(); $GLOBALS['wpdb']=$this->db;
		$GLOBALS['digitalogic_test_options']=['digitalogic_mobile_native_auth'=>true,'digitalogic_mobile_device_reports'=>true,'digitalogic_mobile_device_report_schema'=>'1'];
		$GLOBALS['digitalogic_test_option_cache']=[];
		$GLOBALS['digitalogic_test_capabilities']=['manage_options'=>true];
	}
	protected function tearDown(): void {
		self::assertSame($this->db->acquired,$this->db->released);
		foreach($this->saved as $name=>$value)$GLOBALS[$name]=$value;
	}
	private function input(): array { return ['version_code'=>14,'sdk_int'=>35,'manufacturer'=>'Samsung','model'=>'SM-G988B','abi'=>'arm64-v8a','ram_class'=>'standard','form_factor'=>'phone']; }
	private function session(): array { return ['id'=>str_repeat('a',32),'user_id'=>7,'expires_at'=>time()+1000]; }
	private function error(string $code,$result): void { self::assertInstanceOf(WP_Error::class,$result); self::assertSame($code,$result->get_error_code()); }
	public function test_seven_bounded_fields_are_required_and_legacy_consent_true_is_compatible(): void {
		$data=Reports::normalize($this->input()); self::assertSame(Reports::FIELDS,array_keys($data)); self::assertSame('samsung',$data['manufacturer']);
		self::assertSame($data,Reports::normalize($this->input()+['consent'=>true]));
		foreach([null,false,0,1,'true','false',[]]as$value)$this->error('invalid_device_report',Reports::normalize($this->input()+['consent'=>$value]));
		foreach(['consent'=>false,'version_code'=>'14','sdk_int'=>101,'manufacturer'=>str_repeat('a',65),'model'=>"name\nserial",'abi'=>'mips','ram_class'=>'huge','form_factor'=>'desktop'] as $field=>$value)$this->error('invalid_device_report',Reports::normalize(array_replace($this->input(),[$field=>$value])));
		foreach(['user_id','session_id','token','android_id','serial','fingerprint','ip','email','consent_generation'] as $field)$this->error('invalid_device_report',Reports::normalize($this->input()+[$field=>'must-not-store']));
		foreach(array_keys($this->input()) as $field) { $input=$this->input(); unset($input[$field]); $this->error('invalid_device_report',Reports::normalize($input)); }
		self::assertSame([],$this->db->queries);
	}
	public function test_operator_disable_and_explicit_legacy_refusal_never_write(): void {
		unset($GLOBALS['digitalogic_test_options']['digitalogic_mobile_device_reports']); self::assertFalse(Reports::enabled());
		$this->error('device_reporting_unavailable',Reports::submit($this->session(),$this->input()));
		foreach(['false','true',[],0] as $value) { $GLOBALS['digitalogic_test_options']['digitalogic_mobile_device_reports']=$value; self::assertFalse(Reports::enabled()); }
		$GLOBALS['digitalogic_test_options']['digitalogic_mobile_device_reports']=true;
		$this->error('invalid_device_report',Reports::submit($this->session(),array_replace($this->input(),['consent'=>false])));
		self::assertSame([],$this->db->queries); self::assertSame([],$this->db->rows);
	}
	public function test_same_session_day_replaces_metadata_without_duplicate_or_identifier_storage(): void {
		self::assertSame(['accepted'=>true,'retention_days'=>30],Reports::submit($this->session(),$this->input()));
		Reports::submit($this->session(),array_replace($this->input(),['version_code'=>15,'consent'=>true]));
		self::assertCount(1,$this->db->rows); $row=array_values($this->db->rows)[0]; self::assertSame(15,$row['version_code']);
		self::assertSame(['contribution_key','day',...Reports::FIELDS,'expires_at'],array_keys($row));
		self::assertStringNotContainsString($this->session()['id'],json_encode($row)); self::assertArrayNotHasKey('user_id',$row);
		self::assertArrayNotHasKey('consent',$row);
		self::assertLessThanOrEqual(time()+30*DAY_IN_SECONDS,$row['expires_at']); self::assertGreaterThan(time()+29*DAY_IN_SECONDS-5,$row['expires_at']);
		self::assertNotSame(Reports::contributionKey($this->session()['id'],'2026-11-15'),Reports::contributionKey($this->session()['id'],'2026-11-16'));
		self::assertNotSame(Reports::contributionKey(str_repeat('b',32),'2026-11-15'),Reports::contributionKey($this->session()['id'],'2026-11-15'));
	}
	public function test_revoked_or_changed_session_is_rechecked_after_lock_without_report_write(): void {
		$this->db->revokeWhileWaiting=true; $this->error('unauthorized',Reports::submit($this->session(),$this->input()));
		self::assertSame([],$this->db->rows); self::assertSame(0,$this->db->replacements);
		$this->db->revokeWhileWaiting=false; $this->db->active=true; $this->db->current=false;
		$this->error('unauthorized',Reports::submit($this->session(),$this->input())); self::assertSame(0,$this->db->replacements);
	}
	public function test_rate_limit_lock_and_storage_failure_do_not_leave_report(): void {
		$this->db->allowLock=false; $this->error('device_report_busy',Reports::submit($this->session(),$this->input()));
		$this->db->allowLock=true; $this->db->allowRate=false; $this->error('device_report_rate_limited',Reports::submit($this->session(),$this->input()));
		$this->db->allowRate=true; $this->db->allowReplace=false; $this->error('device_report_not_saved',Reports::submit($this->session(),$this->input()));
		$this->db->allowReplace=true; $this->db->allowCommit=false; $this->error('device_report_not_saved',Reports::submit($this->session(),$this->input()));
		self::assertSame([],$this->db->rows);
	}
	public function test_expired_contributions_are_excluded_and_cleanup_works_while_disabled(): void {
		Reports::submit($this->session(),$this->input()); $row=array_values($this->db->rows)[0];
		$this->db->rows=[]; for($i=0;$i<5;$i++)$this->db->rows['expired-'.$i]=array_replace($row,['contribution_key'=>'expired-'.$i,'expires_at'=>time()-1]);
		self::assertSame([],Reports::report()['items']);
		$GLOBALS['digitalogic_test_options']['digitalogic_mobile_device_reports']=false; Reports::cleanup(); self::assertSame([],$this->db->rows);
	}
	public function test_administrator_report_suppresses_small_groups_and_never_projects_keys(): void {
		Reports::submit($this->session(),$this->input()); self::assertSame([],Reports::report()['items']);
		$row=array_values($this->db->rows)[0]; for($i=0;$i<4;$i++)$this->db->rows['sample-'.$i]=array_replace($row,['contribution_key'=>'sample-'.$i]);
		$report=Reports::report(); self::assertCount(1,$report['items']); self::assertSame(5,$report['items'][0]['session_days']); self::assertArrayNotHasKey('contribution_key',$report['items'][0]);
		$GLOBALS['digitalogic_test_capabilities']=[]; $this->error('forbidden',Reports::report());
	}
}
