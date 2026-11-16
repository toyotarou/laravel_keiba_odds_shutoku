"""Reproduce the checks selected for R15.24; no external AI or deployed database."""
from pathlib import Path
import argparse, subprocess, sys

def main():
    root = Path(__file__).resolve().parent
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--submitted-php', type=Path, default=root.parent/'integration/reference/AiController.php')
    args = parser.parse_args()
    cases = [('verify_release.py', ['--stress-seed', '20261109']),
             ('verify_r1519.py', ['--quick']), ('verify_r1523.py', ['--quick']),
             ('verify_r1524.py', [])]
    for name, extra in cases:
        print('Running ' + name + (' ' + ' '.join(extra) if extra else ''), flush=True)
        subprocess.run([sys.executable, '-u', str(root/name), '--php', args.php,
                        '--submitted-php', str(args.submitted_php.resolve()), *extra], check=True)
    print('PASS R15.24 selected offline checks; deployed paths remain unverified', flush=True)

if __name__ == '__main__':
    main()
