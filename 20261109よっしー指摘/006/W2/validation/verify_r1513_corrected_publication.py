"""Run both response slots through submitted inspection, correction, candidate storage and the public read endpoint."""
import argparse
from datetime import timedelta
from hashlib import sha256
import json
from pathlib import Path
import subprocess
from tempfile import TemporaryDirectory

from verify_r1512_publication import PublicationTrial, ROLES
from verify_r1511_longshots import change_role
from verify_r157_receive import dump, require, db_time

HERE = Path(__file__).resolve().parent
PREVIOUS = '29caf003896cd7bd8ec8e52820da819895710978adb9b02ce7d99753d25a6a9f'


def change_raw(raw, changes):
    begin, body, end = raw.splitlines()
    reply = json.loads(body)
    horses = {row['horse_no']:row for row in reply['horses']}
    for number, field in changes:
        if field=='evidence':
            horses[number]['evidence'][0]['facts'][0]['value']='not the stored measurement'
        elif field=='blank':
            horses[number]['explanation']='　'
        elif field=='outside':
            horses[number]['explanation']+='（許可外の変更）'
        else:
            raise ValueError(field)
    return begin+'\n'+dump(reply)+'\n'+end


class CorrectedTrial(PublicationTrial):
    def action(self, cmd, **options):
        request=dict(cmd=cmd,clock=self.us(self.now),**options)
        result=subprocess.run([self.php,str(HERE/'r1512_publication.php'),str(self.path),'_',str(self.controller)],
                              input=dump(request),text=True,capture_output=True,check=True)
        require(not result.stderr,result.stderr)
        return json.loads(result.stdout)

    def inspect(self, role, with_grant=False):
        return self.action('inspect_correction',role=role,with_grant=with_grant)

    def correction(self, role, raw, complete=True, delayed=False):
        reserve=self.start+timedelta(seconds=4)
        token=self.worker('reserve',reserve,race=1,role=role,slot=1)
        require(token['ok'],token)
        token=token['token']
        sent=self.start+timedelta(seconds=5)
        require(self.worker('send',sent,token=token)['ok'],'send correction')
        self.db.execute('UPDATE baganriki_ai_attempts SET id=rowid,sent_at=? WHERE token=?',
                        (sent.isoformat(timespec='microseconds'),token))
        if delayed: return token
        got=self.worker('receive',self.now,token=token,raw=raw,complete=complete)
        require(got['ok'],got)
        return token

    def init_role(self, role, target, changes=()):
        row=self.role_rows[role]
        change_role(row,target)
        healthy=row['original']
        if changes:
            row['original']=change_raw(healthy,changes)
        self.put(role)
        return healthy

    def check_public(self, published, expected, role=None, bonus_allowed=True):
        require(published['code']=='PUBLISHED',published)
        rows=published['candidates'];picks=[row['horse_no'] for row in rows]
        require(len(picks)==len(set(picks)) and [n for n in picks if n>=7]==expected,(picks,expected))
        if role is not None:require(role not in published.get('role_issues',{}),published.get('role_issues'))
        if not bonus_allowed:require(all(row['bonus']==0 for row in rows),rows)
        saved=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',published['publication_id'])
        before=self.counts()
        dto=self.get(clock=self.us(self.now))['body']
        require(json.loads(saved)==rows and dto['state']=='PUBLISHED' and dto['publication']['publication_id']==published['publication_id'],dto)
        require(dto['live_state']['available_candidate_nos']==picks and dto['buy_recommended'] is False and self.counts()==before,dto)
        return picks

    def one(self, role, case, old=False):
        self.setup()
        self.now=self.start+timedelta(seconds=8)
        other=ROLES[1-ROLES.index(role)];target=12 if role=='1st' else 16
        changes={
            'unchanged':[], 'full':[(target,'evidence')], 'partial':[(target,'evidence'),(2,'blank')],
            'forbidden':[(2,'evidence')], 'unresolved':[(target,'evidence')],
            'incomplete':[(2,'evidence')], 'hash':[(2,'evidence')], 'late':[(2,'evidence')],
            'bad_initial':[(2,'evidence')], 'only_role':[(target,'evidence')]
        }[case]
        good=self.init_role(role,target,changes)
        if case!='only_role':self.init_role(other,None)
        before=self.publish_db()
        earlier=self.check_public(before,[] if (target,'evidence') in changes else [target],role)
        stored=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',before['publication_id'])
        raw=change_raw(good,[(2,'blank')]) if case=='partial' else (
              change_raw(good,[(4,'outside')]) if case in ('unresolved','forbidden') else good)
        self.correction(role,raw,complete=case!='incomplete')
        if case=='hash':self.db.execute('UPDATE baganriki_ai_attempts SET response_hash=? WHERE role=? AND attempt_no=1',('0'*64,role))
        if case=='late':
            late=self.sales-timedelta(seconds=90)+timedelta(microseconds=1)
            self.db.execute('UPDATE baganriki_ai_attempts SET received_at=? WHERE role=? AND attempt_no=1',(db_time(late),role))
            self.now=late
        if case=='bad_initial':
            self.db.execute('UPDATE baganriki_ai_attempts SET response_hash=? WHERE role=? AND attempt_no=0',('0'*64,role))
        diagnostic=self.inspect(role)
        code={'unchanged':'NO_FIX_NEEDED','full':'FIX_APPLIED','only_role':'FIX_APPLIED','partial':'FIX_PARTIAL',
              'forbidden':'FIX_REJECTED','unresolved':'FIX_REJECTED','incomplete':'FIX_NOT_USABLE',
              'hash':'FIX_NOT_USABLE','late':'FIX_NOT_USABLE','bad_initial':'FIRST_NOT_USABLE'}[case]
        require(diagnostic['code']==code,(role,case,diagnostic))
        if case in ('full','partial','only_role'):require(target in diagnostic['rescued'],diagnostic)
        pub=self.publish_db()
        if old:
            require(diagnostic['complete'] is None,diagnostic)
            if case=='only_role':require(pub['code']=='NO_RESPONSE',pub)
            else:self.check_public(pub,[])
        else:
            if case!='bad_initial':require(diagnostic['complete'] is True,diagnostic)
            if case=='bad_initial':
                require(not diagnostic['ok'],diagnostic)
                self.check_public(pub,[],bonus_allowed=False)
            else:
                self.check_public(pub,[] if case=='unresolved' else [target],role,
                                  bonus_allowed=case in ('full','unchanged'))
        require(self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',before['publication_id'])==stored,'historical list changed')
        return earlier,pub,diagnostic


    def from_journal(self, grants):
        from copy import deepcopy
        live=self.row()
        gone=json.loads(live[0]);packet=deepcopy(self.data)
        packet.update(send=False,now=self.now.isoformat(timespec='microseconds'),cancelled=gone,roles=[])
        for role in ROLES:
            item=deepcopy(self.role_rows[role]);rows=self.db.execute(
                'SELECT attempt_no,raw_response,response_hash,complete,sent_at,received_at FROM baganriki_ai_attempts WHERE role=? ORDER BY attempt_no',(role,)).fetchall()
            require(len(rows)==2,rows)
            for slot,raw,digest,complete,sent,received in rows:
                require(complete==1 and sha256(raw.encode()).hexdigest()==digest,'independent saved receipt')
                receipt=dict(item['receipt'],attempt_no=slot,response_hash=digest,complete=True,sent_at=sent,
                             received_at=received.replace(' ','T')+'+09:00',original_approved=slot==1)
                if slot==0:
                    item.update(receipt=receipt,original=raw)
                else:
                    receipt.pop('sent_request',None)
                    sent_fix=json.loads(self.scalar('SELECT sent FROM r1513_fix_dispatch WHERE role=?',role))
                    item['correction']=dict(receipt,raw=raw,repair_scope=grants[role]['scope'],repair_diagnostics=grants[role]['diagnostics'],**sent_fix)
            packet['roles'].append(item)
        packet['live']=dict(state_revision=live[3],response_revision=live[4],publication_revision=live[5],cancelled_horse_nos=gone,
                            race_id=packet['race_id'],snapshot_hash=packet['hash'],now=packet['now'],
                            live_close_at=None,all_cancelled=False,race_started=False)
        adopted=self.replay('adopt',packet);require(adopted['ok'],adopted)
        packet['live']['role_journal']=adopted['data']['role_journal']
        opened=self.replay('open',packet);require(opened['ok'],opened)
        selected=opened['data']['result']
        require(self.replay('commit',dict(packet,selection=selected,finished_at=packet['now']))['ok'],'W9 correction proof commit')
        return selected

    def both(self, order, old=False):
        self.setup();self.now=self.start+timedelta(seconds=8)
        healthy={};grants={};target=12
        for role in ROLES:
            healthy[role]=self.init_role(role,target,[(target,'evidence')])
            grants[role]=self.inspect(role,with_grant=True)
            require(self.action('settle',role=role)['marked'],'first adoption')
        self.db.execute('CREATE TABLE IF NOT EXISTS r1513_fix_dispatch(role TEXT PRIMARY KEY,sent TEXT)')
        for role in order:
            data=self.role_rows[role]
            first=self.scalar('SELECT raw_response FROM baganriki_ai_attempts WHERE role=? AND attempt_no=0',role)
            prepared=self.replay('record_correction_fixture',dict(
                common=self.data['common'],input=data['input'],hash=self.data['hash'],witness=self.data['witness'],role=role,
                original=first,format=data['receipt']['sent_request']['format'],scope=grants[role]['scope'],diagnostics=grants[role]['diagnostics']))
            require(prepared['ok'],prepared)
            self.db.execute('INSERT OR REPLACE INTO r1513_fix_dispatch(role,sent) VALUES(?,?)',(role,dump(prepared['data'])))
            token=self.correction(role,healthy[role],delayed=True)
            require(self.action('when_ready')['code']=='WAITING','wait while correcting')
            require(self.worker('receive',self.now,token=token,raw=healthy[role],complete=True)['ok'],'correction received')
            require(self.action('when_ready')['code']=='WAITING','re-read adoption of changed receipt')
            require(self.inspect(role)['code']=='FIX_APPLIED','approved correction')
            require(self.action('settle',role=role)['marked'],'changed adoption')
        expected=self.from_journal(grants);got=self.action('when_ready')
        if old:
            require(target in expected['public_candidates'] and got['code']=='NO_RESPONSE',(expected['public_candidates'],got['code']))
            print('R15.12 queue: both complete corrections individually accepted; W9 finds horse 12; public queue reports NO_RESPONSE.',flush=True)
            return
        def match(current, exp, holes):
            picks=self.check_public(current,holes)
            require(picks==exp['public_candidates'] and current['actual_quota']==exp['actual_quota'] and current['shortfalls']==exp['shortfalls'],(picks,exp))
            for row in current['candidates']:
                n=str(row['horse_no']);proof=exp['public_proof_hashes']
                require(row['bonus']==exp['agreement_bonus'][n] and row['public_proof_hash']==(proof.get(n) if isinstance(proof,dict) else None),row)
        match(got,expected,[target]);row=next(h for h in got['candidates'] if h['horse_no']==target)
        require(row['bonus']==5 and row['public_proof_hash']=={r:sha256(healthy[r].encode()).hexdigest() for r in ROLES},row)
        pid=got['publication_id'];saved=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',pid)
        require(self.update([target])['code']=='APPLIED','official withdrawal')
        dto=self.get(clock=self.us(self.now))['body']
        require(dto['publication']['publication_id']==pid and target not in dto['live_state']['available_candidate_nos'],dto)
        ref=self.from_journal(grants);next_pub=self.action('when_ready');match(next_pub,ref,[])
        require(next_pub['publication_id']!=pid and self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',pid)==saved,'keep old publication')
        print('PASS two complete corrections '+','.join(order)+': dated dispatch and receipt proof, queue readiness, exact W9 bonus and public list, then official withdrawal and immutable prior ID.',flush=True)

    def competing(self, role):
        self.setup();self.now=self.start+timedelta(seconds=8)
        other=ROLES[1-ROLES.index(role)];target=16 if role=='2nd' else 12
        healthy=self.init_role(role,target,[(target,'evidence')]);self.init_role(other,None)
        token=self.correction(role,healthy,delayed=True)
        response=self.publish_db(arrival={'token':token,'raw':healthy})
        require(response['code']=='STALE' and self.scalar('SELECT count(*) FROM baganriki_publications')==0,response)
        self.check_public(self.publish_db(),[target],role)
        print('PASS correction during commit '+role+': old calculation refused; corrected outsider persisted and returned.',flush=True)

    def confirmed_empty(self):
        self.empty()
        for role in ROLES:
            self.correction(role,self.role_rows[role]['original'])
            require(self.inspect(role)['code']=='NO_FIX_NEEDED','usable original')
        pub=self.publish_db();picks=self.check_public(pub,[])
        require(not picks and sum(i['missing'] for i in pub['shortfalls'])==7,pub)
        print('PASS zero eligible after two corrections: verified empty, seven unfilled positions and no buy.',flush=True)

    def upgrade(self, previous):
        current=self.controller
        require(sha256(previous.read_bytes()).hexdigest()==PREVIOUS,'R15.12 controller required')
        self.controller=previous
        try:
            _,old,_=self.one('1st','full',True)
        finally:self.controller=current
        old_id=old['publication_id'];old_rows=self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',old_id)
        ai_calls=self.scalar('SELECT count(*) FROM provider_stub')
        shown=self.get(clock=self.us(self.now))['body']
        require(shown['publication']['publication_id']==old_id and 12 not in shown['live_state']['available_candidate_nos'],shown)
        pub=self.publish_db();self.check_public(pub,[12],'1st')
        require(pub['publication_id']!=old_id and self.scalar('SELECT candidates FROM baganriki_publications WHERE publication_id=?',old_id)==old_rows,'history kept')
        require(self.scalar('SELECT count(*) FROM provider_stub')==ai_calls,'no extra provider calls')
        print('PASS R15.12 saved correction history kept; separately recalculated R15.13 ID restores horse 12 without another AI call.',flush=True)


def smoke(php,controller):
    with TemporaryDirectory() as temp:
        t=CorrectedTrial(str(php),Path(controller).resolve(),Path(temp))
        try:
            t.one('1st','only_role')
            t.one('2nd','incomplete')
        finally:t.db.close()
    print('PASS R15.13 standard: the only corrected role retains its outsider; incomplete correction preserves verified initial rows.',flush=True)


def boundaries(php,controller):
    with TemporaryDirectory() as temp:
        t=CorrectedTrial(php,controller,Path(temp))
        try:
            for role in ROLES:
                target=12 if role=='1st' else 16;other=ROLES[1-ROLES.index(role)]
                for fault in ('incomplete','late','hash'):
                    t.setup();t.now=t.start+timedelta(seconds=8)
                    healthy=t.init_role(role,target,[(target,'evidence')]);t.init_role(other,None)
                    token=t.correction(role,healthy,complete=fault!='incomplete')
                    if fault=='late':
                        late=t.sales-timedelta(seconds=90)+timedelta(microseconds=1)
                        t.db.execute('UPDATE baganriki_ai_attempts SET received_at=? WHERE token=?',(db_time(late),token));t.now=late
                    elif fault=='hash':
                        t.db.execute('UPDATE baganriki_ai_attempts SET response_hash=? WHERE token=?',('0'*64,token))
                    require(t.inspect(role)['code']=='FIX_NOT_USABLE',fault)
                    t.check_public(t.publish_db(),[],role,bonus_allowed=False)
            t.setup();t.now=t.start+timedelta(seconds=8)
            for role in ROLES:
                healthy=t.init_role(role,12,[(12,'evidence')]);t.correction(role,healthy)
            t.db.execute("UPDATE baganriki_ai_attempts SET complete=0 WHERE attempt_no=0")
            for role in ROLES:require(t.inspect(role)['code']=='FIRST_NOT_USABLE','invalid initial')
            result=t.publish_db()
            require(result['code']=='NO_RESPONSE' and t.scalar('SELECT count(*) FROM baganriki_publications')==0,result)
        finally:t.db.close()
    print('PASS six unusable corrections cannot restore an unverified outsider; two incomplete initial receipts cannot publish even with complete corrections.',flush=True)


def popularity(php,controller):
    with TemporaryDirectory() as temp:
        t=CorrectedTrial(php,controller,Path(temp))
        try:
            for role in ROLES:
                other=ROLES[1-ROLES.index(role)]
                for target in range(7,17):
                    t.setup();t.now=t.start+timedelta(seconds=8)
                    runners=json.loads(t.role_rows[role]['input'])['runners']
                    require(next(h['win_rank'] for h in runners if h['horse_no']==target)==target,'six-minute popularity')
                    good=t.init_role(role,target,[(target,'evidence')]);t.init_role(other,None)
                    before=t.publish_db();t.check_public(before,[],role)
                    t.correction(role,good)
                    require(t.inspect(role)['code']=='FIX_APPLIED','only the specified row repaired')
                    current=t.publish_db();t.check_public(current,[target],role)
                    row=next(h for h in current['candidates'] if h['horse_no']==target)
                    require(row['bonus']==0 and row['independent_groups']==1 and not row['both_public'],row)
                    require(t.update([target])['code']=='APPLIED','withdraw corrected outsider')
                    t.check_public(t.publish_db(),[],role)
                print('PASS all six-minute ranks 7 through 16, '+role+' only: ten sole-evidence outsiders enter after valid correction with zero agreement bonus; after official withdrawal none is replaced by an unsupported outsider.',flush=True)
        finally:t.db.close()


def run(php,controller,old=False,previous=None):
    if old:require(sha256(controller.read_bytes()).hexdigest()==PREVIOUS,'R15.12 controller required')
    with TemporaryDirectory() as temp:
        t=CorrectedTrial(php,controller,Path(temp))
        try:
            if previous:
                t.upgrade(previous);return
            if old:
                for case in ('unchanged','full','only_role'):
                    before,pub,diagnostic=t.one('1st',case,True)
                    print('R15.12', case,diagnostic['code'],'before',before,'after',pub['code'],[h['horse_no'] for h in pub['candidates']],flush=True)
                t.both(ROLES,True);return
            for r in ROLES:
                for case in ('unchanged','full','partial','forbidden','unresolved','incomplete','hash','late','bad_initial','only_role'):
                    t.one(r,case)
                print('PASS 10 submitted correction paths for '+r+': originals, complete and partial repairs, unauthorized edits, incomplete, late, corrupt initial or fix, one surviving role; saved/public lists agree.',flush=True)
            for order in (ROLES,ROLES[::-1]):t.both(order)
            for r in ROLES:t.competing(r)
            t.confirmed_empty()
        finally:t.db.close()


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('php');parser.add_argument('controller',type=Path)
    scope=parser.add_mutually_exclusive_group()
    scope.add_argument('--observe-r1512',action='store_true')
    scope.add_argument('--upgrade-from',type=Path)
    scope.add_argument('--boundaries',action='store_true')
    scope.add_argument('--popularity',action='store_true')
    args=parser.parse_args()
    if args.popularity:popularity(args.php,args.controller.resolve())
    elif args.boundaries:boundaries(args.php,args.controller.resolve())
    else:run(args.php,args.controller.resolve(),args.observe_r1512,args.upgrade_from.resolve() if args.upgrade_from else None)
