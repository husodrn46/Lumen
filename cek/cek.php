<?php

declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../log_ip.php");
include_once(__DIR__ . "/../ayr.php");

// Dönem kontrolü (2025 dönemi için eski tabloları kullan)
$donemParam = isset($_GET['donem']) ? $_GET['donem'] : '';
if ($donemParam === '2025' && isset($eskifirmadonem)) {
    $firmadonem = $eskifirmadonem;
}

// Parametreler: cari_id + (CSREF=tek cek | ROLLREF=bordro)
$CARIID = isset($_GET['cari_id']) ? (int) $_GET['cari_id'] : 0;
$CSREF  = isset($_GET['CSREF']) ? (int) $_GET['CSREF'] : (isset($_GET['REF']) ? (int) $_GET['REF'] : 0);
$ROLLREF = isset($_GET['ROLLREF']) ? (int) $_GET['ROLLREF'] : 0;
if ($CARIID <= 0 || ($CSREF <= 0 && $ROLLREF <= 0)) {
    exit('Parametre eksik.');
}
$mod = $ROLLREF > 0 ? 'bordro' : 'tekcek';

// GUVENLIK: Tek cek modunda URL'deki cari_id yanlis olabilir (cek baska bir
// cariden alinmis olabilir). Cekin GERCEK giris carisini (CSTRANS ilk TRCODE=1
// hareketi) kullan ki baslik/bakiye dogru cariye ait olsun.
if ($mod === 'tekcek' && $CSREF > 0) {
    try {
        $gcStmt = $dbh->prepare("SELECT TOP 1 t.CARDREF FROM {$firmadonem}CSTRANS t WHERE t.CSREF = :c AND t.TRCODE = 1 ORDER BY t.LOGICALREF ASC");
        $gcStmt->execute([':c' => $CSREF]);
        $gercekCari = (int) $gcStmt->fetchColumn();
        if ($gercekCari > 0) {
            $CARIID = $gercekCari;
        }
    } catch (Throwable $e) {
        // hata olursa URL'deki cari_id ile devam
    }
}

if (!function_exists('paraformat')) {
    function paraformat(float|int|string|null $kusurat): string
    {
        global $parakusurat;
        if ($kusurat === "" || is_null($kusurat)) {
            $kusurat = 0;
        }
        return number_format((float) $kusurat, $parakusurat ?? 2, ',', '.');
    }
}

$mdoviz = "₺";

// CSTRANS.TRCODE -> islem metni
$trcodeText = static function (int $kod): string {
    return match ($kod) {
        1 => 'Giriş', 2 => 'Ciro', 3 => 'Ciro Edilen', 4 => 'Teminat',
        5 => 'Tahsile Verildi', 6 => 'Tahsil', 7 => 'Protestolu Tahsil',
        8 => 'İade', 9 => 'Protesto', 10 => 'Kendi Çekimiz', 11 => 'Borç Senedimiz',
        default => 'Diğer',
    };
};

// Guncel durum SON HAREKETIN TRCODE'undan belirlenir (CURRSTAT bu sistemde guvenilir degil).
$durumBilgi = static function (int $sonTrcode): array {
    return match ($sonTrcode) {
        1       => ['Portföyde', 'mavi'],
        2, 3    => ['Ciro edildi', 'amber'],
        4       => ['Teminatta', 'mavi'],
        5       => ['Tahsile verildi', 'mavi'],
        6       => ['Tahsil edildi', 'yesil'],
        7       => ['Protestolu tahsil', 'kirmizi'],
        8       => ['İade edildi', 'gri'],
        9       => ['Protesto edildi', 'kirmizi'],
        10      => ['Kendi çekimiz', 'gri'],
        11      => ['Borç senedimiz', 'gri'],
        default => ['Belirsiz', 'gri'],
    };
};

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// --- Cari + bakiye (ortak) ---
$stmtCari = $dbh->prepare("SELECT DEFINITION_, CITY, TELNRS1 FROM {$firma}CLCARD WHERE LOGICALREF = :cariid");
$stmtCari->execute([':cariid' => $CARIID]);
$cari = $stmtCari->fetch(PDO::FETCH_ASSOC) ?: ['DEFINITION_' => '', 'CITY' => '', 'TELNRS1' => ''];

$stmtBakiye = $dbh->prepare("
    SELECT SUM((1 - L.SIGN) * L.AMOUNT) - SUM(L.SIGN * L.AMOUNT) AS BAKIYE
    FROM {$firma}CLCARD C
    LEFT JOIN {$firmadonem}CLFLINE L ON C.LOGICALREF = L.CLIENTREF AND L.CANCELLED = 0
    WHERE C.LOGICALREF = :cariid
");
$stmtBakiye->execute([':cariid' => $CARIID]);
$sqlbakiye = $stmtBakiye->fetch(PDO::FETCH_ASSOC);
$bakiyeSon = $sqlbakiye['BAKIYE'] ?? 0;

if ($mod === 'bordro') {
    // ---------------- BORDRO MODU: cikis bordrosundaki tum cek/senetler ----------------
    $stmtBordro = $dbh->prepare("SELECT ROLLNO, DATE_, TRCODE FROM {$firmadonem}CSROLL WHERE LOGICALREF = :rollref");
    $stmtBordro->execute([':rollref' => $ROLLREF]);
    $bordro = $stmtBordro->fetch(PDO::FETCH_ASSOC) ?: ['ROLLNO' => '', 'DATE_' => null, 'TRCODE' => 0];

    $stmtCekler = $dbh->prepare("
        SELECT
          c.LOGICALREF AS CSREF, c.DOC, c.NEWSERINO AS SERINO,
          c.AMOUNT AS TUTAR, c.DUEDATE AS VADE, c.OWING AS SAHIP,
          (SELECT TOP 1 t2.TRCODE FROM {$firmadonem}CSTRANS t2 WHERE t2.CSREF = c.LOGICALREF ORDER BY t2.LOGICALREF DESC) AS SON_TRCODE
        FROM {$firmadonem}CSTRANS t
        JOIN {$firmadonem}CSCARD c ON c.LOGICALREF = t.CSREF
        WHERE t.ROLLREF = :rollref
        ORDER BY c.DUEDATE ASC, c.AMOUNT DESC
    ");
    $stmtCekler->execute([':rollref' => $ROLLREF]);
    $cekler = $stmtCekler->fetchAll(PDO::FETCH_ASSOC);

    $bordroToplam = 0.0;
    foreach ($cekler as $c) {
        $bordroToplam += (float) $c['TUTAR'];
    }
    $bordroTur = ((int) $bordro['TRCODE'] === 4) ? 'Senet' : 'Çek';
    $sayfaBasligi = 'Çek/Senet Çıkış Bordrosu';
} else {
    // ---------------- TEK CEK MODU ----------------
    $stmtHeader = $dbh->prepare("
        SELECT
          c.DOC AS DOC, c.NEWSERINO AS SERINO, c.BANKNAME AS BANKA,
          SUBSTRING(c.BNBRANCHNO,6,12) AS SUBE, c.AMOUNT AS TUTAR,
          c.DUEDATE AS VADE, CONVERT(char(8), c.DUEDATE, 112) AS VADE_YMD,
          (SELECT TOP 1 cl.CODE FROM {$firmadonem}CSTRANS t JOIN {$firma}CLCARD cl ON cl.LOGICALREF = t.CARDREF
            WHERE t.CSREF = c.LOGICALREF ORDER BY t.LOGICALREF ASC) AS KIMDEN_CODE,
          (SELECT TOP 1 cl.DEFINITION_ FROM {$firmadonem}CSTRANS t JOIN {$firma}CLCARD cl ON cl.LOGICALREF = t.CARDREF
            WHERE t.CSREF = c.LOGICALREF ORDER BY t.LOGICALREF ASC) AS KIMDEN_AD,
          lm.LastTrCode
        FROM {$firmadonem}CSCARD c
        OUTER APPLY (
            SELECT TOP (1) t.TRCODE AS LastTrCode
            FROM {$firmadonem}CSTRANS t
            WHERE t.CSREF = c.LOGICALREF
            ORDER BY t.LOGICALREF DESC
        ) lm
        WHERE c.LOGICALREF = :csref
    ");
    $stmtHeader->execute([':csref' => $CSREF]);
    $sqlHeader = $stmtHeader->fetch(PDO::FETCH_ASSOC);
    if (!$sqlHeader) {
        exit('Çek/Senet bulunamadı.');
    }

    $sonTrcode = (int) ($sqlHeader['LastTrCode'] ?? 0);
    [$durumEtiket, $durumRenk] = $durumBilgi($sonTrcode);
    $portfoyde = ($sonTrcode === 1 || $sonTrcode === 0);
    $bugun = new DateTimeImmutable(date('Y-m-d'));
    $vade = DateTimeImmutable::createFromFormat('Ymd', (string) $sqlHeader['VADE_YMD']) ?: $bugun;
    $kalanGun = (int) $bugun->diff($vade)->format('%r%a');
    $turAdi = ($sqlHeader['DOC'] == 1 ? 'Çek' : 'Senet');
    $sayfaBasligi = $turAdi . ' Detayı';

    // --- Bu cekin gorselleri (M_CEK_EK) ---
    $isYonetici = (int) ($yetkidurum ?? 1) === 0;
    $csrfTokenCek = function_exists('csrf_token') ? csrf_token() : '';
    $cekGorseller = [];
    try {
        $gs = $dbh->prepare("SELECT ID, ETIKET, DOSYA_ADI FROM M_CEK_EK WHERE CEK_REF = :c ORDER BY ID");
        $gs->execute([':c' => $CSREF]);
        $cekGorseller = $gs->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cekGorseller = [];
    }

    // --- Islem gecmisi (akordeon: ozet satir + dokununca tum hareketler) ---
    $gecmis = [];
    try {
        $stmtTr = $dbh->prepare("
            SELECT t.DATE_ AS Tarih, t.TRCODE, r.ROLLNO, cp.DEFINITION_ AS KarsiCariAdi, cp.CODE AS KarsiCariKodu
            FROM {$firmadonem}CSTRANS t
            LEFT JOIN {$firmadonem}CSROLL r ON r.LOGICALREF = t.ROLLREF
            LEFT JOIN {$firma}CLCARD cp ON cp.LOGICALREF = t.CARDREF
            WHERE t.CSREF = :csref
            ORDER BY t.DATE_, t.LOGICALREF
        ");
        $stmtTr->execute([':csref' => $CSREF]);
        $gecmis = $stmtTr->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $gecmis = [];
    }
    $sonHareket = $gecmis === [] ? null : end($gecmis);
    if ($gecmis !== []) { reset($gecmis); }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo $h($sayfaBasligi); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --text-1:#1f2937; --text-2:#6b7280; --text-3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; }
        * { box-sizing: border-box; margin: 0; }
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; background: var(--bg); color: var(--text-1); min-height: 100vh; font-size: 14px; }

        .top-header { position: sticky; top: 0; z-index: 40; background: rgba(255,255,255,0.92); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); border-bottom: 1px solid var(--border); }
        .header-inner { max-width: 560px; margin: 0 auto; padding: 11px 16px; display: flex; align-items: center; gap: 12px; }
        .header-back { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 9px; color: var(--text-2); text-decoration: none; transition: all 0.18s ease; flex-shrink: 0; }
        .header-back:hover { background: rgba(0,0,0,0.05); color: var(--red); }
        .header-title { font-size: 15px; font-weight: 600; flex: 1 1 auto; display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
        .header-title i { color: var(--red); }
        .btn-print { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: var(--red); color: #fff; border: none; border-radius: 9px; font-family: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: all 0.18s ease; flex-shrink: 0; }
        .btn-print:hover { background: #b91c1c; }

        main { max-width: 560px; margin: 0 auto; padding: 16px 14px 50px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; margin-bottom: 12px; overflow: hidden; }

        /* HERO: tek bakista tutar + durum + vade */
        .hero { padding: 16px 18px 14px; }
        .hero-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .hero-kod { font-size: 11px; font-weight: 600; letter-spacing: 0.4px; color: var(--text-3); text-transform: uppercase; }
        .hero-tutar { font-size: 28px; font-weight: 700; line-height: 1.15; margin-top: 9px; }
        .hero-cari { font-size: 13.5px; color: var(--text-2); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .hero-vade { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 14px; padding-top: 13px; border-top: 1px dashed var(--border); }
        .hero-vade .lbl { font-size: 11px; color: var(--text-3); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
        .hero-vade .tarih { font-size: 16px; font-weight: 600; margin-top: 1px; }
        .vade-rozet { font-size: 12.5px; font-weight: 600; padding: 5px 12px; border-radius: 8px; white-space: nowrap; }
        .vade-rozet.yesil { background: #ecfdf5; color: #047857; }
        .vade-rozet.amber { background: #fffbeb; color: #b45309; }
        .vade-rozet.kirmizi { background: var(--red-soft); color: var(--red); }

        /* Ozet (bordro modu) */
        .ozet { padding: 18px; display: flex; align-items: flex-start; gap: 14px; flex-wrap: wrap; }
        .ozet-ico { width: 46px; height: 46px; border-radius: 12px; background: var(--red-soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 19px; flex-shrink: 0; }
        .ozet-main { flex: 1 1 200px; min-width: 0; }
        .ozet-main h2 { font-size: 16px; font-weight: 600; margin-bottom: 2px; }
        .ozet-main .cari { font-size: 13px; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ozet-tutar { text-align: right; flex: 0 0 auto; margin-left: auto; }
        .ozet-tutar .lbl { font-size: 10.5px; color: var(--text-3); text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }
        .ozet-tutar .val { font-size: 22px; font-weight: 700; line-height: 1.1; }
        .durum-bar { padding: 10px 18px; display: flex; align-items: center; gap: 10px; border-top: 1px solid var(--border); flex-wrap: wrap; }
        .vade-not { font-size: 12px; color: var(--text-2); }

        .durum-rozet { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 8px; font-size: 12.5px; font-weight: 600; }
        .durum-rozet.mavi { background: #eff6ff; color: #1d4ed8; }
        .durum-rozet.yesil { background: #ecfdf5; color: #047857; }
        .durum-rozet.kirmizi { background: var(--red-soft); color: var(--red); }
        .durum-rozet.amber { background: #fffbeb; color: #b45309; }
        .durum-rozet.gri { background: #f3f4f6; color: #4b5563; }

        .bilgi-liste { border-top: 1px solid var(--border); }
        .bilgi-satir { display: flex; align-items: center; gap: 12px; padding: 12px 18px; border-bottom: 1px solid #f1f3f5; }
        .bilgi-satir:last-child { border-bottom: none; }
        .bilgi-satir .b-ico { width: 18px; text-align: center; color: var(--text-3); font-size: 13px; flex-shrink: 0; }
        .bilgi-satir .b-lbl { font-size: 13px; color: var(--text-2); flex: 0 0 auto; }
        .bilgi-satir .b-val { font-size: 13.5px; font-weight: 500; text-align: right; margin-left: auto; word-break: break-word; }

        .blok-baslik { padding: 13px 18px; font-size: 12px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.4px; display: flex; align-items: center; gap: 8px; border-bottom: 1px solid var(--border); }
        .blok-baslik i { color: var(--red); }

        /* Bordro cek listesi */
        .cek-liste { display: flex; flex-direction: column; }
        .cek-satir { display: flex; align-items: center; gap: 12px; padding: 13px 18px; border-bottom: 1px solid #f1f3f5; text-decoration: none; color: inherit; transition: background 0.15s ease; }
        .cek-satir:last-child { border-bottom: none; }
        .cek-satir:hover { background: #fafbff; }
        .cek-sol { flex: 1 1 auto; min-width: 0; }
        .cek-sahip { font-size: 13.5px; font-weight: 600; }
        .cek-alt { font-size: 12px; color: var(--text-2); margin-top: 2px; display: flex; gap: 10px; flex-wrap: wrap; }
        .cek-sag { text-align: right; flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
        .cek-tutar { font-size: 14px; font-weight: 700; }
        .mini-rozet { font-size: 10.5px; font-weight: 600; padding: 1px 7px; border-radius: 6px; }
        .mini-rozet.mavi { background: #eff6ff; color: #1d4ed8; }
        .mini-rozet.yesil { background: #ecfdf5; color: #047857; }
        .mini-rozet.kirmizi { background: var(--red-soft); color: var(--red); }
        .mini-rozet.amber { background: #fffbeb; color: #b45309; }
        .mini-rozet.gri { background: #f3f4f6; color: #4b5563; }
        .cek-chevron { color: var(--text-3); font-size: 12px; flex-shrink: 0; }

        /* Islem gecmisi akordeon */
        .akor-bas { display: flex; align-items: center; gap: 12px; padding: 13px 18px; cursor: pointer; border-top: 1px solid var(--border); user-select: none; -webkit-tap-highlight-color: transparent; }
        .akor-bas .b-ico { width: 18px; text-align: center; color: var(--text-3); font-size: 13px; flex-shrink: 0; }
        .akor-lbl { font-size: 13px; font-weight: 600; color: var(--text-2); flex: 1 1 auto; min-width: 0; }
        .akor-lbl .akor-son { font-weight: 400; color: var(--text-3); font-size: 12px; }
        .akor-bas .ico { color: var(--text-3); font-size: 12px; transition: transform 0.2s ease; flex-shrink: 0; }
        .akor-bas.acik .ico { transform: rotate(180deg); }
        .akor-icerik { display: none; border-top: 1px solid #f1f3f5; }
        .akor-icerik.acik { display: block; }

        .gecmis-liste { display: flex; flex-direction: column; }
        .gecmis-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 18px; border-bottom: 1px solid #f1f3f5; }
        .gecmis-item:last-child { border-bottom: none; }
        .gecmis-nokta { width: 28px; height: 28px; border-radius: 50%; background: var(--red-soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 10px; flex-shrink: 0; }
        .gecmis-icerik { flex: 1 1 auto; min-width: 0; }
        .gecmis-islem { font-size: 13.5px; font-weight: 600; }
        .gecmis-cari { font-size: 12.5px; color: var(--text-2); margin-top: 1px; }
        .gecmis-tarih { font-size: 11.5px; color: var(--text-3); flex-shrink: 0; text-align: right; white-space: nowrap; }
        .gecmis-bos { padding: 20px; text-align: center; color: var(--text-3); font-size: 13px; }

        .bakiye-kart { display: flex; align-items: center; gap: 12px; padding: 16px 18px; }
        .bakiye-ico { width: 40px; height: 40px; border-radius: 11px; background: #ecfdf5; color: #047857; display: inline-flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
        .bakiye-kart .b-text { flex: 1 1 auto; min-width: 0; }
        .bakiye-kart .b-text .t1 { font-size: 13px; font-weight: 600; }
        .bakiye-kart .b-text .t2 { font-size: 12px; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bakiye-kart .b-val { margin-left: auto; font-size: 19px; font-weight: 700; color: #047857; white-space: nowrap; }

        /* Cek gorselleri */
        .gorsel-grid { display:flex; flex-wrap:wrap; gap:10px; padding:14px 18px; }
        .gorsel-bos { padding:14px 18px; color:var(--text-3); font-size:13px; }
        .gorsel-item { position:relative; width:72px; }
        .gorsel-item a { display:block; }
        .gorsel-item img, .gorsel-item .pdfico { width:72px; height:72px; object-fit:cover; border-radius:10px; border:1px solid var(--border); background:#f3f4f6; display:flex; align-items:center; justify-content:center; color:var(--text-3); font-size:20px; }
        .gorsel-item .etiket { position:absolute; bottom:5px; left:5px; padding:1px 6px; border-radius:5px; background:rgba(0,0,0,0.6); color:#fff; font-size:9.5px; font-weight:600; }
        .gorsel-item .sil { position:absolute; top:5px; right:5px; width:22px; height:22px; border-radius:50%; background:rgba(111,16,34,0.9); color:#fff; border:none; cursor:pointer; font-size:11px; display:flex; align-items:center; justify-content:center; }
        .yukle-row { display:flex; flex-wrap:wrap; gap:8px; padding:0 18px 16px; }
        .yukle-btn { flex:1 1 auto; min-width:120px; min-height:46px; display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:11px 12px; border-radius:10px; border:1px dashed #fca5a5; background:var(--red-soft); color:var(--red); font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; }
        .yukle-btn.ek { border-color:var(--border); background:#f9fafb; color:var(--text-2); }
        .cek-up { font-size:12px; color:#b45309; padding:0 18px 12px; display:none; }

        @media (max-width: 560px) {
            .header-inner { padding: 10px 12px; }
            main { padding: 14px 10px 40px; }
            .hero { padding: 15px 15px 13px; }
            .hero-tutar { font-size: 25px; }
            .ozet { padding: 16px; gap: 12px; }
            .ozet-tutar { margin-left: 0; text-align: left; width: 100%; padding-top: 8px; border-top: 1px dashed var(--border); }
            .bilgi-satir { padding: 11px 14px; gap: 10px; }
            .akor-bas { padding: 12px 14px; }
            .gecmis-item { padding: 11px 14px; }
            .cek-satir { padding: 11px 14px; }
            .bakiye-kart .b-val { font-size: 17px; }
        }
        @media print {
            body { background: #fff; }
            .top-header { position: static; }
            .btn-print, .header-back, .cek-chevron, .akor-bas .ico { display: none !important; }
            .akor-icerik { display: block !important; }
            .card { border: 1px solid #ccc; break-inside: avoid; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="../cari/lg_hareket.php?cariid=<?php echo (int) $CARIID; ?>" class="header-back" title="Geri">
                <i class="fa fa-arrow-left"></i>
            </a>
            <span class="header-title"><i class="fa-solid fa-money-check-dollar"></i><?php echo $h($sayfaBasligi); ?></span>
            <button onclick="window.print()" class="btn-print" type="button"><i class="fa-solid fa-print"></i> PDF</button>
        </div>
    </header>

    <main>
    <?php if ($mod === 'bordro'): ?>
        <section class="card">
            <div class="ozet">
                <span class="ozet-ico"><i class="fa-solid fa-layer-group"></i></span>
                <div class="ozet-main">
                    <h2><?php echo $h($bordroTur); ?> Çıkış Bordrosu &mdash; <?php echo $h($bordro['ROLLNO'] ?: '-'); ?></h2>
                    <div class="cari"><?php echo $h($cari['DEFINITION_']); ?></div>
                </div>
                <div class="ozet-tutar">
                    <div class="lbl"><?php echo count($cekler); ?> adet · Toplam</div>
                    <div class="val"><?php echo paraformat($bordroToplam) . ' ' . $mdoviz; ?></div>
                </div>
            </div>
            <div class="durum-bar">
                <span class="durum-rozet amber"><i class="fa-solid fa-share-from-square"></i>Cari hesaba ciro</span>
                <?php if (!empty($bordro['DATE_'])): ?><span class="vade-not"><?php echo tarihcevir($bordro['DATE_']); ?></span><?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="blok-baslik"><i class="fa-solid fa-money-check-dollar"></i> Bordrodaki <?php echo $h($bordroTur); ?>ler (<?php echo count($cekler); ?>)</div>
            <div class="cek-liste">
                <?php if (empty($cekler)): ?>
                    <div class="gecmis-bos">Bu bordroda kayıtlı çek/senet bulunmuyor.</div>
                <?php else: ?>
                    <?php foreach ($cekler as $c):
                        [$dEtiket, $dRenk] = $durumBilgi((int) $c['SON_TRCODE']);
                    ?>
                        <a class="cek-satir" href="cek.php?cari_id=<?php echo (int) $CARIID; ?>&CSREF=<?php echo (int) $c['CSREF']; ?>">
                            <div class="cek-sol">
                                <div class="cek-sahip"><?php echo $h(trim((string) $c['SAHIP']) ?: '(isim yok)'); ?></div>
                                <div class="cek-alt">
                                    <span><i class="fa-solid fa-hashtag" style="font-size:10px;color:var(--text-3)"></i> <?php echo $h($c['SERINO']); ?></span>
                                    <span><i class="fa-regular fa-calendar" style="font-size:10px;color:var(--text-3)"></i> <?php echo tarihcevir($c['VADE']); ?></span>
                                </div>
                            </div>
                            <div class="cek-sag">
                                <span class="cek-tutar"><?php echo paraformat($c['TUTAR']) . ' ' . $mdoviz; ?></span>
                                <span class="mini-rozet <?php echo $dRenk; ?>"><?php echo $h($dEtiket); ?></span>
                            </div>
                            <i class="fa-solid fa-chevron-right cek-chevron"></i>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php else: ?>
        <section class="card">
            <div class="hero">
                <div class="hero-top">
                    <span class="hero-kod"><?php echo $h($turAdi); ?> · <?php echo $h($sqlHeader['SERINO']); ?></span>
                    <span class="durum-rozet <?php echo $durumRenk; ?>"><i class="fa-solid fa-circle-info"></i><?php echo $h($durumEtiket); ?></span>
                </div>
                <div class="hero-tutar"><?php echo paraformat($sqlHeader['TUTAR']) . ' ' . $mdoviz; ?></div>
                <div class="hero-cari"><?php echo $h($cari['DEFINITION_']); ?></div>
                <div class="hero-vade">
                    <div>
                        <div class="lbl">Vade</div>
                        <div class="tarih"><?php echo tarihcevir($sqlHeader['VADE']); ?></div>
                    </div>
                    <?php if ($portfoyde): ?>
                        <?php if ($kalanGun < 0): ?>
                            <span class="vade-rozet kirmizi"><?php echo abs($kalanGun); ?> gün gecikmiş</span>
                        <?php elseif ($kalanGun === 0): ?>
                            <span class="vade-rozet amber">Vadesi bugün</span>
                        <?php elseif ($kalanGun <= 7): ?>
                            <span class="vade-rozet amber"><?php echo $kalanGun; ?> gün kaldı</span>
                        <?php else: ?>
                            <span class="vade-rozet yesil"><?php echo $kalanGun; ?> gün kaldı</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="bilgi-liste">
                <div class="bilgi-satir">
                    <span class="b-ico"><i class="fa-solid fa-user-tag"></i></span>
                    <span class="b-lbl">Kimden alındı</span>
                    <span class="b-val"><?php $kimden = trim(($sqlHeader['KIMDEN_CODE'] ?? '') . ' — ' . ($sqlHeader['KIMDEN_AD'] ?? ''), ' —'); echo $kimden !== '' ? $h($kimden) : '-'; ?></span>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="blok-baslik"><i class="fa-solid fa-images"></i> Çek Görselleri</div>
            <div class="gorsel-grid" id="cekGorselGrid">
                <?php if (empty($cekGorseller)): ?>
                    <div class="gorsel-bos">Henüz görsel eklenmemiş.<?php echo $isYonetici ? ' Aşağıdan ön/arka fotoğraf ekleyebilirsiniz.' : ''; ?></div>
                <?php else: foreach ($cekGorseller as $g):
                    $isPdf = str_contains((string) ($g['DOSYA_ADI'] ?? ''), '.pdf'); ?>
                    <div class="gorsel-item">
                        <span class="etiket"><?php echo $h(ucfirst((string) ($g['ETIKET'] ?? 'ek'))); ?></span>
                        <?php if ($isYonetici): ?><button type="button" class="sil no-print" title="Sil" onclick="cekEkSil(<?php echo (int) $g['ID']; ?>)"><i class="fa-solid fa-xmark"></i></button><?php endif; ?>
                        <a href="cek_ek_goster.php?id=<?php echo (int) $g['ID']; ?>" target="_blank" rel="noopener">
                            <?php if ($isPdf): ?><span class="pdfico"><i class="fa-solid fa-file-pdf"></i></span>
                            <?php else: ?><img src="cek_ek_goster.php?id=<?php echo (int) $g['ID']; ?>" alt="cek gorseli" loading="lazy"><?php endif; ?>
                        </a>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <?php if ($isYonetici): ?>
            <div class="cek-up" id="cekUp"><i class="fa-solid fa-circle-notch fa-spin"></i> Yükleniyor...</div>
            <div class="yukle-row no-print">
                <button type="button" class="yukle-btn" onclick="document.getElementById('inp-on').click()"><i class="fa-solid fa-camera"></i> Ön Yüz</button>
                <button type="button" class="yukle-btn" onclick="document.getElementById('inp-arka').click()"><i class="fa-solid fa-camera-rotate"></i> Arka Yüz</button>
                <button type="button" class="yukle-btn ek" onclick="document.getElementById('inp-ek').click()"><i class="fa-solid fa-paperclip"></i> Ek</button>
            </div>
            <input type="file" accept="image/*" capture="environment" style="display:none" id="inp-on" onchange="cekEkYukle('on',this)">
            <input type="file" accept="image/*" capture="environment" style="display:none" id="inp-arka" onchange="cekEkYukle('arka',this)">
            <input type="file" accept="image/*,application/pdf" style="display:none" id="inp-ek" onchange="cekEkYukle('ek',this)">
            <?php endif; ?>
        </section>

        <section class="card">
            <div class="bilgi-liste" style="border-top:none">
                <div class="bilgi-satir">
                    <span class="b-ico"><i class="fa-solid fa-building-columns"></i></span>
                    <span class="b-lbl">Banka / Şube</span>
                    <span class="b-val"><?php echo $h(trim(($sqlHeader['BANKA'] ?? '') . ' / ' . ($sqlHeader['SUBE'] ?? ''), ' /')) ?: '-'; ?></span>
                </div>
                <?php if (!empty($cari['CITY']) || !empty($cari['TELNRS1'])): ?>
                <div class="bilgi-satir">
                    <span class="b-ico"><i class="fa-solid fa-location-dot"></i></span>
                    <span class="b-lbl">Şehir / Tel</span>
                    <span class="b-val"><?php echo $h(trim(($cari['CITY'] ?? '') . ' / ' . ($cari['TELNRS1'] ?? ''), ' /')); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php
            $sonIslem = $sonHareket ? $trcodeText((int) $sonHareket['TRCODE']) : '';
            $sonTarih = $sonHareket ? tarihcevir($sonHareket['Tarih']) : '';
            ?>
            <div class="akor-bas" onclick="akordeonTac(this)">
                <span class="b-ico"><i class="fa-solid fa-clock-rotate-left"></i></span>
                <span class="akor-lbl">İşlem Geçmişi<?php if ($sonIslem !== ''): ?> <span class="akor-son">· son: <?php echo $h($sonIslem) . ($sonTarih ? ', ' . $sonTarih : ''); ?></span><?php endif; ?></span>
                <i class="fa-solid fa-chevron-down ico"></i>
            </div>
            <div class="akor-icerik">
                <div class="gecmis-liste">
                    <?php if (empty($gecmis)): ?>
                        <div class="gecmis-bos">Kayıtlı işlem bulunmuyor.</div>
                    <?php else: foreach ($gecmis as $row):
                        $islem = $trcodeText((int) $row['TRCODE']);
                        $karsi = trim(trim((string) ($row['KarsiCariKodu'] ?? '')) . ' · ' . trim((string) ($row['KarsiCariAdi'] ?? '')), ' ·');
                        $rollNo = trim((string) ($row['ROLLNO'] ?? '')); ?>
                        <div class="gecmis-item">
                            <span class="gecmis-nokta"><i class="fa-solid fa-circle"></i></span>
                            <div class="gecmis-icerik">
                                <div class="gecmis-islem"><?php echo $h($islem); echo $rollNo !== '' ? ' <span style="font-weight:400;color:var(--text-3);font-size:11.5px">· Roll ' . $h($rollNo) . '</span>' : ''; ?></div>
                                <?php if ($karsi !== ''): ?><div class="gecmis-cari"><?php echo $h($karsi); ?></div><?php endif; ?>
                            </div>
                            <span class="gecmis-tarih"><?php echo tarihcevir($row['Tarih']); ?></span>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

        <section class="card">
            <div class="bakiye-kart">
                <span class="bakiye-ico"><i class="fa-solid fa-scale-balanced"></i></span>
                <div class="b-text">
                    <div class="t1">Cari Son Bakiye</div>
                    <div class="t2"><?php echo $h($cari['DEFINITION_']); ?></div>
                </div>
                <span class="b-val"><?php echo paraformat($bakiyeSon) . ' ' . $mdoviz; ?></span>
            </div>
        </section>
    </main>

    <script>
    function akordeonTac(el) {
        el.classList.toggle('acik');
        var ic = el.nextElementSibling;
        if (ic && ic.classList.contains('akor-icerik')) { ic.classList.toggle('acik'); }
    }
    <?php if ($mod === 'tekcek'): ?>
    const CEK_CSRF = <?php echo json_encode($csrfTokenCek ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const CEK_REF = <?php echo (int) ($CSREF ?? 0); ?>;
    function cekEkYukle(etiket, input) {
        if (!input.files || !input.files[0]) return;
        var dosya = input.files[0];
        input.value = '';
        cekKirpAc(dosya, function (blob) {
            var up = document.getElementById('cekUp'); if (up) up.style.display = 'block';
            var fd = new FormData();
            fd.append('cek_ref', CEK_REF); fd.append('etiket', etiket);
            fd.append('csrf_token', CEK_CSRF); fd.append('dosya', blob, blob.name || 'cek.jpg');
            fetch('cek_ek_yukle.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(j => { if (up) up.style.display = 'none'; if (j.ok) { location.reload(); } else { if (window.toast) { toast(j.mesaj || 'Yükleme başarısız.', 'error'); } else { alert(j.mesaj || 'Yükleme başarısız.'); } } })
                .catch(() => { if (up) up.style.display = 'none'; if (window.toast) { toast('Bağlantı hatası.', 'error'); } else { alert('Bağlantı hatası.'); } });
        });
    }
    function cekEkSil(id) {
        if (!confirm('Bu görseli silmek istiyor musunuz?')) return;
        var fd = new FormData(); fd.append('ek_id', id); fd.append('csrf_token', CEK_CSRF);
        fetch('cek_ek_sil.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(j => { if (j.ok) { location.reload(); } else { if (window.toast) { toast(j.mesaj || 'Silinemedi.', 'error'); } else { alert(j.mesaj || 'Silinemedi.'); } } })
            .catch(() => { if (window.toast) { toast('Bağlantı hatası.', 'error'); } else { alert('Bağlantı hatası.'); } });
    }
    <?php endif; ?>
    </script>

    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
    <?php if (!empty($isYonetici)) { include_once(__DIR__ . '/cek_kirpma.php'); } ?>
</body>
</html>
