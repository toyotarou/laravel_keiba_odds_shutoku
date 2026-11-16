<?php
declare(strict_types=1);
namespace App\Http\Controllers { class Controller {} }
namespace {
    use App\Http\Controllers\Api\BaganrikiWorker as Worker;
    function env($key,$default=null) { return $GLOBALS['worker_test_env'][$key]??$default; }
    require $argv[3];
    require __DIR__.'/../php/ExpectedBridgeW2.php';

    class DB {
        public static PDO $p;
        public static bool $inWrite = false;
        public static function table($n) { return new Table($n); }
        public static function transaction($f) {
            self::$p->exec('BEGIN IMMEDIATE');
            self::$inWrite = true;
            try { $r=$f(); self::$p->exec('COMMIT'); return $r; }
            catch (Throwable $e) { self::$p->exec('ROLLBACK'); throw $e; }
            finally { self::$inWrite = false; }
        }
    }
    class Table {
        private array $conditions=[], $args=[], $order=[];
        private string $name;
        private bool $hasIn = false;
        private ?int $rowLimit = null;
        function __construct($table) { $this->name=$table; }
        function where($k,$v) { $this->conditions[]=$k.'=?'; $this->args[]=$v; return $this; }
        function whereNull($key) { $this->conditions[]=$key.' IS NULL'; return $this; }
        function whereIn($field, array $values) {
            $this->hasIn = true;
            $this->conditions[] = $values ? $field.' IN ('.implode(',',array_fill(0,count($values),'?')).')' : '0=1';
            array_push($this->args,...array_values($values));
            return $this;
        }
        function orderBy($k) { $this->order[]=$k; return $this; }
        function orderByDesc($field) { $this->order[]=$field.' DESC'; return $this; }
        function limit(int $count) { $this->rowLimit=$count; return $this; }
        function lockForUpdate() { return $this; } // BEGIN IMMEDIATE holds the writer lock
        function tail() {
            return ($this->conditions?' WHERE '.implode(' AND ',$this->conditions):'').
                   ($this->order?' ORDER BY '.implode(',',$this->order):'');
        }
        function stmt($sql,$args) { $q=DB::$p->prepare($sql); $q->execute($args); return $q; }
        function first($cols=['*']) {
            return $this->stmt('SELECT '.implode(',',$cols).' FROM '.$this->name.$this->tail().' LIMIT 1',$this->args)
                        ->fetch(PDO::FETCH_OBJ) ?: null;
        }
        function get() {
            $limit=$this->rowLimit===null?'':' LIMIT '.$this->rowLimit;
            $rows=$this->stmt('SELECT * FROM '.$this->name.$this->tail().$limit,$this->args)->fetchAll(PDO::FETCH_OBJ);
            global $gate;
            if ($gate && $this->name==='baganriki_ai_attempts') {
                $dir=$gate; $gate=null; touch($dir.'/read-'.getmypid());
                $end=microtime(true)+7;
                while(count(glob($dir.'/read-*'))<2) {
                    if(microtime(true)>$end) { throw new RuntimeException('barrier timeout'); }
                    usleep(500);
                }
            }
            // The submitted correction resolver reads both attempt slots as a keyed Laravel collection.
            if ($this->hasIn) return new class($rows) extends ArrayObject {
                function keyBy($field) {
                    return array_column($this->getArrayCopy(),null,$field);
                }
            };
            return $rows;
        }
        function exists() { return $this->first(['1'])!==null; }
        function insert($row) {
            $this->stmt('INSERT INTO '.$this->name.' ('.implode(',',array_keys($row)).') VALUES ('.
                        implode(',',array_fill(0,count($row),'?')).')',array_values($row));
        }
        function insertGetId($row) {
            $this->insert($row);
            return (int) DB::$p->lastInsertId();
        }
        function insertOrIgnore($row) {
            $this->stmt('INSERT OR IGNORE INTO '.$this->name.' ('.implode(',',array_keys($row)).') VALUES ('.
                        implode(',',array_fill(0,count($row),'?')).')',array_values($row));
        }
        function update($row) {
            $set=implode(',',array_map(static fn($k)=>$k.'=?',array_keys($row)));
            return $this->stmt('UPDATE '.$this->name.' SET '.$set.$this->tail(),
                               array_merge(array_values($row),$this->args))->rowCount();
        }
    }
    DB::$p=new PDO('sqlite:'.$argv[1],null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    DB::$p->exec('PRAGMA busy_timeout=10000');
    if (realpath($_SERVER['SCRIPT_FILENAME'])!==__FILE__) return;
    $now=(new DateTimeImmutable('2026-11-04T15:50:00+09:00'))->getTimestamp()*1000000;
    $arg=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    $gate=$arg['gate']??null;
    $now+=($arg['offset']??0);
    if(isset($arg['start'])) { while(microtime(true)<$arg['start']) { usleep(500); } }
    $r=match ($arg['cmd']) {
        'reserve'=>Worker::reserve($arg['race'],$arg['role'],$arg['slot'],$now),
        'send'=>Worker::allowSend($arg['token'],$now+10),
        'receive'=>Worker::receive($arg['token'],$arg['raw'],$arg['complete'],$now+20),
        'fix'=>Worker::fixAllowed($arg['race'],$arg['role']),
        'fail'=>Worker::fail($arg['token'],'test failure',$now+30,$arg['unknown']),
        'resume'=>Worker::resume($arg['race'],$now+40),
    };
    if ($arg['cmd']==='send' && $r['ok']) {
        DB::table('provider_stub')->insert(['token'=>$arg['token']]);
    }
    echo json_encode($r,JSON_THROW_ON_ERROR);
}
