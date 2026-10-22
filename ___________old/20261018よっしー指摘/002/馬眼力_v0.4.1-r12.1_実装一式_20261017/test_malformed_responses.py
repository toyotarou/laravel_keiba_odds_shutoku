"""Deterministic type mutations at the untrusted model-output boundary."""
import copy,json,unittest
from pathlib import Path
import engine as e,fixtures as f,reference as r

class MalformedResponses(unittest.TestCase):
    def test_bad_fields_do_not_crash_other_role_or_break_quota(self):
        p=e.prepare(f.seed(),f.REQUEST);inp=p['inputs']['1st'];base=f.output(inp)
        paths=[(x,) for x in ('horses','band_ranks','evidence_band_ranks','public_candidates','shortfalls','validation_issues')]
        paths += [('horses',6,x) for x in base['horses'][6]]
        paths += [('horses',6,'family_review','ability'),('horses',6,'family_review','ability','source_refs'),
            ('horses',6,'evidence',0,'facts'),('horses',6,'evidence',0,'source_refs'),
            ('horses',6,'score_evidence','V4'),('horses',6,'scores','V4')]
        paths += [('horses',2,'nonselect_reason','code'),('horses',2,'nonselect_reason','reason'),
            ('horses',6,'evidence',0,'facts',0,'source_ref'),('horses',6,'evidence',0,'facts',0,'value'),
            ('horses',6,'family_review','ability','status'),('horses',6,'family_review','ability','evidence_ids')]
        values=(None,True,0,'',[],{},[{}],{'unknown':None})
        count=0
        for path in paths:
            for value in values:
                with self.subTest(path=path,value=value):
                    obj=copy.deepcopy(base);parent=obj
                    for part in path[:-1]:parent=parent[part]
                    parent[path[-1]]=value
                    text=f.text(obj)
                    e.inspect_for_correction(inp,text,f.RECEIVED)
                    out=e.resolve(inp,{'1st':[dict(text=text,received_at=f.RECEIVED)],'2nd':f.responses(p)['2nd']},[10],f.NOW)
                    self.assertEqual(len(out['public_candidates']),len(set(out['public_candidates'])))
                    self.assertNotIn(10,out['public_candidates'])
                    self.assertEqual(out['actual_quota'],out['publication_quota'])
                    self.assertNotIn('AI_FAILURE',out['status_flags']);count+=1
        self.assertEqual(count,len(paths)*len(values));self.__class__.cases=count

if __name__=='__main__':
    result=unittest.TextTestRunner(verbosity=2).run(unittest.defaultTestLoader.loadTestsFromTestCase(MalformedResponses))
    Path('malformed_results.json').write_text(json.dumps(dict(tests_run=result.testsRun,cases=getattr(MalformedResponses,'cases',0),failures=len(result.failures),errors=len(result.errors)),indent=2))
    raise SystemExit(not result.wasSuccessful())
