-- ============================================================================
-- Lumen bağımsız mod demo verisi — TAMAMEN UYDURMADIR.
-- LOGO demosuyla (demo/sql/02_demo_veri.sql) aynı kullanıcı, cari ve ürünler;
-- iki mod yan yana karşılaştırılabilsin diye.
-- Önce sql/lumen_cekirdek/*.sql ve sql/*.sql (bağımsız mod) uygulanmış olmalıdır.
-- Tekrar çalıştırılabilir: demo tablolarını boşaltıp yeniden doldurur.
--
-- Kullanıcılar (parola hepsinde: demo1234): YONETICI, SATIS1, DEPO1, ENTEGRASYON
-- ============================================================================
SET NOCOUNT ON;
SET DATEFORMAT ymd;

DELETE FROM M_P_YETKI;
DELETE FROM LM_CARI_HAREKET; DELETE FROM LM_CEK_HAREKET; DELETE FROM LM_CEK;
DELETE FROM LM_FINANS_HAREKET; DELETE FROM LM_FINANS_FIS;
DELETE FROM LM_STOK_HAREKET; DELETE FROM LM_BELGE_SATIR; DELETE FROM LM_BELGE;
DELETE FROM LM_FIYAT; DELETE FROM LM_BARKOD; DELETE FROM LM_STOK_BIRIM; DELETE FROM LM_STOK;
DELETE FROM LM_CARI; DELETE FROM LM_KUR;
UPDATE LM_PERSONEL SET DEPO_ID = NULL; DELETE FROM LM_PERSONEL;
GO

-- ---------------------------------------------------------------- kullanıcılar
SET IDENTITY_INSERT LM_PERSONEL ON;
INSERT INTO LM_PERSONEL (ID, KOD, AD, AKTIF, DEPO_ID, YETKI_KODU) VALUES
    (1, 'YONETICI', N'Demo Yönetici', 1, NULL, ''),
    (2, 'SATIS1', N'Ayşe Demir (demo)', 1, NULL, ''),
    (3, 'DEPO1', N'Mehmet Kaya (demo)', 1, NULL, ''),
    (4, 'ESKI1', N'Pasif Kullanıcı (demo)', 0, NULL, ''),
    (5, 'ENTEGRASYON', N'Raporlama entegrasyonu (demo)', 1, NULL, '');
SET IDENTITY_INSERT LM_PERSONEL OFF;

DECLARE @hash NVARCHAR(255) = N'$2y$10$SunKM5b7dCQna2VUK8vFTuuY.LVzKikqg8DOSVLOJdTgjVFQh3ZXG';
INSERT INTO M_P_YETKI (PERSONEL, SIFRE, YETKI) VALUES (1, @hash, 0), (2, @hash, 1), (3, @hash, 1), (4, @hash, 1), (5, @hash, 3);
UPDATE M_P_YETKI SET M1=1, M2=1, M3=1, M4=1, M5=1, M7=1, M8=1, M15=1, M30=1, M31=1, M32=1, ST1=1, ST2=1, CR1=1, CR2=1 WHERE PERSONEL = 2;
UPDATE M_P_YETKI SET M15=1, ST1=1 WHERE PERSONEL = 3;
UPDATE M_P_YETKI SET M5 = 1, M17 = 1, CR1 = 1, ST1 = 1, ST2 = 1 WHERE PERSONEL = 5;
GO

-- ---------------------------------------------------------------- firma
DELETE FROM LM_AYAR WHERE ANAHTAR LIKE 'firma_%';
INSERT INTO LM_AYAR (ANAHTAR, DEGER) VALUES ('firma_unvan', N'Lumen Demo Firması'), ('firma_vergi_no', '1234567890'),
    ('firma_vergi_dairesi', N'Demo'), ('firma_adres', N'Örnek Mah. Deneme Cad. No: 1 Kadıköy / İstanbul'),
    ('firma_telefon', '0 216 000 00 00'), ('firma_eposta', 'info@ornek.test');
INSERT INTO LM_KUR (TARIH, PARA_BIRIMI, ALIS, SATIS) VALUES ('2026-10-15', 'USD', 41.10, 41.25), ('2026-10-15', 'EUR', 47.80, 48.00);
GO

-- ---------------------------------------------------------------- stok kartları
DECLARE @urun TABLE (n INT IDENTITY(1,1), ad NVARCHAR(100), grup NVARCHAR(20), fiyat DECIMAL(19,4), koli INT);
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

DECLARE @adet INT = (SELECT ID FROM LM_BIRIM WHERE KOD = N'ADET');
DECLARE @koli INT = (SELECT ID FROM LM_BIRIM WHERE KOD = N'KOLI');
DECLARE @paket INT = (SELECT ID FROM LM_BIRIM WHERE KOD = N'PAKET');

SET IDENTITY_INSERT LM_STOK ON;
INSERT INTO LM_STOK (ID, KOD, AD, GRUP, OZEL_KOD, ANA_BIRIM_ID, KDV_ORANI, AGIRLIK_KG)
SELECT n, 'DM-' + RIGHT('0000' + CAST(n AS VARCHAR(4)), 4), ad, grup, grup, @adet, 20, 0.35 FROM @urun;
SET IDENTITY_INSERT LM_STOK OFF;

INSERT INTO LM_STOK_BIRIM (STOK_ID, BIRIM_ID, CARPAN)
SELECT n, @adet, 1 FROM @urun UNION ALL SELECT n, @koli, koli FROM @urun UNION ALL SELECT n, @paket, 6 FROM @urun;
INSERT INTO LM_BARKOD (STOK_ID, BIRIM_ID, BARKOD)
SELECT n, @adet, '86900000' + RIGHT('00000' + CAST(n AS VARCHAR(5)), 5) FROM @urun;
INSERT INTO LM_FIYAT (STOK_ID, TUR, BIRIM_ID, FIYAT, BASLANGIC)
SELECT n, 1, @adet, fiyat, '2026-01-01' FROM @urun UNION ALL SELECT n, 2, @adet, ROUND(fiyat * 0.6, 2), '2026-01-01' FROM @urun;
UPDATE SB SET AGIRLIK_KG = 0.35 * SB.CARPAN FROM LM_STOK_BIRIM SB;

-- Açılış stoku: LOGO demosundaki miktarlar, bir sayım belgesi (TUR 6, giriş) ile.
DECLARE @merkez INT = (SELECT ID FROM LM_DEPO WHERE NO = 0);
DECLARE @acilis TABLE (ID INT);
INSERT INTO LM_BELGE (TUR, YON, NO, TARIH, DEPO_ID, ACIKLAMA, DURUM) OUTPUT INSERTED.ID INTO @acilis
VALUES (6, 2, N'SYM000001', '2026-01-01', @merkez, N'Açılış sayımı (demo)', 1);
INSERT INTO LM_STOK_HAREKET (STOK_ID, DEPO_ID, TARIH, BELGE_ID, GIRIS)
SELECT n, @merkez, '2026-01-01', (SELECT ID FROM @acilis), (n * 37) % 900 + 40 FROM @urun;
UPDATE LM_SERI SET SON_NO = 1 WHERE TUR = 'SAYIM';
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

SET IDENTITY_INSERT LM_CARI ON;
INSERT INTO LM_CARI (ID, KOD, UNVAN, TUR, IL, ILCE, ADRES, TELEFON, EPOSTA, VERGI_NO, VERGI_DAIRESI)
SELECT n, '120.DM.' + RIGHT('000' + CAST(n AS VARCHAR(3)), 3), unvan, 3, sehir, ilce,
       N'Demo Cad. No:' + CAST(n AS NVARCHAR(5)), '0 (555) 000 00 ' + RIGHT('00' + CAST(n AS VARCHAR(2)), 2),
       'demo' + CAST(n AS VARCHAR(3)) + '@example.com', RIGHT('0000000000' + CAST(n * 1234567 AS VARCHAR(10)), 10), N'Demo VD'
FROM @cari;
SET IDENTITY_INSERT LM_CARI OFF;
GO

-- ---------------------------------------------------------------- siparişler
-- LOGO demosundakiyle aynı 30 sipariş; LOGO uyumlu görünümler (LMV_ORFICHE/LMV_ORFLINE)
-- üzerinden eklenir — demo kurulumu aynı zamanda yazma tetikleyicilerini sınar.
DECLARE @i INT = 1;
WHILE @i <= 30
BEGIN
    DECLARE @cariRef INT = ((@i * 7) % 14) + 1;
    DECLARE @tarih DATETIME = DATEADD(DAY, -(@i * 3), CAST('2026-10-15' AS DATETIME));
    INSERT INTO dbo.LMV_ORFICHE (TRCODE, FICHENO, DATE_, TIME_, CLIENTREF, SALESMANREF, STATUS, SOURCEINDEX, BRANCH,
                                 TRCURR, TRRATE, GENEXP1, CANCELLED, CAPIBLOCK_CREADEDDATE, CAPIBLOCK_CREATEDBY)
    VALUES (1, 'SIP' + RIGHT('000000' + CAST(@i AS VARCHAR(6)), 6), @tarih, 168427520, @cariRef,
            CASE WHEN @i % 3 = 0 THEN 1 ELSE 2 END, 4, 0, 0, 0, 0, N'Demo sipariş', 0, @tarih, 1);
    DECLARE @fis INT = CAST(@@IDENTITY AS INT);   -- görünüme ekleme: kimlik tetikleyicinin eklemesinden

    DECLARE @satir INT = 1;
    WHILE @satir <= 2 + (@i % 4)
    BEGIN
        DECLARE @stok INT = ((@i * 5 + @satir * 3) % 25) + 1;
        DECLARE @miktar FLOAT = ((@i + @satir) % 6 + 1) * 12;
        DECLARE @fiyat FLOAT = (SELECT CAST(FIYAT AS FLOAT) FROM LM_FIYAT WHERE STOK_ID = @stok AND TUR = 1);
        DECLARE @tutar FLOAT = ROUND(@miktar * @fiyat, 2);
        DECLARE @uom INT = (SELECT SB.ID FROM LM_STOK_BIRIM SB JOIN LM_STOK S ON S.ID = SB.STOK_ID AND S.ANA_BIRIM_ID = SB.BIRIM_ID
                            WHERE SB.STOK_ID = @stok);
        INSERT INTO dbo.LMV_ORFLINE (ORDFICHEREF, CLIENTREF, STOCKREF, LINETYPE, LINENO_, TRCODE, DATE_, TIME_, AMOUNT, PRICE,
                                     TOTAL, LINENET, VAT, VATAMNT, VATMATRAH, UOMREF, USREF, UINFO1, UINFO2, SOURCEINDEX,
                                     STATUS, SALESMANREF, SHIPPEDAMOUNT, CLOSED, CANCELLED, TRCURR, TRRATE, PRCURR, PRPRICE)
        VALUES (@fis, @cariRef, @stok, 0, @satir, 1, @tarih, 168427520, @miktar, @fiyat,
                @tutar, @tutar, 20, ROUND(@tutar * 0.2, 2), @tutar, @uom, @stok, 1, 1, 0,
                4, CASE WHEN @i % 3 = 0 THEN 1 ELSE 2 END, CASE WHEN @i > 20 THEN @miktar ELSE 0 END,
                CASE WHEN @i > 20 THEN 1 ELSE 0 END, 0, 0, 0, 0, @fiyat);
        SET @satir += 1;
    END;

    -- INSTEAD OF tetikleyicili görünüm birleştirmeli UPDATE ... FROM kabul etmez: alt sorgu ile.
    DECLARE @t FLOAT = (SELECT SUM(TOTAL) FROM dbo.LMV_ORFLINE WHERE ORDFICHEREF = @fis);
    DECLARE @v FLOAT = (SELECT SUM(VATAMNT) FROM dbo.LMV_ORFLINE WHERE ORDFICHEREF = @fis);
    UPDATE dbo.LMV_ORFICHE SET GROSSTOTAL = @t, TOTALVAT = @v, NETTOTAL = @t + @v, TOTALDISCOUNTED = @t, REPORTNET = @t + @v
    WHERE LOGICALREF = @fis;
    SET @i += 1;
END;
UPDATE LM_SERI SET SON_NO = 30 WHERE TUR = 'SATIS_SIPARIS';
GO

-- ---------------------------------------------------------------- cari hareketler (açılış)
-- LOGO demosundaki gibi: her cariye üç borç (fatura) ve bir tahsilat; 15. cari bakiyesiz.
-- Belge/finans kayıtları sonraki fazlarda gelir; burada açılış hareketi (KAYNAK 4).
INSERT INTO LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, BELGE_NO, BORC, ALACAK, ACIKLAMA)
SELECT C.ID, DATEADD(DAY, -40 + v.k * 9, '2026-10-10'), 4, 'FTR' + CAST(C.ID * 10 + v.k AS VARCHAR(8)),
       5000 + C.ID * 750 + v.k * 300, 0, N'Demo satış faturası (açılış)'
FROM LM_CARI C CROSS JOIN (VALUES (1), (2), (3)) v(k) WHERE C.ID <= 14;
GO

-- ---------------------------------------------------------------- kasa / banka / çek
-- Tahsilatlar gerçek kasa fişi (LOGO demosundaki KSLINES ile aynı: kasa toplamı 108.500).
UPDATE LM_FINANS_HESAP SET AD = N'Merkez Kasa (demo)' WHERE KOD = 'KASA.01';
INSERT INTO LM_FINANS_HESAP (TUR, KOD, AD, BANKA_ADI, IBAN)
VALUES (2, 'BNK.01.TL', N'Demo Bankası TL Hesabı', N'Demo Bankası', 'TR690006100000001234567890');
DECLARE @kasa INT = (SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'KASA.01');
INSERT INTO LM_FINANS_FIS (TUR, NO, TARIH, HESAP_ID, CARI_ID, TUTAR, ACIKLAMA, OLUSTURAN_ID)
SELECT 1, 'THS' + RIGHT('000000' + CAST(C.ID AS VARCHAR(6)), 6), DATEADD(DAY, -5, '2026-10-10'), @kasa, C.ID,
       4000 + C.ID * 500, N'Demo nakit tahsilat', 1
FROM LM_CARI C WHERE C.ID <= 14;
INSERT INTO LM_FINANS_HAREKET (HESAP_ID, TARIH, FIS_ID, GIRIS, CIKIS, ACIKLAMA)
SELECT HESAP_ID, TARIH, ID, TUTAR, 0, ACIKLAMA FROM LM_FINANS_FIS WHERE TUR = 1;
INSERT INTO LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, FINANS_FIS_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
SELECT CARI_ID, TARIH, 2, ID, NO, 0, TUTAR, ACIKLAMA FROM LM_FINANS_FIS WHERE TUR = 1;
UPDATE LM_SERI SET SON_NO = 14 WHERE TUR = 'TAHSILAT';

-- Portföydeki müşteri çekleri (açılış portföyü: cari etkisi açılış bakiyesinde).
INSERT INTO LM_CEK (TUR, PORTFOY_NO, SERI_NO, BANKA, BORCLU, VADE, TUTAR, DURUM, ALINAN_CARI_ID)
SELECT 1, 'P' + RIGHT('00000' + CAST(C.ID AS VARCHAR(5)), 5), 'CK' + CAST(900000 + C.ID AS VARCHAR(8)), N'Demo Bankası', C.UNVAN,
       DATEADD(DAY, 15 + C.ID * 6, '2026-10-10'), 2500 + C.ID * 400, 1, C.ID
FROM LM_CARI C WHERE C.ID BETWEEN 1 AND 8;
INSERT INTO LM_CEK_HAREKET (CEK_ID, TARIH, BORDRO_NO, ISLEM, CARI_ID, ACIKLAMA, OLUSTURAN_ID)
SELECT ID, '2026-10-01', 'BRD000001', 1, ALINAN_CARI_ID, N'Açılış portföyü', 1 FROM LM_CEK WHERE TUR = 1;
UPDATE LM_SERI SET SON_NO = 1 WHERE TUR = 'CEK_BORDRO';
GO
