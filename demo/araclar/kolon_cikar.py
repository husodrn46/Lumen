"""Lumen kaynak kodundaki SQL'den LOGO/M_ tablolarının kullandığı kolonları çıkarır.

Demo şemasını (demo/sql/01_logo_sema.sql) güncel tutmaya yardımcı geliştirici aracı.
Kullanım: python3 demo/araclar/kolon_cikar.py [--json]
"""
import json, os, re, sys
from collections import defaultdict

KOK = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
ON = r'\{\$(?:firma|firmadonem|firmadonemx|eskifirma|eskifirmadonem|eskifirmadonemx|fd|donemBulundu|donemTablo|prefix|prevDonem|tbl|p)\}'
TABLO = re.compile(r'(?:' + ON + r'([A-Z][A-Z0-9_]+)|\b(LG_SLSMAN|M_[A-Z0-9_]+))'
                   r'(?:\s+WITH\s*\([^)]*\))?(?:\s+(?:AS\s+)?(?!ON\b|WHERE\b|SET\b|INNER\b|LEFT\b|JOIN\b|WITH\b|VALUES\b|GROUP\b|ORDER\b)([A-Za-z][A-Za-z0-9_]*))?', re.I)
INSERT = re.compile(r'INSERT\s+INTO\s+(?:' + ON + r'([A-Z][A-Z0-9_]+)|(LG_SLSMAN|M_[A-Z0-9_]+))\s*\(([^)]*)\)', re.I)
ATAMA = re.compile(r'UPDATE\s+(?:' + ON + r'([A-Z][A-Z0-9_]+)|(LG_SLSMAN|M_[A-Z0-9_]+))\s+SET\s+(.{0,6000}?)\s+WHERE', re.I | re.S)

kolonlar = defaultdict(set)
for kok, dizinler, dosyalar in os.walk(KOK):
    dizinler[:] = [d for d in dizinler if d not in ('vendor', '.git', 'demo', 'node_modules')]
    for ad in dosyalar:
        if not ad.endswith(('.php', '.inc')):
            continue
        metin = open(os.path.join(kok, ad), encoding='utf-8', errors='ignore').read()
        takma = defaultdict(set)
        for m in TABLO.finditer(metin):
            t = (m.group(1) or m.group(2)).upper()
            kolonlar.setdefault(t, set())
            if m.group(3):
                takma[m.group(3)].add(t)
        for m in INSERT.finditer(metin):
            t = (m.group(1) or m.group(2)).upper()
            for k in m.group(3).split(','):
                k = k.strip().strip('[]')
                if re.fullmatch(r'[A-Z][A-Z0-9_]*', k):
                    kolonlar[t].add(k)
        for m in ATAMA.finditer(metin):
            t = (m.group(1) or m.group(2)).upper()
            for k in re.findall(r'(?:^|,)\s*(?:\w+\.)?\[?([A-Z][A-Z0-9_]*)\]?\s*=', m.group(3)):
                kolonlar[t].add(k)
        for m in re.finditer(r'\b([A-Za-z][A-Za-z0-9_]*)\.\[?([A-Z][A-Z0-9_]*)\]?\b', metin):
            for t in takma.get(m.group(1), ()):
                kolonlar[t].add(m.group(2))

if '--json' in sys.argv:
    print(json.dumps({k: sorted(v) for k, v in sorted(kolonlar.items())}, indent=1))
else:
    for t, k in sorted(kolonlar.items()):
        print(f'{t} ({len(k)}): ' + ' '.join(sorted(k)))
