"""Durable, single-host AI call grants. No lease expiry can regrant a call.

A committed RESERVED slot with an unknown provider outcome stays uncertain.
This bounds dispatches; it does not claim exactly-once delivery to a provider.
"""
import secrets
import engine as e
import reference as r

MAX_RESPONSE_BYTES = 4 * 1024 * 1024

class Journal:
    def init_journal(self):
        self.db.executescript('''
        CREATE TABLE IF NOT EXISTS ai_attempts(
          race_id TEXT NOT NULL,generation INTEGER NOT NULL,ai_role TEXT NOT NULL,
          attempt_no INTEGER NOT NULL CHECK(attempt_no IN (0,1)),
          snapshot_hash TEXT NOT NULL,token TEXT NOT NULL UNIQUE,
          status TEXT NOT NULL CHECK(status IN ('RESERVED','RECEIVED','FAILED')),
          claimed_at TEXT NOT NULL,call_deadline TEXT NOT NULL,
          received_at TEXT,response_text TEXT,complete INTEGER,payload_hash TEXT,error_code TEXT,
          PRIMARY KEY(race_id,generation,ai_role,attempt_no),
          FOREIGN KEY(race_id) REFERENCES race_state(race_id));
        ''')

    def response_revision(self, race_id, generation):
        # Every grant and every terminal transition changes this revision.
        return self.db.execute("SELECT COALESCE(SUM(1 + (status != 'RESERVED')),0) FROM ai_attempts WHERE race_id=? AND generation=?",(race_id,generation)).fetchone()[0]

    def _rows(self, race_id, generation, role=None):
        query='SELECT * FROM ai_attempts WHERE race_id=? AND generation=?'
        args=[race_id,generation]
        if role is not None:query+=' AND ai_role=?';args.append(role)
        return [dict(x) for x in self.db.execute(query+' ORDER BY ai_role,attempt_no',args)]

    def _input(self, inp, generation):
        e.require(type(generation) is int and generation>=1,'generation')
        e.validate_input(inp,min(self.clock(),inp['time_contract']['reply_deadline'],key=r.timestamp))
        st=self.state(inp['race_id'])
        e.require((generation,inp['snapshot_hash'])==(st['generation'],st['snapshot_hash']),'stale worker snapshot')
        frozen=r.strict_loads(st['snapshot_json']);role=inp['ai_role']
        frozen['ai_role']=role
        frozen['role_cap'],frozen['role_quota']=r.role_quota(frozen['quota'],frozen['target_count'],role)
        e.require(r.canonical_hash(frozen)==r.canonical_hash(inp),'worker input differs from frozen snapshot')
        return st

    @staticmethod
    def _attempts(rows):
        return [dict(text=x['response_text'],received_at=x['received_at'],complete=bool(x['complete']))
                for x in rows if x['status']=='RECEIVED']

    def _summary(self, rows):
        now=r.timestamp(self.clock()) if any(x['status']=='RESERVED' for x in rows) else None
        return [dict(ai_role=x['ai_role'],attempt_no=x['attempt_no'],status=x['status'],
                     outcome_unknown=x['status']=='RESERVED' and now>=r.timestamp(x['call_deadline']),
                     claimed_at=x['claimed_at'],call_deadline=x['call_deadline'],received_at=x['received_at'],
                     complete=None if x['complete'] is None else bool(x['complete']),error_code=x['error_code']) for x in rows]

    def worker_state(self, inp, generation):
        self.db.execute('BEGIN')
        try:
            self._input(inp,generation);rows=self._rows(inp['race_id'],generation,inp['ai_role'])
            out=dict(attempts=self._attempts(rows),slots=self._summary(rows))
            self.db.execute('COMMIT');return out
        except Exception:self.db.execute('ROLLBACK');raise

    def claim(self, inp, generation, system):
        e.require(inp.get('ai_role') in ('1st','2nd'),'AI role')
        e.require(system==(e.ROOT/f"prompt_{inp['ai_role']}.txt").read_text(),'system prompt differs from installed protocol')
        # Immutable input checks and model diagnostics can be expensive. Do not
        # hold the SQLite writer while the other role is trying to save a reply.
        st=self._input(inp,generation);race=inp['race_id'];role=inp['ai_role'];tc=inp['time_contract']
        rows=self._rows(race,generation,role)
        signature=lambda items:[(x['attempt_no'],x['token'],x['status']) for x in items]
        observed=signature(rows);reason=None;user='INPUT_JSON\n'+r.json_dumps(inp)
        deadline=min(r.timestamp(tc['reply_deadline']),r.publication_deadline(tc['publish_deadline'],st['live_close'],tc['safety_margin_ms']))
        if r.timestamp(self.clock())>=deadline:reason='DEADLINE'
        elif len(rows)>=2:reason='BUDGET_EXHAUSTED'
        elif rows:
            first=rows[0]
            if first['status']!='RECEIVED':reason='INITIAL_OUTCOME_PENDING_OR_FAILED'
            elif r.timestamp(first['received_at'])>r.timestamp(tc['reply_deadline']):reason='LATE_INITIAL'
            else:
                inspect_text=first['response_text'] if first['complete'] else 'INCOMPLETE_MODEL_RESPONSE'
                check=e.inspect_for_correction(inp,inspect_text,first['received_at'])
                if not check['needs_correction']:reason='COMPLETE'
                else:user+='\nINITIAL_RESPONSE\n'+first['response_text']+'\n'+check['request']
        self.db.execute('BEGIN IMMEDIATE')
        try:
            st=self.state(race)
            e.require((generation,inp['snapshot_hash'])==(st['generation'],st['snapshot_hash']),'stale worker snapshot')
            current=self._rows(race,generation,role)
            deadline=min(r.timestamp(tc['reply_deadline']),r.publication_deadline(tc['publish_deadline'],st['live_close'],tc['safety_margin_ms']))
            now=self.clock()
            prior_times=[r.timestamp(x[key]) for x in current for key in ('claimed_at','received_at') if x[key] is not None]
            if len(r.strict_loads(st['cancelled_json']))==inp['N']:reason='ALL_CANCELLED'
            elif r.timestamp(now)>=deadline:reason='DEADLINE'
            elif prior_times and r.timestamp(now)<max(prior_times):reason='CLOCK_ROLLBACK'
            elif signature(current)!=observed:reason='STATE_CHANGED'
            if reason:
                out=dict(granted=False,reason=reason,attempts=self._attempts(current),slots=self._summary(current))
            else:
                token=secrets.token_hex(32);index=len(current)
                self.db.execute('INSERT INTO ai_attempts(race_id,generation,ai_role,attempt_no,snapshot_hash,token,status,claimed_at,call_deadline) VALUES(?,?,?,?,?,?,\'RESERVED\',?,?)',
                    (race,generation,role,index,st['snapshot_hash'],token,now,deadline.isoformat()))
                out=dict(granted=True,token=token,attempt_no=index,user=user,call_deadline=deadline.isoformat())
            self.db.execute('COMMIT');return out
        except Exception:
            if self.db.in_transaction:self.db.execute('ROLLBACK')
            raise

    def complete(self, token, text, received_at, complete):
        e.require(isinstance(text,str) and len(text.encode('utf-8'))<=MAX_RESPONSE_BYTES,'response size/UTF-8/type')
        e.require(type(complete) is bool,'completion flag must be boolean')
        payload_hash=r.canonical_hash(dict(text=text,received_at=received_at,complete=complete))
        self.db.execute('BEGIN IMMEDIATE')
        try:
            row=self.db.execute('SELECT * FROM ai_attempts WHERE token=?',(token,)).fetchone()
            e.require(row is not None,'unknown call token')
            if row['status']=='RECEIVED':
                e.require(row['payload_hash']==payload_hash,'conflicting response for completed call')
            else:
                e.require(row['status']=='RESERVED','call already failed')
                e.require(r.timestamp(row['claimed_at'])<=r.timestamp(received_at)<=r.timestamp(self.clock()),'receipt outside dispatch/current time')
                # Keep even late replies for audit; resolve enforces reply/publication deadlines.
                self.db.execute("UPDATE ai_attempts SET status='RECEIVED',response_text=?,received_at=?,complete=?,payload_hash=? WHERE token=?",
                    (text,received_at,int(complete),payload_hash,token))
            rev=self.response_revision(row['race_id'],row['generation'])
            self.db.execute('COMMIT');return dict(saved=True,response_revision=rev)
        except Exception:self.db.execute('ROLLBACK');raise

    def fail(self, token, error_code):
        e.require(error_code in ('PROVIDER_ERROR','DEADLINE_BEFORE_CALL','INVALID_PROVIDER_REPLY'),'failure code')
        self.db.execute('BEGIN IMMEDIATE')
        try:
            row=self.db.execute('SELECT * FROM ai_attempts WHERE token=?',(token,)).fetchone()
            e.require(row is not None,'unknown call token')
            if row['status']=='FAILED':e.require(row['error_code']==error_code,'conflicting failure')
            else:
                e.require(row['status']=='RESERVED','cannot fail a completed response')
                self.db.execute("UPDATE ai_attempts SET status='FAILED',error_code=? WHERE token=?",(error_code,token))
            self.db.execute('COMMIT');return dict(saved=True)
        except Exception:self.db.execute('ROLLBACK');raise

    def saved_responses(self, race_id, generation):
        return {role:self._attempts(self._rows(race_id,generation,role)) for role in ('1st','2nd')}

