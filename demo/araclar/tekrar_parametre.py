"""Aynı adlı PDO parametresinin bir SQL ifadesinde birden çok kez geçtiği yerleri bulur.

pdo_sqlsrv yerel (native) prepare kullanır ve aynı :ad'ın tekrarını desteklemez:
sorgu "COUNT field incorrect" hatasıyla başarısız olur. Kullanım:
  python3 demo/araclar/tekrar_parametre.py
Çıkış kodu: bulgu varsa 1.
"""
import os, re, sys

KOK = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
# ->prepare("...") / ->prepare('...') — yalnız doğrudan dize argümanı (değişkenle
# kurulan sorgular kapsam dışıdır; onlar duman testiyle yakalanır).
DIZE = re.compile(r'->prepare\(\s*(["\'])(.*?)(?<!\\)\1\s*\)', re.S)
SQL = re.compile(r'\b(SELECT|INSERT|UPDATE|DELETE|MERGE)\b', re.I)
PARAM = re.compile(r'(?<![:\w]):([A-Za-z_]\w*)')
bulgu = 0
for kok, dizinler, dosyalar in os.walk(KOK):
    dizinler[:] = [d for d in dizinler if d not in ('vendor', '.git', 'demo', 'node_modules')]
    for ad in dosyalar:
        if not ad.endswith(('.php', '.inc')):
            continue
        yol = os.path.join(kok, ad)
        metin = open(yol, encoding='utf-8', errors='ignore').read()
        for m in DIZE.finditer(metin):
            s = m.group(2)
            if not SQL.search(s) or len(s) < 30:
                continue
            gorulen = {}
            for p in PARAM.findall(s):
                gorulen[p] = gorulen.get(p, 0) + 1
            tekrar = [p for p, n in gorulen.items() if n > 1]
            if tekrar:
                satir = metin.count('\n', 0, m.start()) + 1
                print(f'{os.path.relpath(yol, KOK)}:{satir}: tekrar eden parametre: {", ".join(":" + t for t in tekrar)}')
                bulgu += 1
sys.exit(1 if bulgu else 0)
