"""Apply the three-site parser routing patch to the pinned submitted controller, then execute those exact fragments."""
from pathlib import Path
from hashlib import sha256
from tempfile import TemporaryDirectory
import json,re,subprocess

ROOT=Path(__file__).resolve().parents[1]
SUBMITTED_SHA='1e8f0fbfe732fd24639c6b1f40c40b384e5956accd969a5aea3ff838159ed7ae'

def run(php,backend,submitted):
    source=Path(submitted).read_bytes();assert sha256(source).hexdigest()==SUBMITTED_SHA
    with TemporaryDirectory() as d:
        tmp=Path(d);controller=tmp/'AiController.php';controller.write_bytes(source)
        subprocess.run(['patch','--batch','--fuzz=0','--silent','-p1','-i',str(ROOT/'integration/response_frame_w2.patch')],cwd=tmp,check=True,capture_output=True,text=True)
        updated=controller.read_text()
        pattern=r'(\$(ex|pe) = \\App\\Services\\Baganriki\\ResponseFrameW2::supportsVersion\(\$(src1?)\[\'prompt_version\'\] \?\? null\)\n\s+\? \\App\\Services\\Baganriki\\ResponseFrameW2::extract\(\(string\) \$(a|p)->raw_response\)\n\s+: BaganrikiResponse::extract\(\(string\) \$\4->raw_response\);)'
        fragments=list(re.finditer(pattern,updated));assert len(fragments)==3
        def original(m):return '$'+m.group(2)+' = BaganrikiResponse::extract((string) $'+m.group(4)+'->raw_response);'
        assert re.sub(pattern,original,updated).encode()==source,'patch altered unrelated submitted code'
        subprocess.run([str(php),'-l',str(controller)],check=True,capture_output=True,text=True)
        code='<?php\ndeclare(strict_types=1);\nrequire $argv[1];\nrequire $argv[2];\n'
        code+='use App\\Http\\Controllers\\Api\\BaganrikiResponse;\nuse App\\Services\\Baganriki\\CandidateSelectionW2 as Sel;\n'
        for i,m in enumerate(fragments):
            code+='function site'+str(i)+'(string $raw,?string $version):array {\n'
            code+='$src=$src1=["prompt_version"=>$version];$a=$p=(object)["raw_response"=>$raw];\n'+m.group(1)+'\nreturn $'+m.group(2)+';\n}\n'
        code+='''$count=0;
$b=BaganrikiResponse::BEGIN;$e=BaganrikiResponse::END;
$samples=[
 ['raw'=>$b."\\n".'{"text":"normal"}'."\\n".$e,'current'=>true,'legacy'=>true],
 ['raw'=>$b."\\n".'{"text":"'.$e.' / '.$b.'"}'."\\n".$e,'current'=>true,'legacy'=>false],
 ['raw'=>$b."\\n\\0".'{"text":"normal"}'."\\n".$e,'current'=>false,'legacy'=>true],
 ['raw'=>$b."\\n".'{"x":1,"x":2}'."\\n".$e,'current'=>false,'legacy'=>false],
];
$strict=[Sel::VERSION,'baganriki-v041-r12-2-w2-e35-r14-20261104','baganriki-v041-r12-2-w2-e35-r13-20261104','baganriki-v041-r12-2-w2-e35-r12-20261104','baganriki-v041-r12-2-w2-e35-r11-20261101','baganriki-v041-r12-2-w2-e35-r10-20261101'];
foreach (array_merge($strict,['baganriki-v041-r12-2-w2-e35-r9-20261101','other',null]) as $version) {
 foreach ($samples as $sample) for($i=0;$i<3;$i++) {
  $method='site'.$i;$got=$method($sample['raw'],$version);
  $want=$sample[in_array($version,$strict,true)?'current':'legacy'];
  if ($got['ok']!==$want)throw new RuntimeException('wrong extraction branch '.$i.' / '.$version);
  ++$count;
 }
}
echo $count;
'''
        fixture=tmp/'probe.php';fixture.write_text(code)
        r=subprocess.run([str(php),str(fixture),str(backend),str(ROOT/'php/ExpectedBridgeW2.php')],check=True,capture_output=True,text=True)
        assert r.stdout=='108',r.stdout
    print('PASS R10 three-site ingress patch: exact application to pinned controller, full PHP syntax and 108 saved-version routing checks; no deployed Laravel execution.')
