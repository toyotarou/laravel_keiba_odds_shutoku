"""DB export adapter. Decimal DB columns arrive as strings; AI strings never go here."""
import copy,re
from decimal import Decimal
from datetime import timedelta
import reference as r
from engine import require

ODDS_FIELDS={'odds':'win_odds','fuku_min':'place_min','fuku_max':'place_max'}

def db_decimal(value):
    if value is None or value=='':return None
    require(isinstance(value,str) and re.fullmatch(r'-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?',value) is not None,'DB decimal must be original decimal string')
    require(len(value)<=128,'DB decimal length')
    return Decimal(value)

def seed_from_export(export):
    """Consumes existing AiController odds-column names without assuming timestamp columns.
    Export caller supplies observed_at and available_at from verified DB/log mappings.
    Other original fields (OPI, past runs, estimates, statistics) go through extra_sources.
    """
    require(isinstance(export,dict),'export object')
    seed={k:copy.deepcopy(export[k]) for k in ('race_id','cutoff_at','time_contract','runners')}
    seed.update(source_registry={},field_catalog={},input_indices={},legacy_gap_type=export.get('legacy_gap_type'))
    r.validate_runners(seed['runners']);nums={h['horse_no'] for h in seed['runners']};seen=set()
    for field in ODDS_FIELDS.values():
        seed['field_catalog'][field]=dict(unit='odds',scope='HORSE',display_label=field,value_type='number',missing_allowed=True,minimum=0,maximum=None)
    for row in export['odds_rows']:
        no=row['num'];slot=row['minutes_before_start']
        require(r.exact_int(no) and no in nums and r.exact_int(slot) and slot in (21,18,15,12,9,6),'odds row identity')
        require((no,slot) not in seen,'duplicate odds slot; select observation using your sampling policy first');seen.add((no,slot))
        for dbfield,field in ODDS_FIELDS.items():
            value=db_decimal(row.get(dbfield));require(value is None or value>0,'invalid odds sentinel; map documented unavailable values to null')
            ref=f'odds/{no}/{slot}/{field}'
            seed['source_registry'][ref]=dict(source_ref=ref,race_id=seed['race_id'],value=value,scope='HORSE',horse_nos=[no],applicable_horse_nos=[],field=field,unit='odds',slot_offset=slot,observed_at=row['observed_at'],available_at=row['available_at'],computed_at=None,source_kind='OBSERVED',parents=[],formula_id=None,formula_version=None,dependency_key=f'odds/{no}/{field}',dependency_role='SIGNAL')
            seed['input_indices'][f'horse/{no}/{field}/{slot}']=[ref]
    for field,catalog in export.get('extra_field_catalog',{}).items():
        require(field not in seed['field_catalog'],'duplicate field definition');seed['field_catalog'][field]=copy.deepcopy(catalog)
    for source in export.get('extra_sources',[]):
        source=copy.deepcopy(source);ref=source['source_ref'];require(ref not in seed['source_registry'],'duplicate source')
        catalog=seed['field_catalog'][source['field']]
        if catalog['value_type']=='number':source['value']=db_decimal(source['value'])
        seed['source_registry'][ref]=source
    for key,refs in export.get('extra_indices',{}).items():
        require(key not in seed['input_indices'],'duplicate index');seed['input_indices'][key]=copy.deepcopy(refs)
    # Every available legacy data section must be accounted for by the exporter.
    coverage=export.get('coverage',{})
    r.validate_coverage(coverage,seed['source_registry'])
    seed['coverage']=copy.deepcopy(coverage)
    return seed
