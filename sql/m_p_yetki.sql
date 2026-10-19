-- ============================================================================
-- M_P_YETKI : Kullanici yetkileri (Lumen'e ozel tablo)
-- ----------------------------------------------------------------------------
-- PERSONEL -> LG_SLSMAN.LOGICALREF (uygulama kullanicisi)
-- SIFRE    -> bcrypt hash (password_hash)
-- YETKI    -> 0 = Yonetici (tum izinleri otomatik alir)
--             1 = Personel (izin kolonlarina bakilir)
--             2 = Musteri  (salt okunur)
-- M*/ST*/CR*/SP* -> izin kolonlari (0/1). Ayarlar > Kullanici & Yetki >
--                   Izin Matrisi ekranindan yonetilir.
-- Idempotent: tekrar calistirilabilir.
-- ============================================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'M_P_YETKI')
BEGIN
    CREATE TABLE M_P_YETKI (
        PERSONEL INT           NOT NULL PRIMARY KEY,
        SIFRE    NVARCHAR(255) NULL,
        YETKI    INT           NULL,

        -- Menu / modul izinleri
        M1  INT NOT NULL DEFAULT 0,  M2  INT NOT NULL DEFAULT 0,  M3  INT NOT NULL DEFAULT 0,
        M4  INT NOT NULL DEFAULT 0,  M5  INT NOT NULL DEFAULT 0,  M6  INT NOT NULL DEFAULT 0,
        M7  INT NOT NULL DEFAULT 0,  M8  INT NOT NULL DEFAULT 0,  M9  INT NOT NULL DEFAULT 0,
        M10 INT NOT NULL DEFAULT 0,  M11 INT NOT NULL DEFAULT 0,  M12 INT NOT NULL DEFAULT 0,
        M13 INT NOT NULL DEFAULT 0,  M14 INT NOT NULL DEFAULT 0,  M15 INT NOT NULL DEFAULT 0,
        M16 INT NOT NULL DEFAULT 0,  M17 INT NOT NULL DEFAULT 0,  M18 INT NOT NULL DEFAULT 0,
        M19 INT NOT NULL DEFAULT 0,  M20 INT NOT NULL DEFAULT 0,  M21 INT NOT NULL DEFAULT 0,
        M22 INT NOT NULL DEFAULT 0,  M23 INT NOT NULL DEFAULT 0,  M24 INT NOT NULL DEFAULT 0,
        M25 INT NOT NULL DEFAULT 0,  M26 INT NOT NULL DEFAULT 0,  M27 INT NOT NULL DEFAULT 0,
        M28 INT NOT NULL DEFAULT 0,  M29 INT NOT NULL DEFAULT 0,  M30 INT NOT NULL DEFAULT 0,
        M31 INT NOT NULL DEFAULT 0,

        -- Stok alan izinleri
        ST1 INT NOT NULL DEFAULT 0,  ST2 INT NOT NULL DEFAULT 0,  ST3 INT NOT NULL DEFAULT 0,

        -- Cari alan izinleri
        CR1 INT NOT NULL DEFAULT 0,  CR2 INT NOT NULL DEFAULT 0,
        CR3 INT NOT NULL DEFAULT 0,  CR4 INT NOT NULL DEFAULT 0,

        -- Siparis / fiyat alan izinleri
        SP1 INT NOT NULL DEFAULT 0,  SP2 INT NOT NULL DEFAULT 0,
        SP3 INT NOT NULL DEFAULT 0,  SP4 INT NOT NULL DEFAULT 0
    );
END
GO
