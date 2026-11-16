"""Run the real submitted inspect/resolve methods. Test doubles provide saved rows and a fixed open deadline."""
from pathlib import Path
from tempfile import TemporaryDirectory
import subprocess

def run(php, backend, updated, examples, root):
    def method(name):
        import re
        m=re.search(r'^    (?:public|private) (?:static )?function '+name+r'\(',updated,re.M)
        if m is None: raise ValueError(name)
        return updated[m.start():updated.index('\n    }\n',m.start())+6]
    def klass(name):
        i=updated.index('final class '+name+'\n')
        return updated[i:updated.index('\n}\n',i)+3]
    i=updated.index('    public const SCORE_ONLY_ERROR_CODES')
    iface=updated[i:updated.index('    ];',i)+6]+'\n'
    iface+='\n'.join(method(n) for n in ('inspectionSentAt','acceptedHorsesOf','inspectAiResponse','resolveCorrection','markResponseUsed','normalizedPartOf','dbTimeToRfc3339','fieldSourcesOf','rankSourcesOf'))
    iface+='\n'+method('saveValidationResult')+'\n'
    code=Path(backend).read_text().replace('final class AiController {','final class AiController {\n'+iface,1)
    code+='\n'+klass('BaganrikiDiagnose')+'\n'+klass('BaganrikiTime')+'\n'
    code+=r"""
final class BaganrikiWorker {
    const RECEIVED='RECEIVED', FIRST=0, FIX=1;
    public static ?int $fixtureNow=null;
    public static function nowUs() {return self::$fixtureNow ?? BaganrikiTime::us('2026-10-17T14:57:30+09:00');}
    public static function toDb($v) {return "2026-11-04 00:00:00.000001";}
    public static function dbUs($v) { $s=AiController::dbTimeToRfc3339($v); return $s===null?null:BaganrikiTime::us($s); }
    public static function deadlines($snapshot,$live) {return ['ok'=>true,'reply'=>BaganrikiTime::us($snapshot->deadline)];}
}
require $argv[1];require $argv[2];
"""
    worker = updated[updated.index('final class BaganrikiWorker\n'):]
    def worker_method(name):
        import re
        m=re.search(r'^    (?:public|private) static function '+name+r'\(',worker,re.M)
        return worker[m.start():worker.index('\n    }\n',m.start())+6]
    code=code.replace('    const RECEIVED=',"    const FAILED='FAILED', UNKNOWN='UNKNOWN';\n"+'\n'.join(worker_method(n) for n in ('rowsOf','fin','fixAllowed'))+'\n    const RECEIVED=')
    with TemporaryDirectory() as d:
        test=Path(d)/'inspection.php';test.write_text(code)
        res=subprocess.run([str(php),str(test),str(root/'php/ExpectedBridgeW2.php'),str(root/'validation/r15_inspection.php'),str(examples)],text=True,capture_output=True)
        if res.returncode or res.stderr or 'Warning:' in res.stdout: raise RuntimeError((res.stdout+res.stderr)[-4500:])
        if not res.stdout.startswith('PASS ') or 'R15' not in res.stdout: raise RuntimeError(res.stdout)
        print(res.stdout.strip())
