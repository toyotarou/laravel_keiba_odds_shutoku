import sys,json
from pathlib import Path
from datetime import datetime,timezone,timedelta
root=Path(__file__).resolve().parents[1];sys.path.insert(0,str(root))
import fixtures as f,engine as e,reference as r
import copy
from test_audit_regressions import set_orders
now=datetime.now(timezone.utc);seed=f.seed();delta=now-r.timestamp(f.REQUEST)
def shift(t):return (r.timestamp(t)+delta).isoformat()
seed['cutoff_at']=shift(seed['cutoff_at'])
for key in ('anchor_at','reply_deadline','publish_deadline','sales_close_at'):seed['time_contract'][key]=shift(seed['time_contract'][key])
for source in seed['source_registry'].values():
 for key in ('observed_at','available_at'):source[key]=shift(source[key])
p=e.prepare(seed,now.isoformat());first=f.output(p['inputs']['1st']);correct=f.output(p['inputs']['1st']);first['horses']=[h for h in first['horses'] if h['horse_no']!=7]
result=dict(prepared=p,initial=f.text(first),corrected=f.text(correct),reply_deadline=seed['time_contract']['reply_deadline'])
normal=f.output(p['inputs']['1st']);orders=copy.deepcopy(normal['band_ranks']);orders['B']=[10,9,8,7];set_orders(normal,orders)
bad=copy.deepcopy(normal);bad['horses'][0].pop('rank_reason')
result['unrelated_correction']=dict(initial=f.text(bad),corrected=f.text(normal),expected=[1,2,10,9,11])
bad_flag=copy.deepcopy(normal);bad_flag['horses'][0]['public_candidate']=False
result['public_flag_correction']=dict(initial=f.text(bad_flag),corrected=f.text(normal))
bad_summary=copy.deepcopy(normal);bad_summary['horses'][6]['observed_sum']=99
result['summary_correction']=dict(initial=f.text(bad_summary),corrected=f.text(normal))
bad_review=copy.deepcopy(normal);bad_review['horses'][6]['family_review']['ability']['evidence_ids']=['missing']
result['review_correction']=dict(initial=f.text(bad_review),corrected=f.text(normal))
bad_counter=copy.deepcopy(normal);bad_counter['horses'][6]['counterevidence']=[dict(source_refs=['missing'],reason='不正な反証参照')]
result['counter_correction']=dict(initial=f.text(bad_counter),corrected=f.text(normal))
from test_r8_regressions import deep_text
bad_unicode=json.loads(r.json_dumps(normal));h=bad_unicode['horses'][6]
h['evidence'][0]['evidence_id']='\ud800';h['evidence_rank_evidence_ids']=['\ud800'];h['family_review']['ability']['evidence_ids']=['\ud800']
result['malformed_responses']={'deep':deep_text(normal),'unicode':e.BEGIN+'\n'+json.dumps(bad_unicode)+'\n'+e.END}
result['second_response']=f.text(f.output(p['inputs']['2nd']))
from decimal import Decimal
numeric_seed=copy.deepcopy(seed)
numeric_values={1:Decimal('9E308'),2:10**80+1,7:Decimal('1.000000000000000001')}
for no,value in numeric_values.items():numeric_seed['source_registry'][f'h{no}_past_performance_None']['value']=value
numeric_prepared=e.prepare(numeric_seed,now.isoformat());numeric_responses={}
for role in ('1st','2nd'):
 obj=f.output(numeric_prepared['inputs'][role])
 for row in obj['horses']:
  if row['horse_no'] in numeric_values:
   row['evidence'][0]['facts'][0]['value']=numeric_values[row['horse_no']]
   row['evidence'][0]['observation']='架空の数値境界'
 numeric_responses[role]=f.text(obj)
result['numeric_case']=dict(prepared=numeric_prepared,responses=numeric_responses,
 expected={str(no):r.json_dumps(value) for no,value in numeric_values.items()})
(root/'php'/'live_clock_fixture.json').write_text(r.json_dumps(result))
