"""Keep versioned schema resources distinct; exercise real cross-version $ref resolution."""
from copy import deepcopy
from hashlib import sha256
import json
from pathlib import Path
import subprocess
from zipfile import ZipFile

from jsonschema import Draft202012Validator, FormatChecker
from referencing import Registry, Resource

ROOT = Path(__file__).resolve().parents[1]
KINDS = ('input', 'output', 'recovery')
REFERENCE_SHA = '756845983bc2f7b20fd3a8a58822aee7a5e4cd29144c3da40bd6cc8cc16d4961'


def read(path):
    return json.loads(path.read_text(encoding='utf-8'))


def check_identity(config, schemas):
    """Release guard: an unversioned or stale identity must fail before packaging."""
    version = config['schema_version']
    prefix = 'baganriki-v041-'
    if not version.startswith(prefix):
        raise ValueError('unexpected schema_version prefix')
    for kind in KINDS:
        schema = schemas[kind]
        expected = 'urn:baganriki:' + version[len(prefix):] + ':' + kind
        if schema.get('$id') != expected:
            raise ValueError('schema identity mismatch: ' + kind)
        for field in ('prompt_version', 'schema_version'):
            if schema.get('properties', {}).get(field, {}).get('const') != config[field]:
                raise ValueError('schema version mismatch: ' + kind + '/' + field)


def release_guard():
    config = read(ROOT / 'config/w2_contract.json')
    schemas = {kind: read(ROOT / f'schemas/{kind}_schema.json') for kind in KINDS}
    check_identity(config, schemas)
    return config, schemas


def recovery_fixture(reply, schema):
    """Schema-only fixture; receipts and provenance are tested by the PHP backend suite."""
    doc = {key: reply[key] for key in ('schema_version', 'prompt_version', 'race_id', 'snapshot_hash', 'ai_role')}
    keys = schema['properties']['horses']['items']['properties']
    doc['horses'] = [{key: value for key, value in horse.items() if key in keys} for horse in reply['horses']]
    doc['base_ranks'] = deepcopy(reply['band_ranks'])
    doc['row_sources'] = {str(horse['horse_no']): ['a' * 64] for horse in reply['horses']}
    doc['rank_sources'] = dict.fromkeys(('A', 'B', 'D'), 'a' * 64)
    return doc


def run(php, bootstrap, examples):
    config, current = release_guard()
    archive = ROOT / 'validation/schema_identity_reference_R10.zip'
    assert sha256(archive.read_bytes()).hexdigest() == REFERENCE_SHA, 'R10 schema reference changed'
    with ZipFile(archive) as z:
        assert z.testzip() is None
        assert set(z.namelist()) == {f'{kind}_schema.json' for kind in (*KINDS, 'source_registry')}
        previous = {kind: json.loads(z.read(f'{kind}_schema.json')) for kind in KINDS}
        assert z.read('source_registry_schema.json') == (ROOT / 'schemas/source_registry_schema.json').read_bytes()
    for schema in [*previous.values(), *current.values()]:
        Draft202012Validator.check_schema(schema)
    assert len({s['$id'] for s in [*previous.values(), *current.values()]}) == 6

    # The same stale ID is a defect even when direct local-file validation succeeds.
    guards = 0
    for kind in KINDS:
        for mutation in ('missing_id', 'stale_id', 'other_kind_id', 'prompt_version', 'schema_version'):
            broken = deepcopy(current)
            if mutation == 'missing_id':
                del broken[kind]['$id']
            elif mutation == 'stale_id':
                broken[kind]['$id'] = previous[kind]['$id']
            elif mutation == 'other_kind_id':
                broken[kind]['$id'] = current[KINDS[(KINDS.index(kind) + 1) % len(KINDS)]]['$id']
            else:
                broken[kind]['properties'][mutation]['const'] = previous[kind]['properties'][mutation]['const']
            try:
                check_identity(config, broken)
            except ValueError:
                guards += 1
            else:
                raise AssertionError('release guard accepted ' + kind + '/' + mutation)

    docs = {}
    for role in ('1st', '2nd'):
        reply = read(examples / f'{role}_output.json')
        for kind in KINDS:
            doc = recovery_fixture(reply, current['recovery']) if kind == 'recovery' else read(examples / f'{role}_{kind}.json')
            docs['current', kind, role] = doc
            old = deepcopy(doc)
            for field in ('prompt_version', 'schema_version'):
                old[field] = previous[kind]['properties'][field]['const']
            docs['previous', kind, role] = old
            Draft202012Validator(current[kind], format_checker=FormatChecker()).validate(doc)
            Draft202012Validator(previous[kind], format_checker=FormatChecker()).validate(old)

    registry_checks = 0
    for versions in (('previous', 'current'), ('current', 'previous')):
        sets = {'previous': previous, 'current': current}
        registry = Registry().with_resources([
            (schema['$id'], Resource.from_contents(schema))
            for version in versions for schema in sets[version].values()
        ])
        for kind in KINDS:
            for role in ('1st', '2nd'):
                for version in ('previous', 'current'):
                    other = 'previous' if version == 'current' else 'current'
                    validator = Draft202012Validator({'$ref': sets[version][kind]['$id']},
                                                      registry=registry, format_checker=FormatChecker())
                    good = docs[version, kind, role]
                    bad_prompt = deepcopy(good)
                    bad_prompt['prompt_version'] = docs[other, kind, role]['prompt_version']
                    bad_schema = deepcopy(good)
                    bad_schema['schema_version'] = docs[other, kind, role]['schema_version']
                    for obj, expected in ((good, True), (docs[other, kind, role], False),
                                          (bad_prompt, False), (bad_schema, False)):
                        assert validator.is_valid(obj) == expected, (versions, kind, role, version)
                        registry_checks += 1

    requests, answers = [], []
    for kind in KINDS:
        validator = Draft202012Validator(current[kind], format_checker=FormatChecker())
        for role in ('1st', '2nd'):
            good, old = docs['current', kind, role], docs['previous', kind, role]
            for obj in (good, old, {**good, 'prompt_version': old['prompt_version']},
                        {**good, 'schema_version': old['schema_version']}):
                requests.append(kind + '\t' + json.dumps(obj, ensure_ascii=False, separators=(',', ':')))
                answers.append('1' if validator.is_valid(obj) else '0')
    probe = subprocess.run([str(php), str(ROOT / 'validation/schema_probe.php'), str(bootstrap)],
                           input='\n'.join(requests) + '\n', check=True, capture_output=True, text=True)
    assert not probe.stderr and probe.stdout.splitlines() == answers, 'PHP schema/version parity'
    assert (registry_checks, guards, len(answers)) == (96, 15, 24)
    print(f'PASS R11 schema identities: {registry_checks} real registry checks in both registration orders, '
          f'{guards} stale/missing/mixed release guards and {len(answers)} PHP/Python document checks; '
          'input/output/recovery and both roles; no deployed registry execution.')
