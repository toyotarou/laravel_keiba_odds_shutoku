<?php
declare(strict_types=1);
namespace App\Jobs { class PredictionAggregateRaceJob {} }
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
    $GLOBALS['worker_test_env']=['BAGANRIKI_R122_PROMPT'=>'on','BAGANRIKI_R122_QUEUE'=>'on'];
    require __DIR__.'/r154_worker.php';
    $request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $GLOBALS['publication_clock']=$request['clock'];

    $GLOBALS['worker_test_env']['BAGANRIKI_R122_JOBS']=$request['inline']?'0':'1';
    $GLOBALS['worker_test_env']['BAGANRIKI_R122_TARGETS']='20261017-4-05-07-11';
    $ctrl=new class(new \App\Services\AnthropicService()) extends AiController {
        public int $networkAttempts=0;
        public function callPredictionAi(string $role,string $system,string $user,int $timeout,?int $snapshotId=null):array {
            $this->networkAttempts++;
            throw new \RuntimeException('outside provider call unexpectedly reached');
        }
    };
    $out=$ctrl->afterAiResponseReceived('2026-10-17',4,'05','07',11,$request['role'],
            ['skip'=>false,'ok'=>true,'snapshot_id'=>1]);
    echo json_encode(['result'=>$out,'network_attempts'=>$ctrl->networkAttempts],JSON_THROW_ON_ERROR);
}
