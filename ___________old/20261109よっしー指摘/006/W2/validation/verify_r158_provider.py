"""Run the submitted sender with local provider, database and clock fixtures."""
from pathlib import Path
from tempfile import TemporaryDirectory
import argparse
import subprocess

HERE = Path(__file__).resolve().parent

def run(php, controller):
    with TemporaryDirectory() as folder:
        subprocess.run([php, str(HERE/'verify_w2.php'), '--examples', folder, '--sdk-fixture'],
                       check=True, stdout=subprocess.DEVNULL)
        subprocess.run([php, str(HERE/'r158_provider.php'), str(Path(controller).resolve()), folder], check=True)

if __name__ == '__main__':
    cli = argparse.ArgumentParser(description=__doc__)
    cli.add_argument('php')
    cli.add_argument('patched_controller')
    args = cli.parse_args()
    run(args.php, args.patched_controller)
