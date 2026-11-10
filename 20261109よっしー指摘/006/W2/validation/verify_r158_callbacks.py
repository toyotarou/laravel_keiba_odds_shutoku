"""Replay delivery order, terminal completion and deadlines against the submitted Queue code."""
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from datetime import timedelta
from hashlib import sha256
from pathlib import Path
from tempfile import TemporaryDirectory
import argparse
import json
import subprocess
from copy import deepcopy
from verify_r157_receive import Trial, ROLES, db_time, require

STATE = 'SELECT status, raw_response, response_hash, complete, received_at FROM baganriki_ai_attempts WHERE token=?'

def revision(t):
    return t.scalar('SELECT response_revision FROM baganriki_live_states')

def count_events(t):
    return t.scalar("SELECT count(*) FROM baganriki_audit_events WHERE kind='QUEUE_RECEIVED'")

def verify_duplicates(t):
    stats=Counter()
    for slot in [0,1]:
        for kind in ['valid_crlf','cutoff_text','composite','malformed']:
            for change in ['unchanged','one_removed','all_removed']:
                for delay in [-1,0,1]:
                    t.reset()
                    if slot: t.initial()
                    tokens={r:t.allowed(r,slot) for r in ROLES}
                    at=t.start+timedelta(seconds=3+2*slot)
                    t.set_close(at+timedelta(seconds=90))
                    if change=='one_removed': t.withdraw([t.nums[0]])
                    if change=='all_removed': t.withdraw(t.nums)
                    when=at+timedelta(microseconds=delay)
                    for role,token in tokens.items():
                        raw=t.rows[role]['original']
                        payload={'valid_crlf':raw+'\r\n', 'cutoff_text':raw[:132],
                                 'composite':raw+'\n'+raw, 'malformed':'\x00'+raw}[kind]
                        complete=kind!='cutoff_text'
                        want='ALL_CANCELLED' if change=='all_removed' else 'DEADLINE_PASSED' if delay>0 else 'OK'
                        before=t.db.execute(STATE,(token,)).fetchone();rev=revision(t);events=count_events(t)
                        send=lambda data,done:t.worker('receive',when,token=token,raw=data,complete=done)['code']
                        replies=[send(payload,complete),send(payload,complete),send(raw,complete),send(payload,not complete)]
                        require(replies==([want,'IDEMPOTENT','RESPONSE_MISMATCH','RESPONSE_MISMATCH'] if want=='OK' else [want]*4),
                                (role,slot,kind,change,delay,replies))
                        accepted=int(want=='OK')
                        stored=t.db.execute(STATE,(token,)).fetchone()
                        wanted=('RECEIVED',payload,sha256(payload.encode()).hexdigest(),int(complete),db_time(when))
                        require(stored==(wanted if accepted else before),(role,slot,kind,'receipt bytes/clock'))
                        require(revision(t)==rev+accepted and count_events(t)==events+accepted,'replay created an additional receipt')
                        require(t.scalar('SELECT count(*) FROM provider_stub WHERE token=?',token)==1,'repeat provider dispatch')
                        stats[want]+=1
    print(f'PASS {sum(stats.values())} callback cases, four notifications per role/attempt and case: {dict(sorted(stats.items()))}. First exact bytes, completion and timing retained; no extra provider call.')

def verify_races(t):
    count=0
    with ThreadPoolExecutor(max_workers=4) as pool:
        for slot in [0,1]:
            for role in ROLES:
                t.reset()
                if slot:t.initial()
                token=t.allowed(role,slot);when=t.start+timedelta(seconds=3+2*slot)
                source=t.rows[role]['original']
                # Any one identity can win. A notification with different bytes or completion cannot replace it.
                options=[(source+'\r\n',True),(source,True),(source,False),(source[:132],False)]
                rev=revision(t);events=count_events(t)
                def notify(value):
                    body,complete=value
                    return t.worker('receive',when,token=token,raw=body,complete=complete)['code']
                codes=list(pool.map(notify,options));require(Counter(codes)==Counter(OK=1,RESPONSE_MISMATCH=3),codes)
                winner=codes.index('OK')
                body,complete=options[winner]
                require(t.db.execute(STATE,(token,)).fetchone()==('RECEIVED',body,sha256(body.encode()).hexdigest(),int(complete),db_time(when)),'winning identity changed')
                t.withdraw(t.nums)
                replay=list(pool.map(notify,options))
                require(replay==['IDEMPOTENT' if i==winner else 'RESPONSE_MISMATCH' for i in range(4)],replay)
                require(revision(t)==rev+1 and count_events(t)==events+1,'collision produced extra receipt')
                require(t.scalar('SELECT count(*) FROM provider_stub WHERE token=?',token)==1,'concurrent redispatch')
                count+=1
    print(f'PASS {count} competing-callback groups: four different payload/completion pairs per token; exactly one retained, stable after full withdrawal.')

def provider_to_publication(t):
    here=Path(__file__).resolve().parent
    file=t.directory/'callback_output.json'
    subprocess.run([t.php,str(here/'r158_provider.php'),str(t.controller),str(t.directory),'--export-callbacks',str(file)],
                   stdout=subprocess.DEVNULL,check=True)
    sent=json.loads(file.read_text())
    healthy={'plain','crlf','one_lf','split_text'}
    outcomes=Counter()
    tests=[('crlf','one_lf',[]),('split_text','plain',[1]),
           ('suffix_vtab','one_lf',[]),('prefix_nul','plain',[2]),
           ('plain','limit_partial',[]),('crlf','truncated_body',[10,12]),
           ('limit_full','one_lf',[1,7]),('plain','missing_finish',[1,12]),
           ('prefix_nul','suffix_space',[]),('limit_full','array_content',[1])]
    for first,second,withdrawn in tests:
        t.reset()
        tokens={r:t.allowed(r,0) for r in ROLES}
        when=t.start+timedelta(seconds=3)
        t.withdraw(withdrawn)
        packet=deepcopy(t.data);packet['roles']=[]
        for role,case in zip(ROLES,(first,second)):
            value=sent[role][case];kw=dict(token=tokens[role],raw=value['raw'],complete=value['complete'])
            require(t.worker('receive',when,**kw)['code']=='OK',(role,case,'receive'))
            require(t.worker('receive',when,**kw)['code']=='IDEMPOTENT',(role,case,'repeat'))
            status,raw,digest,complete,clock=t.db.execute(STATE,(tokens[role],)).fetchone()
            require(digest==value['hash']==sha256(raw.encode()).hexdigest() and raw==value['raw'],'provider to database bytes')
            row=deepcopy(t.rows[role]);row['original']=raw
            row['receipt'].update(response_hash=digest,complete=complete,received_at=clock.replace(' ','T')+'+09:00',status=status)
            packet['roles'].append(row)
        rev,rr,pr=t.db.execute('SELECT state_revision,response_revision,publication_revision FROM baganriki_live_states').fetchone()
        stamp=when.isoformat(timespec='microseconds')
        packet.update(now=stamp,send=False,cancelled=withdrawn,live=dict(state_revision=rev,response_revision=rr,publication_revision=pr,
                      cancelled_horse_nos=withdrawn,race_id=packet['race_id'],snapshot_hash=packet['hash'],now=stamp,
                      live_close_at=None,all_cancelled=False,race_started=False))
        packet['live'].update(t.replay('adopt',packet)['data'])
        opened=t.replay('open',packet)
        valid=int(first in healthy)+int(second in healthy)
        if valid:
            require(opened['ok'],('expected valid',first,second,opened))
            selection=opened['data']['result'];picks=selection['public_candidates']
            require(picks and len(picks)==len(set(picks)) and not set(picks).intersection(withdrawn),'published invalid horse')
            require(t.replay('commit',dict(packet,selection=selection,finished_at=stamp))['ok'],'commit')
            if valid==1:
                require(not any(selection['agreement_bonus'].values()) and not selection['public_proof_hashes'],'false agreement')
            else:
                require(bool(selection['public_proof_hashes']),'expected agreement')
                for hashes in selection['public_proof_hashes'].values():
                    require(hashes=={r:sent[r][case]['hash'] for r,case in zip(ROLES,(first,second))},'provider proof drift')
        else:
            require(not opened['ok'] and opened['reason']=='W2_NO_VERIFIED_ROLE',('published without a valid role',opened))
        require(t.scalar('SELECT count(*) FROM provider_stub')==2 and count_events(t)==2,'duplicate dispatch or receipt')
        outcomes[valid]+=1
    print(f'PASS {len(tests)} provider-to-SQLite-to-publication replays, two callbacks per role; accepted role counts {dict(sorted(outcomes.items()))}.')

def run(php,controller):
    with TemporaryDirectory() as folder:
        trial=Trial(php,Path(controller).resolve(),Path(folder))
        try:
            verify_duplicates(trial)
            verify_races(trial)
            provider_to_publication(trial)
        finally:trial.db.close()

if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('php');p.add_argument('patched_controller')
    args=p.parse_args();run(args.php,args.patched_controller)
