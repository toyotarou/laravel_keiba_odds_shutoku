"""Internal worker CLI; raw JSON on stdin. Never expose directly as an unauthenticated endpoint."""
import argparse,sys,json
from datetime import datetime,timezone
import reference as r
import engine as e
from store import Store

def main():
    p=argparse.ArgumentParser();p.add_argument('--db');p.add_argument('--request-file');args=p.parse_args()
    limit=64*1024*1024
    if args.request_file:
        with open(args.request_file,encoding='utf-8') as stream:raw=stream.read(limit+1)
    else:raw=sys.stdin.read(limit+1)
    req=r.strict_loads(raw,max_bytes=limit);action=req['action']
    now=datetime.now(timezone.utc).isoformat()
    if 'input_json' in req:req['input']=r.strict_loads(req['input_json'])
    if 'seed_json' in req:req['seed']=r.strict_loads(req['seed_json'])
    if action=='prepare':return e.prepare(req['seed'],now)
    if action=='prepare_export':
        from adapter import seed_from_export
        if 'export_json' in req:req['export']=r.strict_loads(req['export_json'])
        return e.prepare(seed_from_export(req['export']),now)
    if action=='inspect':return e.inspect_for_correction(req['input'],req['text'],req['received_at'])
    if action=='validate':return dict(valid=bool(e.validate_input(req['input'],now)))
    if action=='replay':
        result=e.resolve(req['input'],req['responses'],req.get('cancelled',[]),req['replay_at'])
        result['replay_only']=True;return result
    if not args.db:raise ValueError('--db required for state operations')
    store=Store(args.db)
    try:
        if action=='install':
            st=store.install(req['input'],req['generation']);return {k:st[k] for k in ('race_id','snapshot_hash','generation','state_revision','publication_revision')}
        if action=='publish':return store.publish(req['race_id'],req['generation'],req['state_revision'],req['publication_revision'],req['job_id'],req['responses'])
        if action=='publish_saved':return store.publish_saved(req['race_id'],req['generation'],req['state_revision'],req['publication_revision'],req['response_revision'],req['job_id'])
        if action=='claim':return store.claim(req['input'],req['generation'],req['system'])
        if action=='complete':return store.complete(req['token'],req['text'],req['received_at'],req['complete'])
        if action=='fail':return store.fail(req['token'],req['error_code'])
        if action=='worker_state':return store.worker_state(req['input'],req['generation'])
        if action=='live':
            st=store.update_live(req['race_id'],req['expected_revision'],req['cancelled'],req.get('live_close'))
            return {k:st[k] for k in ('race_id','state_revision','publication_revision','live_close')}
        if action=='read':return store.read(req['race_id'])
        raise ValueError('unknown action')
    finally:store.close()

if __name__=='__main__':
    try:print(r.json_dumps(dict(ok=True,result=main())))
    except Exception as exc:
        print(json.dumps(dict(ok=False,error=type(exc).__name__,message=str(exc)[:1000]),ensure_ascii=False));sys.exit(1)
