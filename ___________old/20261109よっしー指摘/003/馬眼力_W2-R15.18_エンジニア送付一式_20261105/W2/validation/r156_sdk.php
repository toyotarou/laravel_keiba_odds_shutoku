<?php
declare(strict_types=1);
// Replace only the DB, clock and provider transports. The actual submitted sender handles both roles.
namespace App\Http\Controllers { class Controller {} }
namespace Illuminate\Database { interface ConnectionInterface {} }
namespace App\Http\Controllers\Api {
    function gettimeofday(): array {
        return ['sec'=>intdiv(\TestIO::$now,1000000),'usec'=>\TestIO::$now%1000000];
    }
}
namespace App\Services {
    class AnthropicService {
        function sendWithRetry(...$args) {
            if($args['maxAttempts']!==1) throw new \LogicException('unexpected retry');
            return \TestIO::sent('1st',$args['system'],$args['prompt'],$args['timeout']);
        }
    }
}
namespace Illuminate\Support\Facades {
    class Http {
        static function timeout($t){ \TestIO::$httpTimeout=$t;return new self(); }
        static function withHeaders($headers){ return new self(); }
        function post($url,$data) {
            if($url==='https://api.anthropic.com/v1/messages') {
                $system=$data['system']; if(is_array($system)) $system=$system[0]['text'];
                return \TestIO::sent('1st',$system,$data['messages'][0]['content'],\TestIO::$httpTimeout);
            }
            if($url!=='https://api.deepseek.com/v1/chat/completions') throw new \LogicException('unexpected transport');
            [$s,$u]=$data['messages'];
            if($s['role']!=='system'||$u['role']!=='user') throw new \LogicException('message order');
            return \TestIO::sent('2nd',$s['content'],$u['content'],\TestIO::$httpTimeout);
        }
    }
}
namespace {
use App\Http\Controllers\Api\AiController as Controller;
use App\Http\Controllers\Api\BaganrikiTime as Time;
use App\Http\Controllers\Api\BaganrikiWorker as Worker;
use App\Services\Baganriki\CandidateSelectionW2 as Selection;
use App\Services\Baganriki\FrozenInputW2 as Frozen;
use App\Services\Baganriki\AttemptWindowW2 as Window;
use App\Services\Baganriki\DispatchW2 as Dispatch;
function env($k,$default=null){return ['BAGANRIKI_R122_BUDGET_USD_DAY'=>'500','BAGANRIKI_R122_COMPACT'=>TestIO::$compact,'BAGANRIKI_R122_1ST_MODEL'=>TestIO::$firstModel][$k]??$default;}
function config($key){return ['driver'=>'local_test'];}
class TestIO {
    public static $attempt=null,$atLiveLock=null,$applied=[],$tx=false,$locks=[],$providerResponse=null,$firstModel='';
    public static $snap,$live,$official,$onHold,$now,$lag,$httpTimeout,$compact,$calls,$audit,$failRead;
    static function reset($p,$in,$remaining) {
        self::$snap=(object)['id'=>1,'race_id'=>$p['race_id'],'snapshot_hash'=>$p['hash'],'common_json'=>$p['common'],'release_version'=>Selection::VERSION,
            'frozen_nums'=>dbJson($p['numbers']),'sales_close_at'=>Worker::toDb(Time::us($in['time_contract']['sales_close_at']))];
        self::$attempt=null;self::$atLiveLock=null;
        foreach ($p['roles'] as $row) self::$snap->{'input_json_'.$row['role']}=$row['input'];
        self::$live=(object)['snapshot_id'=>1,'active_count'=>count($p['numbers']),'cancellations'=>'[]','live_close'=>null,'state_revision'=>0];
        self::$official=(object)['start_time'=>'15:00'];self::$now=Time::us($p['now'])-$remaining;
        self::$tx=false;self::$locks=[];self::$onHold=null;self::$lag=0;self::$httpTimeout=null;self::$compact='1';self::$calls=[];self::$audit=[];self::$applied=[];self::$failRead=false;
    }
    static function readyBinding($f,$format,$slot,$system,$user,$status='SENT') {
        $in=Frozen::checked($f);$token='test-'.$in['ai_role'].'-'.$slot;
        $record=Dispatch::record($f,$format,$slot,$system,$user);
        self::$attempt=(object)['token'=>$token,'role'=>$in['ai_role'],'snapshot_id'=>1,'attempt_no'=>$slot,'status'=>$status,
            'w2_send_proof'=>'R'.Dispatch::sendBinding(1,$token,$f->raw(),$record['request_sha256'])];
    }
    static function withdraw($numbers) {
        self::$applied[]=['race_id'=>self::$snap->race_id,'old_revision'=>0,'new_revision'=>1,
            'diagnostics'=>dbJson(['cancellations'=>['前'=>[],'後'=>$numbers]])];
        self::$live->cancellations=dbJson($numbers);self::$live->active_count-=count($numbers);self::$live->state_revision=1;
    }
    static function sent($role,$system,$user,$timeout) {
        if(self::$tx) throw new \LogicException('provider called under DB lock');
        self::$calls[]=[$role,hash('sha256',$system),hash('sha256',$user),$timeout,self::$now];return new self();
    }
    function successful(){return true;} function failed(){return false;} function status(){return 200;}
    function json($key=null){
        $a=self::$providerResponse??['choices'=>[['message'=>['content'=>'local'],'finish_reason'=>'stop']],'content'=>[['type'=>'text','text'=>'local']],
            'stop_reason'=>'end_turn','usage'=>[],'model'=>'local'];return $key===null?$a:($a[$key]??null);
    }
}
class DB implements \Illuminate\Database\ConnectionInterface {
    private $tableName,$key,$filters=[],$liveTrail=false;
    static function transaction($fn){
        if(TestIO::$tx) throw new \LogicException('nested state check');
        TestIO::$tx=true;try{return $fn();}finally{TestIO::$tx=false;}
    }
    static function connection($n){return new self();}
    function transactionLevel(){return 0;}
    function select($sql){return [(object)['l'=>1]];}
    static function table($n){$q=new self();$q->tableName=$n;return $q;}
    function where(...$args){if(count($args)===2){$this->key=$args[1];$this->filters[$args[0]]=$args[1];if($args[0]==='kind' && $args[1]==='R122_LIVE_APPLIED')$this->liveTrail=true;}return $this;}
    function whereNull($field){$this->filters[$field]=null;return $this;}
    private function pending(){
        $row=TestIO::$attempt;if($row===null)return null;
        foreach($this->filters as $k=>$v)if(($row->$k??null)!==$v)return null;
        return $row;
    }
    function update($cols){$row=$this->pending();if($this->tableName!=='baganriki_ai_attempts'||$row===null)return 0;
        foreach($cols as $k=>$v)$row->$k=$v;return 1;}
    function whereIn(...$args){return $this;} function orderBy(...$args){return $this;}
    function lockForUpdate(){if(!TestIO::$tx)throw new \LogicException('unprotected read');TestIO::$locks[]=$this->tableName;
        if($this->tableName==='baganriki_live_states' && TestIO::$atLiveLock!==null)(TestIO::$atLiveLock)();return $this;}
    function get($columns){return array_map(static fn($a)=>(object)$a,$this->liveTrail?TestIO::$applied:TestIO::$audit);}
    function insert($row){
        if($this->tableName!=='baganriki_audit_events') throw new \LogicException('unexpected insert');
        TestIO::$audit[]=$row;
        if($row['kind']==='R122_BUDGET_HOLD' && TestIO::$onHold!==null) (TestIO::$onHold)();
        return true;
    }
    function first($columns=['*']){
        switch($this->tableName){
        case 'baganriki_snapshots': return $this->key===1?clone TestIO::$snap:null;
        case 'baganriki_ai_attempts':return $this->pending();
        case 'baganriki_live_states': if(TestIO::$failRead) throw new \RuntimeException('local DB unavailable');return TestIO::$live;
        case 't_horse_odds_finder_races': TestIO::$now+=TestIO::$lag;return TestIO::$official;
        default: throw new \LogicException('unexpected table '.$this->tableName);
        }
    }
}
require __DIR__.'/r7_storage_probe.php';
if(realpath($_SERVER['SCRIPT_FILENAME'])!==__FILE__)return;
function must156($ok,$msg){if(!$ok)throw new \RuntimeException($msg);}
$p=preparedRows($argv[2]);$controller=new Controller(new \App\Services\AnthropicService());$total=0;$blocked=0;$sent=0;
$observe=in_array('--observe-old',$argv,true);
foreach($p['roles'] as $row){
    $role=$row['role'];$other=$role==='1st'?'2nd':'1st';
    $f=Frozen::fromStored($p['common'],$row['input'],$p['hash'],$role,$p['witness']);$in=Frozen::checked($f);
    $scope=Window::scope(r5scope($row['receipt'],[],['A']),$row['receipt']['response_hash'],$in);
    foreach(['FULL','SOURCE_TABLE_V1'] as $format)foreach([0,1] as $attempt){
        $system=Dispatch::system($role,$format);$input=Dispatch::inputBody($f,$format);
        $user=$attempt?Dispatch::correctionBody($f,$input,$row['original'],$scope,r9diagnostics()):$input;
        foreach([
          'ordinary'=>'OK','explicit_legacy_flag_off'=>'OK','partial_cancel'=>'OK','db_count_string'=>'OK',
          'all_cancel'=>'ALL_CANCELLED','missing_state'=>'LIVE_STATE_UNAVAILABLE','null_count'=>'LIVE_STATE_UNAVAILABLE',
          'count_disagrees'=>'LIVE_STATE_UNAVAILABLE','duplicates'=>'LIVE_STATE_UNAVAILABLE','foreign_horse'=>'LIVE_STATE_UNAVAILABLE',
          'object_roster'=>'LIVE_STATE_UNAVAILABLE','invalid_json'=>'LIVE_STATE_UNAVAILABLE','missing_cancellations'=>'LIVE_STATE_UNAVAILABLE',
          'missing_official'=>'RACE_STATUS_UNAVAILABLE','started'=>'RACE_STARTED','db_error'=>'LIVE_STATE_UNAVAILABLE',
          'bad_hash'=>'SNAPSHOT_MISMATCH','bad_race'=>'SNAPSHOT_MISMATCH','bad_role'=>'SNAPSHOT_MISMATCH','wrong_release'=>'SNAPSHOT_MISMATCH',
          'no_snapshot'=>'NO_SNAPSHOT','no_id'=>'NO_SNAPSHOT','just_below'=>'DEADLINE_AFTER_WAIT','exact_five'=>'OK',
          'just_above'=>'OK','lookup_crosses'=>'DEADLINE_AFTER_WAIT','lookup_shortens'=>'OK','past_deadline'=>'DEADLINE_AFTER_WAIT',
          'caller_crosses'=>'DEADLINE_AFTER_WAIT','caller_shortens'=>'OK','clock_reversed'=>'DEADLINE_AFTER_WAIT','live_close_earlier'=>'DEADLINE_AFTER_WAIT'
        ] as $case=>$code){
            if($observe&&!in_array($case,['ordinary','all_cancel','missing_state','lookup_crosses','partial_cancel'],true))continue;
            $remaining=['just_below'=>4999999,'exact_five'=>5000000,'just_above'=>5000001,
                'lookup_crosses'=>6000000,'lookup_shortens'=>10000000,'past_deadline'=>6000000][$case]??30000000;
            TestIO::reset($p,$in,$remaining);$snapshotId=$case==='no_id'?null:($case==='no_snapshot'?2:1);
            $timeout=$case==='caller_crosses'?7:($case==='caller_shortens'?10:40);
            $sendRole=$case==='bad_role'?$other:$role;$all=$p['numbers'];
            if($case==='explicit_legacy_flag_off')TestIO::$compact='0';
            TestIO::$onHold=static function()use($case,$all,$in){
                switch($case){
                case 'partial_cancel':TestIO::withdraw([$all[0]]);break;
                case 'db_count_string':TestIO::$live->active_count=(string)count($all);break;
                case 'all_cancel':TestIO::withdraw($all);break;
                case 'missing_state':TestIO::$live=null;break;
                case 'null_count':TestIO::$live->active_count=null;break;
                case 'count_disagrees':--TestIO::$live->active_count;break;
                case 'duplicates':TestIO::$live->cancellations=dbJson([$all[0],$all[0]]);break;
                case 'foreign_horse':TestIO::$live->cancellations='[19]';break;
                case 'object_roster':TestIO::$snap->frozen_nums='{}';break;
                case 'invalid_json':TestIO::$live->cancellations='[';break;
                case 'missing_cancellations':unset(TestIO::$live->cancellations);break;
                case 'missing_official':TestIO::$official=null;break;
                case 'started':TestIO::$official->start_time='XXX';break;
                case 'db_error':TestIO::$failRead=true;break;
                case 'bad_hash':TestIO::$snap->snapshot_hash=str_repeat('f',64);break;
                case 'bad_race':TestIO::$snap->race_id='20261017-4-05-07-02';break;
                case 'wrong_release':TestIO::$snap->release_version='historical';break;
                case 'lookup_crosses':case 'lookup_shortens':case 'caller_crosses':case 'caller_shortens':TestIO::$lag=2200000;break;
                case 'past_deadline':TestIO::$lag=7000000;break;
                case 'clock_reversed':TestIO::$lag=-1;break;
                case 'live_close_earlier':
                    $cutoff=TestIO::$now+94999999;
                    TestIO::$live->live_close=Worker::toDb($cutoff);TestIO::$live->state_revision=1;
                    TestIO::$applied[]=['race_id'=>TestIO::$snap->race_id,'old_revision'=>0,'new_revision'=>1,
                        'diagnostics'=>dbJson(['live_close'=>['前'=>null,'後'=>$cutoff]])];break;
                }
            };
            TestIO::readyBinding($f,$format,$attempt,$system,$user);
            $result=$controller->callPredictionAi($sendRole,$system,$user,$timeout,$snapshotId);
            $label="$role $format #$attempt $case";$calls=TestIO::$calls;$kinds=array_column(TestIO::$audit,'kind');
            $match=$calls&&$calls[0][1]===hash('sha256',$system)&&$calls[0][2]===hash('sha256',$user);
            if($observe){echo "$label => ".$result['code'].' calls='.count($calls).' exact='.(int)$match.PHP_EOL;continue;}
            must156($result['code']===$code,"$label expected $code, got ".dbJson($result));
            if($code==='OK'){
                must156(TestIO::$locks===['baganriki_live_states','baganriki_snapshots','t_horse_odds_finder_races','baganriki_ai_attempts'],"$label authoritative read order");
                $expectedTimeout=min($timeout,(int)floor($remaining/1000000));
                if($case==='caller_shortens'||$case==='lookup_shortens')$expectedTimeout=7;
                must156(count($calls)===1&&$match&&$calls[0][0]===$role&&$calls[0][3]===$expectedTimeout
                    &&$result['timeout_sec']===$expectedTimeout&&$result['complete']&&$result['sent_at']===Time::toJst($calls[0][4]),"$label sent data/time");
                must156($kinds===['R122_BUDGET_HOLD']&&$result['budget_hold']===TestIO::$audit[0]['related_id'],"$label budget");
                $budgeted=json_decode(TestIO::$audit[0]['diagnostics'],true)['bytes'];must156($budgeted===strlen($system)+strlen($user),"$label budget bytes");++$sent;
            }else{
                must156(!$calls&&!isset($result['sent_at'])&&in_array($code,Controller::NOT_SENT_CODES,true),"$label provider/accounting");
                if($case==='no_id')must156($kinds===[],"$label premature hold");
                else must156($kinds===['R122_BUDGET_HOLD','R122_BUDGET_RELEASE']&&TestIO::$audit[0]['related_id']===TestIO::$audit[1]['related_id']
                    &&Controller::spentTodayUsd(TestIO::$now)===0.0,"$label hold release");
                ++$blocked;
            }
            ++$total;
        }
    }
    if(!$observe){
        TestIO::reset($p,$in,30000000);
        $old=$controller->callPredictionAi($role,'historical','{"race_id":"older-test"}',17);
        must156($old['code']==='OK'&&count(TestIO::$calls)===1&&$old['timeout_sec']===17,"historical $role");
    }
}
if(!$observe)echo "PASS $total sender scenarios ($sent accepted exactly once, $blocked stopped before transport), plus 2 legacy calls. Local provider transports only.\n";
}
