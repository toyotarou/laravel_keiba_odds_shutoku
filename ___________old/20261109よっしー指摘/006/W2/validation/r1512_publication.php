<?php
declare(strict_types=1);
namespace App\Services { class AnthropicService {} }
namespace App\Http\Controllers\Api {
    function gettimeofday(){
        $clock=$GLOBALS['publication_clock'];
        return ['sec'=>intdiv($clock,1000000),'usec'=>$clock%1000000];
    }
}
namespace {
    use App\Http\Controllers\Api\AiController;
    use App\Http\Controllers\Api\BaganrikiWorker as Worker;

    class Schema {
        static function hasTable($name){
            $q=DB::$p->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
            $q->execute([$name]);return (bool)$q->fetchColumn();
        }
    }
    class Log {
        static function info(...$args){}
        static function error(...$args){fwrite(STDERR,json_encode($args,JSON_UNESCAPED_UNICODE)."\n");}
    }
    function app(){return new class {function runningInConsole(){return true;}};}
    $GLOBALS['worker_test_env']=['BAGANRIKI_R122_PROMPT'=>'on'];
    require ($argv[4]??__DIR__).'/r154_worker.php';
    $request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $GLOBALS['publication_clock']=$request['clock'];
    $options=($request['server_clock']??false) ? [] : ['now_us'=>$request['clock']];
    if (array_key_exists('read_revisions',$request)) $options['read_revisions']=$request['read_revisions'];
    if (isset($request['arrival'])) $options['test_before_commit']=static function()use($request){
        $pending=$request['arrival'];
        $received=Worker::receive($pending['token'],$pending['raw'],true,$request['clock']);
        if (!$received['ok']) throw new RuntimeException('fixture pending receipt '.$received['code']);
    };
    function waitForPublicationTest(string $dir):void {
        touch($dir.'/ready-'.getmypid());$until=microtime(true)+45;
        while (!file_exists($dir.'/continue')) {
            if (microtime(true)>$until) throw new RuntimeException('publication rendezvous timed out');
            usleep(3000);
        }
    }
    if (isset($request['rendezvous'])) $options['test_before_commit']=static function()use($request){
        waitForPublicationTest($request['rendezvous']);
    };
    $ctrl=new AiController(new \App\Services\AnthropicService());
    if (isset($request['official_steps'])) {
        $steps=$request['official_steps'];
        $options['test_before_commit']=static function()use(&$steps){
            if (!$steps) return;
            $statement=DB::$p->prepare('UPDATE t_horse_odds_finder_races SET start_time=?');
            $statement->execute([array_shift($steps)]);
        };
        $ctrl=new class(new \App\Services\AnthropicService()) extends AiController {
            public array $roundClocks;
            public int $rounds=0;
            public function publishPrediction(int $snapshotId,array $opt=[]):array {
                $clock=$this->roundClocks[$this->rounds++]??null;
                if ($clock!==null) $GLOBALS['publication_clock']=$clock;
                return parent::publishPrediction($snapshotId,$opt);
            }
        };
        $ctrl->roundClocks=$request['round_clocks']??[];
    }
    // Test only: the official source changes and reverts during one real role inspection.
    if (isset($request['transient_role'])) {
        $ctrl=new class(new \App\Services\AnthropicService()) extends AiController {
            public string $shiftRole;
            public function acceptedHorsesOf(int $snapshotId,string $role):array {
                if ($role!==$this->shiftRole) return parent::acceptedHorsesOf($snapshotId,$role);
                DB::$p->exec("UPDATE t_horse_odds_finder_races SET start_time='14:58'");
                try { return parent::acceptedHorsesOf($snapshotId,$role); }
                finally { DB::$p->exec("UPDATE t_horse_odds_finder_races SET start_time='15:00'"); }
            }
        };
        $ctrl->shiftRole=$request['transient_role'];
    }
    if (isset($request['handoff_barrier'])) {
        $ctrl=new class(new \App\Services\AnthropicService()) extends AiController {
            public string $directory;
            public int $resumeClock;
            private bool $paused=false;
            public function publishPrediction(int $snapshotId,array $opt=[]):array {
                if (!$this->paused) {
                    $this->paused=true;
                    waitForPublicationTest($this->directory);
                    $GLOBALS['publication_clock']=$this->resumeClock;
                }
                return parent::publishPrediction($snapshotId,$opt);
            }
        };
        $ctrl->directory=$request['handoff_barrier'];
        $ctrl->resumeClock=$request['resume_clock'];
    }
    if ($request['cmd']==='inspect_correction') {
        $role=$request['role'];
        $accepted=$ctrl->acceptedHorsesOf(1,$role);
        $extra=[];
        if ($request['with_grant']??false) {
            $first=$ctrl->inspectAiResponse(1,$role,0);
            $scope=$first['repair_scope']; $diag=$first['diag'];
            $extra['scope']=array_intersect_key($scope,array_flip(['horses','rank_bands','summary_horses','presentation_horses']));
            $extra['scope']['first_response_hash']=$first['response_hash'];
            $extra['diagnostics']=['errors'=>$diag['findings'],'missing_horses'=>$diag['missing'],'duplicated_horses'=>$diag['duplicated'],
                'unknown_horses'=>$diag['unknown'],'isolated_horses'=>$diag['isolated']];
        }
        echo json_encode($extra+['ok'=>$accepted['ok'],'code'=>$accepted['code'],'complete'=>$accepted['complete']??null,
            'applied'=>$accepted['applied'],'rescued'=>$accepted['rescued'],'skipped'=>$accepted['skipped'],
            'merged_nos'=>array_keys($accepted['merged']),'reason'=>$accepted['reason']],JSON_THROW_ON_ERROR);
        return;
    }
    if ($request['cmd']==='settle') {
        $accepted=$ctrl->acceptedHorsesOf(1,$request['role']);
        $published=$ctrl::markRoleSettled(1,$request['role'],['code'=>$accepted['code']],$request['clock']);
    } elseif ($request['cmd']==='when_ready') {
        $published=$ctrl->publishWhenReady(1,$options);
    } else {
        $published=$ctrl->publishPrediction(1,$options);
    }
    if (isset($request['official_steps'])) $published['test_rounds']=$ctrl->rounds;
    echo json_encode($published,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
