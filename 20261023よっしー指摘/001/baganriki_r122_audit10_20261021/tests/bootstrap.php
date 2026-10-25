<?php
declare(strict_types=1);
// Standalone test doubles only. Never load this bootstrap inside Laravel.
namespace App\Http\Controllers { class Controller {} }
namespace App\Services { class AnthropicService {
    public function sendWithRetry(...$args): \TestResponse { return \TestHttp::send('1st',$args); }
    public function extractText($response): string { return $response->json('content')[0]['text'] ?? ''; }
} }
namespace Illuminate\Contracts\Queue { interface ShouldQueue {} }
namespace Illuminate\Bus { trait Queueable {
    public $connection; public $queue; public $delay; public $afterCommit;
    public function onConnection($v): static { $this->connection=$v; return $this; }
    public function onQueue($v): static { $this->queue=$v; return $this; }
    public function delay($v): static { $this->delay=$v; return $this; }
    public function beforeCommit(): static { $this->afterCommit=false; return $this; }
} }
namespace Illuminate\Foundation\Bus { trait Dispatchable {} }
namespace Illuminate\Queue { trait InteractsWithQueue {} trait SerializesModels {} }
namespace {
    class TestCollection implements \IteratorAggregate, \JsonSerializable, \Countable {
        public function __construct(public array $rows) {}
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
        public function jsonSerialize(): mixed { return $this->rows; }
        public function count(): int { return count($this->rows); }
        public function keyBy(string $key): array { $out=[]; foreach($this->rows as $r) $out[$r->$key]=$r; return $out; }
    }
    class TestQuery {
        private array $w=[]; private array $order=[]; private bool $locked=false;
        public function __construct(private string $table) {}
        public function where(string $key, mixed $value, mixed $third=null): self { $this->w[]=[$key,func_num_args()===3 ? $value : '=',func_num_args()===3 ? $third : $value]; return $this; }
        public function whereIn(string $key, array $values): self { $this->w[]=[$key,'in',$values]; return $this; }
        public function orderBy(string $key): self { $this->order[]=[$key,1]; return $this; }
        public function orderByDesc(string $key): self { $this->order[]=[$key,-1]; return $this; }
        public function lockForUpdate(): self { $this->locked=true; DB::$lockEvents[]=$this->table; if(DB::$lockHook) { $f=DB::$lockHook; DB::$lockHook=null; $f(); } return $this; }
        private function matches(array $row): bool {
            foreach($this->w as [$k,$op,$v]) {
                $a=$row[$k] ?? null;
                if(!match($op) { '='=>$a==$v, '<='=>$a<=$v, '<'=>$a<$v, '>'=>$a>$v, 'in'=>in_array($a,$v,true), default=>throw new \LogicException('unsupported operator') }) return false;
            }
            return true;
        }
        public function get(array $columns=['*']): TestCollection {
            $out=[];
            $rows=DB::$level>0 && !$this->locked && isset(DB::$readSnapshots[$this->table])
                ? DB::$readSnapshots[$this->table] : (DB::$rows[$this->table] ?? []);
            foreach($rows as $row) {
                if(!$this->matches($row)) continue;
                $out[]=$row;
            }
            foreach(array_reverse($this->order) as [$key,$dir]) usort($out,fn($a,$b)=>$dir*(($a[$key]??null)<=>($b[$key]??null)));
            return new TestCollection(array_map(fn($r)=>(object)($columns===['*'] ? $r : array_intersect_key($r,array_flip($columns))),$out));
        }
        public function first(array $columns=['*']): ?object { return $this->get($columns)->rows[0] ?? null; }
        public function exists(): bool { return $this->first() !== null; }
        public function insert(array $row): bool {
            if(DB::$failWrites) throw new \RuntimeException('Simulated DB write failure');
            if(DB::$writeHook) (DB::$writeHook)($this->table,'insert',$row);
            if($this->table==='baganriki_ai_attempts' && !isset($row['id'])) $row['id']=++DB::$sequence;
            DB::$rows[$this->table][]=$row; return true;
        }
        public function updateOrInsert(array $key,array $value): bool { return $this->insert($key+$value); }
        public function insertOrIgnore(array $row): int {
            $keys=match($this->table) { 'baganriki_r122_leases'=>['lease_key'], 'baganriki_r122_role_states'=>['snapshot_id','role'], 'baganriki_live_states'=>['snapshot_id'], default=>['race_id'] };
            foreach(DB::$rows[$this->table] ?? [] as $r) { $same=true; foreach($keys as $key) if(($r[$key]??null)!==($row[$key]??null)) $same=false; if($same) return 0; }
            $this->insert($row); return 1;
        }
        public function update(array $values): int {
            if(DB::$failWrites) throw new \RuntimeException('Simulated DB write failure');
            if(DB::$writeHook) (DB::$writeHook)($this->table,'update',$values);
            $n=0; foreach(DB::$rows[$this->table] as &$r) if($this->matches($r)) { $r=array_replace($r,$values); ++$n; } return $n;
        }
        public function insertGetId(array $row): int { $row['id']=++DB::$sequence; $this->insert($row); return $row['id']; }
    }
    class TestPDO extends \PDO {
        public array $locks=[];
        public function __construct() {}
        public function prepare(string $query,array $options=[]): \PDOStatement|false { return new TestStatement($this,$query); }
    }
    class TestStatement extends \PDOStatement {
        private mixed $value=null;
        public function __construct(private TestPDO $pdo,private string $sql) {}
        public function execute(?array $params=null): bool {
            $key=$params[0];
            if(str_contains($this->sql,'GET_LOCK(')) { $this->value=empty($this->pdo->locks[$key]) ? 1 : 0; if($this->value) $this->pdo->locks[$key]=1; }
            elseif(str_contains($this->sql,'RELEASE_LOCK(')) { unset($this->pdo->locks[$key]); $this->value=1; }
            else $this->value=$this->pdo->locks[$key] ?? null;
            return true;
        }
        public function fetchColumn(int $column=0): mixed { return $this->value; }
    }
    class TestConnection {
        public TestPDO $pdo;
        public function __construct() { $this->pdo=new TestPDO(); }
        public function getPdo(): TestPDO { return $this->pdo; }
        public function getDatabaseName(): string { return 'unit-test'; }
        public function getName(): string { return 'mysql'; }
        public function getTablePrefix(): string { return ''; }
        public function transactionLevel(): int { return DB::$level; }
    }
    class DB {
        public static array $rows=[];
        public static bool $failWrites=false;
        public static $writeHook=null;
        public static int $sequence=100; public static int $level=0;
        public static $lockHook=null;
        // Explicit read-view simulation for repeatable-read regression tests only.
        public static array $readSnapshots=[]; public static array $lockEvents=[];
        public static ?TestConnection $connection=null;
        public static string $storageType='longtext';
        public static function table(string $name): TestQuery { return new TestQuery($name); }
        public static function connection(?string $name=null): TestConnection { return self::$connection ??= new TestConnection(); }
        public static function transaction(callable $f): mixed {
            $before=self::$rows; ++self::$level;
            try { return $f(); } catch(\Throwable $e) { self::$rows=$before; throw $e; } finally { --self::$level; }
        }
        public static function selectOne(string $sql,array $bindings=[]): object { return (object)['data_type'=>self::$storageType,'max_length'=>4294967295]; }
    }
    class Schema {
        public static function hasTable(string $name): bool { return array_key_exists($name,DB::$rows); }
        public static function hasColumn(string $table,string $column): bool { return true; }
    }
    class TestBus {
        public static array $sent=[];
        public static bool $throwAfterInsert=false;
        public static function dispatch($job): int {
            self::$sent[]=$job;
            $id=DB::table('baganriki_r122_jobs')->insertGetId(['queue'=>$job->queue,'reserved_at'=>null,'available_at'=>time()+($job->delay??0),'created_at'=>time()]);
            if(self::$throwAfterInsert) throw new \RuntimeException('Simulated dispatch failure after insert');
            return $id;
        }
    }
    class TestResponse {
        public function __construct(private array $body,private int $statusCode=200) {}
        public function failed(): bool { return $this->statusCode>=400; }
        public function status(): int { return $this->statusCode; }
        public function json(?string $key=null): mixed { return $key===null ? $this->body : ($this->body[$key]??null); }
        public function header(string $name): ?string { return null; }
    }
    class TestHttp {
        public static TestResponse $response;
        public static $handler=null; public static array $calls=[];
        public static function send(string $role,array $payload): TestResponse {
            self::$calls[]=['role'=>$role,'payload'=>$payload];
            return self::$handler ? (self::$handler)($role,$payload) : self::$response;
        }
        public static function timeout(int $seconds): self { return new self(); }
        public function withHeaders(array $headers): self { return $this; }
        public function post(string $url,array $body): TestResponse { return self::send('2nd',$body); }
    }
    class Log {
        public static array $errors=[];
        public static function error(string $s,array $context=[]): void { self::$errors[]=$s; }
        public static function info(string $s,array $context=[]): void {}
    }
    $testConfig=[]; $testEnv=[];
    function config(string $key,mixed $default=null): mixed { global $testConfig; return $testConfig[$key] ?? $default; }
    function env(string $key,mixed $default=null): mixed { global $testEnv; return $testEnv[$key] ?? $default; }
    class_alias(DB::class,'Illuminate\Support\Facades\DB');
    class_alias(Schema::class,'Illuminate\Support\Facades\Schema');
    class_alias(TestBus::class,'Illuminate\Support\Facades\Bus');
    class_alias(TestHttp::class,'Illuminate\Support\Facades\Http');
    $testRoot=getenv('BAGANRIKI_TEST_ROOT') ?: dirname(__DIR__);
    foreach(['Decimal','ExactJson','JobPolicy','ExactStorage','R122Jobs'] as $name) {
        $file=$testRoot.'/app/Support/Baganriki/'.$name.'.php'; if(is_file($file)) require $file;
    }
    foreach(['R122Job','FreezeRaceJob','RunRoleJob','AggregateRaceJob','UpdateRaceLiveJob'] as $name) require $testRoot.'/app/Jobs/Baganriki/'.$name.'.php';
    require $testRoot.'/app/Http/Controllers/Api/AiController.php';
}
