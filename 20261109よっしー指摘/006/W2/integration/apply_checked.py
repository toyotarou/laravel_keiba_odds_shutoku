"""Apply only to a recognized reference controller, into a new destination; never overwrite a live file."""
from pathlib import Path
from tempfile import TemporaryDirectory
from hashlib import sha256
import argparse,json,shutil,subprocess

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--source',type=Path,required=True)
    p.add_argument('--destination',type=Path,required=True)
    p.add_argument('--php',default='php')
    a=p.parse_args();root=Path(__file__).resolve().parent
    manifest=json.loads((root/'controller_bases.json').read_text())
    digest=sha256(a.source.read_bytes()).hexdigest()
    patches=manifest['accepted_bases'].get(digest)
    if patches is None:p.error('Unknown controller SHA-256: merge against the actual current source, then rerun the release suite. No file changed.')
    if a.destination.exists():p.error('Destination exists. Choose a new output file. No file changed.')
    with TemporaryDirectory() as folder:
        tmp=Path(folder);target=tmp/'AiController.php';shutil.copy2(a.source,target)
        for name in patches:
            subprocess.run(['patch','--batch','--silent','--fuzz=0','-p1','-i',str(root/name)],cwd=tmp,check=True)
        payload=target.read_bytes()
        if sha256(payload).hexdigest()!=manifest['result_sha256']:raise RuntimeError('Patched bytes disagree with the reviewed result')
        subprocess.run([a.php,'-l',str(target)],check=True,capture_output=True)
        # Exclusive creation handles a concurrent caller without overwriting their work.
        with a.destination.open('xb') as out:out.write(payload)
    print(json.dumps({'file':str(a.destination.resolve()),'sha256':manifest['result_sha256']},ensure_ascii=False))

if __name__=='__main__':main()
