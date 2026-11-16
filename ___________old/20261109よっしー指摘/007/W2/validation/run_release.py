"""Reproduce the baseline and R15.19 through R15.24 regressions without a live server."""
from pathlib import Path
import argparse,subprocess,sys

def main():
    root=Path(__file__).resolve().parent
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--php',default='php')
    p.add_argument('--submitted-php',type=Path,default=root.parent/'integration/reference/AiController.php')
    a=p.parse_args()
    for file in ['verify_release.py','verify_r1519.py','verify_r1520.py','verify_r1521.py','verify_r1522.py','verify_r1523.py','verify_r1524.py']:
        print('Running '+file,flush=True)
        cmd=[sys.executable,'-u',str(root/file),'--php',a.php,'--submitted-php',str(a.submitted_php.resolve())]
        if file=='verify_release.py':cmd+=['--stress-seed','20261109']
        subprocess.run(cmd,check=True)
    print('PASS R15.24 complete offline verification; deployment checks remain separate',flush=True)

if __name__=='__main__':main()
