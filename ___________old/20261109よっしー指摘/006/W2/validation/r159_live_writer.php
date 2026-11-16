<?php
declare(strict_types=1);
namespace App\Services { class AnthropicService {} }
namespace App\Http\Controllers\Api {
function gettimeofday(){return isset($GLOBALS['writer_clock'])?['sec'=>intdiv($GLOBALS['writer_clock'],1000000),'usec'=>$GLOBALS['writer_clock']%1000000]:\gettimeofday();}
}
namespace App\Jobs {
class PredictionUpdateRaceLiveJob {
    static function dispatch($id,$change){
        \DB::table('queued_changes')->insert(['snapshot_id'=>$id,'change_json'=>json_encode($change,JSON_THROW_ON_ERROR)]);
    }
}
}
namespace {
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\BaganrikiWorker as Worker;
class Schema { static function hasTable($n){return true;} }
class Log {
    static function info(...$args) {}
    static function error(...$args) {file_put_contents('php://stderr',json_encode($args,JSON_UNESCAPED_UNICODE)."\n");}
}
function app($class=null) {if($class===null)return new class {function runningInConsole(){return true;}}; return new $class(new \App\Services\AnthropicService());}
$GLOBALS['worker_test_env']=['BAGANRIKI_R122_PROMPT'=>'on'];
require __DIR__.'/r154_worker.php';
$a=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!empty($a['jobs'])) { $GLOBALS['worker_test_env']+=['BAGANRIKI_R122_QUEUE'=>'on','BAGANRIKI_R122_JOBS'=>'on']; }
if(isset($a['start']))while(microtime(true)<$a['start'])usleep(500);
$snap=DB::table('baganriki_snapshots')->where('id',1)->first();
$live=DB::table('baganriki_live_states')->where('snapshot_id',1)->first();
switch($a['cmd']){
case 'update': $out=app(AiController::class)->applyLiveChange(1,$a['change']);break;
case 'odds':
    $race=DB::table('t_horse_odds_finder_races')->first();
    $out=AiController::syncLiveFromAcquisition($race,$a['raw']);break;
case 'roster': $out=Worker::sendRosterIssue($snap,$live)??['code'=>'OPEN'];break;
case 'publish':
    $GLOBALS['writer_clock']=$a['clock'];
    $ctl=new class(new \App\Services\AnthropicService()) extends AiController {
        public function acceptedHorsesOf(int $snapshotId,string $role):array {
            return ['ok'=>true,'complete'=>true,'merged'=>array_column($GLOBALS['writer_sample'][$role]['horses'],null,'horse_no')];
        }
        public function markResponseUsed(int $snapshotId,string $role,array $accepted):array {
            $v=$GLOBALS['writer_sample'][$role];
            return ['band_ranks'=>$v['band_ranks'],'public_candidates'=>$v['public_candidates'],'proof'=>null];
        }
    };
    foreach(['1st','2nd'] as $role)$GLOBALS['writer_sample'][$role]=json_decode(file_get_contents(dirname($argv[1]).'/'.$role.'_output.json'),true,512,JSON_THROW_ON_ERROR);
    $options=['now_us'=>$a['clock']];
    if(isset($a['overlay']))$options['test_before_commit']=static function()use($a){
        DB::table('baganriki_live_states')->where('snapshot_id',1)->update($a['overlay']);
    };
    $out=$ctl->publishPrediction(1,$options);break;
case 'view': $out=\App\Http\Controllers\Api\BaganrikiPublicView::build(['race_id'=>$snap->race_id,'publication'=>$a['publication'],'buy_recommended'=>true]);break;
case 'deadline': $out=Worker::deadlines($snap,$live,false,true);break;
default: throw new \LogicException('unknown test operation');
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
}
