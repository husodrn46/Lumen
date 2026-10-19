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
     * cari        : ID, KOD, UNVAN, TUR, VERGI_NO, VERGI_DAIRESI, IL, ILCE, ADRES, TELEFON, EPOSTA, OZEL_KOD, AKTIF
     * cari_bakiye : CARI_ID, BORC, ALACAK, BAKIYE (+ borçlu)
     */
    function lumen_tablo(string $varlik): string
    {
        if (lumen_bagimsiz_mi()) {
            return match ($varlik) {
                'personel' => '(SELECT ID, KOD, AD, CAST(AKTIF AS INT) AS AKTIF, DEPO_ID AS DEPO, YETKI_KODU FROM dbo.LM_PERSONEL)',
                'cari' => '(SELECT ID, KOD, UNVAN, TUR, VERGI_NO, VERGI_DAIRESI, IL, ILCE, ADRES, TELEFON, EPOSTA, OZEL_KOD,'
                    . ' CAST(AKTIF AS INT) AS AKTIF FROM dbo.LM_CARI)',
                'cari_bakiye' => 'dbo.LMV_CARI_BAKIYE',
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
            'cari' => "(SELECT LOGICALREF AS ID, CODE AS KOD, DEFINITION_ AS UNVAN, CARDTYPE AS TUR, TAXNR AS VERGI_NO,"
                . " TAXOFFICE AS VERGI_DAIRESI, CITY AS IL, TOWN AS ILCE, ADDR1 AS ADRES, TELNRS1 AS TELEFON, EMAILADDR AS EPOSTA,"
                . " SPECODE AS OZEL_KOD, CASE WHEN ACTIVE = 0 THEN 1 ELSE 0 END AS AKTIF FROM {$firma}CLCARD)",
            'cari_bakiye' => "(SELECT CARDREF AS CARI_ID, DEBIT AS BORC, CREDIT AS ALACAK, DEBIT - CREDIT AS BAKIYE"
                . " FROM {$firmadonemx}GNTOTCL WHERE TOTTYP = 1)",
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
        $st = $dbh->prepare("SELECT P.ID, P.KOD, P.AD, P.AKTIF, P.DEPO, P.YETKI_KODU FROM {$P} P WHERE P.ID = :id"
            . ($pasifDahil ? '' : ' AND P.AKTIF = 1'));
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
