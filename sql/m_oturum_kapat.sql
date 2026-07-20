-- ============================================================================
-- M_OTURUM_KAPAT : Force-logout (yonetici acik oturumlari kapatabilsin)
-- ----------------------------------------------------------------------------
-- loglar.php cihaz sekmesindeki "Oturumlari Kapat" butonu bu tabloya kullanici
-- icin GETDATE() damgasi yazar. kontrol.php her istekte bu damgayi kontrol eder:
-- kullanicinin oturum baslangici (login_ts) bu damgadan eskiyse otomatik cikis.
-- Idempotent: tekrar calistirilabilir.
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'M_OTURUM_KAPAT')
BEGIN
    CREATE TABLE M_OTURUM_KAPAT (
        KULLANICI_ID INT NOT NULL PRIMARY KEY,  -- LG_SLSMAN.LOGICALREF (= plasiyer_id)
        KAPATMA_TS   DATETIME NOT NULL DEFAULT GETDATE(),  -- oturumlarin kapatildigi an
        KAPATAN_ID   INT NULL                   -- islemi yapan yonetici
    );
END
GO
