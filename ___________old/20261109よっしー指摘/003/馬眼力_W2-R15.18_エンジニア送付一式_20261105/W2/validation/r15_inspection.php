<?php
declare(strict_types=1);

namespace {
    /** Read-only in-memory stand-in for the two saved attempt rows, snapshot and live state. */
    final class R15Table implements \IteratorAggregate {
        public static array $tables=[];
        private array $pred=[];
        public function __construct(private string $table) {}
        public static function table(string $name):self {return new self($name);}
        public function where(string $key,mixed $value):self {$this->pred[$key]=[$value];return $this;}
        public function whereIn(string $key,array $values):self {$this->pred[$key]=$values;return $this;}
        private bool $descending=false;
        public function exists():bool {return $this->first()!==null;}
        public function orderByDesc(string $key):self {if ($key!=='id') throw new \LogicException($key);$this->descending=true;return $this;}
        public function value(string $key):mixed {return $this->first()->$key??null;}
        public function join(string $table,string $left,string $operator,string $right):self {
            if ([$this->table,$table,$left,$operator,$right]!==['baganriki_ai_attempts as a','baganriki_snapshots as s','s.id','=','a.snapshot_id']) throw new \LogicException('unexpected audit join');
            return $this;
        }
        public function insert(array $values):bool {
            $values['id']=count(self::$tables[$this->table]??[])+1;self::$tables[$this->table][]=(object)$values;return true;
        }
        private function rows():array {
            $data=self::$tables[$this->table]??[];
            if ($this->table==='baganriki_ai_attempts as a') foreach (self::$tables['baganriki_ai_attempts'] as $attempt)
                foreach (self::$tables['baganriki_snapshots'] as $snapshot) if ($attempt->snapshot_id===$snapshot->id)
                    $data[]=(object)['a.id'=>$attempt->id,'s.release_version'=>$snapshot->release_version];
            if ($this->descending) usort($data,static fn($a,$b)=>$b->id<=>$a->id);
            return array_filter($data,function($row){
                foreach ($this->pred as $k=>$values) if (!in_array($row->$k,$values,true)) return false;
                return true;
            });
        }
        public function first():?object {$rows=$this->rows();return array_shift($rows);}
        public function getIterator():\Traversable {yield from $this->rows();}
        public function get():self {return $this;}
        public function keyBy(string $key):array {$out=[];foreach($this->rows() as $row) $out[$row->$key]=$row;return $out;}
    }
    final class R151Schema {public static bool $record=false;public static function hasTable(string $name):bool {return (self::$record && $name==='baganriki_validation_results') || ($name==='baganriki_audit_events' && array_key_exists($name,\R15Table::$tables));}}
    class_alias(R151Schema::class,'Schema');
    class_alias(R15Table::class,'DB');
    function r3bytes(array $a):string {return json_encode($a,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);}
    function r3frame(array $a):string {return \App\Http\Controllers\Api\BaganrikiResponse::BEGIN."\n".r3bytes($a)."\n".\App\Http\Controllers\Api\BaganrikiResponse::END;}
    require __DIR__.'/r4_fixtures.php';
}

namespace App\Http\Controllers\Api {
    use App\Services\Baganriki\CandidateSelectionW2 as Sel;
    use App\Services\Baganriki\FrozenInputW2 as Frozen;
    use App\Services\Baganriki\AttemptWindowW2 as Attempt;
    use App\Services\Baganriki\DispatchW2 as Dispatch;

    (function(string $dir):void {
        $n=0;$check=static function(bool $pass,string $msg)use(&$n):void {
            if (!$pass) throw new \RuntimeException('R15 '.$msg);
            ++$n;
        };
        $frames=[];$stored=[];$correctionChecks=0;$rankChecks=0;$clockChecks=0;$clockCases=[];
        require_once __DIR__.'/r151_corrections.php';
        require_once __DIR__.'/r152_rank_handoff.php';
        require_once __DIR__.'/r153_receipts.php';
        foreach (['1st','2nd'] as $role) {
            $bytes=file_get_contents("$dir/{$role}_input.json");$in=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
            $o=json_decode(file_get_contents("$dir/{$role}_output.json"),true,512,JSON_THROW_ON_ERROR);
            $proof=json_decode(file_get_contents("$dir/expected_proof.json"),true,512,JSON_THROW_ON_ERROR);
            $f=Frozen::fromStored(BaganrikiPrompt::commonHash($in)['json'],$bytes,$in['snapshot_hash'],$role,$proof);$nos=array_column($in['runners'],'horse_no');
            $ctrl=new AiController();$rows=[];
            $db=static function($a,$b,?Frozen $snapshot=null)use($f,$role):void {
                $snapshot??=$f;$in=Frozen::checked($snapshot);$nos=array_column($in['runners'],'horse_no');
                $snap=(object)['id'=>1,'input_json_1st'=>$snapshot->raw(),'input_json_2nd'=>$snapshot->raw(),'release_version'=>Sel::VERSION,'race_id'=>$in['race_id'],'common_json'=>BaganrikiPrompt::commonHash($in)['json'],'snapshot_hash'=>$in['snapshot_hash'],'frozen_nums'=>json_encode($nos),'deadline'=>$in['time_contract']['reply_deadline']];
                $rs=[];foreach ([$a,$b] as $i=>$raw) {
                    $receipt=\r4receipt($snapshot,$raw,$i);
                    $stamp=(new \DateTimeImmutable($receipt['received_at']))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s.u');
                    $rs[]=(object)['id'=>$i+1,'snapshot_id'=>1,'role'=>$role,'attempt_no'=>$i,'raw_response'=>$raw,'response_hash'=>hash('sha256',$raw),'status'=>BaganrikiWorker::RECEIVED,'sent_at'=>$receipt['sent_at'],'received_at'=>$stamp,'complete'=>1];
                }
                \R15Table::$tables=['baganriki_snapshots'=>[$snap],'baganriki_ai_attempts'=>$rs,'baganriki_live_states'=>[]];
            };
            $saved=static function($first,$fix,$inspection,?Frozen $snapshot=null)use($f,$role):array {
                $snapshot??=$f;
                $record=\r4original($snapshot,$first);$r=&$record['receipts'][0];$r['original_approved']=false;
                $sha=$r['response_hash'];$system=Dispatch::system($role,'FULL');
                $r['sent_request']=Dispatch::record($snapshot,'FULL',0,$system,$snapshot->raw());
                $sc=$inspection['repair_scope'];$grant=\r5scope($r,$sc['horses'],$sc['rank_bands'],$sc['summary_horses'],$sc['presentation_horses']);
                $diag=$inspection['diag'];$info=['errors'=>$diag['findings'],'missing_horses'=>$diag['missing'],'duplicated_horses'=>$diag['duplicated'],'unknown_horses'=>$diag['unknown'],'isolated_horses'=>$diag['isolated']];
                $fixRow=\r4receipt($snapshot,$fix,1);$fixRow['repair_scope']=$grant;$fixRow['repair_diagnostics']=$info;
                $fixRow['inspection_sha256']=Dispatch::inspectionSeal($snapshot,$sha,$grant,$info);
                $scope=Attempt::scope($grant,$sha,Frozen::checked($snapshot));$body=Dispatch::correctionBody($snapshot,$snapshot->raw(),$first,$scope,$info);
                $fixRow['sent_request']=Dispatch::record($snapshot,'FULL',1,$system,$body);$record['receipts'][]=$fixRow;return $record;
            };
            $healthy=\r3frame($o);$db($healthy,$healthy);
            $inspection=$ctrl->inspectAiResponse(1,$role);$check($inspection['code']==='OK' && !$inspection['needs_fix'] && count($inspection['normal'])===16,'healthy whole submitted inspection '.$role);
            $check($ctrl->resolveCorrection(1,$role)['code']==='NO_FIX_NEEDED','healthy role spends no correction');
            $baseRecord=\r4original($f,$healthy);$stored[$role]=[$f,$in,$nos,$baseRecord];
            $merge=static fn($record)=>\r9merge($f,[$role=>$record],$in['quota'],$nos,[],\r4live($f));
            $base=$merge($baseRecord)['public_candidates'];$cases=[];$outsiders=array_values(array_diff($nos,$o['public_candidates']));$b=$outsiders[0];
            $short=['band'=>'A','requested'=>3,'eligible'=>1,'missing'=>2,'reason'=>'不足'];
            $bad=$o;$bad['shortfalls']=[$short];$cases['invented shortfall']=[$bad,$o,'W15_SHORTFALLS',[],[]];
            $bad=$o;$bad['horses'][$b-1]['nonselect_reason']['code']='NO_EVIDENCE';$cases['quota reason']=[$bad,$o,'W15_NONSELECT_CODE',[],[$b]];
            $bad=$o;$bad['horses'][0]['evidence'][0]['facts'][0]['value']=8765;$cases['other measurement']=[$bad,$o,'W3_FACT_VALUE',[1],[]];
            $bad=$o;$v=$bad['horses'][0]['evidence'][0]['facts'][0]['value'];$bad['horses'][0]['evidence'][0]['facts'][0]['value']=(string)$v;$cases['number changed into text']=[$bad,$o,'W3_FACT_VALUE',[1],[]];
            $bad=$o;$bad['horses'][0]['evidence_rank_evidence_ids']=['absent_id'];$cases['unregistered evidence ID']=[$bad,$o,'W3_RANK_EVIDENCE_ID',[],[1]];
            foreach (['',' ',"\t\n","　","\u{200B}\u{FEFF}"] as $id=>$space) {
                $bad=$o;$bad['horses'][0]['explanation']=$space;$cases['blank explanation '.$id]=[$bad,$o,'W15_SCHEMA',[],[1]];
            }
            $bad=$o;$bad['extra_note']='未定義';$cases['unknown root key']=[$bad,$o,'W15_SCHEMA',[],[]];
            $bad=$o;$bad['horses'][0]['extra_note']='未定義';$cases['unknown horse key']=[$bad,$o,'W15_EXTRA_ROW_FIELD',[],[]];
            $bad=$o;$bad['shortfalls']=(object)[];$cases['object in place of empty list']=[$bad,$o,'W15_SCHEMA',[],[]];
            $bad=$o;$bad['horses'][0]['counterevidence']=(object)[];$cases['empty counterevidence object']=[$bad,$o,'W15_SCHEMA',[1],[]];
            $bad=$o;unset($bad['band_ranks']);$cases['missing rank dictionary']=[$bad,$o,'BANDS_TYPE',[],[]];
            $bad=$o;$bad['evidence_band_ranks']['Z']=[];$cases['extra band key']=[$bad,$o,'BANDS_UNKNOWN',[],[]];
            $bad=$o;$extra=$o['horses'][0];$extra['horse_no']=99;$bad['horses'][]=$extra;$cases['unknown runner']=[$bad,$o,'HORSE_UNKNOWN',[],[]];
            $bad=$o;$bad['horses'][]=$o['horses'][0];$cases['duplicate runner']=[$bad,$o,'HORSE_DUPLICATED',[1],[]];
            $bad=$o;$bad['public_candidates']=[];foreach(Sel::BANDS as $band) array_push($bad['public_candidates'],...array_slice($o['band_ranks'][$band],0,$in['role_quota'][$band]));
            $delta=array_values(array_unique(array_merge(array_diff($o['public_candidates'],$bad['public_candidates']),array_diff($bad['public_candidates'],$o['public_candidates']))));sort($delta);
            foreach ($bad['horses'] as &$h) {
                $chosen=in_array($h['horse_no'],$bad['public_candidates'],true);
                if ($h['public_candidate']===$chosen) continue;
                $h['public_candidate']=$chosen;
                $h['nonselect_reason']=$h['public_candidate']?null:['code'=>'BAND_QUOTA','reason'=>'入替前'];
            }unset($h);
            $bad['horses'][0]['observed_sum']=11;$cases['score total plus stale independent candidate flags']=[$bad,$o,'W14_PUBLIC_ORDER',[],$delta];
            $bad=$o;$bad['horses'][0]['evidence'][0]['facts'][0]['value']=8765;$bad['horses'][$b-1]['nonselect_reason']['code']='NO_EVIDENCE';$bad['shortfalls']=[$short];
            $cases['three simultaneous issues']=[$bad,$o,'W3_FACT_VALUE',[1],[$b]];
            $true=$o;$removed=range(1,$in['role_quota']['A']===1?6:5);$left=6-count($removed);
            foreach ($removed as $hno) {
                $h=&$true['horses'][$hno-1];$h['eligibility']=$h['tier']='UNVERIFIED';$h['rank_basis']='NOT_RANKED';$h['evidence']=$h['evidence_rank_evidence_ids']=[];
                foreach ($h['family_review'] as &$rv) $rv=['status'=>'DATA_MISSING','evidence_ids'=>[],'source_refs'=>[],'reason'=>'必要な資料が不足'];unset($rv,$h);
            }
            foreach (['band_ranks','evidence_band_ranks'] as $k) $true[$k]['A']=array_values(array_diff($true[$k]['A'],$removed));
            $calc=Sel::apply($f,$true['horses'],$true['band_ranks']);foreach (['expected_reviews','public_candidates','selection_ranks'] as $k) $true[$k]=$calc[$k];
            foreach ($true['horses'] as &$h) {
                $h['public_candidate']=in_array($h['horse_no'],$true['public_candidates'],true);
                $h['nonselect_reason']=$h['public_candidate']?null:['code'=>$h['eligibility']==='ELIGIBLE'?'BAND_QUOTA':'UNVERIFIED','reason'=>'資料または枠の確認'];
            }unset($h);
            $true['shortfalls']=[['band'=>'A','requested'=>$in['role_quota']['A'],'eligible'=>$left,'missing'=>$in['role_quota']['A']-$left,'reason'=>'全登録馬の資格を照合済み']];
            $correct=\r3frame($true);$db($correct,$correct);
            $check(!$ctrl->inspectAiResponse(1,$role)['needs_fix'] && $ctrl->resolveCorrection(1,$role)['code']==='NO_FIX_NEEDED','real shortage allowed '.$role.' '.json_encode($ctrl->inspectAiResponse(1,$role)['diag']['findings'],JSON_UNESCAPED_UNICODE));
            $correctionChecks+=r151Corrections($role,$ctrl,$f,$in,$o,$true,$db,$saved,$merge);
            $rankChecks+=r152RankHandoff($role,$ctrl,$f,$in,$o,$db,$saved,$proof);
            [$cc,$clockCases[$role]]=r153RoleClock($role,$ctrl,$f,$in,$o,$db,$saved,$proof);$clockChecks+=$cc;
            foreach (['missing entry','duplicate','wrong count','wrong band','blank reason'] as $variant) {
                $bad=$true;
                if ($variant==='missing entry') $bad['shortfalls']=[];
                if ($variant==='duplicate') $bad['shortfalls'][]=$bad['shortfalls'][0];
                if ($variant==='wrong count') ++$bad['shortfalls'][0]['missing'];
                if ($variant==='wrong band') $bad['shortfalls'][0]['band']='B';
                if ($variant==='blank reason') $bad['shortfalls'][0]['reason']="　";
                $cases['true shortage '.$variant]=[$bad,$true,'W15_SHORTFALLS',[],[]];
            }
            foreach ($cases as $label=>[$bad,$expected,$code,$horses,$shown]) {
                $first=\r3frame($bad);$fix=\r3frame($expected);$db($first,$fix);$d=$ctrl->inspectAiResponse(1,$role);$sc=$d['repair_scope'];
                $check($d['needs_fix'] && in_array($code,array_column($d['diag']['findings'],'code'),true),$label.' diagnostic');
                $check(!array_diff($sc['horses'],$nos) && !array_diff($sc['rank_bands'],Sel::BANDS),$label.' scope can be sent');
                $check($sc['horses']===$horses && $sc['presentation_horses']===$shown,$label.' scope '.json_encode([$sc['horses'],$sc['presentation_horses']]));
                $corr=$ctrl->resolveCorrection(1,$role);
                $check($corr['code']==='FIX_APPLIED' && count($corr['merged'])===16,$label.' submitted resolve '.json_encode([$corr['code'],$corr['reason']],JSON_UNESCAPED_UNICODE));
                $record=$saved($first,$fix,$d);$got=$merge($record);$want=$merge(\r4original($f,$fix));
                $check($got['public_candidates']===$want['public_candidates'] && $got['role_issues']===$want['role_issues'] && !$got['attempt_issues'],$label.' final saved response '.json_encode([$got['public_candidates'],$want['public_candidates'],$got['role_issues'],$got['attempt_issues']]));
                if ($label==='unknown horse key' || $label==='three simultaneous issues' || $label==='quota reason') {
                    $sneak=$expected;$sneak['horses'][14]['explanation'].='許可外の変更';$alt=\r3frame($sneak);$db($first,$alt);
                    $check($ctrl->resolveCorrection(1,$role)['code']==='FIX_REJECTED',$label.' submitted out-of-scope rejection');
                    try {$merge($saved($first,$alt,$d));$denied=false;} catch (\InvalidArgumentException $e) {$denied=$e->getMessage()==='W2_NO_VERIFIED_ROLE';}
                    $check($denied,$label.' final out-of-scope rejection');
                }
                if ($label==='three simultaneous issues') $frames[$role]=[$first,$record];
            }
            foreach (['different role','different race','wrong stored hash','stale common bytes','altered common field','different release','legacy body'] as $case) {
                $db($healthy,$healthy);$snap=\R15Table::$tables['baganriki_snapshots'][0];
                if ($case==='different role') {
                    $other=$role==='1st'?'2nd':'1st';$raw=file_get_contents("$dir/{$other}_input.json");
                    $snap->input_json_1st=$snap->input_json_2nd=$raw;
                    \R15Table::$tables['baganriki_ai_attempts'][0]->raw_response=\r3frame(json_decode(file_get_contents("$dir/{$other}_output.json"),true));
                }
                if ($case==='different race') $snap->race_id='OTHER_RACE';
                if ($case==='wrong stored hash') $snap->snapshot_hash=str_repeat('a',64);
                if ($case==='stale common bytes') $snap->common_json.=' ';
                if ($case==='different release') $snap->release_version=\App\Services\Baganriki\ScoreMaterialsW2::R14;
                if ($case==='altered common field' || $case==='legacy body') {
                    $wrong=$in;
                    if ($case==='legacy body') $wrong['prompt_version']=\App\Services\Baganriki\ScoreMaterialsW2::R14;
                    else $wrong['source_registry']['h1_past_performance_None']['value']=7;
                    $snap->input_json_1st=$snap->input_json_2nd=\r3bytes($wrong);
                }
                $d=$ctrl->inspectAiResponse(1,$role);
                $check($d['code']==='INVALID_SAVED_INPUT' && !$d['accepted'] && !$d['needs_fix'] && !$d['normal'],$case.' held at snapshot');
            }
            // Deliberately overwrite every root and horse field with each JSON type, including {} versus [].
            foreach ([[],[0],(object)[],null,true,'bad',-3] as $i=>$variant) {
                foreach (['root'=>array_keys($o),'row'=>array_keys($o['horses'][0])] as $level=>$keys) foreach($keys as $field) {
                    $x=$o;
                    if ($level==='root') $x[$field]=$variant;else $x['horses'][0][$field]=$variant;
                    $raw=\r3frame($x);$db($raw,$healthy);
                    try {Sel::verifyOriginal($f,$raw,hash('sha256',$raw));$valid=true;}
                    catch (\InvalidArgumentException $e) {$valid=false;}
                    $d=$ctrl->inspectAiResponse(1,$role);
                    $check(($d['code']==='OK' && !$d['needs_fix'])===$valid,'raw/final field matrix '.$role.' '.$level.'/'.$field.' '.$i.' '.json_encode([$valid,$d['code'],$d['diag']['findings']??[]],JSON_UNESCAPED_UNICODE));
                }
            }
            // Include both JSON number spellings that compare equal, an adjacent number, and a quoted number.
            $ctx=$in;$ctx['source_registry']['h1_past_performance_None']['value']=987654321;$token='1.00000000000000000001';
            $replace=static function($raw,$v) { if(substr_count($raw,'987654321')!==1) throw new \RuntimeException('decimal fixture marker');return str_replace('987654321',$v,$raw); };
            $common=$replace(BaganrikiPrompt::commonHash($ctx)['json'],$token);$ctx['snapshot_hash']=hash('sha256',$common);$typed=$replace(\r3bytes($ctx),$token);
            $precise=Frozen::fromStored($common,$typed,$ctx['snapshot_hash'],$role,$proof);
            $xo=$o;$xo['snapshot_hash']=$ctx['snapshot_hash'];$xo['horses'][0]['evidence'][0]['facts'][0]['value']=987654321;
            foreach ([$token=>true,'100000000000000000001e-20'=>true,'1.00000000000000000002'=>false,'"1.00000000000000000001"'=>false] as $v=>$valid) {
                $raw=$replace(\r3frame($xo),$v);$db($raw,$raw);$snap=\R15Table::$tables['baganriki_snapshots'][0];$snap->input_json_1st=$snap->input_json_2nd=$typed;$snap->common_json=$common;$snap->snapshot_hash=$ctx['snapshot_hash'];
                $d=$ctrl->inspectAiResponse(1,$role);$check(($d['code']==='OK' && !$d['needs_fix'])===$valid,'strict numeric fact at real ingress');
                try {Sel::verifyOriginal($precise,$raw,hash('sha256',$raw));$verified=true;}catch(\InvalidArgumentException $e){$verified=false;}
                $check($verified===$valid,'original numeric/string semantic parity');
            }
            $old=$in;$old['prompt_version']=\App\Services\Baganriki\ScoreMaterialsW2::R14;$old['schema_version']='baganriki-v041-r12-2-w2-e35-r14';
            $old['snapshot_hash']=BaganrikiPrompt::commonHash($old)['hash'];$xo=$o;foreach(['prompt_version','schema_version','snapshot_hash']as $k)$xo[$k]=$old[$k];
            foreach ([[],[$short]] as $oldShort) {
                $xo['shortfalls']=$oldShort;$raw=\r3frame($xo);$db($raw,$raw);$snap=\R15Table::$tables['baganriki_snapshots'][0];$snap->input_json_1st=$snap->input_json_2nd=\r3bytes($old);$snap->release_version=$old['prompt_version'];$snap->snapshot_hash=$old['snapshot_hash'];$snap->common_json=BaganrikiPrompt::commonHash($old)['json'];
                $d=$ctrl->inspectAiResponse(1,$role);$check($d['code']==='OK' && !$d['needs_fix'],'historical R14 preliminary routing');
            }
        }
        foreach (['1st','2nd'] as $failed) {
            $good=$failed==='1st'?'2nd':'1st';[$f,$in,$nos,$normal]=$stored[$good];
            $solo=\r9merge($f,[$good=>$normal],$in['quota'],$nos,[],\r4live($f));
            $mixed=\r9merge($f,[$good=>$normal,$failed=>\r4original($stored[$failed][0],$frames[$failed][0])],$in['quota'],$nos,[],\r4live($f));
            $check($mixed['public_candidates']===$solo['public_candidates'] && isset($mixed['role_issues'][$failed]) && max($mixed['agreement_bonus'])===0,'one invalid role leaves good role');
            $repaired=\r9merge($f,[$good=>$normal,$failed=>$frames[$failed][1]],$in['quota'],$nos,[],\r4live($f));
            $both=\r9merge($f,[$good=>$normal,$failed=>$stored[$failed][3]],$in['quota'],$nos,[],\r4live($f));
            $check(!$repaired['role_issues'] && !$repaired['attempt_issues'] && $repaired['public_candidates']===$both['public_candidates'],'corrected role rejoins other role');
        }
        echo "PASS $n R15 actual submitted inspect/resolve, typed raw input, bounded grants, original-to-correction and two-role adoption checks (local rows and deadline).\n";
        echo "PASS $correctionChecks R15.1 compound faults, correction status, eligible rankings, independent role, source hashes and validator history.\n";
        echo "PASS $rankChecks R15.2 rank permission, ordered public candidates, score fallback, receipt replay and recovered roles.\n";
        $mixedChecks=r153Mixed($clockCases);
        echo "PASS $clockChecks R15.3 receipt window, exact completion, normal entry and legacy input checks.\n";
        echo "PASS $mixedChecks R15.3 two-role corrections, withdrawals, shortened deadlines and commit checks.\n";
    })($argv[3]);
}
