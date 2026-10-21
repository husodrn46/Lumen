-- ============================================================================
-- Lumen çekirdek — 08: performans dizinleri (büyük veri ölçümünde eksik bulunanlar;
-- tests/performans/olc.php). Tekrar çalıştırılabilir.
-- ============================================================================

-- İrsaliye/sipariş satırının hangi fatura satırına aktarıldığı (LMV_STLINE, iptal, siparişten fatura):
-- dizinsiz her satır için bütün satır tablosu taranıyordu (rapor 3+ dakika).
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_BS_KAYNAK')
    CREATE INDEX IX_LM_BS_KAYNAK ON dbo.LM_BELGE_SATIR (KAYNAK_SATIR_ID) INCLUDE (BELGE_ID, MIKTAR) WHERE KAYNAK_SATIR_ID IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_BELGE_KAYNAK')
    CREATE INDEX IX_LM_BELGE_KAYNAK ON dbo.LM_BELGE (KAYNAK_BELGE_ID) WHERE KAYNAK_BELGE_ID IS NOT NULL;
GO
-- Cari hareketin kaynağı (belge/fiş/çek iptali ve ekstre bağlantıları)
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_CRH_BELGE')
    CREATE INDEX IX_LM_CRH_BELGE ON dbo.LM_CARI_HAREKET (BELGE_ID) WHERE BELGE_ID IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_CRH_FIS')
    CREATE INDEX IX_LM_CRH_FIS ON dbo.LM_CARI_HAREKET (FINANS_FIS_ID) WHERE FINANS_FIS_ID IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_CRH_CEK')
    CREATE INDEX IX_LM_CRH_CEK ON dbo.LM_CARI_HAREKET (CEK_HAREKET_ID) WHERE CEK_HAREKET_ID IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_FHR_FIS')
    CREATE INDEX IX_LM_FHR_FIS ON dbo.LM_FINANS_HAREKET (FIS_ID);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_FF_CARI')
    CREATE INDEX IX_LM_FF_CARI ON dbo.LM_FINANS_FIS (CARI_ID, TARIH) WHERE CARI_ID IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_FF_TARIH')
    CREATE INDEX IX_LM_FF_TARIH ON dbo.LM_FINANS_FIS (TARIH) INCLUDE (TUR, HESAP_ID, KARSI_HESAP_ID, CARI_ID, TUTAR, DURUM);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_CH_CEK')
    CREATE INDEX IX_LM_CH_CEK ON dbo.LM_CEK_HAREKET (CEK_ID);
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_SH_SATIR')
    CREATE INDEX IX_LM_SH_SATIR ON dbo.LM_STOK_HAREKET (SATIR_ID) WHERE SATIR_ID IS NOT NULL;
GO
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_SH_TARIH')
    CREATE INDEX IX_LM_SH_TARIH ON dbo.LM_STOK_HAREKET (TARIH) INCLUDE (STOK_ID, DEPO_ID, GIRIS, CIKIS, BELGE_ID);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_LM_BELGE_CARIREF')
    CREATE INDEX IX_LM_BELGE_CARIREF ON dbo.LM_BELGE (CARI_REF, TUR, YON, TARIH) INCLUDE (DURUM, NET);
GO
