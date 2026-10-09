import copy
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('planner', ROOT / 'tools/favorites_dry_run.py')
planner = importlib.util.module_from_spec(spec); spec.loader.exec_module(planner)


class AuditTests(unittest.TestCase):
    def setUp(self):
        self.data = json.loads((ROOT / 'tests/fixtures/favorites-dry-run.json').read_text())
    def blocked(self, reason):
        report = planner.audit(self.data)
        self.assertEqual(report['status'], 'review_required')
        self.assertIn(reason, report['issues'][0]['reasons'])
        self.assertEqual(report['candidates'], [])
    def test_valid_deterministic_and_immutable(self):
        original = copy.deepcopy(self.data)
        first = planner.audit(self.data)
        self.assertEqual(first, planner.audit(self.data)); self.assertEqual(self.data, original)
        self.assertEqual(first['candidates'][0]['card_count'], 1)
        self.assertEqual(first['writes_performed'], 0)
        text = json.dumps(first)
        for sensitive in ['SYNTHETIC-7', '11111111-1111-4111-8111-111111111111', 'STOK/LIST.PHP']:
            self.assertNotIn(sensitive, text)
    def test_empty_favorites(self):
        self.data['preferences'][0]['favorites'] = ''
        self.assertEqual(planner.audit(self.data)['candidates'][0]['card_count'], 0)
    def test_empty_snapshot(self):
        for key in ['persons', 'actor_links', 'preferences']: self.data[key] = []
        self.assertEqual(planner.audit(self.data)['counts']['source_rows'], 0)
    def test_unmapped(self):
        self.data['persons'] = []; self.blocked('unmapped_code')
    def test_case_not_guessed(self):
        self.data['preferences'][0]['user_code'] = 'synthetic-7'; self.blocked('unmapped_code')
    def test_multiple_people(self):
        row = copy.deepcopy(self.data['persons'][0]); row['personel'] = 8
        self.data['persons'].append(row); self.blocked('ambiguous_code')
    def test_duplicate_source(self):
        self.data['preferences'] *= 2; self.blocked('duplicate_preference_code')
    def test_person_multiple_codes(self):
        row = copy.deepcopy(self.data['persons'][0]); row['code'] = 'OTHER'
        self.data['persons'].append(row); self.blocked('duplicate_person')
    def test_missing_actor(self):
        self.data['actor_links'] = []; self.blocked('missing_actor_link')
    def test_multiple_actor(self):
        self.data['actor_links'] *= 2; self.blocked('multiple_actor_links')
    def test_shared_actor(self):
        row = copy.deepcopy(self.data['actor_links'][0]); row['personel'] = 8
        self.data['actor_links'].append(row); self.blocked('actor_shared_by_persons')
    def test_orphan_unused_link(self):
        row = copy.deepcopy(self.data['actor_links'][0]); row.update(personel=9, actor_id='22222222-2222-4222-8222-222222222222')
        self.data['actor_links'].append(row)
        self.assertEqual(planner.audit(self.data)['mapping_issue_counts']['orphan_actor_links'], 1)
        self.assertEqual(planner.audit(self.data)['status'], 'review_required')
    def test_bad_keys_not_discarded(self):
        self.data['preferences'][0]['favorites'] = 'valid.php,invalid?.php'; self.blocked('invalid_favorite_key')
    def test_php_ascii_contract_rejects_unicode(self):
        for raw in ['K.php', '\u00a0card.php\u00a0', '\u2003card.php', 'CARD.K']:
            self.data['preferences'][0]['favorites'] = raw
            self.blocked('invalid_favorite_key')
    def test_php_trim_contract(self):
        self.data['preferences'][0]['favorites'] = '\x00\x0b CARD.PHP\t\r\n'
        self.assertEqual(planner.audit(self.data)['candidates'][0]['card_count'],1)
    def test_real_php_normalization_contract(self):
        samples = ['CARD.PHP,card.php, other/list.php', '', '\x00\x0b CARD.PHP\r\n', 'K.php', '\u00a0card.php\u00a0']
        script = 'require "includes/lumen_preferences.php"; foreach(json_decode(stream_get_contents(STDIN),true) as $raw) { $out[]=lumen_favorites_normalize($raw); } echo json_encode($out);'
        result = subprocess.run(['php', '-r', script], input=json.dumps(samples).encode(), cwd=ROOT, capture_output=True, check=True)
        expected = json.loads(result.stdout)
        for raw, php_value in zip(samples, expected):
            value, _, reasons = planner.normalized(raw)
            if reasons: self.assertEqual(php_value, '')
            else: self.assertEqual(value, php_value)
    def test_limit_not_truncated(self):
        self.data['preferences'][0]['favorites'] = ','.join(f'card{i}.php' for i in range(41)); self.blocked('favorite_limit_exceeded')
    def test_scope_entire_input(self):
        for collection in ['persons', 'actor_links', 'preferences']:
            for key, value in [('firma', 3), ('connection', 'other-logo')]:
                data = copy.deepcopy(self.data); data[collection][0][key] = value
                with self.assertRaises(ValueError): planner.audit(data)
    def test_invalid_types(self):
        for key, value in [('firma', True), ('schema_version', True), ('persons', {}), ('code_match_policy', 'casefold')]:
            data = copy.deepcopy(self.data); data[key] = value
            with self.assertRaises(ValueError): planner.audit(data)
    def test_unknown_credentials_rejected(self):
        self.data['password'] = 'synthetic-secret'
        with self.assertRaises(ValueError): planner.audit(self.data)
    def test_invalid_uuid(self):
        self.data['actor_links'][0]['actor_id'] = 'derive-from-code'
        with self.assertRaises(ValueError): planner.audit(self.data)
    def test_duplicate_json_and_size(self):
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / 'snapshot.json'
            for content in ['{"firma":2,"firma":3}', 'x' * (planner.MAX_BYTES + 1)]:
                file.write_text(content)
                with self.assertRaises(ValueError): planner.load(file)
    def test_cli_exit_codes_and_no_writes(self):
        with tempfile.TemporaryDirectory() as tmp:
            file = Path(tmp) / 'snapshot.json'
            for expected in [0, 2, 1]:
                if expected == 2: self.data['actor_links'] = []
                if expected == 1: self.data['firma'] = 0
                original = json.dumps(self.data).encode(); file.write_bytes(original)
                result = subprocess.run([sys.executable, str(ROOT / 'tools/favorites_dry_run.py'), str(file)], cwd=tmp, capture_output=True)
                self.assertEqual(result.returncode, expected); self.assertEqual(file.read_bytes(), original)
                self.assertEqual(list(Path(tmp).iterdir()), [file]); self.assertEqual(result.stderr, b'')
                self.assertEqual(json.loads(result.stdout)['writes_performed'], 0)


class TargetAuditTests(AuditTests):
    # Existing v1 cases rerun against v2 to protect compatibility and isolation.
    def setUp(self):
        super().setUp()
        self.data.update(schema_version=2, target_snapshot_complete=True, target_favorites=[])
    def target(self, value='stok/list.php'):
        return {'connection': self.data['connection'], 'firma': self.data['firma'],
                'actor_id': self.data['actor_links'][0]['actor_id'], 'favorites': value}
    def test_missing_target_is_candidate(self):
        report = planner.audit(self.data)
        self.assertEqual(report['candidates'][0]['action'], 'insert_candidate')
        self.assertFalse(report['candidates'][0]['target_present'])
    def test_same_target_preserved(self):
        self.data['target_favorites'] = [self.target(' STOK/LIST.PHP ')]
        original = copy.deepcopy(self.data)
        candidate = planner.audit(self.data)['candidates'][0]
        self.assertEqual(candidate['action'], 'no_change')
        self.assertTrue(candidate['target_present']); self.assertEqual(self.data, original)
        self.assertNotIn('STOK/LIST.PHP', json.dumps(candidate))
    def test_target_difference_blocked(self):
        self.data['target_favorites'] = [self.target('other.php')]
        self.blocked('target_value_conflict')
    def test_empty_source_cannot_erase_target(self):
        self.data['preferences'][0]['favorites'] = ''
        self.data['target_favorites'] = [self.target()]
        self.blocked('target_value_conflict')
    def test_empty_target_is_existing_value(self):
        self.data['target_favorites'] = [self.target('')]
        self.blocked('target_value_conflict')
    def test_duplicate_target(self):
        self.data['target_favorites'] = [self.target(), self.target()]
        self.blocked('duplicate_target_actor')
    def test_unused_target_not_deleted(self):
        target = self.target(); target['actor_id'] = '22222222-2222-4222-8222-222222222222'
        self.data['target_favorites'] = [target]
        report = planner.audit(self.data)
        self.assertEqual(report['mapping_issue_counts']['unlinked_target_actors'], 1)
        self.assertEqual(report['status'], 'review_required')
        self.assertEqual(report['writes_performed'], 0)
    def test_target_scope_stops_all(self):
        for key, value in [('firma', 3), ('connection', 'other-logo')]:
            target = self.target(); target[key] = value
            self.data['target_favorites'] = [target]
            with self.assertRaises(ValueError): planner.audit(self.data)
    def test_target_incomplete_rejected(self):
        for value in [False, 1, 'true', None]:
            self.data['target_snapshot_complete'] = value
            with self.assertRaises(ValueError): planner.audit(self.data)
    def test_invalid_target_stops_all(self):
        for value in ['invalid?.php', ','.join(f'{i}.php' for i in range(41))]:
            self.data['target_favorites'] = [self.target(value)]
            with self.assertRaises(ValueError): planner.audit(self.data)
    def test_actor_uuid_case_comparison(self):
        actor = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
        self.data['actor_links'][0]['actor_id'] = actor
        target = self.target(); target['actor_id'] = actor.upper()
        self.data['target_favorites'] = [target]
        self.assertEqual(planner.audit(self.data)['candidates'][0]['action'], 'no_change')
    def test_conflict_cli_fixture(self):
        result = subprocess.run([sys.executable, str(ROOT / 'tools/favorites_dry_run.py'), str(ROOT / 'tests/fixtures/favorites-target-dry-run.json')], capture_output=True)
        self.assertEqual(result.returncode, 2)
        report = json.loads(result.stdout)
        self.assertEqual(report['reason_counts']['target_value_conflict'], 1)
        self.assertEqual(report['candidates'], [])


if __name__ == '__main__': unittest.main()
