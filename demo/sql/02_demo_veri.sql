-- ============================================================================
-- Lumen demo verisi — TAMAMEN UYDURMADIR. Gerçek firma, kişi veya ürün içermez.
-- Önce 01_logo_sema.sql ve depodaki sql/*.sql betikleri çalışmış olmalıdır.
-- Tekrar çalıştırılabilir: demo tablolarını boşaltıp yeniden doldurur.
--
-- Demo kullanıcıları (parola hepsinde: demo1234)
--   YONETICI  — yönetici (tüm yetkiler)
--   SATIS1    — personel (sipariş, cari, stok, çek; ayar yok)
--   DEPO1     — personel (yalnız stok)
--   ENTEGRASYON — entegrasyon/bot (YETKI=3): yalnız API, yalnız okuma
-- ============================================================================
SET NOCOUNT ON;
SET DATEFORMAT ymd;

DELETE FROM M_P_YETKI;
DELETE FROM LG_SLSMAN;
DELETE FROM LG_001_02_ORFLINE;   DELETE FROM LG_001_02_ORFICHE;
DELETE FROM LG_001_02_STLINE;    DELETE FROM LG_001_02_STFICHE;
DELETE FROM LG_001_02_CLFLINE;   DELETE FROM LG_001_02_INVOICE;
DELETE FROM LG_001_02_CSTRANS;   DELETE FROM LG_001_02_CSROLL;   DELETE FROM LG_001_02_CSCARD;
DELETE FROM LG_001_02_KSLINES;   DELETE FROM LG_001_KSCARD;
DELETE FROM LV_001_02_GNTOTCL;   DELETE FROM LV_001_02_STINVTOT;
DELETE FROM LG_001_PRCLIST;      DELETE FROM LG_001_UNITBARCODE;  DELETE FROM LG_001_ITMUNITA;
DELETE FROM LG_001_ITEMS;        DELETE FROM LG_001_UNITSETL;     DELETE FROM LG_001_CLCARD;
DELETE FROM LG_001_BNCARD;       DELETE FROM LG_001_BANKACC;      DELETE FROM LG_001_SRVCARD;
GO

-- ---------------------------------------------------------------- kullanıcılar
SET IDENTITY_INSERT LG_SLSMAN ON;
INSERT INTO LG_SLSMAN (LOGICALREF, CODE, DEFINITION_, ACTIVE, FIRMNR, CYPHCODE, SPECODE, TYP, CARDTYPE, USERID, CAPIBLOCK_CREADEDDATE)
VALUES (1, 'YONETICI', N'Demo Yönetici', 0, 1, '', '0', 0, 0, 1, '2026-01-02'),
       (2, 'SATIS1',   N'Ayşe Demir (demo)', 0, 1, '', '0', 0, 0, 2, '2026-01-02'),
       (3, 'DEPO1',    N'Mehmet Kaya (demo)', 0, 1, '', '1', 0, 0, 3, '2026-01-02'),
       (4, 'ESKI1',    N'Pasif Kullanıcı (demo)', 1, 1, '', '0', 0, 0, 4, '2026-01-02');
SET IDENTITY_INSERT LG_SLSMAN OFF;

DECLARE @hash NVARCHAR(255) = N'$2y$10$SunKM5b7dCQna2VUK8vFTuuY.LVzKikqg8DOSVLOJdTgjVFQh3ZXG';
INSERT INTO M_P_YETKI (PERSONEL, SIFRE, YETKI) VALUES (1, @hash, 0), (2, @hash, 1), (3, @hash, 1), (4, @hash, 1);
-- SATIS1: sipariş/cari/stok/çek ekranları; DEPO1: yalnız stok.
UPDATE M_P_YETKI SET M1=1, M2=1, M3=1, M4=1, M5=1, M7=1, M8=1, M15=1, M30=1, ST1=1, ST2=1, CR1=1, CR2=1 WHERE PERSONEL = 2;
UPDATE M_P_YETKI SET M15=1, ST1=1 WHERE PERSONEL = 3;
GO

-- ---------------------------------------------------------------- birimler
SET IDENTITY_INSERT LG_001_UNITSETL ON;
INSERT INTO LG_001_UNITSETL (LOGICALREF, CODE, NAME, UNITSETREF, LINENR, MAINUNIT, CONVFACT1, CONVFACT2)
VALUES (1, 'ADET', N'Adet', 1, 1, 1, 1, 1), (2, 'KOLI', N'Koli', 1, 2, 0, 1, 24), (3, 'PAKET', N'Paket', 1, 3, 0, 1, 6);
SET IDENTITY_INSERT LG_001_UNITSETL OFF;
GO

-- ---------------------------------------------------------------- stok kartları
DECLARE @urun TABLE (n INT IDENTITY(1,1), ad NVARCHAR(100), grup NVARCHAR(20), fiyat FLOAT, koli INT);
INSERT INTO @urun (ad, grup, fiyat, koli) VALUES
 (N'Yemek Tabağı 26 cm Beyaz', 'TABAK', 42.50, 24), (N'Yemek Tabağı 26 cm Krem', 'TABAK', 44.00, 24),
 (N'Tatlı Tabağı 20 cm Beyaz', 'TABAK', 31.00, 36), (N'Çukur Tabak 22 cm', 'TABAK', 36.75, 24),
 (N'Servis Tabağı Oval 35 cm', 'SERVIS', 89.90, 12), (N'Servis Tabağı Kare 30 cm', 'SERVIS', 79.00, 12),
 (N'Kase 12 cm', 'KASE', 18.25, 48), (N'Kase 16 cm', 'KASE', 24.40, 36), (N'Salata Kasesi 24 cm', 'KASE', 58.00, 12),
 (N'Çorba Kasesi Kulplu', 'KASE', 27.90, 36), (N'Kahvaltılık 3 Bölmeli', 'SERVIS', 49.50, 24),
 (N'Çay Bardağı Altlığı', 'AKSESUAR', 6.20, 120), (N'Kupa 300 ml', 'BARDAK', 22.00, 48),
 (N'Su Bardağı 250 ml', 'BARDAK', 14.80, 72), (N'Tepsi Dikdörtgen 40x28', 'TEPSI', 112.00, 10),
 (N'Tepsi Yuvarlak 35 cm', 'TEPSI', 98.50, 10), (N'Sosluk 8 cm', 'AKSESUAR', 9.40, 96),
 (N'Ekmek Sepeti Oval', 'SERVIS', 64.00, 12), (N'Çocuk Tabağı Bölmeli', 'COCUK', 33.00, 24),
 (N'Çocuk Kasesi Desenli', 'COCUK', 21.50, 48), (N'Yemek Tabağı 28 cm Siyah', 'TABAK', 52.00, 24),
 (N'Pasta Tabağı 32 cm Ayaklı', 'SERVIS', 135.00, 6), (N'Meze Tabağı 4 Bölmeli', 'SERVIS', 57.25, 12),
 (N'Kase Seti 6 Parça', 'SET', 129.00, 8), (N'Yemek Takımı 24 Parça', 'SET', 1150.00, 2);

SET IDENTITY_INSERT LG_001_ITEMS ON;
INSERT INTO LG_001_ITEMS (LOGICALREF, CODE, NAME, STGRPCODE, SPECODE, CARDTYPE, ACTIVE, UNITSETREF, VAT, SELLVAT, RETURNVAT, CLASSTYPE)
SELECT n, 'DM-' + RIGHT('0000' + CAST(n AS VARCHAR(4)), 4), ad, grup, grup, 1, 0, 1, 20, 20, 20, 0 FROM @urun;
SET IDENTITY_INSERT LG_001_ITEMS OFF;

-- Birim satırları: 1 = adet (ana), 2 = koli, 3 = paket
INSERT INTO LG_001_ITMUNITA (ITEMREF, LINENR, UNITLINEREF, CONVFACT1, CONVFACT2, WEIGHT, MTRLCLAS, PURCHCLAS, SALESCLAS)
SELECT n, 1, 1, 1, 1, 0.35, 1, 1, 1 FROM @urun
UNION ALL SELECT n, 2, 2, 1, koli, 0.35 * koli, 1, 1, 1 FROM @urun
UNION ALL SELECT n, 3, 3, 1, 6, 2.1, 1, 1, 1 FROM @urun;

INSERT INTO LG_001_UNITBARCODE (ITEMREF, ITMUNITAREF, UNITLINEREF, LINENR, BARCODE, TYP)
SELECT n, 0, 1, 1, '86900000' + RIGHT('00000' + CAST(n AS VARCHAR(5)), 5), 0 FROM @urun;

-- Fiyat listesi: PTYPE 2 = satış, PTYPE 1 = alış (maliyet ~%60)
INSERT INTO LG_001_PRCLIST (CARDREF, PTYPE, PRICE, ACTIVE)
SELECT n, 2, fiyat, 0 FROM @urun
UNION ALL SELECT n, 1, ROUND(fiyat * 0.6, 2), 0 FROM @urun;

-- Eldeki stok (LOGO'da view; demoda tablo). INVENNO -1 = tüm ambarlar toplamı.
INSERT INTO LV_001_02_STINVTOT (STOCKREF, INVENNO, ONHAND, DATE_)
SELECT n, -1, (n * 37) % 900 + 40, '2026-01-01' FROM @urun
UNION ALL SELECT n, 0, (n * 37) % 900 + 40, '2026-01-01' FROM @urun;
GO

-- ---------------------------------------------------------------- cariler
DECLARE @cari TABLE (n INT IDENTITY(1,1), unvan NVARCHAR(120), sehir NVARCHAR(40), ilce NVARCHAR(40));
INSERT INTO @cari (unvan, sehir, ilce) VALUES
 (N'Örnek Züccaciye Ltd. Şti.', N'İstanbul', N'Eminönü'), (N'Demo Otel Malzemeleri A.Ş.', N'Antalya', N'Muratpaşa'),
 (N'Kuzey Mutfak Gereçleri', N'Samsun', N'İlkadım'), (N'Güney Toptan Ticaret', N'Mersin', N'Akdeniz'),
 (N'Anadolu Ev Eşyası', N'Ankara', N'Çankaya'), (N'Ege Restoran Tedarik', N'İzmir', N'Konak'),
 (N'Marmara Kafe Ekipmanları', N'Bursa', N'Osmangazi'), (N'Doğu Ticaret ve Pazarlama', N'Erzurum', N'Yakutiye'),
 (N'İç Anadolu Züccaciye', N'Konya', N'Selçuklu'), (N'Karadeniz Otelcilik', N'Trabzon', N'Ortahisar'),
 (N'Akdeniz Catering Hizmetleri', N'Adana', N'Seyhan'), (N'Mağaza Perakende Satış', N'İstanbul', N'Kadıköy'),
 (N'Trakya Hediyelik Eşya', N'Edirne', N'Merkez'), (N'Başkent Kurumsal Mutfak', N'Ankara', N'Yenimahalle'),
 (N'Yeni Müşteri (bakiyesiz)', N'Eskişehir', N'Tepebaşı');

SET IDENTITY_INSERT LG_001_CLCARD ON;
INSERT INTO LG_001_CLCARD (LOGICALREF, CODE, DEFINITION_, CARDTYPE, ACTIVE, CITY, DISTRICT, TOWN, ADDR1, TELNRS1, EMAILADDR, TAXNR, TAXOFFICE, SPECODE)
SELECT n, '120.DM.' + RIGHT('000' + CAST(n AS VARCHAR(3)), 3), unvan, 3, 0, sehir, ilce, ilce,
       N'Demo Cad. No:' + CAST(n AS NVARCHAR(5)), '0 (555) 000 00 ' + RIGHT('00' + CAST(n AS VARCHAR(2)), 2),
       'demo' + CAST(n AS VARCHAR(3)) + '@example.com', RIGHT('0000000000' + CAST(n * 1234567 AS VARCHAR(10)), 10), N'Demo VD', ''
FROM @cari;
SET IDENTITY_INSERT LG_001_CLCARD OFF;
GO

-- ---------------------------------------------------------------- siparişler
-- TRCODE 1 = satış siparişi; STATUS 4 = öneri değil, sevk edilebilir.
DECLARE @i INT = 1;
WHILE @i <= 30
BEGIN
    DECLARE @cariRef INT = ((@i * 7) % 14) + 1;
    DECLARE @tarih DATETIME = DATEADD(DAY, -(@i * 3), CAST('2026-10-15' AS DATETIME));
    INSERT INTO LG_001_02_ORFICHE (TRCODE, FICHENO, DATE_, TIME_, CLIENTREF, SALESMANREF, STATUS, SOURCEINDEX, BRANCH,
                                   TRCURR, TRRATE, GENEXP1, CANCELLED, CAPIBLOCK_CREADEDDATE, CAPIBLOCK_CREATEDBY)
    VALUES (1, 'SIP' + RIGHT('000000' + CAST(@i AS VARCHAR(6)), 6), @tarih, 168427520, @cariRef,
            CASE WHEN @i % 3 = 0 THEN 1 ELSE 2 END, 4, 0, 0, 0, 0, N'Demo sipariş', 0, @tarih, 1);
    DECLARE @fis INT = SCOPE_IDENTITY();

    DECLARE @satir INT = 1;
    WHILE @satir <= 2 + (@i % 4)
    BEGIN
        DECLARE @stok INT = ((@i * 5 + @satir * 3) % 25) + 1;
        DECLARE @miktar FLOAT = ((@i + @satir) % 6 + 1) * 12;
        DECLARE @fiyat FLOAT = (SELECT PRICE FROM LG_001_PRCLIST WHERE CARDREF = @stok AND PTYPE = 2);
        DECLARE @tutar FLOAT = ROUND(@miktar * @fiyat, 2);
        INSERT INTO LG_001_02_ORFLINE (ORDFICHEREF, CLIENTREF, STOCKREF, LINETYPE, LINENO_, TRCODE, DATE_, TIME_, AMOUNT, PRICE,
                                       TOTAL, LINENET, VAT, VATAMNT, VATMATRAH, UOMREF, USREF, UINFO1, UINFO2, SOURCEINDEX,
                                       STATUS, SALESMANREF, SHIPPEDAMOUNT, CLOSED, CANCELLED, TRCURR, TRRATE, PRCURR, PRPRICE)
        VALUES (@fis, @cariRef, @stok, 0, @satir, 1, @tarih, 168427520, @miktar, @fiyat,
                @tutar, @tutar, 20, ROUND(@tutar * 0.2, 2), @tutar, 1, 1, 1, 1, 0,
                4, CASE WHEN @i % 3 = 0 THEN 1 ELSE 2 END, CASE WHEN @i > 20 THEN @miktar ELSE 0 END,
                CASE WHEN @i > 20 THEN 1 ELSE 0 END, 0, 0, 0, 0, @fiyat);
        SET @satir += 1;
    END;

    UPDATE F SET GROSSTOTAL = S.T, TOTALVAT = S.V, NETTOTAL = S.T + S.V, TOTALDISCOUNTED = S.T, REPORTNET = S.T + S.V
    FROM LG_001_02_ORFICHE F
    CROSS APPLY (SELECT SUM(TOTAL) T, SUM(VATAMNT) V FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = F.LOGICALREF) S
    WHERE F.LOGICALREF = @fis;
    SET @i += 1;
END;
GO

-- ---------------------------------------------------------------- cari hareketler + bakiye
-- Faturalar (TRCODE 38 = toptan satış faturası) borç, tahsilatlar alacak.
INSERT INTO LG_001_02_CLFLINE (CLIENTREF, DATE_, TRCODE, SIGN, AMOUNT, TRNET, LINEEXP, TRANNO, CANCELLED, MODULENR)
SELECT C.LOGICALREF, DATEADD(DAY, -40 + v.k * 9, '2026-10-10'), 38, 0, 5000 + C.LOGICALREF * 750 + v.k * 300,
       5000 + C.LOGICALREF * 750 + v.k * 300, N'Demo satış faturası', 'FTR' + CAST(C.LOGICALREF * 10 + v.k AS VARCHAR(8)), 0, 4
FROM LG_001_CLCARD C CROSS JOIN (VALUES (1), (2), (3)) v(k) WHERE C.LOGICALREF <= 14;

INSERT INTO LG_001_02_CLFLINE (CLIENTREF, DATE_, TRCODE, SIGN, AMOUNT, TRNET, LINEEXP, TRANNO, CANCELLED, MODULENR)
SELECT C.LOGICALREF, DATEADD(DAY, -5, '2026-10-10'), 1, 1, 4000 + C.LOGICALREF * 500, 4000 + C.LOGICALREF * 500,
       N'Demo nakit tahsilat', 'THS' + CAST(C.LOGICALREF AS VARCHAR(8)), 0, 10
FROM LG_001_CLCARD C WHERE C.LOGICALREF <= 14;

INSERT INTO LV_001_02_GNTOTCL (CARDREF, TOTTYP, DEBIT, CREDIT)
SELECT CLIENTREF, 1, SUM(CASE WHEN SIGN = 0 THEN AMOUNT ELSE 0 END), SUM(CASE WHEN SIGN = 1 THEN AMOUNT ELSE 0 END)
FROM LG_001_02_CLFLINE GROUP BY CLIENTREF;
GO

-- ---------------------------------------------------------------- kasa / banka / çek
SET IDENTITY_INSERT LG_001_KSCARD ON;
INSERT INTO LG_001_KSCARD (LOGICALREF, CODE, NAME, ACTIVE) VALUES (1, 'KASA.01', N'Merkez Kasa (demo)', 0);
SET IDENTITY_INSERT LG_001_KSCARD OFF;
INSERT INTO LG_001_02_KSLINES (CARDREF, DATE_, TRCODE, SIGN, AMOUNT, TRNET, LINEEXP)
SELECT 1, DATEADD(DAY, -5, '2026-10-10'), 11, 0, 4000 + LOGICALREF * 500, 4000 + LOGICALREF * 500, N'Demo tahsilat'
FROM LG_001_CLCARD WHERE LOGICALREF <= 14;

SET IDENTITY_INSERT LG_001_BNCARD ON;
INSERT INTO LG_001_BNCARD (LOGICALREF, CODE, DEFINITION_, ACTIVE) VALUES (1, 'BNK.01', N'Demo Bankası', 0);
SET IDENTITY_INSERT LG_001_BNCARD OFF;
SET IDENTITY_INSERT LG_001_BANKACC ON;
INSERT INTO LG_001_BANKACC (LOGICALREF, CODE, DEFINITION_, ACTIVE, BANKREF) VALUES (1, 'BNK.01.TL', N'Demo Bankası TL Hesabı', 0, 1);
SET IDENTITY_INSERT LG_001_BANKACC OFF;

-- Portföydeki müşteri çekleri (DOC 1 = çek, CURRSTAT 1 = portföyde)
INSERT INTO LG_001_02_CSCARD (DOC, PORTFOYNO, NEWSERINO, BANKNAME, OWING, DUEDATE, SETDATE, AMOUNT, TRNET, CURRSTAT, STATUS, CITY, CARDMD)
SELECT 1, 'P' + RIGHT('00000' + CAST(LOGICALREF AS VARCHAR(5)), 5), 'CK' + CAST(900000 + LOGICALREF AS VARCHAR(8)),
       N'Demo Bankası', DEFINITION_, DATEADD(DAY, 15 + LOGICALREF * 6, '2026-10-10'), '2026-10-01',
       2500 + LOGICALREF * 400, 2500 + LOGICALREF * 400, 1, 1, CITY, 1
FROM LG_001_CLCARD WHERE LOGICALREF BETWEEN 1 AND 8;
GO

-- ---------------------------------------------------------------- çek şablon hareketleri
-- Lumen çek modülü yeni kayıtları LOGO'daki mevcut bir hareketi şablon alarak
-- klonlar (cek/cek_lib.php → cek_klonla). Demo için birer geçmiş giriş (TRCODE 1)
-- ve ciro (TRCODE 3) bordrosu ile cari ayakları.
INSERT INTO LG_001_02_CSROLL (TRCODE, ROLLNO, DATE_, CARDREF, TOTAL, DOCCNT, TRCURR, CANCELLED, GENEXP1)
VALUES (1, 'A0000001', '2026-10-01', 1, 2900, 1, 0, 0, N'Demo çek giriş bordrosu'),
       (3, 'A0000002', '2026-10-02', 3, 3300, 1, 0, 0, N'Demo ciro bordrosu');
INSERT INTO LG_001_02_CSTRANS (CSREF, ROLLREF, TRCODE, DATE_, CARDMD, CARDREF, STATUS, CANCELLED, LINENO_)
VALUES (1, 1, 1, '2026-10-01', 5, 1, 1, 0, 1), (2, 2, 3, '2026-10-02', 5, 3, 1, 0, 1);
INSERT INTO LG_001_02_CLFLINE (CLIENTREF, DATE_, MODULENR, TRCODE, SIGN, AMOUNT, TRNET, TRCURR, LINEEXP, TRANNO, SOURCEFREF, CANCELLED)
VALUES (1, '2026-10-01', 6, 61, 1, 2900, 2900, 0, N'Demo çek girişi', 'A0000001', 1, 0),
       (3, '2026-10-02', 6, 63, 0, 3300, 3300, 0, N'Demo çek cirosu', 'A0000002', 2, 0);
INSERT INTO LG_001_02_CSCARD (DOC, PORTFOYNO, NEWSERINO, BANKNAME, OWING, DUEDATE, SETDATE, AMOUNT, TRNET, CURRSTAT, STATUS, CARDMD, TRCURR)
VALUES (3, '00000901', 'KC000901', N'Demo Bankası', N'Lumen Demo', '2026-12-20', '2026-10-01', 7500, 7500, 6, 1, 1, 0);
GO

-- ---------------------------------------------------------------- LOGO sistem tabloları (demo)
IF OBJECT_ID('dbo.L_CAPIFIRM', 'U') IS NULL
    CREATE TABLE dbo.L_CAPIFIRM (LOGICALREF INT IDENTITY(1,1) PRIMARY KEY, NR INT NOT NULL, NAME NVARCHAR(100) NULL);
IF OBJECT_ID('dbo.L_DAILYEXCHANGES', 'U') IS NULL
    CREATE TABLE dbo.L_DAILYEXCHANGES (LREF INT IDENTITY(1,1) PRIMARY KEY, CRTYPE INT NOT NULL, EDATE DATETIME NULL,
                                       RATES1 FLOAT NOT NULL DEFAULT 0, RATES2 FLOAT NOT NULL DEFAULT 0,
                                       RATES3 FLOAT NOT NULL DEFAULT 0, RATES4 FLOAT NOT NULL DEFAULT 0);
GO
DELETE FROM L_CAPIFIRM; DELETE FROM L_DAILYEXCHANGES;
INSERT INTO L_CAPIFIRM (NR, NAME) VALUES (1, N'Lumen Demo Firması');
-- Uydurma kurlar (USD=1, EUR=20)
INSERT INTO L_DAILYEXCHANGES (CRTYPE, EDATE, RATES1, RATES2, RATES3, RATES4)
VALUES (1, '2026-10-15', 41.10, 41.25, 41.10, 41.25), (20, '2026-10-15', 47.80, 48.00, 47.80, 48.00);
GO

-- ---------------------------------------------------------------- entegrasyon (bot) hesabı
-- YETKI=3: yalnız API, yalnız okuma uçları; web'e giremez. Rapor + bakiye izinleri.
SET IDENTITY_INSERT LG_SLSMAN ON;
INSERT INTO LG_SLSMAN (LOGICALREF, CODE, DEFINITION_, ACTIVE, FIRMNR, CYPHCODE, SPECODE, TYP, CARDTYPE, USERID, CAPIBLOCK_CREADEDDATE)
VALUES (5, 'ENTEGRASYON', N'Raporlama entegrasyonu (demo)', 0, 1, '', '0', 0, 0, 5, '2026-01-02');
SET IDENTITY_INSERT LG_SLSMAN OFF;
INSERT INTO M_P_YETKI (PERSONEL, SIFRE, YETKI) VALUES (5, N'$2y$10$SunKM5b7dCQna2VUK8vFTuuY.LVzKikqg8DOSVLOJdTgjVFQh3ZXG', 3);
UPDATE M_P_YETKI SET M5 = 1, M17 = 1, CR1 = 1, ST1 = 1, ST2 = 1 WHERE PERSONEL = 5;
GO
