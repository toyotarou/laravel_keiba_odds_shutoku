<?php
declare(strict_types=1);
namespace App\Http\Controllers { class Controller {} }
namespace App\Services { final class AnthropicService {} }
namespace {
    use App\Http\Controllers\Api\AiController;
    use App\Http\Controllers\Api\BaganrikiTime as Clock;
    use App\Http\Controllers\Api\BaganrikiWorker as Worker;
    use App\Http\Controllers\Api\BaganrikiPrompt as Codec;
    use App\Services\Baganriki\AttemptWindowW2 as Window;
    use App\Services\Baganriki\CandidateSelectionW2 as Selection;
    use App\Services\Baganriki\ExpectedBridgeW2 as Bridge;
    use App\Services\Baganriki\FrozenInputW2 as Frozen;

    function env($key,$default=null) { return $default; }
    class Schema { public static function hasTable($unused) { return true; } }
    class DB {
        public static ?string $clock='09:40';
        public static string $date='2099-11-04';
        private string $name;
        public static function table($name) { $q=new self();$q->name=$name;return $q; }
        public function where($field,$value) { return $this; }
        public function lockForUpdate() { return $this; }
        public function first($cols=['*']) {
            if ($this->name==='t_horse_odds_finder_races' && self::$clock!==null)
                return (object)['date'=>self::$date,'start_time'=>self::$clock];
            return null;
        }
        public function get($cols=['*']) { return []; }
    }
    require __DIR__.'/r7_storage_probe.php';
    function check155(bool $ok,string $why):void {
        if (!$ok) throw new \RuntimeException($why);
    }
    function code155(callable $f):string {
        try { $f();return 'OK'; }
        catch (\InvalidArgumentException $e) { return $e->getMessage(); }
    }

    // Use an independent integer seconds oracle for every clock hour and minute.
    $snap=(object)['race_id'=>'20991104-4-05-07-01','sales_close_at'=>'2099-11-04 23:59:00.000000',
                   'release_version'=>Selection::VERSION];
    $snap->common_json=json_encode(['race_id'=>$snap->race_id,'prompt_version'=>Selection::VERSION,
        'time_contract'=>['sales_close_at'=>'2099-11-04T23:59:00.000000+09:00']],JSON_THROW_ON_ERROR);
    $snap->snapshot_hash=hash('sha256',$snap->common_json);
    $midnight=(new \DateTimeImmutable('2099-11-04T00:00:00+09:00'))->getTimestamp();
    $checks=0;
    for ($hour=0;$hour<24;$hour++) for ($minute=0;$minute<60;$minute++) {
        foreach ([null,0,17,59] as $second) {
            $expected=($midnight+3600*$hour+60*$minute+($second??0))*1000000;
            $padded=sprintf('%02d:%02d',$hour,$minute);$short=sprintf('%d:%02d',$hour,$minute);
            foreach (array_unique([$short,$padded]) as $hm) {
                $raw=$hm.($second===null?'':sprintf(':%02d',$second));DB::$clock=$raw;
                $canonical=Clock::normalizeRaceStartClock($raw);
                check155(Clock::us(DB::$date.'T'.$canonical.'+09:00')===$expected,'clock '.$raw);
                $d=Worker::deadlines($snap,null,true,true);
                $sales=min(($midnight+86340)*1000000,$expected-60000000);
                check155($d['ok'] && $d['race_started']===false && $d['publish']===$sales-60000000
                    && $d['reply']===$sales-90000000,'deadline '.$raw);$checks++;
            }
        }
    }
    $ctrl=new AiController(new \App\Services\AnthropicService());
    foreach (['9:40','09:40','9:40:18','09:40:18'] as $raw) {
        DB::$clock=$raw;
        check155(AiController::gatherPredictionContext(DB::$date,4,'05',7,1)['code']==='ENTRY_MISSING','valid time reaches roster');
        foreach ([[],['start_time'=>$raw]] as $options)
            check155($ctrl->freezePredictionInput(DB::$date,4,'05',7,1,$options)['code']==='TOO_EARLY','future production freeze cutoff');
    }
    foreach (['9:7','09:60','09:55:60','24:00','XXX','','09:00x',null] as $raw) {
        DB::$clock=$raw;$d=Worker::deadlines($snap,null,true,true);
        $code=$raw==='XXX'?'RACE_STARTED':($raw===null?'RACE_STATUS_UNAVAILABLE':'START_TIME_UNREADABLE');
        check155(!$d['ok'] && $d['code']===$code,'invalid/live input '.var_export($raw,true));
    }
    DB::$clock='XXX';
    $cleanup=Worker::deadlines($snap,null,false);
    check155($cleanup['ok'] && $cleanup['race_started']===true && is_int($cleanup['reply']),'historical timer remains usable');
    echo "PASS $checks independently calculated race clock and deadline combinations; production context/freeze, official started/missing states and historical timing.\n";

    function publication155(string $dir):void {
        $data=preparedRows($dir);
        $roleRows=$data['roles'];$snapshots=[];
        foreach ($roleRows as $row) {
            $role=$row['role'];
            $snapshots[$role]=Frozen::fromStored($data['common'],$row['input'],$data['hash'],$role,$data['witness']);
        }
        $f=$snapshots['1st'];$live=r4live($f,$data['now']);$live['role_journal']=$data['role_journal'];
        $p=$data+['send'=>false,'cancelled'=>[],'live'=>$live];
        $selection=replayRows($p,'open')['result'];$picks=$selection['public_candidates'];$ids=$data['numbers'];
        check155($selection['selection_state']==='CANDIDATES_READY' && count($picks)>0,'baseline');
        $input=Frozen::checked($f);$close=$input['time_contract']['sales_close_at'];$deadline=$data['publish_deadline'];
        $finished=$live['now'];$total=0;
        foreach ([false,true,null] as $start) foreach ([-1,0,1] as $us) foreach ([false,true] as $earlier)
        foreach ([[],[$picks[0]],$ids] as $withdrawn) foreach ([false,true] as $bump) {
            $s=$live;$s['race_started']=$start;$s['now']=r4time($deadline,($us>=0?'+':'').$us.' microseconds');
            $s['live_close_at']=$earlier?r4time($close,'-10 seconds'):null;$s['cancelled_horse_nos']=$withdrawn;
            $s['all_cancelled']=count($withdrawn)===count($ids);$s['state_revision']=(int)$bump;
            $actual=code155(fn()=>Window::assertPublicationCommit($f,$selection,$finished,$s));
            $expected=$start===null?'RACE_STATUS_UNAVAILABLE':($start?'RACE_STARTED':
                (($earlier || $withdrawn || $bump)?'W8_PUBLICATION_CHANGED':($us>0?'W8_COMMIT_WINDOW':'OK')));
            check155($actual===$expected,'stale commit '.json_encode([$start,$us,$earlier,$withdrawn,$bump,$actual,$expected]));
            ++$total;
        }
        foreach ($ids as $withdrawn) {
            $s=$live;$s['cancelled_horse_nos']=[$withdrawn];
            check155(code155(fn()=>Window::assertPublicationCommit($f,$selection,$finished,$s))==='W8_PUBLICATION_CHANGED','withdrawn '.(string)$withdrawn);++$total;
        }
        foreach ($ids as $withdrawn) {
            $q=$p;$q['live']['cancelled_horse_nos']=$q['cancelled']=[$withdrawn];$q['live']['state_revision']++;
            $fresh=replayRows($q,'open')['result'];
            check155(!in_array($withdrawn,$fresh['public_candidates'],true) && count($fresh['public_candidates'])===count(array_unique($fresh['public_candidates'])),'fresh candidates');
            Window::assertPublicationCommit($f,$fresh,$finished,$q['live']);++$total;
        }
        $q=$p;$q['cancelled']=$q['live']['cancelled_horse_nos']=$ids;$q['live']['all_cancelled']=true;
        $empty=replayRows($q,'open')['result'];
        check155($empty['selection_state']==='CANCELLED' && !$empty['public_candidates'] &&
            code155(fn()=>Window::assertPublicationCommit($f,$empty,$finished,$q['live']))==='W8_NOT_PUBLISHABLE','all withdrawn');++$total;
        foreach (['1st','2nd'] as $role) {
            $roleInput=$snapshots[$role];
            $compact=static function ($t) {return Codec::compactWire($t)['wire'];};
            $expand=static function ($t) {return Codec::expandWire($t)['input'];};
            foreach (['started','missing','cancelled','earlier','valid'] as $case) {
                $end=$live;$expected='OK';
                if ($case==='started') {$end['race_started']='1';$expected='RACE_STARTED';}
                if ($case==='missing') {unset($end['race_started']);$expected='RACE_STATUS_UNAVAILABLE';}
                if ($case==='cancelled') {$end['all_cancelled']=true;$expected='W4_SEND_WINDOW';}
                if ($case==='earlier') {$end['live_close_at']=r4time($close,'-5 seconds');$expected='W4_SEND_WINDOW';}
                $reads=0;$get=function()use(&$reads,$live,$end) {return ++$reads===1?$live:$end;};
                $actual=code155(fn()=>Bridge::prepareSend($roleInput,$compact,$expand,static fn()=>true,$get));
                check155($actual===$expected && $reads===2,'send '.$case.' '.$actual);++$total;
            }
        }
        foreach ([false,0,'0',true,1,'1',null,'yes','00',-1] as $flag) {
            $s=$live;$s['race_started']=$flag;
            $expected=in_array($flag,[false,0,'0'],true)?'OK':(in_array($flag,[true,1,'1'],true)?'RACE_STARTED':'RACE_STATUS_UNAVAILABLE');
            check155(code155(fn()=>Window::assertPublicationCommit($f,$selection,$finished,$s))===$expected,'flag check');++$total;
        }
        $late=$p;$late['live']['race_started']=true;
        check155(code155(fn()=>replayRows($late,'open'))==='RACE_STARTED','post-start merge stopped');++$total;
        $raw=dbJson($selection);$hash=hash('sha256',$raw);$intent=hash('sha256','completed-race-intent');
        $record=['race_id'=>$data['race_id'],'snapshot_hash'=>$data['hash'],'intent_hash'=>$intent,'publication_id'=>'31',
                 'selection_json'=>$raw,'payload_sha256'=>$hash,'publication_sha256'=>Window::publicationSeal($f,$intent,'31',$hash)];
        check155(Window::replayId($f,$intent,$record)==='31','existing verified id returned');++$total;
        echo "PASS $total publication, cancellation, dispatch and replay checks for {$data['race_id']} (".count($ids)." horses).\n";
    }
    foreach (array_slice($argv,2) as $dir) publication155($dir);
}
