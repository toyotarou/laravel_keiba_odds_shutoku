"""Complete the final inspection/storage checks after a baseline run ended after its R14 marker."""
from pathlib import Path
from tempfile import TemporaryDirectory
from hashlib import sha256
import argparse, json, subprocess
from verify_release import reference_from_submission
from verify_r15_inspection import run as verify_inspection
from verify_r7_storage import run as verify_storage

def main():
    root=Path(__file__).resolve().parents[1]
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--php',required=True)
    p.add_argument('--submitted-php',type=Path,default=root/'integration/reference/AiController.php')
    args=p.parse_args()
    with TemporaryDirectory() as folder:
        tmp=Path(folder);bootstrap=tmp/'reference.php';reference_from_submission(args.submitted_php,bootstrap)
        ctrl=root/'integration/patched/AiController.php'
        manifest=json.loads((root/'integration/controller_bases.json').read_text())
        assert sha256(ctrl.read_bytes()).hexdigest()==manifest['result_sha256']
        examples=tmp/'examples'
        subprocess.run([args.php,'-n',str(root/'validation/verify_w2.php'),'--examples',str(examples)],check=True,capture_output=True)
        print('Running final submitted-controller inspection checks',flush=True)
        verify_inspection(args.php,bootstrap,ctrl.read_text(),examples,root)
        print('Running final SQLite/storage checks',flush=True)
        verify_storage(args.php,bootstrap,examples)
    print('PASS R15.24 baseline tail: final inspection and storage checks completed',flush=True)

if __name__=='__main__':main()
