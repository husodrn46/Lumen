<?php

declare(strict_types=1);

/**
 * Lumen veri katmanı — iki modda (bağlı: LOGO, bağımsız: Lumen) aynı ekranlar.
 *
 * 1) Ortak (kanonik) tablolar — okuma:
 *      $P = lumen_tablo('personel');
 *      SELECT P.ID, P.KOD, P.AD FROM {$P} P WHERE P.AKTIF = 1
 *    LOGO modunda LG_SLSMAN üzerinde kolonları yeniden adlandıran bir alt sorgu,
 *    bağımsız modda doğrudan LM_PERSONEL döner. Kolon adları her iki modda aynıdır
 *    (LM_ tablolarının adları); ekranlara LOGO kolon adı sızmaz.
 *
 * 2) Depolar — yazma ve iş kuralları (lumen_personel_kaydet() …): modlara göre ayrılır.
 *
 * Ayrıntı ve yol haritası: docs/mimari/bagimsiz-lumen.md
 */

require_once __DIR__ . '/kaynak.php';
require_once __DIR__ . '/moduller.php';

if (!function_exists('lumen_tablo')) {
    /**
     * Varlığın ortak kolon adlarıyla SQL tablo ifadesi (FROM/JOIN'de takma adla kullanılır).
     *
     * personel    : ID, KOD, AD, AKTIF (1/0), DEPO, YETKI_KODU
     * cari        : ID, KOD, UNVAN, UNVAN2, TUR, VERGI_NO, VERGI_DAIRESI, IL, ILCE, SEMT, ADRES, ADRES2, ULKE, TELEFON, TELEFON2,
     *               EPOSTA, OZEL_KOD, AKTIF
     * cari_bakiye : CARI_ID, BORC, ALACAK, BAKIYE (+ borçlu)
     * firma       : NR, NAME (LOGO L_CAPIFIRM; bağımsızda tek firma)
     * depo        : NR (ambar no), NAME, FIRMNR (LOGO L_CAPIWHOUSE; bağımsızda LM_DEPO)
     */
    function lumen_tablo(string $varlik): string
    {
        if (lumen_bagimsiz_mi()) {
            return match ($varlik) {
                // DEPO: ambar numarası (LOGO: SPECODE) — LM_DEPO.NO; tanımsızsa 0 (merkez).
                'personel' => '(SELECT P.ID, P.KOD, P.AD, CAST(P.AKTIF AS INT) AS AKTIF,'
                    . ' ISNULL((SELECT D.NO FROM dbo.LM_DEPO D WHERE D.ID = P.DEPO_ID), 0) AS DEPO, P.YETKI_KODU FROM dbo.LM_PERSONEL P)',
                'cari' => '(SELECT ID, KOD, UNVAN, CAST(NULL AS NVARCHAR(200)) AS UNVAN2, TUR, VERGI_NO, VERGI_DAIRESI, IL, ILCE,'
                    . ' CAST(NULL AS NVARCHAR(60)) AS SEMT, ADRES, CAST(NULL AS NVARCHAR(200)) AS ADRES2, CAST(NULL AS NVARCHAR(60)) AS ULKE,'
                    . ' TELEFON, CAST(NULL AS NVARCHAR(40)) AS TELEFON2, EPOSTA, OZEL_KOD,'
                    . ' CAST(AKTIF AS INT) AS AKTIF FROM dbo.LM_CARI)',
                'cari_bakiye' => 'dbo.LMV_CARI_BAKIYE',
                'depo' => 'dbo.LMV_CAPIWHOUSE',
                // Tek firma = tek veritabanı; numara .env FIRMA_NO, ad LM_AYAR.firma_unvan.
                'firma' => '(SELECT ' . (int) (getenv('FIRMA_NO') ?: 1) . " AS NR, ISNULL((SELECT DEGER FROM dbo.LM_AYAR WHERE ANAHTAR = 'firma_unvan'), N'Lumen') AS NAME)",
                default => throw new InvalidArgumentException("Bilinmeyen varlık: {$varlik}"),
            };
        }

        $firmano = (int) ($GLOBALS['firmano'] ?? 1);
        $firma = lumen_logo_onek((string) ($GLOBALS['firma'] ?? ''));
        $firmadonemx = lumen_logo_onek((string) ($GLOBALS['firmadonemx'] ?? ''));
        return match ($varlik) {
            // LOGO'da ACTIVE = 0 etkin demektir.
            'personel' => "(SELECT LOGICALREF AS ID, CODE AS KOD, DEFINITION_ AS AD, CASE WHEN ACTIVE = 0 THEN 1 ELSE 0 END AS AKTIF,"
                . " SPECODE AS DEPO, CYPHCODE AS YETKI_KODU FROM LG_SLSMAN WHERE FIRMNR = {$firmano})",
            'cari' => "(SELECT LOGICALREF AS ID, CODE AS KOD, DEFINITION_ AS UNVAN, DEFINITION2 AS UNVAN2, CARDTYPE AS TUR, TAXNR AS VERGI_NO,"
                . " TAXOFFICE AS VERGI_DAIRESI, CITY AS IL, TOWN AS ILCE, DISTRICT AS SEMT, ADDR1 AS ADRES, ADDR2 AS ADRES2, COUNTRY AS ULKE,"
                . " TELNRS1 AS TELEFON, TELNRS2 AS TELEFON2, EMAILADDR AS EPOSTA,"
                . " SPECODE AS OZEL_KOD, CASE WHEN ACTIVE = 0 THEN 1 ELSE 0 END AS AKTIF FROM {$firma}CLCARD)",
            'cari_bakiye' => "(SELECT CARDREF AS CARI_ID, DEBIT AS BORC, CREDIT AS ALACAK, DEBIT - CREDIT AS BAKIYE"
                . " FROM {$firmadonemx}GNTOTCL WHERE TOTTYP = 1)",
            'firma' => 'L_CAPIFIRM',
            'depo' => 'L_CAPIWHOUSE',
            default => throw new InvalidArgumentException("Bilinmeyen varlık: {$varlik}"),
        };
    }
}

if (!function_exists('lumen_logo_onek')) {
    /** LOGO tablo öneki .env'den gelir; SQL'e girmeden önce biçimi doğrulanır. */
    function lumen_logo_onek(string $onek): string
    {
        if ($onek !== '' && preg_match('/^L[GV]_\d{3}_(\d{2}_)?$/', $onek) !== 1) {
            throw new RuntimeException("Geçersiz LOGO tablo öneki: {$onek}");
        }
        return $onek;
    }
}

// ---------------------------------------------------------------- personel

/*
 * LOGO uyumlu kişi tablosu (geçiş dönemi). Kullanıcı adını göstermek için
 * LG_SLSMAN'a JOIN yapan onlarca okuma sorgusu "{$GLOBALS['LUMEN_SLSMAN']}" ile
 * yazılır: LOGO modunda ifade aynen LG_SLSMAN'dır (SQL birebir aynı), bağımsız
 * modda aynı kolon adlarını LM_PERSONEL'den üreten LMV_SLSMAN görünümüdür (takma ad
 * ve WITH(NOLOCK) ipuçları her iki modda da geçerli kalır).
 * Yeni kod lumen_tablo('personel') ortak adlarını kullanmalıdır.
 */
$GLOBALS['LUMEN_SLSMAN'] = lumen_bagimsiz_mi() ? 'dbo.LMV_SLSMAN' : 'LG_SLSMAN';
// Günlük kurlar: sabit adlı LOGO sistem tablosu; bağımsızda LM_KUR üzerindeki uyumlu görünüm.
$GLOBALS['LUMEN_KUR'] = lumen_bagimsiz_mi() ? 'dbo.LMV_DAILYEXCHANGES' : 'L_DAILYEXCHANGES';   // görünüm: sql/lumen_cekirdek/05_gorunumler.sql

if (!function_exists('lumen_personel_giris_bilgisi')) {
    /**
     * Giriş için etkin kullanıcı: kimlik + parola özeti. Bulunamazsa null.
     * @return array{ID:int, KOD:string, AD:string, DEPO:mixed, YETKI_KODU:mixed, SIFRE:?string}|null
     */
    function lumen_personel_giris_bilgisi(PDO $dbh, string $kod): ?array
    {
        $P = lumen_tablo('personel');
        $st = $dbh->prepare("SELECT P.ID, P.KOD, P.AD, P.DEPO, P.YETKI_KODU, M.SIFRE
                             FROM {$P} P LEFT JOIN M_P_YETKI M ON M.PERSONEL = P.ID
                             WHERE P.KOD = :kod AND P.AKTIF = 1");
        $st->execute([':kod' => $kod]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('lumen_personel_getir')) {
    /** Kimliğe göre kullanıcı (yalnız etkinler, $pasifDahil değilse). */
    function lumen_personel_getir(PDO $dbh, int $id, bool $pasifDahil = false): ?array
    {
        $P = lumen_tablo('personel');
        $etkin = $pasifDahil ? '' : ' AND P.AKTIF = 1';
        $st = $dbh->prepare("SELECT P.ID, P.KOD, P.AD, P.AKTIF, P.DEPO, P.YETKI_KODU FROM {$P} P WHERE P.ID = :id{$etkin}");
        $st->execute([':id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
}

if (!function_exists('lumen_oturum_ac')) {
    /** Doğrulanmış kullanıcı için oturum değişkenlerini yazar (giriş ve "beni hatırla"). */
    function lumen_oturum_ac(array $personel): void
    {
        session_regenerate_id(true);
        $_SESSION['plasiyer_id'] = (int) $personel['ID'];
        $_SESSION['plasiyer_depo'] = $personel['DEPO'] ?? '';
        $_SESSION['plasiyer_yetki'] = $personel['YETKI_KODU'] ?? '';
        $_SESSION['kullanici_adi'] = (string) $personel['KOD'];
        $_SESSION['login_ts'] = time(); // force-logout referansı (oturum başlangıcı)
    }
}

// Kişi deposu — yazma. LOGO modunda LG_SLSMAN (LOGO tablosu; ACTIVE 0 = etkin),
// bağımsız modda LM_PERSONEL. Parola/yetki M_P_YETKI'de, iki modda ortaktır.

if (!function_exists('lumen_personel_ekle')) {
    /** Yeni kullanıcı; kimliğini döndürür. Çağıran transaction açabilir. */
    function lumen_personel_ekle(PDO $dbh, string $kod, string $ad, int $firmaNo): int
    {
        if (lumen_bagimsiz_mi()) {
            return lumen_insert_kimlik($dbh, 'INSERT INTO LM_PERSONEL (KOD, AD, AKTIF) VALUES (:c, :d, 1)',
                [':c' => $kod, ':d' => $ad], 'ID');
        }
        // LG_SLSMAN tetikleyicili olabilir: kimlik lastInsertId() ile değil OUTPUT ile alınır.
        return lumen_insert_kimlik($dbh,
            'INSERT INTO LG_SLSMAN (CODE, DEFINITION_, CARDTYPE, CAPIBLOCK_CREADEDDATE, USERID, FIRMNR, TYP, ACTIVE)
             VALUES (:c, :d, 0, GETDATE(), 1, :f, 0, 0)',
            [':c' => $kod, ':d' => $ad, ':f' => $firmaNo]);
    }
}

if (!function_exists('lumen_personel_guncelle')) {
    function lumen_personel_guncelle(PDO $dbh, int $id, string $kod, string $ad, int $firmaNo): void
    {
        if (lumen_bagimsiz_mi()) {
            $dbh->prepare('UPDATE LM_PERSONEL SET KOD = :c, AD = :d WHERE ID = :id')
                ->execute([':c' => $kod, ':d' => $ad, ':id' => $id]);
            return;
        }
        $dbh->prepare('UPDATE LG_SLSMAN SET CODE = :c, DEFINITION_ = :d, FIRMNR = :f WHERE LOGICALREF = :id')
            ->execute([':c' => $kod, ':d' => $ad, ':f' => $firmaNo, ':id' => $id]);
    }
}

if (!function_exists('lumen_personel_aktiflik')) {
    function lumen_personel_aktiflik(PDO $dbh, int $id, bool $aktif): void
    {
        if (lumen_bagimsiz_mi()) {
            $dbh->prepare('UPDATE LM_PERSONEL SET AKTIF = :a WHERE ID = :id')->execute([':a' => $aktif ? 1 : 0, ':id' => $id]);
            return;
        }
        $dbh->prepare('UPDATE LG_SLSMAN SET ACTIVE = :a WHERE LOGICALREF = :id')->execute([':a' => $aktif ? 0 : 1, ':id' => $id]);
    }
}

if (!function_exists('lumen_personel_sil')) {
    /** Yalnız pasif kullanıcı silinir; silindiyse true. Yetki satırını çağıran siler. */
    function lumen_personel_sil(PDO $dbh, int $id): bool
    {
        $st = lumen_bagimsiz_mi()
            ? $dbh->prepare('DELETE FROM LM_PERSONEL WHERE ID = :id AND AKTIF = 0')
            : $dbh->prepare('DELETE FROM LG_SLSMAN WHERE LOGICALREF = :id AND ACTIVE = 1');
        $st->execute([':id' => $id]);
        return $st->rowCount() > 0;
    }
}
