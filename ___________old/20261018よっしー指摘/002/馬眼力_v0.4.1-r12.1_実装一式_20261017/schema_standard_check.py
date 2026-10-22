"""Required production gate; exit 2 if a standard validator is unavailable."""
import copy,json
from pathlib import Path

def main():
    try:
        from jsonschema import Draft202012Validator,FormatChecker
    except ImportError:
        result={'status':'NOT_RUN','reason':'Standard jsonschema validator unavailable','production_gate_passed':False}
        Path('schema_standard_results.json').write_text(json.dumps(result,indent=2))
        print(json.dumps(result));return 2
    checker=FormatChecker()
    if 'date-time' not in checker.checkers:
        result={'status':'NOT_RUN','reason':'date-time format checker unavailable','production_gate_passed':False}
        Path('schema_standard_results.json').write_text(json.dumps(result,indent=2))
        print(json.dumps(result));return 2
    validators={}
    for name in ('input','output','source_registry'):
        schema=json.loads(Path(name+'_schema.json').read_text())
        Draft202012Validator.check_schema(schema)
        validators[name]=Draft202012Validator(schema,format_checker=checker)
    source=json.loads(Path('fixture_input.json').read_text())
    output=json.loads(Path('fixture_output.json').read_text())
    validators['input'].validate(source)
    validators['output'].validate(output)
    validators['source_registry'].validate(source['source_registry'])
    import fixtures as f,engine as e
    extra_normal=0
    for n in (13,18):
        prepared=e.prepare(f.seed(n),f.REQUEST)
        for role,inp in prepared['inputs'].items():
            validators['input'].validate(inp);validators['output'].validate(f.output(inp));extra_normal+=2
    cases=[]
    bad=copy.deepcopy(output);bad['horses'][0]['scores']['H1']=True;cases.append(('output',bad))
    bad=copy.deepcopy(output);bad['public_candidates']=[1,1];cases.append(('output',bad))
    bad=copy.deepcopy(output);bad['ai_role']='2nd';bad['role_cap']=7;cases.append(('output',bad))
    bad=copy.deepcopy(output);bad['evidence_band_ranks']={};cases.append(('output',bad))
    bad=copy.deepcopy(source);bad['source_registry']['h1']['horse_nos']=[1,2];cases.append(('input',bad))
    bad=copy.deepcopy(source);del bad['field_catalog'];cases.append(('input',bad))
    bad=copy.deepcopy(source);bad['time_contract']['anchor_at']='no-date';cases.append(('input',bad))
    bad=copy.deepcopy(source);bad['learning_stage']='LIVE_VALIDATED';cases.append(('input',bad))
    bad=copy.deepcopy(output);bad['horses'][0]['nonselect_reason']='BAND_QUOTA';cases.append(('output',bad))
    for case_no,(name,data) in enumerate(cases,1):
        if not list(validators[name].iter_errors(data)): raise AssertionError(f'invalid fixture {case_no} was accepted')
    result={'status':'PASSED','schemas':3,'normal_fixtures':3+extra_normal,'invalid_fixtures':len(cases),
            'scope':'Schema gate only; arithmetic, ownership, timestamps, recovery and runtime need separate tests'}
    Path('schema_standard_results.json').write_text(json.dumps(result,indent=2));print(json.dumps(result));return 0

if __name__=='__main__':
    try:
        code=main()
    except Exception as exc:
        result={'status':'FAILED','reason':str(exc),'production_gate_passed':False}
        Path('schema_standard_results.json').write_text(json.dumps(result,indent=2))
        print(json.dumps(result));code=1
    raise SystemExit(code)
