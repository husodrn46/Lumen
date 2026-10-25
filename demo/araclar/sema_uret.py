"""Demo LOGO Tiger şemasını üretir: demo/sql/01_logo_sema.sql

Gerçek LOGO şemasının küçük bir alt kümesidir: yalnız Lumen'in okuduğu/yazdığı
tablolar ve kolonlar. Kolon listesi kolon_cikar.py çıktısı + EK_KOLONLAR'dan
gelir; tipler LOGO adlandırma kurallarından türetilir. Gerçek LOGO'da LV_ ile
başlayanlar view'dır; demoda düz tablo olarak tutulur.

Kullanım: python3 demo/araclar/sema_uret.py
"""
import json, os, re, subprocess, sys

BURASI = os.path.dirname(os.path.abspath(__file__))
KOK = os.path.dirname(os.path.dirname(BURASI))

ON_EK = {  # tablo -> önek türü
    'CLCARD': 'F', 'ITEMS': 'F', 'UNITSETL': 'F', 'ITMUNITA': 'F', 'UNITBARCODE': 'F',
    'PRCLIST': 'F', 'KSCARD': 'F', 'SRVCARD': 'F', 'BNCARD': 'F', 'BANKACC': 'F', 'UNITSETF': 'F',
    'ORFICHE': 'D', 'ORFLINE': 'D', 'STLINE': 'D', 'STFICHE': 'D', 'CLFLINE': 'D', 'CLFICHE': 'D',
    'INVOICE': 'D', 'CSTRANS': 'D', 'CSCARD': 'D', 'CSROLL': 'D', 'KSLINES': 'D',
    'GNTOTCL': 'V', 'STINVTOT': 'V',
}
# Kaynak kodda takma adsız kullanılan ya da LOGO'da zorunlu bilinen kolonlar.
EK_KOLONLAR = {
    'ITEMS': 'STGRPCODE SPECODE SPECODE2 SPECODE3 CYPHCODE PRODUCERCODE NAME2 NAME3 VAT SELLVAT RETURNVAT CLASSTYPE',
    'CLCARD': 'SPECODE SPECODE2 CYPHCODE ADDR1 ADDR2 TAXNR TAXOFFICE TCKNO TOWN TELNRS2 FAXNR INCHARGE DEFINITION2 COUNTRY CCURRENCY',
    'UNITSETL': 'NAME',
    'CLFLINE': 'MODULENR TRNET',
    'CSCARD': 'CARDMD',
    'BNCARD': 'CODE DEFINITION_ ACTIVE',
    'BANKACC': 'DEFINITION_ ACTIVE BANKREF',
    'ORFLINE': 'DISCPER',
    'STINVTOT': 'INVENNO STOCKREF ONHAND',
    'GNTOTCL': 'DEBIT CREDIT',
}

METIN = re.compile(r'^(CODE|NAME\d?|DEFINITION_?\d?|DEFINITION2|.*EXP\d?|LINEEXP|SPECODE\d?|CYPHCODE|FICHENO|INVNO|DOCODE|'
                   r'DOCTRACKINGNR|CUSTORDNO|BARCODE|CITY|DISTRICT|COUNTRY|TOWN|ADDR\d|EMAILADDR|TELNRS\d|FAXNR|'
                   r'TAXNR|TAXOFFICE|TCKNO|INCHARGE|BANKNAME|NEWSERINO|PORTFOYNO|OWING|ROLLNO|STGRPCODE|PRODUCERCODE|'
                   r'GUID|GLOBALID|DELVRYCODE|SHPAGNCOD|SHPTYPCOD|TRADINGGRP|KODU|UNVANI|SEHIR|CARIID|SPECODE2|'
                   r'CCURRENCY|BNBRANCHNO|TRANNO|SERINO|IBAN|KEFIL2?|MUHABIR|BNACCOUNTNO|DOCODE|CAMPAIGNREFS\d|CMPGLINEREFS\d|POINTCAMPREFS\d|CAMPPOINTS\d|PURCHOFFNR)$')
TARIH = re.compile(r'^(DATE_|.*DATE|.*DT|CAPIBLOCK_.*DATE|ORGDUEDATE|DUEDATE|SETDATE)$')
ONDALIK = re.compile(r'^(AMOUNT|PRICE|TOTAL|.*TOTAL.*|NET.*|LINENET|VAT|VATAMNT|VATMATRAH|DISCPER|DIST.*|.*RATE|'
                     r'CONVFACT\d|CREDIT|DEBIT|CUM_CREDIT|ONHAND|WEIGHT|WIDTH|LENGTH|HEIGHT|TRNET|REPORTNET|ADD.*AMOUNT|'
                     r'.*AMNT.*|.*AMOUNT|ADDTAX.*|EXADDTAX.*|ORGPRICE|PRPRICE|RPRICE|CHECKPRICE|CHECKAMOUNT|ADVANCEPAYM|'
                     r'UINFO\d|GROSSUINFO\d|DEDUCTIONPART\d|CAMPPOINT|ADDDISCOUNTS|ADDEXPENSES|TOTALVAT|PLNAMOUNT|'
                     r'NETDISCPERC|DISTEXP|DISTPROM|DISTRESERVED|DEMPEGGEDAMNT)$')


def kolon_tipi(tablo, kolon):
    if kolon == 'LOGICALREF':
        return 'INT IDENTITY(1,1) PRIMARY KEY' if ON_EK.get(tablo) != 'V' else 'INT NULL'
    if kolon == 'NETDISCFLAG':
        return 'SMALLINT NOT NULL DEFAULT 0'
    if TARIH.match(kolon):
        return 'DATETIME NULL'
    if METIN.match(kolon):
        return "NVARCHAR(250) NULL DEFAULT ''"
    if ONDALIK.match(kolon):
        return 'FLOAT NOT NULL DEFAULT 0'
    return 'INT NOT NULL DEFAULT 0'


def main():
    if '--yalniz-ambar' in sys.argv:
        hedef = os.path.join(KOK, 'demo', 'sql', '01_logo_sema.sql')
        with open(hedef, 'a', encoding='utf-8') as f:
            f.write("\nIF OBJECT_ID('dbo.L_CAPIWHOUSE', 'U') IS NULL\nCREATE TABLE dbo.L_CAPIWHOUSE (NR SMALLINT NOT NULL, FIRMNR SMALLINT NOT NULL, NAME NVARCHAR(80), PRIMARY KEY(FIRMNR,NR));\nGO\n")
        return
    kolonlar = json.loads(subprocess.check_output([sys.executable, os.path.join(BURASI, 'kolon_cikar.py'), '--json']))
    satirlar = ['-- ÜRETİLMİŞ DOSYA — elle düzenlemeyin: python3 demo/araclar/sema_uret.py',
                '-- Lumen demo veritabanı için asgari LOGO Tiger şeması (firma 001; dönem 02 güncel, 01 önceki/boş).',
                '-- Gerçek bir LOGO kurulumunun yerini TUTMAZ; yalnız geliştirme ve test içindir.', '']
    adlar = []
    for tablo in sorted(ON_EK):
        tur = ON_EK[tablo]
        onekler = {'F': ['LG_001_'], 'D': ['LG_001_02_', 'LG_001_01_'], 'V': ['LV_001_02_', 'LV_001_01_']}[tur]
        adlar += [(tablo, o + tablo) for o in onekler]  # 01 = önceki dönem (boş)
    for tablo, ad in adlar:
        k = set(kolonlar.get(tablo, [])) | set(EK_KOLONLAR.get(tablo, '').split())
        k.add('LOGICALREF')
        sirali = ['LOGICALREF'] + sorted(k - {'LOGICALREF'})
        satirlar.append(f"IF OBJECT_ID('dbo.{ad}', 'U') IS NULL\nCREATE TABLE dbo.{ad} (")
        satirlar.append(',\n'.join(f'    [{c}] {kolon_tipi(tablo, c)}' for c in sirali))
        satirlar.append(');\nGO\n')
    # LG_SLSMAN (firma bağımsız satış elemanı / kullanıcı tablosu)
    sk = sorted(set(kolonlar.get('LG_SLSMAN', [])) - {'LOGICALREF'})
    satirlar.append("IF OBJECT_ID('dbo.LG_SLSMAN', 'U') IS NULL\nCREATE TABLE dbo.LG_SLSMAN (")
    satirlar.append(',\n'.join(['    [LOGICALREF] INT IDENTITY(1,1) PRIMARY KEY']
                               + [f'    [{c}] {kolon_tipi("LG_SLSMAN", c)}' for c in sk]))
    satirlar.append(');\nGO\n')
    # Ortak veri katmanının LOGO ambar okuması için sistem tablosu.
    satirlar.append("IF OBJECT_ID('dbo.L_CAPIWHOUSE', 'U') IS NULL\nCREATE TABLE dbo.L_CAPIWHOUSE (NR SMALLINT NOT NULL, FIRMNR SMALLINT NOT NULL, NAME NVARCHAR(80), PRIMARY KEY(FIRMNR,NR));\nGO\n")
    hedef = os.path.join(KOK, 'demo', 'sql', '01_logo_sema.sql')
    open(hedef, 'w', encoding='utf-8').write('\n'.join(satirlar))
    print('yazıldı:', os.path.relpath(hedef, KOK))


main()
