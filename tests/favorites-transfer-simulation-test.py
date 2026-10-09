import copy
import json
import importlib.util
from pathlib import Path
import sys
import unittest
from concurrent.futures import ThreadPoolExecutor

spec = importlib.util.spec_from_file_location('transfer_model', Path(__file__).parent / 'support/favorites_transfer_simulator.py')
model = importlib.util.module_from_spec(spec); sys.modules[spec.name] = model; spec.loader.exec_module(model)
Item, Simulator, Conflict = model.Item, model.Simulator, model.TransferConflict
A = '11111111-1111-4111-8111-111111111111'
B = '22222222-2222-4222-8222-222222222222'


class TransferTests(unittest.TestCase):
    def setUp(self):
        self.db = Simulator(); self.db.seed('synthetic-logo', 2, 7, A)
        self.item = Item('synthetic-logo', 2, 7, A, 'card.php', None)
    def tearDown(self): self.db.close()
    def test_insert_and_replay(self):
        self.assertEqual(self.db.apply('key', (self.item,))['inserted'], 1)
        before = self.db.snapshot()
        self.assertTrue(self.db.apply('key', (self.item,))['replay']); self.assertEqual(before, self.db.snapshot())
    def test_reused_key_changed_intent(self):
        self.db.apply('key', (self.item,)); before = self.db.snapshot()
        with self.assertRaises(Conflict): self.db.apply('key', (Item('synthetic-logo',2,7,A,'other.php',None),))
        self.assertEqual(before, self.db.snapshot())
    def test_stale_absence(self):
        self.db.apply('first', (self.item,)); before = self.db.snapshot()
        with self.assertRaises(Conflict): self.db.apply('second', (self.item,))
        self.assertEqual(before, self.db.snapshot())
    def test_raw_target_change_even_same_normalization(self):
        self.db.apply('first', (self.item,)); self.db.synthetic_change('synthetic-logo',2,A,' CARD.PHP ')
        before = self.db.snapshot()
        with self.assertRaises(Conflict): self.db.apply('next', (Item('synthetic-logo',2,7,A,'card.php','card.php'),))
        self.assertEqual(before, self.db.snapshot())
    def test_no_change_preserves_raw_value(self):
        self.db.apply('first', (self.item,)); self.db.synthetic_change('synthetic-logo',2,A,' CARD.PHP ')
        result = self.db.apply('next', (Item('synthetic-logo',2,7,A,'card.php',' CARD.PHP '),))
        self.assertEqual(result['unchanged'],1)
        self.assertEqual(self.db.snapshot()['favorites'][0][3],' CARD.PHP ')
    def test_no_overwrite(self):
        with self.assertRaises(Conflict): self.db.apply('key',(Item('synthetic-logo',2,7,A,'','card.php'),))
        self.assertEqual(self.db.snapshot()['favorites'],[])
    def test_actor_mapping_changed(self):
        self.db.synthetic_remap('synthetic-logo',2,7,B)
        with self.assertRaises(Conflict): self.db.apply('key',(self.item,))
        self.assertEqual(self.db.snapshot()['ledger'],[])
    def test_wrong_person(self):
        with self.assertRaises(Conflict): self.db.apply('key',(Item('synthetic-logo',2,8,A,'card.php',None),))
    def test_scope_isolation(self):
        for scope in [('other-logo',2),('synthetic-logo',3)]:
            self.db.seed(*scope,7,A)
            self.db.apply('same-key',(Item(*scope,7,A,'other.php',None),))
        self.db.apply('same-key',(self.item,))
        self.assertEqual(len(self.db.snapshot()['favorites']),3); self.assertEqual(len(self.db.snapshot()['ledger']),3)
    def test_mixed_batch_scope_rejected(self):
        with self.assertRaises(Conflict): self.db.apply('key',(self.item,Item('other-logo',2,8,B,'other.php',None)))
    def test_atomic_rollback_and_retry(self):
        self.db.seed('synthetic-logo',2,8,B)
        batch=(self.item,Item('synthetic-logo',2,8,B,'other.php',None))
        before=self.db.snapshot()
        with self.assertRaises(Conflict): self.db.apply('key',batch,fail_after=1)
        self.assertEqual(before,self.db.snapshot())
        self.assertEqual(self.db.apply('key',batch)['inserted'],2)
    def test_later_conflict_rolls_back_first_insert(self):
        self.db.seed('synthetic-logo',2,8,B,'existing.php'); before=self.db.snapshot()
        with self.assertRaises(Conflict): self.db.apply('key',(self.item,Item('synthetic-logo',2,8,B,'other.php',None)))
        self.assertEqual(before,self.db.snapshot())
    def test_parallel_same_key_once(self):
        with ThreadPoolExecutor(max_workers=10) as pool:
            results=list(pool.map(lambda _:self.db.apply('same-key',(self.item,)),range(10)))
        self.assertEqual(sum(not r['replay'] for r in results),1)
        self.assertEqual(len(self.db.snapshot()['favorites']),1)
    def test_parallel_different_keys_conflict(self):
        def apply(index):
            try: self.db.apply(str(index),(self.item,)); return 'ok'
            except Conflict: return 'conflict'
        with ThreadPoolExecutor(max_workers=10) as pool: results=list(pool.map(apply,range(10)))
        self.assertEqual(results.count('ok'),1); self.assertEqual(results.count('conflict'),9)
        self.assertEqual(len(self.db.snapshot()['ledger']),1)
    def test_replay_does_not_undo_later_user_change(self):
        self.db.apply('key',(self.item,)); self.db.synthetic_change('synthetic-logo',2,A,'new.php'); before=self.db.snapshot()
        self.assertTrue(self.db.apply('key',(self.item,))['replay']); self.assertEqual(before,self.db.snapshot())
    def test_duplicate_batch_rejected(self):
        with self.assertRaises(Conflict): self.db.apply('key',(self.item,self.item))
    def test_batch_order_does_not_change_identity(self):
        self.db.seed('synthetic-logo',2,8,B)
        batch=(self.item,Item('synthetic-logo',2,8,B,'other.php',None))
        self.db.apply('key',batch)
        self.assertTrue(self.db.apply('key',tuple(reversed(batch)))['replay'])
    def test_unicode_expected_value_never_accepted(self):
        for raw in ['K.php', '\u00a0card.php\u00a0']:
            with self.assertRaises(Conflict): self.db.apply('key',(Item('synthetic-logo',2,7,A,'k.php' if 'K' in raw else 'card.php',raw),))
    def test_invalid_value_and_scope(self):
        for item in [Item('synthetic-logo',True,7,A,'card.php',None), Item('synthetic-logo',2,7,A,'bad?.php',None), Item('synthetic-logo',2,7,A,'card.php,card.php',None)]:
            with self.assertRaises(Conflict): self.db.apply('key',(item,))
        self.assertEqual(self.db.snapshot()['favorites'],[])


class BridgeTests(unittest.TestCase):
    def setUp(self):
        self.snapshot=json.loads((Path(__file__).parent/'fixtures/favorites-dry-run.json').read_text())
        self.snapshot.update(schema_version=2,target_snapshot_complete=True,target_favorites=[])
    def test_snapshot_to_commit(self):
        original=copy.deepcopy(self.snapshot); items=model.prepare(self.snapshot)
        db=Simulator()
        try:
            db.seed('synthetic-logo',2,7,A)
            self.assertEqual(db.apply('bridge',items)['inserted'],1)
            self.assertEqual(original,self.snapshot)
        finally: db.close()
    def test_stale_after_prepare(self):
        items=model.prepare(self.snapshot); db=Simulator()
        try:
            db.seed('synthetic-logo',2,7,A,'later.php'); before=db.snapshot()
            with self.assertRaises(Conflict): db.apply('bridge',items)
            self.assertEqual(before,db.snapshot())
        finally: db.close()
    def test_conflict_snapshot_never_prepared(self):
        self.snapshot['target_favorites']=[{'connection':'synthetic-logo','firma':2,'actor_id':A,'favorites':'different.php'}]
        with self.assertRaises(Conflict): model.prepare(self.snapshot)
    def test_v1_never_prepared(self):
        self.snapshot.pop('target_favorites'); self.snapshot.pop('target_snapshot_complete'); self.snapshot['schema_version']=1
        with self.assertRaises(Conflict): model.prepare(self.snapshot)
    def test_items_do_not_follow_snapshot_mutation(self):
        items=model.prepare(self.snapshot); self.snapshot['preferences'][0]['favorites']='changed.php'
        self.assertEqual(items[0].value,'stok/list.php')
    def test_scope_mismatch_never_prepared(self):
        self.snapshot['preferences'][0]['firma']=3
        with self.assertRaises(ValueError): model.prepare(self.snapshot)


if __name__=='__main__': unittest.main()
