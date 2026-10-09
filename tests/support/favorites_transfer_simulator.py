"""Test-only atomic insert/no-change model; private SQLite :memory:, never a live adapter."""
from dataclasses import dataclass
import copy
import importlib.util
from pathlib import Path
import hashlib
import json
import re
import sqlite3
import threading


_spec = importlib.util.spec_from_file_location('offline_favorites_audit', Path(__file__).resolve().parents[2] / 'tools/favorites_dry_run.py')
_planner = importlib.util.module_from_spec(_spec); _spec.loader.exec_module(_planner)


class TransferConflict(RuntimeError):
    pass


@dataclass(frozen=True)
class Item:
    connection: str
    firma: int
    personel: int
    actor: str
    value: str
    expected_raw: str | None


class Simulator:
    def __init__(self):
        # No configurable DSN/path: simulation cannot connect to a real database.
        self._db = sqlite3.connect(':memory:', isolation_level=None, check_same_thread=False)
        self._lock = threading.RLock()
        self._db.executescript('''
            CREATE TABLE links(connection TEXT, firma INTEGER, personel INTEGER, actor TEXT,
                PRIMARY KEY(connection,firma,personel), UNIQUE(connection,firma,actor));
            CREATE TABLE favorites(connection TEXT, firma INTEGER, actor TEXT, value TEXT,
                PRIMARY KEY(connection,firma,actor));
            CREATE TABLE ledger(connection TEXT, firma INTEGER, request_key TEXT, fingerprint TEXT, result TEXT,
                PRIMARY KEY(connection,firma,request_key));
        ''')

    @staticmethod
    def _validate(item):
        if not isinstance(item, Item): raise TransferConflict('Invalid item')
        if not isinstance(item.connection, str) or not re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,63}', item.connection): raise TransferConflict('Invalid scope')
        if type(item.firma) is not int or not 1 <= item.firma <= 999 or type(item.personel) is not int or not 1 <= item.personel <= 999999999: raise TransferConflict('Invalid scope')
        if not isinstance(item.actor, str) or not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}', item.actor): raise TransferConflict('Invalid actor')
        if not isinstance(item.value, str): raise TransferConflict('Invalid favorite value')
        keys = item.value.split(',') if item.value else []
        if len(keys) > 40 or len(set(keys)) != len(keys) or any(not re.fullmatch(r'[a-z0-9_./-]{1,80}', key) for key in keys): raise TransferConflict('Invalid favorite value')
        if item.expected_raw is not None:
            if not isinstance(item.expected_raw, str) or len(item.expected_raw) > 1048576: raise TransferConflict('Invalid expected value')
            normalized, _, invalid = _planner.normalized(item.expected_raw)
            if invalid or normalized != item.value: raise TransferConflict('Overwriting existing preferences is forbidden')

    def seed(self, connection, firma, personel, actor, value=None):
        # Synthetic setup helper only, not part of an import path.
        self._validate(Item(connection, firma, personel, actor, '', None))
        with self._lock:
            self._db.execute('INSERT INTO links VALUES(?,?,?,?)', (connection, firma, personel, actor))
            if value is not None:
                if not isinstance(value, str): raise TransferConflict('Invalid fixture')
                self._db.execute('INSERT INTO favorites VALUES(?,?,?,?)', (connection, firma, actor, value))

    def snapshot(self):
        with self._lock:
            return {table: self._db.execute('SELECT * FROM ' + table + ' ORDER BY 1,2,3').fetchall() for table in ('links', 'favorites', 'ledger')}

    def apply(self, request_key, items, fail_after=None):
        if not isinstance(request_key, str) or not re.fullmatch(r'[a-zA-Z0-9_-]{1,64}', request_key): raise TransferConflict('Invalid request key')
        if not isinstance(items, tuple) or not 1 <= len(items) <= 10000: raise TransferConflict('Invalid batch')
        for item in items: self._validate(item)
        scope = (items[0].connection, items[0].firma)
        if any((i.connection, i.firma) != scope for i in items): raise TransferConflict('Mixed scope')
        if len({i.actor for i in items}) != len(items) or len({i.personel for i in items}) != len(items): raise TransferConflict('Duplicate batch identity')
        # Order-independent identity; the ledger carries a digest, not preference text.
        payload = sorted((i.connection, i.firma, i.personel, i.actor, i.value, i.expected_raw) for i in items)
        fingerprint = hashlib.sha256(json.dumps(payload, ensure_ascii=True, separators=(',', ':')).encode()).hexdigest()
        with self._lock:
            try:
                self._db.execute('BEGIN IMMEDIATE')
                prior = self._db.execute('SELECT fingerprint,result FROM ledger WHERE connection=? AND firma=? AND request_key=?', scope + (request_key,)).fetchone()
                if prior:
                    if prior[0] != fingerprint: raise TransferConflict('Request key reused for different intent')
                    self._db.execute('COMMIT')
                    return dict(json.loads(prior[1]), replay=True)
                inserted = unchanged = 0
                for index, item in enumerate(items):
                    actor = self._db.execute('SELECT actor FROM links WHERE connection=? AND firma=? AND personel=?', (item.connection, item.firma, item.personel)).fetchone()
                    if actor is None or actor[0] != item.actor: raise TransferConflict('Actor mapping changed')
                    target = self._db.execute('SELECT value FROM favorites WHERE connection=? AND firma=? AND actor=?', (item.connection, item.firma, item.actor)).fetchone()
                    if (target[0] if target else None) != item.expected_raw: raise TransferConflict('Target changed after snapshot')
                    if target is None:
                        self._db.execute('INSERT INTO favorites VALUES(?,?,?,?)', (item.connection, item.firma, item.actor, item.value)); inserted += 1
                    else: unchanged += 1
                    if fail_after == index + 1: raise TransferConflict('Injected synthetic failure')
                result = {'inserted': inserted, 'unchanged': unchanged}
                self._db.execute('INSERT INTO ledger VALUES(?,?,?,?,?)', scope + (request_key, fingerprint, json.dumps(result)))
                self._db.execute('COMMIT')
                return dict(result, replay=False)
            except Exception:
                if self._db.in_transaction: self._db.execute('ROLLBACK')
                raise

    def synthetic_change(self, connection, firma, actor, value):
        with self._lock:
            self._db.execute('UPDATE favorites SET value=? WHERE connection=? AND firma=? AND actor=?', (value, connection, firma, actor))

    def synthetic_remap(self, connection, firma, personel, actor):
        with self._lock:
            self._db.execute('UPDATE links SET actor=? WHERE connection=? AND firma=? AND personel=?', (actor, connection, firma, personel))

    def close(self):
        with self._lock: self._db.close()


def prepare(snapshot):
    """Test-only bridge from reviewed v2 snapshot to immutable transaction items."""
    snapshot = copy.deepcopy(snapshot)
    report = _planner.audit(snapshot)
    if snapshot['schema_version'] != 2 or report['status'] != 'ready_for_review':
        raise TransferConflict('Complete conflict-free target comparison required')
    people = {row['code']: row['personel'] for row in snapshot['persons']}
    links = {row['personel']: row['actor_id'].lower() for row in snapshot['actor_links']}
    targets = {row['actor_id'].lower(): row['favorites'] for row in snapshot['target_favorites']}
    items = []
    for candidate in report['candidates']:
        source = snapshot['preferences'][candidate['row_index']]
        personel = people[source['user_code']]; actor = links[personel]
        value, _, _ = _planner.normalized(source['favorites'])
        items.append(Item(snapshot['connection'], snapshot['firma'], personel, actor, value, targets.get(actor)))
    return tuple(items)
