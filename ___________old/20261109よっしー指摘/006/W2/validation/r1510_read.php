<?php
declare(strict_types=1);
namespace App\Http\Controllers { class Controller {} }
namespace App\Services { class AnthropicService { function __call($name,$args){throw new \LogicException('external provider from GET');} } }
namespace App\Http\Controllers\Api {
    function gettimeofday(){return ['sec'=>intdiv($GLOBALS['clock'],1000000),'usec'=>$GLOBALS['clock']%1000000];}
    function date($format,$time=null){return \date($format,$time??intdiv($GLOBALS['clock'],1000000));}
}
namespace {
use App\Http\Controllers\Api\AiController;
class DB {
    public static PDO $db;
    public static array $reads=[];
    public static function table($name){return new Reader($name);}
    public static function transaction($fn){
        self::$db->beginTransaction();
        try {$out=$fn();self::$db->commit();return $out;}
        catch(\Throwable $e){if(self::$db->inTransaction())self::$db->rollBack();throw $e;}
    }
}
class Reader {
    private array $where=[],$params=[],$order=[];
    public function __construct(private string $name){}
    function where($k,$v){$this->where[]=$k.'=?';$this->params[]=$v;return $this;}
    function orderBy($k){$this->order[]=$k.' ASC';return $this;}
    function orderByDesc($k){$this->order[]=$k.' DESC';return $this;}
    private function take($cols,$single){
        $db=DB::$db;$name=$this->name;
        $sql='SELECT '.implode(',',$cols).' FROM '.$name.($this->where?' WHERE '.implode(' AND ',$this->where):'')
             .($this->order?' ORDER BY '.implode(',',$this->order):'').($single?' LIMIT 1':'');
        $q=$db->prepare($sql);$q->execute($this->params);
        DB::$reads[]=[$name,$db->inTransaction()];
        $result=$single?($q->fetch(PDO::FETCH_OBJ)?:null):$q->fetchAll(PDO::FETCH_OBJ);
        if($name==='baganriki_live_states' && !empty($GLOBALS['barrier'])){
            $dir=$GLOBALS['barrier'];$GLOBALS['barrier']=null;touch($dir.'/read');$deadline=microtime(true)+12;
            while(!file_exists($dir.'/resume')){if(microtime(true)>$deadline)throw new RuntimeException('WAL rendezvous timeout');usleep(1000);}
        }
        return $result;
    }
    function first($cols=['*']){return $this->take($cols,true);}
    function get($cols=['*']){return $this->take($cols,false);}
    function exists(){return $this->first(['1'])!==null;}
}
class Schema {
    static function hasTable($name){
        $q=DB::$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$name]);return (bool)$q->fetchColumn();
    }
}
function app(){return new class{function runningInConsole(){return false;}};}
function request(){return new class{
    function header($name){return $GLOBALS['case']['header']??null;}
    function query($name){return $GLOBALS['case']['query']??null;}
};}
function env($k,$d=null){return ['BAGANRIKI_R122_READ'=>$GLOBALS['case']['mode']??'open','BAGANRIKI_R122_READ_KEY'=>'local-test-only'][$k]??$d;}
function response(){return new class{function json($obj,$status=200,$headers=[]){return ['status'=>$status,'body'=>$obj,'headers'=>$headers];}};}
require $argv[2];require __DIR__.'/../php/ExpectedBridgeW2.php';
DB::$db=new PDO('sqlite:'.$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
DB::$db->exec('PRAGMA query_only=ON');DB::$db->exec('PRAGMA busy_timeout=10000');
$a=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$GLOBALS['case']=$a;$GLOBALS['clock']=$a['clock'];$GLOBALS['barrier']=$a['barrier']??null;
set_error_handler(static function($severity,$msg){if(error_reporting() & $severity)throw new \ErrorException($msg,0,$severity);return false;});
try{$out=(new AiController(new \App\Services\AnthropicService()))->getBaganrikiPublishedPrediction($a['race']);}
catch(\Throwable $e){$out=['status'=>500,'error'=>get_class($e).': '.$e->getMessage()];}
$out['read_tables']=DB::$reads;
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
