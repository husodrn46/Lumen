#!/usr/bin/env python3
"""
Bağımsız mod için belge (sipariş, irsaliye, fatura, stok hareketi) LOGO uyumlu
görünümlerini ve yazma tetikleyicilerini üretir:

    python3 scripts/gelistirme/logo_uyum_uret.py   →  sql/lumen_cekirdek/07_logo_uyum_belge.sql

Kolon listesi ve tipleri demo/sql/01_logo_sema.sql'den (kod tabanının kullandığı LOGO
kolonları) okunur. Eşlenmeyen kolonlar okumada tipine göre 0 / NULL döner, yazmada yok
sayılır. Kod yeni bir LOGO kolonuna başvurduğunda: önce demo şeması
(demo/araclar/sema_uret.py), sonra bu betik yeniden çalıştırılır.

Kodlamalar (LOGO ↔ Lumen):
  Sipariş TRCODE 1 satış / 2 alış            ↔ LM_BELGE.TUR 1, YON 1 / 2
  Fatura  TRCODE 8 satış, 1 alış, 3 satış iade, 6 alış iade ↔ TUR 3/4, YON 1/2
  İrsaliye/stok fişi TRCODE 8 satış, 1 alış, 13 üretim, 50 sayım fazlası, 51 sayım eksiği
  Döviz TRCURR 0 TL, 1 USD, 20 EUR, 17 GBP    ↔ PARA_BIRIMI
  İptal CANCELLED 1                           ↔ DURUM 9
  Ambar SOURCEINDEX                           ↔ LM_DEPO.NO
"""
import re
from pathlib import Path

KOK = Path(__file__).resolve().parents[2]
SEMA = (KOK / 'demo/sql/01_logo_sema.sql').read_text(encoding='utf-8')
CIKTI = KOK / 'sql/lumen_cekirdek/07_logo_uyum_belge.sql'


def logo_kolonlari(tablo: str) -> list[tuple[str, str]]:
    m = re.search(r'CREATE TABLE dbo\.' + tablo + r' \((.*?)\n\);', SEMA, re.S)
    if not m:
        raise SystemExit(f'demo şemasında yok: {tablo}')
    return [(k, t) for k, t in re.findall(r'^\s+\[(\w+)\]\s+([A-Z]+(?:\(\d+\))?)', m.group(1), re.M)]


def bos(tip: str) -> str:
    """Eşlenmeyen kolon: sayısal 0, diğerleri NULL (tipiyle)."""
    if tip.startswith(('INT', 'FLOAT', 'SMALLINT', 'DECIMAL', 'BIT')):
        return f'CAST(0 AS {tip})'
    return f'CAST(NULL AS {tip})'


PB_KOD = "CASE {p} WHEN 'USD' THEN 1 WHEN 'EUR' THEN 20 WHEN 'GBP' THEN 17 ELSE 0 END"
KOD_PB = "CASE {k} WHEN 1 THEN 'USD' WHEN 20 THEN 'EUR' WHEN 17 THEN 'GBP' ELSE 'TRY' END"

# ---------------------------------------------------------------------- sipariş başlığı
ORFICHE_OKU = {
    'LOGICALREF': 'B.ID',
    'TRCODE': 'CASE B.YON WHEN 1 THEN 1 ELSE 2 END',
    'FICHENO': 'B.NO',
    'DATE_': 'CAST(B.TARIH AS DATETIME)',
    'TIME_': 'B.SAAT_KODU',
    'CLIENTREF': 'B.CARI_REF',
    'SALESMANREF': 'ISNULL(B.PERSONEL_ID, 0)',
    'SOURCEINDEX': 'ISNULL(D.NO, 0)',
    'CANCELLED': 'CASE WHEN B.DURUM = 9 THEN 1 ELSE 0 END',
    'STATUS': 'B.ONAY_DURUMU',
    'GROSSTOTAL': 'B.BRUT', 'TOTALDISCOUNTS': 'B.ISKONTO', 'TOTALDISCOUNTED': 'B.BRUT - B.ISKONTO',
    'TOTALVAT': 'B.KDV', 'NETTOTAL': 'B.NET', 'REPORTNET': 'B.NET', 'REPORTRATE': '1', 'ADDDISCOUNTS': 'B.EK_ISKONTO',
    'TRCURR': PB_KOD.format(p='B.PARA_BIRIMI'),
    'TRRATE': "CASE WHEN B.PARA_BIRIMI = 'TRY' THEN 0 ELSE B.KUR END",
    'TRNET': 'ISNULL(B.DOVIZ_NET, 0)',
    'GENEXP1': 'B.ACIKLAMA', 'GENEXP2': 'B.ACIKLAMA2', 'GENEXP3': 'B.ACIKLAMA3', 'GENEXP4': 'B.ACIKLAMA4',
    'DOCODE': 'B.DOKUMAN_NO', 'SPECODE': 'B.OZEL_KOD', 'CYPHCODE': 'B.YETKI_KODU',
    'PRINTCNT': 'B.YAZDIRMA_SAYISI', 'GUID': 'B.GUID', 'PAYDEFREF': 'ISNULL(B.ODEME_PLANI_ID, 0)',
    'CAPIBLOCK_CREADEDDATE': 'CAST(B.OLUSTURMA AS DATETIME)', 'CAPIBLOCK_CREATEDBY': 'ISNULL(B.OLUSTURAN_ID, 0)',
    'CAPIBLOCK_CREATEDHOUR': 'DATEPART(HOUR, B.OLUSTURMA)', 'CAPIBLOCK_CREATEDMIN': 'DATEPART(MINUTE, B.OLUSTURMA)',
    'CAPIBLOCK_CREATEDSEC': 'DATEPART(SECOND, B.OLUSTURMA)',
    'CAPIBLOCK_MODIFIEDDATE': 'CAST(B.GUNCELLEME AS DATETIME)', 'CAPIBLOCK_MODIFIEDBY': 'ISNULL(B.GUNCELLEYEN_ID, 0)',
    'CAPIBLOCK_MODIFIEDHOUR': 'ISNULL(DATEPART(HOUR, B.GUNCELLEME), 0)',
    'CAPIBLOCK_MODIFIEDMIN': 'ISNULL(DATEPART(MINUTE, B.GUNCELLEME), 0)',
    'CAPIBLOCK_MODIFIEDSEC': 'ISNULL(DATEPART(SECOND, B.GUNCELLEME), 0)',
    'AFFECTRISK': '1', 'AFFECTCOLLATRL': '0', 'RECSTATUS': '1',
}
ORFICHE_YAZ = {   # LM_BELGE kolonu → inserted (I) ifadesi
    'YON': 'CASE WHEN I.TRCODE = 2 THEN 2 ELSE 1 END',
    'NO': "ISNULL(I.FICHENO, '')",
    'TARIH': 'CAST(ISNULL(I.DATE_, SYSDATETIME()) AS DATE)',
    'SAAT_KODU': 'I.TIME_',
    'CARI_ID': 'NULLIF(I.CLIENTREF, 0)',
    'PERSONEL_ID': 'NULLIF(I.SALESMANREF, 0)',
    'DEPO_ID': '(SELECT TOP 1 DX.ID FROM dbo.LM_DEPO DX WHERE DX.NO = ISNULL(I.SOURCEINDEX, 0))',
    'PARA_BIRIMI': KOD_PB.format(k='ISNULL(I.TRCURR, 0)'),
    'KUR': 'CASE WHEN I.TRRATE > 0 THEN I.TRRATE ELSE 1 END',
    'BRUT': 'ISNULL(I.GROSSTOTAL, 0)', 'ISKONTO': 'ISNULL(I.TOTALDISCOUNTS, 0)', 'KDV': 'ISNULL(I.TOTALVAT, 0)',
    'NET': 'ISNULL(I.NETTOTAL, 0)', 'DOVIZ_NET': 'NULLIF(I.TRNET, 0)', 'EK_ISKONTO': 'ISNULL(I.ADDDISCOUNTS, 0)',
    'ACIKLAMA': 'I.GENEXP1', 'ACIKLAMA2': 'I.GENEXP2', 'ACIKLAMA3': 'I.GENEXP3', 'ACIKLAMA4': 'I.GENEXP4',
    'DOKUMAN_NO': 'I.DOCODE', 'OZEL_KOD': 'I.SPECODE', 'YETKI_KODU': 'I.CYPHCODE',
    'ONAY_DURUMU': 'ISNULL(I.STATUS, 4)', 'YAZDIRMA_SAYISI': 'ISNULL(I.PRINTCNT, 0)', 'GUID': 'I.GUID',
    'ODEME_PLANI_ID': 'NULLIF(I.PAYDEFREF, 0)',
}

# ---------------------------------------------------------------------- sipariş satırı
ORFLINE_OKU = {
    'LOGICALREF': 'S.ID', 'ORDFICHEREF': 'S.BELGE_ID', 'LINENO_': 'S.SIRA', 'LINETYPE': 'S.SATIR_TURU',
    'STOCKREF': 'ISNULL(S.STOK_ID, 0)', 'AMOUNT': 'S.MIKTAR', 'PRICE': 'S.FIYAT', 'TOTAL': 'S.TUTAR',
    'DISTDISC': 'S.ISKONTO_TUTAR', 'DISTCOST': 'S.MASRAF_TUTAR', 'DISCPER': 'S.ISKONTO_ORANI',
    'VAT': 'S.KDV_ORANI', 'VATAMNT': 'S.KDV_TUTAR', 'VATMATRAH': 'S.KDV_MATRAH', 'LINENET': 'S.NET',
    'UOMREF': 'ISNULL(SB.ID, 0)', 'USREF': 'ISNULL(S.STOK_ID, 0)', 'UINFO1': '1', 'UINFO2': 'S.CARPAN',
    'SHIPPEDAMOUNT': 'S.TESLIM_MIKTAR', 'LINEEXP': 'S.ACIKLAMA', 'DUEDATE': 'CAST(S.VADE AS DATETIME)',
    'PRPRICE': 'ISNULL(S.DOVIZ_FIYAT, 0)', 'PRCURR': PB_KOD.format(p='B.PARA_BIRIMI'),
    'TRCODE': 'CASE B.YON WHEN 1 THEN 1 ELSE 2 END', 'TRCURR': PB_KOD.format(p='B.PARA_BIRIMI'),
    'TRRATE': "CASE WHEN B.PARA_BIRIMI = 'TRY' THEN 0 ELSE B.KUR END",
    'DATE_': 'CAST(B.TARIH AS DATETIME)', 'TIME_': 'B.SAAT_KODU', 'CLIENTREF': 'B.CARI_REF',
    'SALESMANREF': 'ISNULL(B.PERSONEL_ID, 0)', 'SOURCEINDEX': 'ISNULL(D.NO, 0)', 'STATUS': 'B.ONAY_DURUMU',
    'CANCELLED': 'CASE WHEN B.DURUM = 9 OR S.IPTAL = 1 THEN 1 ELSE 0 END', 'SPECODE': 'S.OZEL_KOD',
    'CLOSED': 'CAST(S.KAPALI AS INT)', 'RESERVEAMOUNT': 'ISNULL(S.REZERVE_MIKTAR, 0)',
    'RESERVEDATE': 'CAST(S.REZERVE_TARIH AS DATETIME)', 'GUID': 'S.GUID', 'REPORTRATE': '1',
    'AFFECTRISK': '1', 'RECSTATUS': '1',
}
ORFLINE_YAZ = {
    'BELGE_ID': 'I.ORDFICHEREF', 'SIRA': 'ISNULL(I.LINENO_, 0)', 'SATIR_TURU': 'ISNULL(I.LINETYPE, 0)',
    'STOK_ID': 'NULLIF(I.STOCKREF, 0)',
    'BIRIM_ID': 'COALESCE((SELECT TOP 1 SBX.BIRIM_ID FROM dbo.LM_STOK_BIRIM SBX WHERE SBX.ID = I.UOMREF),'
                ' (SELECT TOP 1 SX.ANA_BIRIM_ID FROM dbo.LM_STOK SX WHERE SX.ID = NULLIF(I.STOCKREF, 0)))',
    'CARPAN': 'CASE WHEN I.UINFO1 > 0 AND I.UINFO2 > 0 THEN I.UINFO2 / I.UINFO1'
              ' ELSE ISNULL((SELECT TOP 1 SBX.CARPAN FROM dbo.LM_STOK_BIRIM SBX WHERE SBX.ID = I.UOMREF), 1) END',
    'MIKTAR': 'ISNULL(I.AMOUNT, 0)', 'FIYAT': 'ISNULL(I.PRICE, 0)', 'TUTAR': 'ISNULL(I.TOTAL, 0)',
    'ISKONTO_TUTAR': 'ISNULL(I.DISTDISC, 0)', 'MASRAF_TUTAR': 'ISNULL(I.DISTCOST, 0)',
    'ISKONTO_ORANI': 'ISNULL(I.DISCPER, 0)', 'KDV_ORANI': 'ISNULL(I.VAT, 0)', 'KDV_TUTAR': 'ISNULL(I.VATAMNT, 0)',
    'KDV_MATRAH': 'ISNULL(I.VATMATRAH, 0)', 'NET': 'ISNULL(I.LINENET, 0)',
    'TESLIM_MIKTAR': 'ISNULL(I.SHIPPEDAMOUNT, 0)', 'ACIKLAMA': 'I.LINEEXP', 'VADE': 'CAST(I.DUEDATE AS DATE)',
    'DOVIZ_FIYAT': 'NULLIF(I.PRPRICE, 0)', 'OZEL_KOD': 'I.SPECODE', 'KAPALI': 'ISNULL(I.CLOSED, 0)',
    'REZERVE_MIKTAR': 'NULLIF(I.RESERVEAMOUNT, 0)', 'REZERVE_TARIH': 'CAST(I.RESERVEDATE AS DATE)',
    'GUID': 'I.GUID', 'IPTAL': 'ISNULL(I.CANCELLED, 0)',
}

# ---------------------------------------------------------------------- fatura / irsaliye / stok satırı (salt okunur)
FATURA_TRCODE = 'CASE WHEN B.TUR = 3 AND B.YON = 1 THEN 8 WHEN B.TUR = 3 THEN 1 WHEN B.YON = 1 THEN 3 ELSE 6 END'
STOKFIS_TRCODE = ('CASE WHEN B.TUR = 2 AND B.YON = 1 THEN 8 WHEN B.TUR = 2 THEN 1 WHEN B.TUR = 5 THEN 13'
                  ' WHEN B.TUR = 6 AND B.YON = 2 THEN 50 WHEN B.TUR = 6 THEN 51'
                  ' WHEN B.TUR = 3 AND B.YON = 1 THEN 8 WHEN B.TUR = 3 THEN 1 WHEN B.YON = 1 THEN 3 ELSE 6 END')
ORTAK_BASLIK = {
    'LOGICALREF': 'B.ID', 'FICHENO': 'B.NO', 'DATE_': 'CAST(B.TARIH AS DATETIME)', 'DOCDATE': 'CAST(B.TARIH AS DATETIME)',
    'TIME_': 'B.SAAT_KODU', 'CLIENTREF': 'B.CARI_REF', 'SALESMANREF': 'ISNULL(B.PERSONEL_ID, 0)',
    'SOURCEINDEX': 'ISNULL(D.NO, 0)', 'CANCELLED': 'CASE WHEN B.DURUM = 9 THEN 1 ELSE 0 END',
    'GROSSTOTAL': 'B.BRUT', 'TOTALDISCOUNTS': 'B.ISKONTO', 'TOTALDISCOUNTED': 'B.BRUT - B.ISKONTO', 'TOTALVAT': 'B.KDV',
    'NETTOTAL': 'B.NET', 'REPORTNET': 'B.NET', 'REPORTRATE': '1', 'ADDDISCOUNTS': 'B.EK_ISKONTO',
    'TRCURR': PB_KOD.format(p='B.PARA_BIRIMI'), 'TRRATE': "CASE WHEN B.PARA_BIRIMI = 'TRY' THEN 0 ELSE B.KUR END",
    'TRNET': 'ISNULL(B.DOVIZ_NET, B.NET)', 'GENEXP1': 'B.ACIKLAMA', 'GENEXP2': 'B.ACIKLAMA2',
    'PRINTCNT': 'B.YAZDIRMA_SAYISI', 'GUID': 'B.GUID', 'PAYDEFREF': 'ISNULL(B.ODEME_PLANI_ID, 0)',
    'CAPIBLOCK_CREADEDDATE': 'CAST(B.OLUSTURMA AS DATETIME)', 'CAPIBLOCK_CREATEDBY': 'ISNULL(B.OLUSTURAN_ID, 0)',
    'CAPIBLOCK_MODIFIEDDATE': 'CAST(B.GUNCELLEME AS DATETIME)', 'AFFECTRISK': '1', 'RECSTATUS': '1',
}
INVOICE_OKU = dict(ORTAK_BASLIK, TRCODE=FATURA_TRCODE, GRPCODE='CASE B.YON WHEN 1 THEN 2 ELSE 1 END',
                   ACCOUNTED='0', ACCFICHEREF='0', EINVOICE='0')
STFICHE_OKU = dict(ORTAK_BASLIK, TRCODE=STOKFIS_TRCODE, GRPCODE='CASE B.YON WHEN 1 THEN 2 ELSE 1 END',
                   IOCODE='CASE WHEN B.YON = 2 THEN 1 ELSE 3 END',
                   INVOICEREF='CASE WHEN B.TUR IN (3, 4) THEN B.ID ELSE 0 END',
                   BILLED='CASE WHEN B.TUR IN (3, 4) THEN 1 ELSE 0 END', INVNO='CASE WHEN B.TUR IN (3, 4) THEN B.NO END')
STLINE_OKU = {
    'LOGICALREF': 'S.ID', 'STOCKREF': 'ISNULL(S.STOK_ID, 0)', 'LINETYPE': 'S.SATIR_TURU', 'AMOUNT': 'S.MIKTAR',
    'PRICE': 'S.FIYAT', 'TOTAL': 'S.TUTAR', 'DISTDISC': 'S.ISKONTO_TUTAR', 'DISTCOST': 'S.MASRAF_TUTAR',
    'DISCPER': 'S.ISKONTO_ORANI', 'VAT': 'S.KDV_ORANI', 'VATAMNT': 'S.KDV_TUTAR', 'VATMATRAH': 'S.KDV_MATRAH',
    'LINENET': 'S.NET', 'UOMREF': 'ISNULL(SB.ID, 0)', 'USREF': 'ISNULL(S.STOK_ID, 0)', 'UNITSETREF': 'ISNULL(S.STOK_ID, 0)',
    'UINFO1': '1', 'UINFO2': 'S.CARPAN', 'LINEEXP': 'S.ACIKLAMA', 'TRCODE': STOKFIS_TRCODE,
    'IOCODE': 'CASE WHEN B.YON = 2 THEN 1 ELSE 4 END', 'SIGN': 'CASE WHEN B.YON = 2 THEN 0 ELSE 1 END',
    'DATE_': 'CAST(B.TARIH AS DATETIME)', 'FTIME': 'B.SAAT_KODU',
    'MONTH_': 'MONTH(B.TARIH)', 'YEAR_': 'YEAR(B.TARIH)', 'CLIENTREF': 'B.CARI_REF',
    'SALESMANREF': 'ISNULL(B.PERSONEL_ID, 0)', 'SOURCEINDEX': 'ISNULL(D.NO, 0)', 'INVENNO': 'ISNULL(D.NO, 0)',
    'CANCELLED': 'CASE WHEN B.DURUM = 9 OR S.IPTAL = 1 THEN 1 ELSE 0 END',
    'STFICHEREF': 'CASE WHEN B.TUR IN (2, 5, 6) THEN B.ID ELSE 0 END', 'STFICHELNNO': 'S.SIRA',
    'INVOICEREF': 'CASE WHEN B.TUR IN (3, 4) THEN B.ID ELSE ISNULL(FK.BELGE_ID, 0) END', 'INVOICELNNO': 'S.SIRA',
    'ORDFICHEREF': 'ISNULL(KS.ID, 0)', 'ORDTRANSREF': 'ISNULL(S.KAYNAK_SATIR_ID, 0)',
    'BILLED': 'CASE WHEN B.TUR IN (3, 4) OR FK.BELGE_ID IS NOT NULL THEN 1 ELSE 0 END',
    'TRCURR': PB_KOD.format(p='B.PARA_BIRIMI'), 'TRRATE': "CASE WHEN B.PARA_BIRIMI = 'TRY' THEN 0 ELSE B.KUR END",
    'PRPRICE': 'ISNULL(S.DOVIZ_FIYAT, 0)', 'REPORTRATE': '1', 'GUID': 'S.GUID', 'AFFECTRISK': '1', 'RECSTATUS': '1',
}

# Cari hareket (salt okunur). KAYNAK 1 belge, 2 finans fişi, 3 çek hareketi, 4 açılış → LOGO TRCODE/MODULENR
CLF_TRCODE = ('CASE WHEN H.KAYNAK = 1 AND B.TUR = 3 THEN CASE WHEN B.YON = 1 THEN 38 ELSE 31 END'
              ' WHEN H.KAYNAK = 1 AND B.TUR = 4 THEN CASE WHEN B.YON = 1 THEN 33 ELSE 36 END'
              ' WHEN H.KAYNAK = 2 THEN CASE F.TUR WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 5 WHEN 5 THEN 14 ELSE 2 END'
              ' WHEN H.KAYNAK = 3 AND H.ALACAK > 0 THEN CASE WHEN K.TUR IN (3, 4) THEN 62 ELSE 61 END'
              ' WHEN H.KAYNAK = 3 THEN CASE WHEN K.TUR IN (3, 4) THEN 64 ELSE 63 END ELSE 14 END')
CLFLINE_OKU = {
    'LOGICALREF': 'H.ID', 'CLIENTREF': 'H.CARI_ID', 'DATE_': 'CAST(H.TARIH AS DATETIME)', 'DOCDATE': 'CAST(H.TARIH AS DATETIME)',
    'AMOUNT': 'H.BORC + H.ALACAK', 'TRNET': 'ISNULL(H.DOVIZ_TUTAR, H.BORC + H.ALACAK)',
    'SIGN': 'CASE WHEN H.ALACAK > 0 THEN 1 ELSE 0 END', 'TRCODE': CLF_TRCODE,
    'MODULENR': 'CASE H.KAYNAK WHEN 1 THEN 4 WHEN 2 THEN 10 WHEN 3 THEN 6 ELSE 5 END',
    'SOURCEFREF': 'COALESCE(H.BELGE_ID, H.FINANS_FIS_ID, H.CEK_HAREKET_ID, 0)', 'TRANNO': 'H.BELGE_NO',
    'DOCODE': 'H.BELGE_NO', 'LINEEXP': 'H.ACIKLAMA', 'CANCELLED': '0', 'REPORTRATE': '1',
    'TRCURR': PB_KOD.format(p='H.PARA_BIRIMI'), 'TRRATE': "CASE WHEN H.PARA_BIRIMI = 'TRY' THEN 0 ELSE H.KUR END",
    'MONTH_': 'MONTH(H.TARIH)', 'YEAR_': 'YEAR(H.TARIH)', 'AFFECTRISK': '1', 'RECSTATUS': '1',
}
CLFLINE_FROM = ('FROM dbo.LM_CARI_HAREKET H LEFT JOIN dbo.LM_BELGE B ON B.ID = H.BELGE_ID'
                ' LEFT JOIN dbo.LM_FINANS_FIS F ON F.ID = H.FINANS_FIS_ID'
                ' LEFT JOIN dbo.LM_CEK_HAREKET CH ON CH.ID = H.CEK_HAREKET_ID LEFT JOIN dbo.LM_CEK K ON K.ID = CH.CEK_ID')

# Çek/senet (salt okunur). LOGO DOC: 1 müşteri çeki, 2 müşteri senedi, 3 kendi çekimiz, 4 kendi senedimiz.
# CURRSTAT: 1 portföyde, 2 ciro edildi, 8 tahsil/ödendi, 9 kendi çekimiz verildi.
CSCARD_OKU = {
    'LOGICALREF': 'K.ID', 'DOC': 'CASE K.TUR WHEN 1 THEN 1 WHEN 3 THEN 2 WHEN 2 THEN 3 ELSE 4 END',
    'PORTFOYNO': 'K.PORTFOY_NO', 'NEWSERINO': 'K.SERI_NO', 'SERINO': 'K.SERI_NO', 'BANKNAME': 'K.BANKA', 'OWING': 'K.BORCLU',
    'DUEDATE': 'CAST(K.VADE AS DATETIME)', 'SETDATE': 'CAST(CAST(K.OLUSTURMA AS DATE) AS DATETIME)',
    'AMOUNT': 'K.TUTAR', 'TRNET': 'K.TUTAR', 'REPORTNET': 'K.TUTAR', 'REPORTRATE': '1', 'TRRATE': '1',
    'TRCURR': PB_KOD.format(p='K.PARA_BIRIMI'),
    'CURRSTAT': 'CASE WHEN K.DURUM = 1 THEN 1 WHEN K.DURUM = 2 AND K.TUR IN (2, 4) THEN 9 WHEN K.DURUM = 2 THEN 2'
                ' WHEN K.DURUM IN (3, 7) THEN 8 ELSE K.DURUM END',
    'OURBANKREF': 'ISNULL(K.HESAP_ID, 0)', 'CANCELLED': '0', 'STATUS': '1', 'AFFECTRISK': '1', 'CARDMD': '5',
    'CAPIBLOCK_CREADEDDATE': 'CAST(K.OLUSTURMA AS DATETIME)',
}
CEK_BORDRO = ('FROM (SELECT MIN(H.ID) AS ID, H.BORDRO_NO, MIN(H.ISLEM) AS ISLEM, MIN(H.CARI_ID) AS CARI_ID, MIN(H.TARIH) AS TARIH,'
              ' SUM(K.TUTAR) AS TOPLAM, COUNT(*) AS ADET, MIN(CAST(H.GERI_ALINDI AS INT)) AS GERI'
              ' FROM dbo.LM_CEK_HAREKET H JOIN dbo.LM_CEK K ON K.ID = H.CEK_ID GROUP BY H.BORDRO_NO) R')
CSROLL_OKU = {
    'LOGICALREF': 'R.ID', 'ROLLNO': 'R.BORDRO_NO', 'TRCODE': 'CASE WHEN R.ISLEM = 1 THEN 1 ELSE 3 END',
    'CARDREF': 'ISNULL(R.CARI_ID, 0)', 'DATE_': 'CAST(R.TARIH AS DATETIME)', 'TOTAL': 'R.TOPLAM', 'TRNET': 'R.TOPLAM',
    'REPORTNET': 'R.TOPLAM', 'REPORTRATE': '1', 'TRRATE': '1', 'DOCCNT': 'R.ADET', 'CANCELLED': 'R.GERI', 'CARDMD': '5', 'AFFECTRISK': '1',
}
CSTRANS_OKU = {
    'LOGICALREF': 'H.ID', 'CSREF': 'H.CEK_ID',
    'ROLLREF': '(SELECT MIN(X.ID) FROM dbo.LM_CEK_HAREKET X WHERE X.BORDRO_NO = H.BORDRO_NO)',
    'TRCODE': 'CASE H.ISLEM WHEN 1 THEN 1 ELSE 3 END', 'DATE_': 'CAST(H.TARIH AS DATETIME)', 'CARDREF': 'ISNULL(H.CARI_ID, 0)',
    'STATUS': 'H.ISLEM', 'STATNO': '1', 'LINENO_': '1', 'CANCELLED': 'CAST(H.GERI_ALINDI AS INT)', 'CARDMD': '5', 'AFFECTRISK': '1',
}
# Kasa / banka (salt okunur). KSLINES TRCODE: 11 tahsilat, 12 ödeme, 13 virman, 14 masraf, 15 açılış; SIGN 0 giriş, 1 çıkış.
BNCARD_OKU = {'LOGICALREF': 'F.ID', 'CODE': 'F.KOD', 'DEFINITION_': 'ISNULL(F.BANKA_ADI, F.AD)', 'ACTIVE': 'CASE WHEN F.AKTIF = 1 THEN 0 ELSE 1 END'}
BANKACC_OKU = {'LOGICALREF': 'F.ID', 'BANKREF': 'F.ID', 'CODE': 'F.KOD', 'DEFINITION_': 'F.AD', 'ACTIVE': 'CASE WHEN F.AKTIF = 1 THEN 0 ELSE 1 END'}
KSCARD_OKU = {'LOGICALREF': 'F.ID', 'CODE': 'F.KOD', 'NAME': 'F.AD', 'ACTIVE': 'CASE WHEN F.AKTIF = 1 THEN 0 ELSE 1 END'}
KSLINES_OKU = {
    'LOGICALREF': 'H.ID', 'CARDREF': 'H.HESAP_ID', 'DATE_': 'CAST(H.TARIH AS DATETIME)', 'AMOUNT': 'H.GIRIS + H.CIKIS',
    'TRNET': 'H.GIRIS + H.CIKIS', 'SIGN': 'CASE WHEN H.CIKIS > 0 THEN 1 ELSE 0 END', 'LINEEXP': 'H.ACIKLAMA',
    'TRCODE': 'CASE FF.TUR WHEN 1 THEN 11 WHEN 2 THEN 12 WHEN 3 THEN 13 WHEN 4 THEN 14 ELSE 15 END',
}

BELGE_FROM = 'FROM dbo.LM_BELGE B LEFT JOIN dbo.LM_DEPO D ON D.ID = B.DEPO_ID'
SATIR_FROM = ('FROM dbo.LM_BELGE_SATIR S JOIN dbo.LM_BELGE B ON B.ID = S.BELGE_ID'
              ' LEFT JOIN dbo.LM_DEPO D ON D.ID = B.DEPO_ID'
              ' LEFT JOIN dbo.LM_STOK_BIRIM SB ON SB.STOK_ID = S.STOK_ID AND SB.BIRIM_ID = S.BIRIM_ID')

GORUNUMLER = [
    ('LMV_ORFICHE', 'LG_001_02_ORFICHE', ORFICHE_OKU, BELGE_FROM, 'B.TUR = 1'),
    ('LMV_ORFLINE', 'LG_001_02_ORFLINE', ORFLINE_OKU, SATIR_FROM, 'B.TUR = 1'),
    ('LMV_INVOICE', 'LG_001_02_INVOICE', INVOICE_OKU, BELGE_FROM, 'B.TUR IN (3, 4)'),
    ('LMV_CLFLINE', 'LG_001_02_CLFLINE', CLFLINE_OKU, CLFLINE_FROM, '1 = 1'),
    ('LMV_CSCARD', 'LG_001_02_CSCARD', CSCARD_OKU, 'FROM dbo.LM_CEK K', '1 = 1'),
    ('LMV_CSROLL', 'LG_001_02_CSROLL', CSROLL_OKU, CEK_BORDRO, '1 = 1'),
    ('LMV_CSTRANS', 'LG_001_02_CSTRANS', CSTRANS_OKU, 'FROM dbo.LM_CEK_HAREKET H', '1 = 1'),
    ('LMV_BNCARD', 'LG_001_BNCARD', BNCARD_OKU, 'FROM dbo.LM_FINANS_HESAP F', 'F.TUR = 2'),
    ('LMV_BANKACC', 'LG_001_BANKACC', BANKACC_OKU, 'FROM dbo.LM_FINANS_HESAP F', 'F.TUR = 2'),
    ('LMV_KSCARD', 'LG_001_KSCARD', KSCARD_OKU, 'FROM dbo.LM_FINANS_HESAP F', 'F.TUR = 1'),
    ('LMV_KSLINES', 'LG_001_02_KSLINES', KSLINES_OKU,
     'FROM dbo.LM_FINANS_HAREKET H JOIN dbo.LM_FINANS_HESAP F ON F.ID = H.HESAP_ID AND F.TUR = 1'
     ' JOIN dbo.LM_FINANS_FIS FF ON FF.ID = H.FIS_ID', 'FF.DURUM = 1'),
    ('LMV_STFICHE', 'LG_001_02_STFICHE', STFICHE_OKU, BELGE_FROM, 'B.TUR IN (2, 3, 4, 5, 6)'),
    # Stok satırı: sipariş dışı belgelerin satırları. KS: siparişten geldiyse kaynak sipariş satırı;
    # FK: irsaliye satırı sonradan faturalandıysa o fatura satırı. İki bölüm: fatura satırlarında
    # INVOICEREF = BELGE_ID (sade; rapor birleştirmeleri dizin kullanır — tek ifadeyle 100+ sn sürüyordu),
    # irsaliye satırlarında FK araması, üretim/sayımda yok.
    ('LMV_STLINE', 'LG_001_02_STLINE', STLINE_OKU,
     SATIR_FROM + ' LEFT JOIN dbo.LM_BELGE_SATIR KSS ON KSS.ID = S.KAYNAK_SATIR_ID'
     ' LEFT JOIN dbo.LM_BELGE KS ON KS.ID = KSS.BELGE_ID AND KS.TUR = 1'
     ' OUTER APPLY (SELECT TOP 1 FS.BELGE_ID FROM dbo.LM_BELGE_SATIR FS JOIN dbo.LM_BELGE FB ON FB.ID = FS.BELGE_ID'
     ' AND FB.TUR IN (3, 4) WHERE FS.KAYNAK_SATIR_ID = S.ID) FK',
     'B.TUR = 2',
     [({'INVOICEREF': 'S.BELGE_ID', 'BILLED': '1'},
       SATIR_FROM + ' LEFT JOIN dbo.LM_BELGE_SATIR KSS ON KSS.ID = S.KAYNAK_SATIR_ID'
       ' LEFT JOIN dbo.LM_BELGE KS ON KS.ID = KSS.BELGE_ID AND KS.TUR = 1',
       'B.TUR IN (3, 4)'),
      # Üretim ve sayım hiç faturalanmaz: arama yok (büyük açılış sayımlarında rapor saniyeler sürüyordu)
      ({'INVOICEREF': '0', 'BILLED': '0', 'ORDFICHEREF': '0'}, SATIR_FROM, 'B.TUR IN (5, 6)')]),
]
YAZMA = {
    'LMV_ORFICHE': ('LM_BELGE', ORFICHE_YAZ, {'TUR': '1', 'DURUM': 'CASE WHEN I.CANCELLED = 1 THEN 9 ELSE 1 END',
                                             'OLUSTURAN_ID': 'NULLIF(I.CAPIBLOCK_CREATEDBY, 0)',
                                             'OLUSTURMA': 'ISNULL(I.CAPIBLOCK_CREADEDDATE, SYSDATETIME())'},
                    {'DURUM': 'CASE WHEN I.CANCELLED = 1 THEN 9 WHEN T.DURUM = 9 THEN 1 ELSE T.DURUM END',
                     'GUNCELLEYEN_ID': 'NULLIF(I.CAPIBLOCK_MODIFIEDBY, 0)', 'GUNCELLEME': 'SYSDATETIME()'}),
    'LMV_ORFLINE': ('LM_BELGE_SATIR', ORFLINE_YAZ, {}, {}),
}

# Yazma kuralları (görünüm → ek denetimler). INSTEAD OF tetikleyicisi görünümün WHERE
# koşulunu uygulamaz: sipariş satırı yalnız açık bir SİPARİŞE eklenir; satırın belgesi ve
# teslim miktarı (belge modülü yönetir) LOGO uyumlu yoldan değişmez; teslim edilenin altına
# düşürülemez.
YAZMA_KURAL = {
    'LMV_ORFLINE': {
        'ekle_kosul': 'EXISTS (SELECT 1 FROM dbo.LM_BELGE BX WHERE BX.ID = I.ORDFICHEREF AND BX.TUR = 1 AND BX.DURUM <> 9)',
        'guncelle_haric': ['BELGE_ID', 'TESLIM_MIKTAR'],
        'guncelle_denetim': ("IF EXISTS (SELECT 1 FROM inserted I JOIN dbo.LM_BELGE_SATIR T ON T.ID = I.LOGICALREF\n"
                             "               WHERE ISNULL(I.AMOUNT, 0) < T.TESLIM_MIKTAR - 0.000001)\n"
                             "        THROW 51001, N'Sipariş satırı teslim edilen miktarın altına düşürülemez.', 1;"),
    },
}


def gorunum(ad, logo_tablo, oku, kaynak, kosul, ek_parcalar=()):
    """ek_parcalar: [(oku_degisikligi, kaynak, kosul), ...] — aynı kolonlarla UNION ALL bölümleri.
    Bölme, kolon ifadesinin bölüme göre sade kalmasını sağlar (ör. fatura satırında INVOICEREF
    doğrudan BELGE_ID olur ve birleştirmede dizin kullanılabilir)."""
    def sec(o, kay, kos):
        satirlar = []
        for kolon, tip in logo_kolonlari(logo_tablo):
            ifade = o.get(kolon)
            satirlar.append(f'    CAST({ifade} AS {tip}) AS [{kolon}]' if ifade else f'    {bos(tip)} AS [{kolon}]')
        return 'SELECT\n' + ',\n'.join(satirlar) + f'\n{kay}\nWHERE {kos}'
    bolumler = [sec(oku, kaynak, kosul)] + [sec(dict(oku, **d), kay, kos) for d, kay, kos in ek_parcalar]
    return f'CREATE OR ALTER VIEW dbo.{ad} AS\n' + '\nUNION ALL\n'.join(bolumler) + ';\nGO\n'


def tetikleyiciler(ad):
    hedef, eslem, ek_ekle, ek_guncelle = YAZMA[ad]
    kural = YAZMA_KURAL.get(ad, {})
    ekle = dict(eslem, **ek_ekle)
    kol = ', '.join(ekle)
    deg = ',\n        '.join(ekle.values())
    guncelle = {k: v for k, v in dict(eslem, **ek_guncelle).items() if k not in kural.get('guncelle_haric', [])}
    set_ = ',\n        '.join(f'{k} = {v}' for k, v in guncelle.items())
    kosul = f"\n    WHERE {kural['ekle_kosul']}" if 'ekle_kosul' in kural else ''
    denetim = f"\n    {kural['guncelle_denetim']}" if 'guncelle_denetim' in kural else ''
    # Yeni kaydın kimliği oturum bağlamıyla bildirilir (lumen_insert_kimlik okur; @@IDENTITY
    # başka bir tetikleyicinin ya da eklenmeyen satırın kimliğini verebilirdi).
    return f"""CREATE OR ALTER TRIGGER dbo.TR_{ad}_EKLE ON dbo.{ad} INSTEAD OF INSERT AS
BEGIN
    SET NOCOUNT ON;
    INSERT INTO dbo.{hedef} ({kol})
    SELECT
        {deg}
    FROM inserted I{kosul};
    IF @@ROWCOUNT > 0
    BEGIN
        DECLARE @yeni INT = CAST(SCOPE_IDENTITY() AS INT);
        EXEC sp_set_session_context N'lumen_yeni_id', @yeni;
    END;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_{ad}_GUNCELLE ON dbo.{ad} INSTEAD OF UPDATE AS
BEGIN
    SET NOCOUNT ON;{denetim}
    UPDATE T SET
        {set_}
    FROM dbo.{hedef} T JOIN inserted I ON I.LOGICALREF = T.ID;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_{ad}_SIL ON dbo.{ad} INSTEAD OF DELETE AS
BEGIN
    SET NOCOUNT ON;
    DELETE T FROM dbo.{hedef} T JOIN deleted X ON X.LOGICALREF = T.ID;
END;
GO
"""


def main():
    parcalar = ["""-- ============================================================================
-- Lumen çekirdek — 07: belge (sipariş, fatura, irsaliye, stok satırı) LOGO uyumlu
-- görünümleri ve sipariş yazma tetikleyicileri.
-- ÜRETİLMİŞ DOSYA — elle düzenlemeyin: python3 scripts/gelistirme/logo_uyum_uret.py
-- Eşlemeler ve kodlamalar betiğin başındadır.
-- ============================================================================
"""]
    for g in GORUNUMLER:
        parcalar.append(gorunum(*g))
        if g[0] in YAZMA:
            parcalar.append(tetikleyiciler(g[0]))
    # Ödeme planları (fiyat grupları): bağımsız modda henüz yok — boş liste.
    # (fiyat/fiyat_guncelle.php; demo şeması bu tabloyu yakalamadığından elle.)
    parcalar.append("""CREATE OR ALTER VIEW dbo.LMV_PAYPLANS AS
SELECT CAST(NULL AS INT) AS [LOGICALREF], CAST(NULL AS NVARCHAR(40)) AS [CODE], CAST(NULL AS NVARCHAR(200)) AS [DEFINITION_]
WHERE 1 = 0;
GO
""")
    CIKTI.write_text('\n'.join(parcalar), encoding='utf-8')
    print(f'yazıldı: {CIKTI.relative_to(KOK)}')


if __name__ == '__main__':
    main()
