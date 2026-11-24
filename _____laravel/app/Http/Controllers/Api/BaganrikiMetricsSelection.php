<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use DB;
use App\Constants\Constants;
use App\Services\AnthropicService;

/**
 * @ANCHOR-20261116-METRICS-SELECTION  3指標を最終の選出に反映する（なる11/15 仕様 RACE_METRICS_SELECTION_V1。豊田さん「予想に影響しないと意味がない」）
 *   ・波乱度4〜5で、下位進入度（B）か大穴進入度（D）も4〜5のときだけ、その帯を1枠増やす。総数は変えない。1レース1枠まで。
 *   ・入れるのは、その帯の統合順位で未選出の先頭の馬だけ（飛ばして探さない）。その馬の複勝オッズの時系列をサーバが凍結入力で確かめ、
 *     B：同じ複勝 field の6分前と、それより前の最新の時点（9→12→15→18→21）で厳密に短縮／D：同じ複勝 field で12＞9＞6分前
 *     が成立すること（20261116 なるさんの決定 B案。AIが引用したかどうかは問わない。旧：その馬の採用済み market 根拠に参照がそろっていること）。
 *   ・外すのは A帯の通常候補の最後（A は目標4・5頭なら2頭、6・7頭なら3頭は残す）。A から外せないときだけ、もう一方の穴帯
 *     （その帯の進入度が検証済みの1〜2のとき）の最後。B は最大4頭、D は最大2頭。
 *   ・使わないとき：選出停止／1st が公開に採用されていない／目標3頭以下／基本の枠が通常候補で埋まっていない（補充・不足あり）／
 *     採用された AI の E3・E5 で SWAP がある／波乱度が4〜5でない／該当する進入度が無い。その理由を記録する。
 *   ・AIへの追加の問い合わせはしない。AIの順位・採点・入力の mode・quota・role_quota は書き換えない。凍結入力の本文版が _06 のときだけ使う（凍結時に固定）。
 *   【段階1（11/15 20:30）】この class はまだどこからも呼ばない（判定の規則と根拠の確認だけ）。公開の処理への組み込みは段階2。
 */
final class BaganrikiMetricsSelection
{
    public const POLICY='RACE_METRICS_SELECTION_V1';
    public const TEXT_REVISIONS=['COMPACT_TEXT_20261109_06'];
    public const PARAMS=['wave'=>[4,5],'entry'=>[4,5],'a_floor'=>['4'=>2,'5'=>2,'6'=>3,'7'=>3],'cap'=>['B'=>4,'D'=>2],
        'donor_entry'=>[1,2],'b_earlier_slots'=>[9,12,15,18,21],'d_slots'=>[12,9,6],'min_target'=>4];
    /** 試験用：true/false で本文版に関係なく有効・無効にする（本番は null） */
    public static ?bool $force=null;
    /** 試験用：1stの3指標の値を差し替えて、補正の流れを実データで確かめる（本番は null。20261116） */
    public static ?array $testValues=null;

    /**
     * @ANCHOR-20261124-METRICS-SWAP-PAUSED  3指標による選出の入れ替えを止めます（なる 11/24 問4 B）
     *   3指標をサーバ計算に替えた最初の2週末（11/28〜30、12/5〜6）は、表示だけに使います。
     *   止めている間、公開記録の metrics_selection は status=KEEP・reason=SWAP_PAUSED で残ります。
     *   「入れ替えていたら、どの馬が入り、どの馬が外れたか」は、保存済みの凍結入力と回答から後で計算し直します。
     *   再開するときは false にします（試験の $force=true のときは、今までどおり動かせます）。
     */
    public const SWAP_PAUSED=true;
    public const SWAP_PAUSED_REASON='SWAP_PAUSED';

    public static function enabled(array $in):bool
    {
        return self::$force ?? in_array($in['prompt_text_revision']??null,self::TEXT_REVISIONS,true);
    }

    public static function paramsSha():string
    {
        return hash('sha256',json_encode([self::POLICY,self::PARAMS],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    /** @ANCHOR-20261116-METRICS-SPEC-FREEZE  凍結のときに記録する選出仕様（なる11/16 仕上げ②） */
    public const SPEC_EVENT='METRICS_SELECTION_SPEC';
    public static function specFor(?string $revision):array
    {
        return ['policy'=>self::POLICY,'prompt_text_revision'=>$revision,
            'enabled'=>in_array($revision,self::TEXT_REVISIONS,true),'params_sha256'=>self::paramsSha(),'params'=>self::PARAMS];
    }

    /**
     * 公開のとき：そのスナップショットの凍結時の仕様の記録を読み、今のコードの仕様と一致するか確かめる。
     *   戻り値 ['ok'=>bool,'reason'=>null|'SPEC_NOT_FROZEN'|'SPEC_DISABLED_AT_FREEZE'|'SPEC_MISMATCH','frozen_spec'=>記録 or null]
     *   記録が無い・一致しない場合は、今の設定を推測で当てず、補正しない（理由を残す）。試験で $force=true のときだけ記録を問わない。
     */
    public static function frozenSpecCheck(object $snapshot,array $seed):array
    {
        if (self::$force===true) return ['ok'=>true,'reason'=>null,'frozen_spec'=>null,'test_force'=>true];
        $row=\DB::table('baganriki_audit_events')->where('snapshot_id',(int)$snapshot->id)->where('kind',self::SPEC_EVENT)->orderBy('id')->get();
        if (count($row)!==1) return ['ok'=>false,'reason'=>count($row)===0?'SPEC_NOT_FROZEN':'SPEC_DUPLICATE','frozen_spec'=>null];
        $spec=json_decode((string)$row[0]->diagnostics,true);
        if (!is_array($spec)) return ['ok'=>false,'reason'=>'SPEC_NOT_FROZEN','frozen_spec'=>null];
        if (($spec['enabled']??null)!==true) return ['ok'=>false,'reason'=>'SPEC_DISABLED_AT_FREEZE','frozen_spec'=>$spec];
        if (($spec['policy']??null)!==self::POLICY || ($spec['params_sha256']??null)!==self::paramsSha()
            || ($spec['prompt_text_revision']??null)!==($seed['prompt_text_revision']??null))
            return ['ok'=>false,'reason'=>'SPEC_MISMATCH','frozen_spec'=>$spec];
        return ['ok'=>true,'reason'=>null,'frozen_spec'=>$spec];
    }

    /**
     * 選出の規則そのもの（なるさんの参照実装 03 と同じ判定）。入力は正規化したもの。
     * case: target_count, base_quota{A,B,D}, pools{A,B,D}（統合順位の有効な適格馬）, market_supported{B:[],D:[]},
     *       metrics{wave,lower,big}（検証済みの1〜5、それ以外は null）, selection_enabled, first_adopted, e35_swapped, has_base_fill
     */
    public static function decide(array $case):array
    {
        $q=$case['base_quota']; $pools=$case['pools']; $c=(int)$case['target_count'];
        $before=[]; foreach (['A','B','D'] as $b) foreach (array_slice($pools[$b],0,$q[$b]) as $n) $before[]=$n;
        $r=['policy'=>self::POLICY,'status'=>'KEEP','reason'=>null,'base_quota'=>$q,'effective_quota'=>$q,
            'before'=>$before,'after'=>$before,'removed'=>null,'added'=>null,'source_band'=>null,'target_band'=>null,'attempts'=>[]];
        $keep=static function(string $why) use (&$r):array { $r['reason']=$why; return $r; };
        $met=$case['metrics']??[];
        $m=static fn($k)=>(is_int($met[$k]??null) && $met[$k]>=1 && $met[$k]<=5)?$met[$k]:null;
        if (!($case['selection_enabled']??true)) return $keep('SELECTION_DISABLED');
        if (!($case['first_adopted']??true)) return $keep('FIRST_NOT_ADOPTED');
        if ($c<self::PARAMS['min_target']) return $keep('SMALL_FIELD');
        if (($case['has_base_fill']??false)) return $keep('BASE_NOT_REGULAR_FULL');
        foreach (['A','B','D'] as $b) if (count($pools[$b])<$q[$b]) return $keep('BASE_NOT_REGULAR_FULL');
        if (($case['e35_swapped']??false)) return $keep('E35_SWAP_PRIORITY');
        if (!in_array($m('wave'),self::PARAMS['wave'],true)) return $keep('WAVE_NOT_HIGH');
        $values=['B'=>$m('lower'),'D'=>$m('big')];
        $targets=array_values(array_filter(['B','D'],static fn($b)=>in_array($values[$b],self::PARAMS['entry'],true)));
        usort($targets,static fn($x,$y)=>($values[$y]<=>$values[$x]) ?: ($x==='B'?-1:1));
        if (!$targets) return $keep('NO_HIGH_ENTRY');
        $aFloor=self::PARAMS['a_floor'][(string)$c]??3;
        foreach ($targets as $dest) {
            $at=['target_band'=>$dest,'reason'=>null];
            if ($q[$dest]>=self::PARAMS['cap'][$dest]) { $at['reason']='TARGET_CAP';$r['attempts'][]=$at;continue; }
            if (count($pools[$dest])<=$q[$dest]) { $at['reason']='NO_NEXT_CANDIDATE';$r['attempts'][]=$at;continue; }
            $cand=$pools[$dest][$q[$dest]];
            if (!in_array($cand,$case['market_supported'][$dest]??[],true)) { $at['reason']='NEXT_MARKET_NOT_SUPPORTED';$r['attempts'][]=$at;continue; }
            $donor=null;
            if ($q['A']>$aFloor) $donor='A';
            elseif ($dest==='D' && in_array($values['B'],self::PARAMS['donor_entry'],true) && $q['B']>1) $donor='B';
            elseif ($dest==='B' && in_array($values['D'],self::PARAMS['donor_entry'],true) && $q['D']>0) $donor='D';
            if ($donor===null) { $at['reason']='NO_DONOR';$r['attempts'][]=$at;continue; }
            $removed=$pools[$donor][$q[$donor]-1];
            $cq=$q; $cq[$donor]--; $cq[$dest]++;
            $after=[]; foreach (['A','B','D'] as $b) foreach (array_slice($pools[$b],0,$cq[$b]) as $n) $after[]=$n;
            // 最終検査：総数・重複・1対1・A最低数・帯上限。成り立たなければ基本選出へ戻す
            if (count($after)!==$c || count(array_unique($after))!==$c || array_values(array_diff($before,$after))!==[$removed]
                || array_values(array_diff($after,$before))!==[$cand] || $cq['A']<$aFloor || $cq['B']>self::PARAMS['cap']['B'] || $cq['D']>self::PARAMS['cap']['D']) {
                $at['reason']='FINAL_CHECK_FAILED';$r['attempts'][]=$at;continue;
            }
            $at['reason']='APPLIED';$r['attempts'][]=$at;
            return array_replace($r,['status'=>'APPLIED','reason'=>'ONE_SLOT_ADJUSTMENT','effective_quota'=>$cq,'after'=>$after,
                'removed'=>$removed,'added'=>$cand,'source_band'=>$donor,'target_band'=>$dest]);
        }
        return $keep('NO_FEASIBLE_ADJUSTMENT');
    }

    private static function odd(mixed $v):?array
    {
        $p=BaganrikiExpectedPacket::oddsParts($v);
        return $p===null?null:array_slice($p,0,2);
    }

    private static function cmp(array $a,array $b):int
    {
        return ($a[0]<=>$b[0]) ?: strcmp($a[1],$b[1]);
    }

    // 20261116 なるさんの決定（B案）で置き換え：AIの採用済み market 根拠に複勝の時系列が引用されていることを必須にしていた旧版（AIはほぼ単勝しか引用しないため、補正が事実上起きなかった）
    // /** その馬自身の採用済み market 根拠で、B（直近の短縮）・D（12＞9＞6）が確かめられるか。確かめた参照を返す（だめなら null） */
    // public static function marketRefs(array $in,array $roleHorses,int $no,string $dest):?array
    // {
    //     $obs=[];
    //     foreach ($in['source_registry'] as $ref=>$e) {
    //         if (($e['scope']??null)!=='HORSE' || ($e['horse_nos']??null)!==[$no] || ($e['race_id']??null)!==$in['race_id']
    //             || !in_array($e['field']??null,['place_min','place_max'],true) || ($e['unit']??null)!=='odds'
    //             || ($e['source_kind']??null)!=='OBSERVED' || !in_array($e['slot_offset']??null,[21,18,15,12,9,6],true) || ($e['value']??null)===null) continue;
    //         $o=self::odd($e['value']); if ($o===null) continue;
    //         $obs[$e['field']][$e['slot_offset']]=[(string)$ref,$o];
    //     }
    //     foreach ($roleHorses as $role=>$horses) {
    //         $h=$horses[$no]??null;
    //         if (!is_array($h) || ($h['eligibility']??null)!=='ELIGIBLE') continue;
    //         foreach ((array)($h['evidence']??[]) as $ev) {
    //             if (($ev['family']??null)!=='market') continue;
    //             $refs=array_flip(array_map('strval',(array)($ev['source_refs']??[])));
    //             foreach (['place_min','place_max'] as $f) {
    //                 $s=$obs[$f]??[];
    //                 if (!isset($s[6])) continue;
    //                 if ($dest==='B') {
    //                     $prev=null; foreach (self::PARAMS['b_earlier_slots'] as $slot) if (isset($s[$slot])) { $prev=$slot;break; }
    //                     if ($prev!==null && self::cmp($s[6][1],$s[$prev][1])<0 && isset($refs[$s[6][0]],$refs[$s[$prev][0]]))
    //                         return ['role'=>$role,'evidence_id'=>$ev['evidence_id']??null,'field'=>$f,'refs'=>[$s[$prev][0],$s[6][0]]];
    //                 } else {
    //                     if (isset($s[12],$s[9]) && self::cmp($s[12][1],$s[9][1])>0 && self::cmp($s[9][1],$s[6][1])>0
    //                         && isset($refs[$s[12][0]],$refs[$s[9][0]],$refs[$s[6][0]]))
    //                         return ['role'=>$role,'evidence_id'=>$ev['evidence_id']??null,'field'=>$f,'refs'=>[$s[12][0],$s[9][0],$s[6][0]]];
    //                 }
    //             }
    //         }
    //     }
    //     return null;
    // }
    /**
     * 20261116 B案（なるさんの決定）：次点の馬の複勝オッズの時系列を、サーバが凍結入力の source_registry で直接確かめる。
     *   AIが引用したかどうかは問わない（AIの根拠・独立根拠数・Tier・点数・一致加点には一切足さない）。
     *   B帯へ：place_min か place_max の同じ指標で、6分前 ＜ それより前の最新の利用可能な時点（9→12→15→18→21分の順。直近が使えれば古い時点へは飛ばさない）
     *   D帯へ：同じ指標で 12分前 ＞ 9分前 ＞ 6分前
     *   使う資料：同じレース・同じ馬・同じ指標の OBSERVED、単位 odds、値が正しく、凍結の情報上限（input_freeze_at）までに利用可能になったもの。
     *   同じ時点が二重にある・欠けている・値が不正な時点は「利用できない」。
     *   次点の馬は、どちらかの役割で ELIGIBLE であること（従来どおり）。
     *   成立すればサーバ確認の根拠（参照ID・馬番・指標・時点・元値・判定）を返す。不成立なら null。
     */
    public static function serverPlaceCheck(array $in,array $roleHorses,int $no,string $dest):?array
    {
        $eligible=false;
        foreach ($roleHorses as $horses) if (is_array($horses[$no]??null) && ($horses[$no]['eligibility']??null)==='ELIGIBLE') $eligible=true;
        if (!$eligible) return null;
        $limit=null;
        try { if (is_string($in['input_freeze_at']??null)) $limit=new \DateTimeImmutable($in['input_freeze_at']); } catch (\Throwable) { $limit=null; }
        if ($limit===null) return null;
        $obs=[]; $dup=[];
        foreach ((array)($in['source_registry']??[]) as $ref=>$e) {
            if (!is_array($e) || ($e['scope']??null)!=='HORSE' || ($e['horse_nos']??null)!==[$no] || ($e['race_id']??null)!==($in['race_id']??null)
                || !in_array($e['field']??null,['place_min','place_max'],true) || ($e['unit']??null)!=='odds'
                || ($e['source_kind']??null)!=='OBSERVED' || !in_array($e['slot_offset']??null,[21,18,15,12,9,6],true)) continue;
            $at=null;
            try { if (is_string($e['available_at']??null)) $at=new \DateTimeImmutable($e['available_at']); } catch (\Throwable) { $at=null; }
            if ($at===null || $at>$limit) continue;
            $o=self::odd($e['value']??null); if ($o===null) continue;
            $f=$e['field']; $slot=$e['slot_offset'];
            if (isset($obs[$f][$slot])) { $dup[$f][$slot]=true; continue; }
            $obs[$f][$slot]=['ref'=>(string)$ref,'slot'=>$slot,'value'=>$e['value'],'o'=>$o];
        }
        foreach ($dup as $f=>$slots) foreach ($slots as $slot=>$_) unset($obs[$f][$slot]);
        $checks=[]; $hit=null;
        foreach (['place_min','place_max'] as $f) {
            $s=$obs[$f]??[]; $pts=[]; $ok=false;
            if ($dest==='B') {
                $prev=null; foreach (self::PARAMS['b_earlier_slots'] as $slot) if (isset($s[$slot])) { $prev=$slot; break; }
                if (isset($s[6]) && $prev!==null) { $pts=[$s[$prev],$s[6]]; $ok=self::cmp($s[6]['o'],$s[$prev]['o'])<0; }
            } else {
                if (isset($s[12],$s[9],$s[6])) { $pts=[$s[12],$s[9],$s[6]]; $ok=self::cmp($s[12]['o'],$s[9]['o'])>0 && self::cmp($s[9]['o'],$s[6]['o'])>0; }
            }
            $checks[]=['field'=>$f,'points'=>array_map(static fn($p)=>['source_ref'=>$p['ref'],'slot_offset'=>$p['slot'],'value'=>$p['value']],$pts),
                'result'=>$pts===[]?'NOT_AVAILABLE':($ok?'SATISFIED':'NOT_SATISFIED')];
            if ($ok && $hit===null) $hit=$f;
        }
        if ($hit===null) return null;
        $used=[]; foreach ($checks as $c) if ($c['field']===$hit) $used=$c['points'];
        return ['basis'=>'SERVER_FROZEN_PLACE_ODDS','horse_no'=>$no,'target_band'=>$dest,
            'rule'=>$dest==='B'?'PLACE_6_LT_LATEST_EARLIER':'PLACE_12_GT_9_GT_6','field'=>$hit,
            'refs'=>array_column($used,'source_ref'),'checks'=>$checks];
    }

    /**
     * 【段階2】submittedPublication から呼ぶ。基本選出（mergeChecked の結果）はそのまま残し、補正の結果と記録を返す。
     *   $metrics は公開に採用した 1st の検証済みの3指標（markResponseUsed の race_metrics）。1st が採用されていなければ使わない。
     */
    public static function apply(array $in,array $roles,array $roleHorses,array $selection,array $quota,?array $metrics):array
    {
        $vals=['wave'=>null,'lower'=>null,'big'=>null];
        $first=false;
        foreach ($roles as $r) if (($r['ai_role']??null)==='1st') $first=true;
        if ($first && is_array($metrics) && is_string($metrics['response_hash']??null)) {
            foreach (['wave'=>'wave_level','lower'=>'lower_entry','big'=>'big_gap_entry'] as $k=>$key)
                if (($metrics['status'][$key]??null)==='VALID' && is_int($metrics['values'][$key]??null)) $vals[$k]=$metrics['values'][$key];
        } else { $first=false; }
        if ($first && self::$testValues!==null) $vals=array_replace($vals,self::$testValues);
        $swap=false;
        foreach ($roles as $r) foreach ((array)($r['expected_reviews']??[]) as $rv) if (($rv['decision']??null)==='SWAP') $swap=true;
        $pools=[];
        foreach (['A','B','D'] as $b) $pools[$b]=array_values((array)($selection['band_order'][$b]??[]));
        $supported=['B'=>[],'D'=>[]]; $refs=[];
        foreach (['B','D'] as $b) {
            $next=$pools[$b][(int)($quota[$b]??0)]??null;
            if (is_int($next)) { $x=self::serverPlaceCheck($in,$roleHorses,$next,$b); if ($x!==null) { $supported[$b][]=$next; $refs[$b]=$x; } }   // 20261116 B案：旧 marketRefs から置き換え
        }
        $case=['target_count'=>array_sum($quota),'base_quota'=>$quota,'pools'=>$pools,'market_supported'=>$supported,'metrics'=>$vals,
            'selection_enabled'=>(bool)($in['selection_enabled']??false),'first_adopted'=>$first,'e35_swapped'=>$swap,
            'has_base_fill'=>(($selection['fill']??[])!==[] || ($selection['shortfalls']??[])!==[])];
        $d=self::decide($case);
        // 規則の before が、実際の基本選出（mergeChecked の結果）と一致しなければ適用しない
        // 補充・不足があるレースは decide が先に「補正しない」と決めるので、その理由をそのまま残す（20261116）
        $base=array_values((array)($selection['public_candidates']??[]));
        if ($case['has_base_fill']) { $d['before']=$base; $d['after']=$base; }
        if (!$case['has_base_fill'] && $d['before']!==$base) {
            $d=array_replace($d,['status'=>'KEEP','reason'=>'BASE_SELECTION_MISMATCH','effective_quota'=>$quota,'before'=>$base,
                'after'=>$base,'removed'=>null,'added'=>null,'source_band'=>null,'target_band'=>null]);
        }
        $d['params_sha256']=self::paramsSha();
        $d['metrics']=$vals;
        // $d['market_evidence']=($d['status']==='APPLIED') ? ($refs[$d['target_band']]??null) : null;   // 旧（AIの根拠を記録していた）
        $d['server_place_check']=($d['status']==='APPLIED') ? ($refs[$d['target_band']]??null) : null;   // 20261116 B案：サーバ確認の根拠
        return $d;
    }
}
