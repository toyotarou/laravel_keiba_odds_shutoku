"""Apply both integration patches to the pinned PHP submission and execute its diagnosis and local score salvage."""
from hashlib import sha256
from pathlib import Path
from tempfile import TemporaryDirectory
import subprocess
from verify_r10_adapter import ROOT, SUBMITTED_SHA
from verify_r15_inspection import run as verify_inspection
from verify_r158_provider import run as verify_provider
from verify_r159_live_writer import run as verify_live_writer
from verify_r1510_read import run as verify_read

def run(php, backend, submitted, examples):
    source=Path(submitted).read_bytes()
    assert sha256(source).hexdigest()==SUBMITTED_SHA
    with TemporaryDirectory() as folder:
        tmp=Path(folder);ctrl=tmp/'AiController.php';ctrl.write_bytes(source)
        for name in ['response_frame_w2.patch','score_evidence_w2.patch']:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-p1','-i',str(ROOT/'integration'/name)],cwd=tmp,check=True)
        subprocess.run([str(php),'-l',str(ctrl)],check=True,capture_output=True)
        updated=ctrl.read_text()
        print('PASS patched submitted controller SHA-256 '+sha256(ctrl.read_bytes()).hexdigest())
        verify_provider(php,ctrl)
        verify_live_writer(php,ctrl)
        verify_read(php,ctrl)
        from verify_r1512_publication import smoke as verify_w2_publication
        verify_w2_publication(php,ctrl)
        from verify_r1513_corrected_publication import smoke as verify_corrected_publication
        verify_corrected_publication(php,ctrl)
        from verify_r1514_replays import smoke as verify_retry_record
        verify_retry_record(php,ctrl)
        from verify_r1515_allocation import smoke as verify_current_allocation
        verify_current_allocation(php,ctrl)
        from verify_r1516_window import smoke as verify_commit_window
        verify_commit_window(php,ctrl)
        from verify_r1517_queue import smoke as verify_queue_read
        verify_queue_read(php,ctrl)
        with TemporaryDirectory() as dispatch_dir:
            subprocess.run([php,str(ROOT/'validation/verify_w2.php'),'--examples',dispatch_dir,'--sdk-fixture'],check=True,capture_output=True)
            subprocess.run([php,str(ROOT/'validation/r1518_send.php'),str(ctrl),dispatch_dir],check=True)

        from verify_r1511_clock import run as verify_saved_clocks
        verify_saved_clocks(php,ctrl)
        assert r"FrozenInputW2::forInspection($snap, $role, $inputTypes)" in updated
        assert r"FrozenInputW2::forInspection($snap, $role, $rankTypes);" in updated
        start=updated.index('    public const SCORE_ONLY_ERROR_CODES')
        scores=updated[start:updated.index('    ];',start)+6]
        start=updated.index('final class BaganrikiDiagnose')
        klass=updated[start:updated.index('\n}\n',start)+3]
        start=updated.index("                if (!is_array($h['scores'] ?? null)) { $h['scores'] = []; }")
        end=updated.index("                $h['score_status'] = ($nNull === 0) ? 'complete' : 'partial';",start)
        fragment=updated[start:updated.index('\n',end)]
        core=Path(backend).read_text().replace('final class AiController {','final class AiController {\n'+scores,1)
        probe=tmp/'probe.php'
        code=core+'\n'+klass+'\nrequire $argv[1];\nrequire $argv[2];\n'
        code+='function salvaged($h,$src,$no,$badKeys) {\n'+fragment+'\nreturn $h;\n}\n'
        code+=r"""
function yes($ok,$label) { if (!$ok)throw new \RuntimeException($label); }
$n=0;$currentChecks=0;
foreach (['1st','2nd'] as $role) {
    $in=json_decode(file_get_contents($argv[3].'/'.$role.'_input.json'),true);
    $out=json_decode(file_get_contents($argv[3].'/'.$role.'_output.json'),true);
    $h=&$out['horses'][0];$h['scores']['H1']=4;$h['score_evidence']['H1']=['reason'=>'valid ability','source_refs'=>['h1_past_performance_None']];
    $h['scores']['V3']=8;$h['score_evidence']['V3']=['reason'=>'single odds','source_refs'=>['h1_win_odds_6']];
    $h['observed_sum']=12;$h['observed_cap']=26;unset($h);
    $legacy=$in;$legacy['prompt_version']='baganriki-v041-r12-2-w2-e35-r11-20261101';
    $d=BaganrikiDiagnose::run($out,$legacy);yes(!in_array('W12_SCORE_MATERIAL',array_column($d['findings'],'code'),true),'R11 routing');++$n;
    $d=BaganrikiDiagnose::run($out,$in);$found=array_values(array_filter($d['findings'],static fn($f)=>$f['code']==='W12_SCORE_MATERIAL'));
    yes(count($found)===1 && $found[0]['scope']==='summary_horses' && $found[0]['target']===1 && $d['bad_scores'][1]===['V3'],'local diagnosis');++$n;
    $fixed=salvaged($out['horses'][0],$in,1,$d['bad_scores'][1]);
    yes($fixed['scores']['V3']===null && $fixed['scores']['H1']===4 && $fixed['observed_cap']===18 && $fixed['observed_sum']===4 && $fixed['total_score']===null && $fixed['score_status']==='partial','salvage totals');++$n;
    yes($fixed['eligibility']===$out['horses'][0]['eligibility'] && $fixed['evidence']===$out['horses'][0]['evidence'] && $fixed['score_evidence']['H1']===$out['horses'][0]['score_evidence']['H1'],'other fields retained');++$n;
    $out['horses'][0]['score_evidence']['V3']['source_refs']=['absent','h2_win_odds_6','h1_win_odds_6','h1_win_odds_6'];
    $f=salvaged($out['horses'][0],$in,1,['V3']);yes($f['score_evidence']['V3']['source_refs']===['h1_win_odds_6'] && trim($f['score_evidence']['V3']['reason'])!=='','bad provenance removed');++$n;
    $out['horses'][0]['score_evidence']['V3']['source_refs']=\r12Refs(1)['V3'];
    $d=BaganrikiDiagnose::run($out,\r12ScoreInput($in));yes(empty($d['bad_scores'][1]) && !in_array('W12_SCORE_MATERIAL',array_column($d['findings'],'code'),true),'genuine pair accepted');++$n;
    $past=$in;$past['prompt_version']=\App\Services\Baganriki\ScoreMaterialsW2::PREVIOUS;
    $out['horses'][0]['score_evidence']['V3']['source_refs']=['h1_win_odds_6'];
    $d=BaganrikiDiagnose::run($out,$past);yes(($d['bad_scores'][1]??null)===['V3'],'R12 historical score gate');++$currentChecks;
    $row=$out['horses'][0];$row['scores']['V3']=null;$row['score_evidence']['V3']=['reason'=>'未算出','source_refs'=>[]];
    $row['observed_sum']=4;$row['observed_cap']=18;$row['score_evidence']['H1']['extra_note']='未定義';$out['horses'][0]=$row;
    $d=BaganrikiDiagnose::run($out,$in);$codes=array_values(array_filter($d['findings'],static fn($f)=>$f['code']==='W13_SCORE_PROOF_FIELDS'));
    yes(count($codes)===1 && $codes[0]['scope']==='summary_horses' && $codes[0]['target']===1 && empty($d['bad_scores'][1]),'extra proof field can be removed alone');++$currentChecks;
    $fixed=salvaged($row,$in,1,[]);unset($row['score_evidence']['H1']['extra_note']);
    yes($fixed===$row,'local salvage of extra field preserves all other facts and scores');++$currentChecks;
    $out['horses'][0]['evidence'][0]['family']='gap';
    $d=BaganrikiDiagnose::run($out,$in);$codes=array_values(array_filter($d['findings'],static fn($f)=>$f['code']==='W13_EVIDENCE_FAMILY'));
    yes(count($codes)===1 && $codes[0]['scope']==='horses' && $codes[0]['target']===1 && !$d['row_valid'][1],'family correction scope');++$currentChecks;
    $d=BaganrikiDiagnose::run($out,$past);yes(!array_intersect(['W13_SCORE_PROOF_FIELDS','W13_EVIDENCE_FAMILY'],array_column($d['findings'],'code')),'historical diagnosis unaffected');++$currentChecks;
    yes(isset(salvaged($out['horses'][0],$past,1,[])['score_evidence']['H1']['extra_note']),'historical local schema unaffected');++$currentChecks;
}
echo $n.','.$currentChecks;
require $argv[4];
"""
        probe.write_text(code)
        r=subprocess.run([str(php),str(probe),str(ROOT/'php/ExpectedBridgeW2.php'),str(ROOT/'validation/r12_score_fixtures.php'),str(examples),str(ROOT/'validation/r14_controller.php')],capture_output=True,text=True)
        if r.returncode: raise RuntimeError((r.stdout+r.stderr)[-4000:])
        assert r.stdout=='12,12,44',r.stdout
    print('PASS R12 both patches on the pinned submitted controller, complete PHP syntax and 12 actual diagnosis/routing/local salvage checks across both roles.')

    print('PASS 12 R13 actual submitted-controller diagnosis, horse/score repair scopes, retained points and historical R12 routing checks across both roles.')
    print('PASS 44 R14 actual submitted-controller framing, envelope, isolation, whole-response ranking, candidate/repair scope and exact inspection checks across both roles.')
    verify_inspection(php,backend,updated,examples,ROOT)
