"""Single-host transactional store. Use one local persistent DB for all workers.
SQLite WAL is not a multi-server/network-filesystem locking solution.
"""
import sqlite3,hashlib
from datetime import datetime,timezone
import engine as e
import reference as r
from journal import Journal

class Store(Journal):
    def __init__(self,path,clock=None):
        self.db=sqlite3.connect(path,timeout=15,isolation_level=None)
        self.db.row_factory=sqlite3.Row;self.clock=clock or (lambda:datetime.now(timezone.utc).isoformat())
        self.db.execute('PRAGMA journal_mode=WAL');self.db.execute('PRAGMA synchronous=FULL');self.db.execute('PRAGMA foreign_keys=ON')
        self.db.executescript('''
        CREATE TABLE IF NOT EXISTS race_state(
          race_id TEXT PRIMARY KEY,snapshot_hash TEXT NOT NULL,generation INTEGER NOT NULL,
          state_revision INTEGER NOT NULL DEFAULT 0,publication_revision INTEGER NOT NULL DEFAULT 0,
          snapshot_json TEXT NOT NULL,cancelled_json TEXT NOT NULL DEFAULT '[]',live_close TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS publications(
          id INTEGER PRIMARY KEY AUTOINCREMENT,race_id TEXT NOT NULL,snapshot_hash TEXT NOT NULL,
          generation INTEGER NOT NULL,state_revision INTEGER NOT NULL,publication_revision INTEGER NOT NULL,
          job_id TEXT NOT NULL,request_hash TEXT NOT NULL,result_json TEXT NOT NULL,responses_json TEXT NOT NULL,
          created_at TEXT NOT NULL,UNIQUE(race_id,generation,job_id));
        CREATE TABLE IF NOT EXISTS events(
          id INTEGER PRIMARY KEY AUTOINCREMENT,race_id TEXT NOT NULL,event_json TEXT NOT NULL,created_at TEXT NOT NULL);
        ''')
        self.init_journal()
    def close(self):self.db.close()
    def state(self,race_id):
        row=self.db.execute('SELECT * FROM race_state WHERE race_id=?',(race_id,)).fetchone()
        e.require(row is not None,'snapshot not installed');return dict(row)
    def install(self,inp,generation):
        e.require(r.exact_int(generation) and generation>=1,'generation')
        e.validate_input(inp,self.clock());require_first=inp['ai_role']=='1st';e.require(require_first,'install first-role input')
        self.db.execute('BEGIN IMMEDIATE')
        try:
            row=self.db.execute('SELECT * FROM race_state WHERE race_id=?',(inp['race_id'],)).fetchone()
            if row:
                e.require(row['generation']==generation and row['snapshot_hash']==inp['snapshot_hash'],'immutable snapshot already installed; no automatic re-anchoring')
            else:
                self.db.execute('INSERT INTO race_state(race_id,snapshot_hash,generation,snapshot_json,live_close) VALUES(?,?,?,?,?)',
                    (inp['race_id'],inp['snapshot_hash'],generation,r.json_dumps(inp),inp['time_contract']['sales_close_at']))
            self.db.execute('COMMIT');return self.state(inp['race_id'])
        except Exception:self.db.execute('ROLLBACK');raise
    def update_live(self,race_id,expected_revision,cancelled,live_close=None):
        self.db.execute('BEGIN IMMEDIATE')
        try:
            st=self.state(race_id);e.require(type(expected_revision) is int and expected_revision==st['state_revision'],'stale live state')
            inp=r.strict_loads(st['snapshot_json']);r.cancellation_view(inp['runners'],cancelled,inp['mode'])
            old=set(r.strict_loads(st['cancelled_json']));e.require(old<=set(cancelled),'cancellation removal requires separate authoritative review')
            close=st['live_close'] if live_close is None else live_close
            close_time=r.timestamp(close);old_time=r.timestamp(st['live_close'])
            e.require(close_time<=old_time,'live close cannot be extended')
            # Check every value required by read/claim before committing the new state.
            r.publication_deadline(inp['time_contract']['publish_deadline'],close,inp['time_contract']['safety_margin_ms'])
            if old==set(cancelled) and close_time==old_time:
                self.db.execute('COMMIT');return self.state(race_id)
            rev=st['state_revision']+1
            self.db.execute('UPDATE race_state SET state_revision=?,cancelled_json=?,live_close=? WHERE race_id=?',
                (rev,r.json_dumps(sorted(set(cancelled))),close,race_id))
            self.db.execute('INSERT INTO events(race_id,event_json,created_at) VALUES(?,?,?)',(race_id,r.json_dumps(dict(type='LIVE_STATE',revision=rev,cancelled=sorted(set(cancelled)),live_close=close)),self.clock()))
            self.db.execute('COMMIT');return self.state(race_id)
        except Exception:self.db.execute('ROLLBACK');raise
    def publish(self,race_id,generation,state_revision,publication_revision,job_id,responses):
        return self._publish(race_id,generation,state_revision,publication_revision,job_id,responses,None)
    def publish_saved(self,race_id,generation,state_revision,publication_revision,response_revision,job_id):
        e.require(type(response_revision) is int and response_revision>=0,'response revision')
        return self._publish(race_id,generation,state_revision,publication_revision,job_id,None,response_revision)
    def _publish(self,race_id,generation,state_revision,publication_revision,job_id,responses,response_revision):
        e.require(isinstance(job_id,str) and 0<len(job_id)<=128,'job id')
        require_ints=(generation,state_revision,publication_revision)
        e.require(all(r.exact_int(v) and v>=0 for v in require_ints),'revision types')
        intent=dict(generation=generation,state_revision=state_revision,publication_revision=publication_revision)
        intent.update(dict(source='saved',response_revision=response_revision) if response_revision is not None else dict(responses=responses))
        request_hash=r.canonical_hash(intent)
        # Capture a consistent read snapshot, then release it before validation.
        # WAL allows live updates and response saves while this read is active.
        self.db.execute('BEGIN')
        try:
            previous=self.db.execute('SELECT * FROM publications WHERE race_id=? AND generation=? AND job_id=?',(race_id,generation,job_id)).fetchone()
            if previous:
                e.require(previous['request_hash']==request_hash,'job id reused with different input')
                cached=previous['result_json'];self.db.execute('COMMIT')
                return r.strict_loads(cached,max_bytes=64*1024*1024)
            st=self.state(race_id)
            e.require((generation,state_revision,publication_revision)==(st['generation'],st['state_revision'],st['publication_revision']),'stale publication job')
            observed_response_revision=self.response_revision(race_id,generation)
            if response_revision is not None:
                e.require(response_revision==observed_response_revision,'stale response revision')
                responses=self.saved_responses(race_id,generation)
            else:e.require(observed_response_revision==0,'journal-backed race requires publish_saved')
            inp=r.strict_loads(st['snapshot_json']);cancelled=r.strict_loads(st['cancelled_json'])
            self.db.execute('COMMIT')
        except Exception:
            if self.db.in_transaction:self.db.execute('ROLLBACK')
            raise
        result=e.resolve(inp,responses,cancelled,self.clock())
        # Compare all captured revisions again, inside the short write transaction.
        self.db.execute('BEGIN IMMEDIATE')
        try:
            previous=self.db.execute('SELECT * FROM publications WHERE race_id=? AND generation=? AND job_id=?',(race_id,generation,job_id)).fetchone()
            if previous:
                e.require(previous['request_hash']==request_hash,'job id reused with different input')
                cached=previous['result_json'];self.db.execute('COMMIT')
                return r.strict_loads(cached,max_bytes=64*1024*1024)
            current=self.state(race_id)
            e.require((generation,state_revision,publication_revision,st['snapshot_hash'])==
                (current['generation'],current['state_revision'],current['publication_revision'],current['snapshot_hash']),'stale publication job')
            e.require(observed_response_revision==self.response_revision(race_id,generation),'stale response revision')
            st=current;now=self.clock()
            e.require(r.timestamp(now)>=r.timestamp(result['verified_at']),'clock moved backwards during verification')
            result['verified_at']=now
            margin=inp['time_contract']['safety_margin_ms']
            e.require(r.can_publish(now=now,received_at=result['received_at'],verified_at=result['verified_at'],
                reply_deadline=inp['time_contract']['reply_deadline'],publish_deadline=inp['time_contract']['publish_deadline'],live_close=st['live_close'],
                snapshot_hash=result['snapshot_hash'],current_snapshot_hash=st['snapshot_hash'],state_revision=state_revision,current_state_revision=st['state_revision'],
                safety_margin_ms=margin),'publication deadline/state')
            result['effective_publish_deadline']=r.publication_deadline(inp['time_contract']['publish_deadline'],st['live_close'],margin).isoformat()
            rev=publication_revision+1
            result.update(generation=generation,state_revision=state_revision,publication_revision=rev,
                implementation_version='baganriki-v041-r12-1-quota-20261017')
            if response_revision is not None:result['response_revision']=response_revision
            cur=self.db.execute('INSERT INTO publications(race_id,snapshot_hash,generation,state_revision,publication_revision,job_id,request_hash,result_json,responses_json,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)',
                (race_id,st['snapshot_hash'],generation,state_revision,rev,job_id,request_hash,'{}',r.json_dumps(responses),now))
            result['publication_id']=cur.lastrowid
            result_json=r.json_dumps(result)
            e.require(len(result_json.encode('utf-8'))<=64*1024*1024,'publication size limit')
            self.db.execute('UPDATE publications SET result_json=? WHERE id=?',(result_json,cur.lastrowid))
            self.db.execute('UPDATE race_state SET publication_revision=? WHERE race_id=?',(rev,race_id))
            commit_at=r.timestamp(self.clock())
            e.require(commit_at>=r.timestamp(now),'clock moved backwards before commit')
            e.require(commit_at<=r.publication_deadline(inp['time_contract']['publish_deadline'],st['live_close'],margin)
                and commit_at<r.timestamp(st['live_close']),'deadline elapsed before commit')
            self.db.execute('COMMIT');return result
        except Exception:
            if self.db.in_transaction:self.db.execute('ROLLBACK')
            raise
    def read(self,race_id):
        self.db.execute('BEGIN')
        try:
            st=self.state(race_id)
            row=self.db.execute('SELECT result_json FROM publications WHERE race_id=? AND generation=? ORDER BY publication_revision DESC LIMIT 1',(race_id,st['generation'])).fetchone()
            result=r.strict_loads(row['result_json'],max_bytes=64*1024*1024) if row else None
            inp=r.strict_loads(st['snapshot_json'])
            overlay=r.cancellation_view(inp['runners'],r.strict_loads(st['cancelled_json']),inp['mode'])
            view=dict(publication=result,input_audit=dict(snapshot_hash=st['snapshot_hash'],coverage=inp['coverage']),
                worker_state=dict(response_revision=self.response_revision(race_id,st['generation']),slots=self._summary(self._rows(race_id,st['generation']))),
                live_state=dict(active_count=overlay['active_count'],publication_target_count=overlay['publication_target_count'],publication_quota=overlay['publication_quota'],generation=st['generation'],publication_revision=st['publication_revision'],state_revision=st['state_revision'],cancelled=r.strict_loads(st['cancelled_json']),live_close=st['live_close'],
                    effective_publish_deadline=r.publication_deadline(inp['time_contract']['publish_deadline'],st['live_close'],inp['time_contract']['safety_margin_ms']).isoformat()))
            self.db.execute('COMMIT');return view
        except Exception:self.db.execute('ROLLBACK');raise
