"""Independent canonical source-market witness (decimal, UTC, runner identity, adoption and missing values)."""
from copy import deepcopy
from datetime import datetime, timezone
from decimal import Decimal
from hashlib import sha256
import json
from pathlib import Path
import random
import subprocess

SLOTS = (21, 18, 15, 12, 9, 6)
FIELDS = {'win_odds', 'place_min', 'place_max'}

def digest(market):
    def stamp(timestamp):
        return datetime.fromisoformat(timestamp.replace('Z', '+00:00')).astimezone(timezone.utc).strftime('%Y-%m-%dT%H:%M:%S.%fZ')
    def odd(raw):
        if raw is None:
            return None
        value=Decimal(str(raw))
        digits=''.join(str(x) for x in value.as_tuple().digits)
        return str(value.adjusted()+1)+':'+digits.rstrip('0').ljust(128,'0')
    ranks={str(r['horse_no']):r['win_rank'] for r in sorted(market['runners'],key=lambda r:r['horse_no'])}
    batches={}
    for slot in SLOTS:
        row=market['collection_audit'][str(slot)]
        batches[str(slot)] = None if row['status']=='UNAVAILABLE' else [row['batch_id'],row['payload_hash'],
            stamp(row['request_started_at']),stamp(row['response_received_at']),stamp(row['available_at']),
            stamp(row['source_updated_at']) if row['source_updated_at'] else None]
    # Read the declared per-horse series in the independent representation (PHP walks the registry).
    quotes={}
    for key,refs in market['input_indices'].items():
        pieces=key.split('/')
        if len(pieces)==4 and pieces[0]=='horse' and pieces[2] in FIELDS and refs:
            ref,=refs
            quotes[key]=[ref,odd(market['source_registry'][ref]['value'])]
    payload=['W8_FROZEN_MARKET_V1',market['race_id'],stamp(market['input_freeze_at']),ranks,batches,dict(sorted(quotes.items()))]
    return sha256(json.dumps(payload,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()

def run(php,examples,seed):
    base=json.loads((examples/'1st_input.json').read_text())
    sealed=json.loads(json.loads((examples/'expected_proof.json').read_text())['json'])
    expected=digest(base)
    if expected!=sealed['market_source_sha256']:
        raise AssertionError('separately computed digest mismatches genuine attach witness')
    rng=random.Random(seed);seen=set()
    with subprocess.Popen([php,'-n',str(Path(__file__).with_name('r8_market_probe.php'))],stdin=subprocess.PIPE,stdout=subprocess.PIPE,text=True) as worker:
        for i in range(48):
            x=deepcopy(base)
            for j in range(4):
                slot=rng.choice(SLOTS);no=rng.randint(1,base['N']);key=f'horse/{no}/place_max/{slot}'
                row=x['collection_audit'][str(slot)]
                if j==0:
                    # Missing reference, observed null and exact decimal above the minimum vary independently.
                    if key in x['input_indices']:
                        ref=x['input_indices'][key][0]
                        if i%3==0:
                            del x['source_registry'][ref];del x['input_indices'][key]
                        else:
                            x['source_registry'][ref]['value']=None if i%3==1 else f'{20+no}.000000000000000000001'
                elif j==1:
                    row['source_updated_at']=row['request_started_at']
                elif j==2:
                    row['payload_hash']=sha256(f'{seed}-{i}'.encode()).hexdigest()
                else:
                    row['batch_id']+='-実測/'+str(i)
            rng.shuffle(x['runners'])
            for key in ['source_registry','input_indices','collection_audit']:
                items=list(x[key].items());rng.shuffle(items);x[key]=dict(items)
            want=digest(x);seen.add(want)
            worker.stdin.write(json.dumps(x,ensure_ascii=False,separators=(',',':'))+'\n');worker.stdin.flush()
            found=worker.stdout.readline().strip()
            if found!=want:
                raise AssertionError(('source projection',i,want,found))
        worker.stdin.close()
        if worker.wait()!=0 or len(seen)!=48:
            raise AssertionError('market test coverage')
    print(f'PASS R8 independent market proof: fixture plus 48 unique six-slot books with missing, null, precise prices and reordered references (seed {seed}).')
