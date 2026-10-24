-- ============================================================================
-- Performans denemesi için büyük, tutarlı sentetik veri (bağımsız mod).
-- Demo verisinin üzerine ekler: 5.000 cari, 20.000 ürün (birim, fiyat, barkod,
-- açılış stoku), son bir yılda 6.000 sipariş (~30.000 satır), 12.000 satış faturası
-- (~48.000 satır, stok çıkışı ve cari borcu), 15.000 tahsilat (kasa + cari alacağı).
-- Büyük müşteri PRF.00001: belgelerin dörtte biri, tahsilatların beşte biri (~6.000 hareket).
-- Yalnız LUMEN_ önekli demo/test veritabanında çalıştırın:
--   php tests/performans/olc.php --veri    (bu dosyayı uygular, sonra ölçer)
-- ============================================================================
SET NOCOUNT ON;
IF DB_NAME() NOT LIKE 'LUMEN[_]%' BEGIN RAISERROR('Yalnız LUMEN_ demo/test veritabanında.', 16, 1); RETURN; END;
IF EXISTS (SELECT 1 FROM dbo.LM_CARI WHERE KOD LIKE 'PRF.%') BEGIN PRINT 'Büyük veri zaten yüklü.'; RETURN; END;

DECLARE @bugun DATE = CAST(GETDATE() AS DATE);
DECLARE @merkez INT = (SELECT ID FROM dbo.LM_DEPO WHERE NO = 0);
DECLARE @adet INT = (SELECT ID FROM dbo.LM_BIRIM WHERE KOD = 'ADET');
DECLARE @kasa INT = (SELECT ID FROM dbo.LM_FINANS_HESAP WHERE KOD = 'KASA.01');

-- Sayı tablosu (1..60000)
IF OBJECT_ID('tempdb..#n') IS NOT NULL DROP TABLE #n;
SELECT TOP (60000) ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) AS n INTO #n
FROM sys.all_objects a CROSS JOIN sys.all_objects b;
CREATE UNIQUE CLUSTERED INDEX IX_n ON #n (n);

-- Cariler
INSERT INTO dbo.LM_CARI (KOD, UNVAN, TUR, VERGI_NO, IL, ILCE, TELEFON, VADE_GUN)
SELECT 'PRF.' + RIGHT('00000' + CAST(n AS VARCHAR(6)), 5),
       CASE n % 7 WHEN 0 THEN N'Öztürk' WHEN 1 THEN N'Yılmaz' WHEN 2 THEN N'Şahin' WHEN 3 THEN N'Çelik' WHEN 4 THEN N'Doğan' WHEN 5 THEN N'Kılıç' ELSE N'Aydın' END
         + N' Ticaret ' + CAST(n AS NVARCHAR(6)) + CASE WHEN n % 3 = 0 THEN N' Ltd. Şti.' ELSE N' A.Ş.' END,
       CASE WHEN n % 10 = 0 THEN 2 ELSE 1 END, RIGHT('0000000000' + CAST(1000000000 + n AS VARCHAR(10)), 10),
       CASE n % 5 WHEN 0 THEN N'İstanbul' WHEN 1 THEN N'Ankara' WHEN 2 THEN N'İzmir' WHEN 3 THEN N'Bursa' ELSE N'Antalya' END,
       N'Merkez', '0 5' + RIGHT('00' + CAST(n % 100 AS VARCHAR(3)), 2) + ' 000 00 00', (n % 4) * 15
FROM #n WHERE n <= 5000;

-- Ürünler: ADET ana birim, satış/alış fiyatı, barkod
INSERT INTO dbo.LM_STOK (KOD, AD, GRUP, ANA_BIRIM_ID, KDV_ORANI)
SELECT 'PRF-' + RIGHT('000000' + CAST(n AS VARCHAR(6)), 6),
       CASE n % 6 WHEN 0 THEN N'Tabak' WHEN 1 THEN N'Bardak' WHEN 2 THEN N'Kase' WHEN 3 THEN N'Tencere' WHEN 4 THEN N'Çatal' ELSE N'Sürahi' END
         + N' model ' + CAST(n AS NVARCHAR(6)) + N' ' + CAST(10 + n % 40 AS NVARCHAR(3)) + N' cm',
       CASE n % 6 WHEN 0 THEN 'TABAK' WHEN 1 THEN 'BARDAK' WHEN 2 THEN 'KASE' WHEN 3 THEN 'TENCERE' WHEN 4 THEN 'CATAL' ELSE 'SURAHI' END,
       @adet, CASE WHEN n % 9 = 0 THEN 10 ELSE 20 END
FROM #n WHERE n <= 20000;
INSERT INTO dbo.LM_STOK_BIRIM (STOK_ID, BIRIM_ID, CARPAN)
SELECT ID, @adet, 1 FROM dbo.LM_STOK WHERE KOD LIKE 'PRF-%';
INSERT INTO dbo.LM_FIYAT (STOK_ID, TUR, BIRIM_ID, FIYAT, BASLANGIC)
SELECT ID, 1, @adet, 5 + (ID % 200) * 1.25, DATEADD(DAY, -400, @bugun) FROM dbo.LM_STOK WHERE KOD LIKE 'PRF-%'
UNION ALL
SELECT ID, 2, @adet, 3 + (ID % 200) * 0.75, DATEADD(DAY, -400, @bugun) FROM dbo.LM_STOK WHERE KOD LIKE 'PRF-%';
INSERT INTO dbo.LM_BARKOD (STOK_ID, BIRIM_ID, BARKOD)
SELECT ID, @adet, '869' + RIGHT('0000000000' + CAST(5000000000 + ID AS VARCHAR(10)), 10) FROM dbo.LM_STOK WHERE KOD LIKE 'PRF-%';

-- Açılış stoku: tek sayım belgesi, her ürüne 500–1500 adet
DECLARE @acilis TABLE (ID INT);
INSERT INTO dbo.LM_BELGE (TUR, YON, NO, TARIH, DEPO_ID, ACIKLAMA, DURUM) OUTPUT INSERTED.ID INTO @acilis
VALUES (6, 2, N'PRF-SYM', DATEADD(DAY, -366, @bugun), @merkez, N'Performans açılış stoku', 1);
INSERT INTO dbo.LM_STOK_HAREKET (STOK_ID, DEPO_ID, TARIH, BELGE_ID, GIRIS)
SELECT ID, @merkez, DATEADD(DAY, -366, @bugun), (SELECT ID FROM @acilis), 500 + ID % 1000 FROM dbo.LM_STOK WHERE KOD LIKE 'PRF-%';

IF OBJECT_ID('tempdb..#c') IS NOT NULL DROP TABLE #c;
SELECT ROW_NUMBER() OVER (ORDER BY ID) AS s, ID INTO #c FROM dbo.LM_CARI WHERE KOD LIKE 'PRF.%';
IF OBJECT_ID('tempdb..#s') IS NOT NULL DROP TABLE #s;
SELECT ROW_NUMBER() OVER (ORDER BY ID) AS s, ID, KDV_ORANI, 5 + (ID % 200) * 1.25 AS FIYAT INTO #s FROM dbo.LM_STOK WHERE KOD LIKE 'PRF-%';
CREATE UNIQUE CLUSTERED INDEX IX_c ON #c (s);
CREATE UNIQUE CLUSTERED INDEX IX_s ON #s (s);

-- Belgeler: 6.000 sipariş (TUR 1) + 12.000 satış faturası (TUR 3), son 365 güne yayılı
INSERT INTO dbo.LM_BELGE (TUR, YON, NO, TARIH, CARI_ID, DEPO_ID, PERSONEL_ID, DURUM, GUID)
SELECT CASE WHEN n <= 6000 THEN 1 ELSE 3 END, 1,
       CASE WHEN n <= 6000 THEN 'PSP' ELSE 'PFT' END + RIGHT('000000' + CAST(n AS VARCHAR(6)), 6),
       -- Her dördüncü belge tek büyük müşteriye (PRF.00001): ekstre/kart birkaç bin hareketle ölçülsün
       DATEADD(DAY, -(n * 7919 % 365), @bugun), (SELECT ID FROM #c WHERE s = CASE WHEN n % 4 = 0 THEN 1 ELSE 1 + (n * 37) % 5000 END), @merkez, 2, 1, NEWID()
FROM #n WHERE n <= 18000;
IF OBJECT_ID('tempdb..#b') IS NOT NULL DROP TABLE #b;
SELECT ID, TUR, TARIH, ROW_NUMBER() OVER (ORDER BY ID) AS s INTO #b FROM dbo.LM_BELGE WHERE NO LIKE 'PSP%' OR NO LIKE 'PFT%';
-- Satırlar: sipariş 5, fatura 4 satır (farklı ürünler)
INSERT INTO dbo.LM_BELGE_SATIR (BELGE_ID, SIRA, SATIR_TURU, STOK_ID, BIRIM_ID, CARPAN, MIKTAR, FIYAT, TUTAR, ISKONTO_ORANI, ISKONTO_TUTAR,
                                KDV_ORANI, KDV_MATRAH, KDV_TUTAR, NET)
SELECT B.ID, K.k, 0, S.ID, @adet, 1, M.miktar, S.FIYAT, M.miktar * S.FIYAT, 0, 0, S.KDV_ORANI,
       M.miktar * S.FIYAT, ROUND(M.miktar * S.FIYAT * S.KDV_ORANI / 100, 2), M.miktar * S.FIYAT
FROM #b B
CROSS APPLY (SELECT n AS k FROM #n WHERE n <= CASE WHEN B.TUR = 1 THEN 5 ELSE 4 END) K
JOIN #s S ON S.s = 1 + (B.s * 131 + K.k * 4999) % 20000
CROSS APPLY (SELECT CAST(1 + (B.s + K.k) % 24 AS DECIMAL(19,6)) AS miktar) M;
UPDATE B SET BRUT = A.T, ISKONTO = 0, KDV = A.V, NET = A.T + A.V
FROM dbo.LM_BELGE B JOIN (SELECT BELGE_ID, SUM(TUTAR) T, SUM(KDV_TUTAR) V FROM dbo.LM_BELGE_SATIR GROUP BY BELGE_ID) A ON A.BELGE_ID = B.ID
WHERE B.NO LIKE 'PSP%' OR B.NO LIKE 'PFT%';
-- Faturaların stok çıkışı ve cari borcu
INSERT INTO dbo.LM_STOK_HAREKET (STOK_ID, DEPO_ID, TARIH, BELGE_ID, SATIR_ID, CIKIS)
SELECT S.STOK_ID, @merkez, B.TARIH, B.ID, S.ID, S.MIKTAR
FROM dbo.LM_BELGE B JOIN dbo.LM_BELGE_SATIR S ON S.BELGE_ID = B.ID WHERE B.NO LIKE 'PFT%';
INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, BELGE_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
SELECT CARI_ID, TARIH, 1, ID, NO, NET, 0, N'Satış Faturası ' + NO FROM dbo.LM_BELGE WHERE NO LIKE 'PFT%';

-- Tahsilatlar: 15.000 kasa fişi (kasa girişi + cari alacağı)
INSERT INTO dbo.LM_FINANS_FIS (TUR, NO, TARIH, HESAP_ID, CARI_ID, TUTAR, ACIKLAMA, OLUSTURAN_ID)
SELECT 1, 'PTH' + RIGHT('000000' + CAST(n AS VARCHAR(6)), 6), DATEADD(DAY, -(n * 4513 % 365), @bugun), @kasa,
       (SELECT ID FROM #c WHERE s = CASE WHEN n % 5 = 0 THEN 1 ELSE 1 + (n * 53) % 5000 END), 100 + (n % 50) * 40, N'Performans tahsilat', 1
FROM #n WHERE n <= 15000;
INSERT INTO dbo.LM_FINANS_HAREKET (HESAP_ID, TARIH, FIS_ID, GIRIS, CIKIS, ACIKLAMA)
SELECT HESAP_ID, TARIH, ID, TUTAR, 0, ACIKLAMA FROM dbo.LM_FINANS_FIS WHERE NO LIKE 'PTH%';
INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, FINANS_FIS_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
SELECT CARI_ID, TARIH, 2, ID, NO, 0, TUTAR, ACIKLAMA FROM dbo.LM_FINANS_FIS WHERE NO LIKE 'PTH%';

PRINT 'Büyük veri yüklendi.';
