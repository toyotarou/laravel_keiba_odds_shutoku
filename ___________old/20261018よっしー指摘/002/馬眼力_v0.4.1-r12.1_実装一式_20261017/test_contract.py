import json,re,unittest
from pathlib import Path
import reference as r

class ContractTests(unittest.TestCase):
    def load(self,name): return json.loads(Path(name).read_text())

    def test_versions_and_caps(self):
        cfg=self.load('config.json')
        for name in ('input_schema.json','output_schema.json'):
            schema=self.load(name)
            self.assertEqual(schema['properties']['prompt_version']['const'],cfg['prompt_version'])
            self.assertEqual(schema['properties']['schema_version']['const'],cfg['schema_version'])
        self.assertEqual(cfg['score_caps'],r.CAPS)
        self.assertEqual(cfg['base_quota'],{str(k):{m:list(v) for m,v in x.items()} for k,x in r.BASE.items()})

    def test_prompt_required_fields(self):
        common=Path('common_prompt.txt').read_text();schema=self.load('output_schema.json')
        top=re.search(r'トップレベル必須キー：([^。]+)。',common).group(1).split(',')
        horse=re.search(r'horsesの必須キー：([^。]+)。',common).group(1).split(',')
        self.assertEqual(set(top),set(schema['required']))
        self.assertEqual(set(horse),set(schema['properties']['horses']['items']['required']))

    def test_common_body_identical(self):
        common=Path('common_prompt.txt').read_text()
        for name in ('prompt_1st.txt','prompt_2nd.txt'):
            txt=Path(name).read_text()
            self.assertEqual(txt[txt.index('【目的と優先順位】'):],common)
            self.assertNotIn('<<<BAGANRIKI_V041_R3',txt)

    def test_fixture_keys(self):
        for name in ('input','output'):
            data=self.load('fixture_'+name+'.json');schema=self.load(name+'_schema.json')
            self.assertEqual(set(data),set(schema['required']))
        out=self.load('fixture_output.json');schema=self.load('output_schema.json')
        self.assertEqual(set(out['horses'][0]),set(schema['properties']['horses']['items']['required']))
        inp=self.load('fixture_input.json');ss=self.load('source_registry_schema.json')
        self.assertEqual(set(inp['source_registry']['h1']),set(ss['additionalProperties']['required']))

    def test_fixture_semantics(self):
        inp=self.load('fixture_input.json');out=self.load('fixture_output.json')
        r.validate_runners(inp['runners'])
        r.validate_registry(inp['source_registry'],inp['race_id'],inp['cutoff_at'],[1],inp['field_catalog'])
        h=out['horses'][0]
        ev,issues=r.verified_evidence(h['evidence'],1,inp['source_registry'])
        self.assertEqual(len(ev),1);self.assertFalse(issues)
        scores,issues=r.recover_scores(h['scores'],h['score_evidence'],inp['source_registry'],1)
        self.assertEqual(scores['score_status'],'partial')
        self.assertEqual(r.signal_mode(list(inp['signals'].values()))['mode'],inp['mode'])
        self.assertEqual(out['role_quota'],inp['quota'])
        self.assertEqual(out['public_candidates'],[1])

    def test_predeployment_flags_are_not_claimed(self):
        runtime=self.load('runtime_contract.template.json')
        self.assertFalse(runtime['candidate_route_ready'])
        self.assertFalse(runtime['purchase_ready'])
        self.assertEqual(runtime['learning']['stage'],'COLLECT_ONLY')
        self.assertFalse(runtime['validation']['standard_schema_tests_passed'])

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(ContractTests))
    Path('contract_results.json').write_text(json.dumps(dict(tests_run=result.testsRun,failures=len(result.failures),errors=len(result.errors),scope='text/config/fixture checks, not full JSON Schema validation'),indent=2))
    raise SystemExit(0 if result.wasSuccessful() else 1)
