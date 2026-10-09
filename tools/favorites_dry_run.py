#!/usr/bin/env python3
"""Offline favorite mapping audit. JSON in, redacted report out; no DB or network."""
import argparse
import hashlib
import json
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

MAX_BYTES = 1048576

def normalized(raw):
    if not isinstance(raw, str) or len(raw) > MAX_BYTES:
        raise ValueError('Invalid favorite value type')
    keys = [part.strip(' \t\n\r\x00\x0b').translate(str.maketrans('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')) for part in raw.split(',') if part.strip(' \t\n\r\x00\x0b')]
    reasons = []
    if any(not re.fullmatch(r'[a-z0-9_./-]{1,80}', key) for key in keys): reasons.append('invalid_favorite_key')
    unique = list(dict.fromkeys(keys))
    if len(unique) > 40: reasons.append('favorite_limit_exceeded')
    return ','.join(unique), len(unique), reasons


def audit(snapshot):
    def fields(row, expected):
        if not isinstance(row, dict) or set(row) != set(expected):
            raise ValueError('Unexpected or missing fields')
    if not isinstance(snapshot, dict): raise ValueError('Invalid snapshot')
    version = snapshot.get('schema_version')
    if type(version) is not int or version not in (1, 2): raise ValueError('Unsupported schema')
    required = ['schema_version', 'connection', 'firma', 'code_match_policy', 'persons', 'actor_links', 'preferences']
    if version == 2: required += ['target_snapshot_complete', 'target_favorites']
    fields(snapshot, required)
    connection, firma = snapshot['connection'], snapshot['firma']
    if version == 2 and snapshot['target_snapshot_complete'] is not True:
        raise ValueError('Explicit complete target snapshot required')
    if not isinstance(connection, str) or not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,63}', connection):
        raise ValueError('Invalid connection scope')
    if type(firma) is not int or not 1 <= firma <= 999:
        raise ValueError('Invalid company scope')
    if snapshot['code_match_policy'] != 'exact-reviewed':
        raise ValueError('Explicit reviewed exact CODE matching is required')
    collections = ['persons', 'actor_links', 'preferences'] + (['target_favorites'] if version == 2 else [])
    for name in collections:
        if not isinstance(snapshot[name], list) or len(snapshot[name]) > 10000:
            raise ValueError('Invalid collection')
    def scoped(row, keys):
        fields(row, ['connection', 'firma'] + keys)
        if row['connection'] != connection or type(row['firma']) is not int or row['firma'] != firma:
            raise ValueError('Scope mismatch: entire audit stopped')
    def person(value):
        if type(value) is not int or not 1 <= value <= 999999999:
            raise ValueError('Invalid person identifier')
    def code(value):
        if not isinstance(value, str) or not value or len(value) > 256 or value != value.strip() or any(ord(c) < 32 for c in value):
            raise ValueError('Invalid CODE; explicit source review required')
    persons_by_code, person_rows = defaultdict(list), Counter()
    for row in snapshot['persons']:
        scoped(row, ['personel', 'code']); person(row['personel']); code(row['code'])
        persons_by_code[row['code']].append(row['personel']); person_rows[row['personel']] += 1
    links, actor_rows = defaultdict(list), Counter()
    for row in snapshot['actor_links']:
        scoped(row, ['personel', 'actor_id']); person(row['personel'])
        if not isinstance(row['actor_id'], str) or not re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', row['actor_id']):
            raise ValueError('Invalid explicitly supplied actor UUID')
        actor = row['actor_id'].lower(); links[row['personel']].append(actor); actor_rows[actor] += 1
    targets = defaultdict(list)
    if version == 2:
        for row in snapshot['target_favorites']:
            scoped(row, ['actor_id', 'favorites'])
            actor = row['actor_id']
            if not isinstance(actor, str) or not re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}', actor):
                raise ValueError('Invalid target actor UUID')
            value, _, invalid = normalized(row['favorites'])
            if invalid: raise ValueError('Invalid target favorite list')
            targets[actor.lower()].append((row['favorites'], value))
    preference_codes = Counter()
    for row in snapshot['preferences']:
        scoped(row, ['user_code', 'favorites']); code(row['user_code'])
        if not isinstance(row['favorites'], str) or len(row['favorites']) > 1048576:
            raise ValueError('Invalid favorite value type')
        preference_codes[row['user_code']] += 1
    issues, ready = [], []
    for index, row in enumerate(snapshot['preferences']):
        reasons = []
        candidates = persons_by_code[row['user_code']]
        if preference_codes[row['user_code']] != 1: reasons.append('duplicate_preference_code')
        if not candidates: reasons.append('unmapped_code')
        elif len(candidates) != 1: reasons.append('ambiguous_code')
        else:
            pid = candidates[0]
            if person_rows[pid] != 1: reasons.append('duplicate_person')
            if not links[pid]: reasons.append('missing_actor_link')
            elif len(links[pid]) != 1: reasons.append('multiple_actor_links')
            elif actor_rows[links[pid][0]] != 1: reasons.append('actor_shared_by_persons')
        value, card_count, value_reasons = normalized(row['favorites'])
        reasons.extend(value_reasons)
        comparison = None
        if version == 2 and not reasons:
            existing = targets[links[pid][0]]
            if len(existing) > 1: reasons.append('duplicate_target_actor')
            elif existing:
                if existing[0][1] != value: reasons.append('target_value_conflict')
                else:
                    comparison = {'action': 'no_change', 'target_present': True,
                                  'target_raw_sha256': hashlib.sha256(existing[0][0].encode('utf-8')).hexdigest()}
            else: comparison = {'action': 'insert_candidate', 'target_present': False}

        if reasons:
            issues.append({'row_index': index, 'reasons': sorted(set(reasons))})
        else:
            candidate = {'row_index': index, 'card_count': card_count, 'normalized_sha256': hashlib.sha256(value.encode('ascii')).hexdigest(), 'normalization_changed': value != row['favorites']}
            if comparison is not None: candidate.update(comparison)
            ready.append(candidate)
    counts = Counter(reason for issue in issues for reason in issue['reasons'])
    # Unused invalid links also require review: do not silently declare the snapshot safe.
    mapping_counts = {'duplicate_person_ids': sum(n > 1 for n in person_rows.values()),
                      'duplicate_codes': sum(len(v) > 1 for v in persons_by_code.values()),
                      'multiple_links_per_person': sum(len(v) > 1 for v in links.values()),
                      'shared_actor_ids': sum(n > 1 for n in actor_rows.values()),
                      'orphan_actor_links': sum(pid not in person_rows for pid in links)}
    if version == 2:
        mapping_counts['duplicate_target_actors'] = sum(len(v) > 1 for v in targets.values())
        mapping_counts['unlinked_target_actors'] = sum(actor not in actor_rows for actor, rows in targets.items() if rows)
    safe = not issues and not any(mapping_counts.values())
    return {'schema_version': version, 'target_comparison': 'snapshot-only' if version == 2 else 'not_provided', 'mode': 'offline-dry-run', 'status': 'ready_for_review' if safe else 'review_required',
            'counts': {'source_rows': len(snapshot['preferences']), 'candidate_rows': len(ready), 'blocked_rows': len(issues)},
            'mapping_issue_counts': mapping_counts, 'reason_counts': dict(sorted(counts.items())), 'issues': issues, 'candidates': ready,
            'writes_performed': 0, 'matching_policy': 'exact-reviewed', 'note': 'No import approval or SQL statements; candidates still require authorized review.'}


def load(path):
    # Read at most one MiB, reject duplicate JSON keys instead of last-value wins.
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result: raise ValueError('Duplicate JSON key')
            result[key] = value
        return result
    with Path(path).open('rb') as stream:
        data = stream.read(MAX_BYTES + 1)
    if len(data) > MAX_BYTES: raise ValueError('Snapshot exceeds one MiB')
    return json.loads(data.decode('utf-8'), object_pairs_hook=pairs)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('snapshot', help='Explicit local JSON snapshot; no live connection')
    args = parser.parse_args()
    try:
        report = audit(load(args.snapshot))
    except (ValueError, OSError, UnicodeError):
        print(json.dumps({'status': 'invalid_snapshot', 'writes_performed': 0, 'error': 'Input validation failed; review schema, scope and explicit matching policy.'}))
        return 1
    print(json.dumps(report, sort_keys=True, indent=2))
    return 0 if report['status'] == 'ready_for_review' else 2


if __name__ == '__main__':
    sys.exit(main())
