<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Sipariş Düzenleme
 * Dövizli sipariş detayı ve ürün ekleme/silme
 */

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

// Sipariş ID'sini al
$siparisId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$dovizFisBackUrl = 'siparisler.php';
if ($siparisId > 0) {
    $dovizReturnSessionKey = 'doviz_fis_return_to_' . $siparisId;
    if (isset($_GET['return_to'])) {
        $_SESSION[$dovizReturnSessionKey] = safeLocalReturnUrl((string) $_GET['return_to'], $dovizFisBackUrl);
    }
    if (isset($_SESSION[$dovizReturnSessionKey])) {
        $dovizFisBackUrl = safeLocalReturnUrl((string) $_SESSION[$dovizReturnSessionKey], $dovizFisBackUrl);
    }
}

if ($siparisId <= 0) {
    die('<script>if (window.toast) { toast("Hata: Sipariş bulunamadı!", "error"); } else { alert("Hata: Sipariş bulunamadı!"); } window.location="siparisler.php";</script>');
}

// Sipariş bilgisini çek
$stmtFis = $dbh->prepare("
    SELECT
        F.LOGICALREF, F.FICHENO, F.DATE_, F.GENEXP1,
        F.TRCURR, F.TRRATE, F.TRNET,
        F.NETTOTAL, F.GROSSTOTAL, F.TOTALVAT, F.TOTALDISCOUNTS,
        F.CLIENTREF, F.SALESMANREF, F.STATUS,
        C.CODE AS CARI_KOD, C.DEFINITION_ AS CARI_ISIM, C.CITY AS CARI_SEHIR
    FROM {$firmadonem}ORFICHE F
    LEFT JOIN {$firma}CLCARD C ON C.LOGICALREF = F.CLIENTREF
    WHERE F.LOGICALREF = :id AND F.TRCODE = 1
");
$stmtFis->execute([':id' => $siparisId]);
$siparis = $stmtFis->fetch(PDO::FETCH_ASSOC);

if (!$siparis) {
    die('<script>if (window.toast) { toast("Hata: Sipariş bulunamadı!", "error"); } else { alert("Hata: Sipariş bulunamadı!"); } window.location="siparisler.php";</script>');
}

// Döviz bilgileri
$trcurr = (int)$siparis['TRCURR'];
$trrate = (float)$siparis['TRRATE'];

// Döviz TL ise veya kur 0 ise, döviz modülünden çık
if ($trcurr == 0 || $trrate <= 0) {
    die('<script>if (window.toast) { toast("Bu sipariş TL cinsindendir. Dövizli siparişler için bu modülü kullanın.", "warning"); } else { alert("Bu sipariş TL cinsindendir. Dövizli siparişler için bu modülü kullanın."); } window.location="../siparis/lg_fis.php?stokhareket=' . $siparisId . '";</script>');
}

// LOGO döviz kodları: 1=USD, 20=EUR
$dovizBilgileri = [
    1 => ['kod' => 'USD', 'sembol' => '$', 'ad' => 'Amerikan Doları'],
    20 => ['kod' => 'EUR', 'sembol' => '€', 'ad' => 'Euro'],
];
$doviz = $dovizBilgileri[$trcurr] ?? ['kod' => 'DV', 'sembol' => '?', 'ad' => 'Döviz'];

// Ürün ekleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['stokid'])) {
    $stokid = (int)$_POST['stokid'];
    $miktar = (float)str_replace(',', '.', $_POST['miktar'] ?? '1');
    $dovizFiyat = (float)str_replace(',', '.', $_POST['fiyat'] ?? '0');
    $kdvOran = (float)str_replace(',', '.', $_POST['kdv'] ?? '20');
    $aciklama = trim($_POST['aciklama'] ?? '');

    if ($stokid > 0 && $miktar > 0 && $dovizFiyat > 0) {
        try {
            // TL fiyatını hesapla
            $tlFiyat = $dovizFiyat * $trrate;
            $toplam = $miktar * $tlFiyat;
            $kdvTutar = ($toplam / 100) * $kdvOran;

            // Birim bilgisini al
            $stmtBirim = $dbh->prepare("
                SELECT
                    ISNULL(L1.LOGICALREF, 0) AS UOMREF,
                    ISNULL(I.UNITSETREF, 0) AS USREF
                FROM {$firma}ITEMS I
                LEFT JOIN {$firma}UNITSETL L1 ON L1.UNITSETREF = I.UNITSETREF AND L1.LINENR = 1
                WHERE I.LOGICALREF = :stokid
            ");
            $stmtBirim->execute([':stokid' => $stokid]);
            $birimData = $stmtBirim->fetch(PDO::FETCH_ASSOC);

            // Sonraki satır numarası
            $stmtSatirNo = $dbh->prepare("
                SELECT ISNULL(MAX(LINENO_), 0) + 1 AS NEXT_LN
                FROM {$firmadonem}ORFLINE
                WHERE ORDFICHEREF = :fisid AND LINETYPE = 0
            ");
            $stmtSatirNo->execute([':fisid' => $siparisId]);
            $satirNo = (int)$stmtSatirNo->fetch(PDO::FETCH_ASSOC)['NEXT_LN'];

            $saat = (int)date('H');
            $dakika = (int)date('i');
            $saniye = (int)date('s');
            $kayitsaat = $saat * 65536 + $dakika * 256 + $saniye;
            $guid = function_exists('guid') ? guid() : uniqid('', true);

            // Satır ekle - ../siparis/hareketekle.php ile AYNI yapı kullanılıyor
            $stmtEkle = $dbh->prepare("
                INSERT INTO {$firmadonem}ORFLINE (
                    STOCKREF, ORDFICHEREF, CLIENTREF, LINETYPE, PREVLINEREF, PREVLINENO, DETLINE, LINENO_, TRCODE,
                    DATE_, TIME_, GLOBTRANS, CALCTYPE, CENTERREF, ACCOUNTREF, VATACCREF, VATCENTERREF,
                    PRACCREF, PRCENTERREF, PRVATACCREF, PRVATCENREF, PROMREF, SPECODE, DELVRYCODE,
                    AMOUNT, PRICE, TOTAL, SHIPPEDAMOUNT, DISCPER, DISTCOST, DISTDISC, DISTEXP, DISTPROM,
                    VAT, VATAMNT, VATMATRAH, LINEEXP, UOMREF, USREF, UINFO1, UINFO2, UINFO3, UINFO4, UINFO5, UINFO6, UINFO7, UINFO8,
                    VATINC, CLOSED, INUSE, DUEDATE, PRCURR, PRPRICE, REPORTRATE, BILLEDITEM, PAYDEFREF, EXTENREF, CPSTFLAG,
                    SOURCEINDEX, SOURCECOSTGRP, BRANCH, DEPARTMENT, LINENET, SALESMANREF, STATUS, DREF, TRGFLAG,
                    SITEID, RECSTATUS, ORGLOGICREF, FACTORYNR, WFSTATUS, NETDISCFLAG, NETDISCPERC, NETDISCAMNT,
                    CONDITIONREF, DISTRESERVED, ONVEHICLE, TRCURR, TRRATE, WITHPAYTRANS, PROJECTREF, AFFECTCOLLATRL, AFFECTRISK, GUID
                ) VALUES (
                    :stokid, :fisid, :clientref, 0, 0, 0, 0, :satirno, 1,
                    DATEADD(day,0,datediff(day,0,GETDATE())), :kayitsaat, 0, 0, 0, 0, 0, 0,
                    0, 0, 0, 0, 0, '', '',
                    :miktar, :fiyat, :toplam, 0, 0, 0, 0, 0, 0,
                    :kdv, :kdvtutar, :vatmatrah, :aciklama, :uomref, :usref, 1, 1, 0, 0, 0, 0, 0, 0,
                    0, 0, 0, DATEADD(day,0,datediff(day,0,GETDATE())), :prcurr, :dovizfiyat, 1, 0, 0, 0, 0,
                    :depo, 0, 0, 0, :linenet, :salesmanref, 4, 0, 0,
                    0, 1, 0, 0, 0, 0, 0, 0,
                    0, 0, 0, :trcurr, :trrate, 0, 0, 0, 1, :guid
                )
            ");

            $stmtEkle->execute([
                ':stokid' => $stokid,
                ':fisid' => $siparisId,
                ':clientref' => $siparis['CLIENTREF'],
                ':satirno' => $satirNo,
                ':kayitsaat' => $kayitsaat,
                ':miktar' => $miktar,
                ':fiyat' => $tlFiyat,
                ':toplam' => $toplam,
                ':linenet' => $toplam,
                ':vatmatrah' => $toplam,
                ':uomref' => $birimData['UOMREF'] ?? 0,
                ':usref' => $birimData['USREF'] ?? 0,
                ':kdv' => $kdvOran,
                ':kdvtutar' => $kdvTutar,
                ':depo' => (int)$depo,
                ':prcurr' => $trcurr,
                ':trcurr' => $trcurr,
                ':dovizfiyat' => $dovizFiyat,
                ':trrate' => $trrate,
                ':salesmanref' => (int)($siparis['SALESMANREF'] ?? 0),
                ':aciklama' => $aciklama,
                ':guid' => $guid,
            ]);

            // Fiş toplamlarını güncelle
            $stmtGuncelle = $dbh->prepare("
                UPDATE {$firmadonem}ORFICHE
                SET NETTOTAL = (SELECT ISNULL(SUM(LINENET + VATAMNT), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id1 AND LINETYPE = 0),
                    GROSSTOTAL = (SELECT ISNULL(SUM(TOTAL), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id2 AND LINETYPE = 0),
                    TOTALVAT = (SELECT ISNULL(SUM(VATAMNT), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id3 AND LINETYPE = 0),
                    TRNET = (SELECT ISNULL(SUM(PRPRICE * AMOUNT), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id4 AND LINETYPE = 0)
                WHERE LOGICALREF = :id5
            ");
            $stmtGuncelle->execute([':id1' => $siparisId, ':id2' => $siparisId, ':id3' => $siparisId, ':id4' => $siparisId, ':id5' => $siparisId]);

            $basari = "Ürün eklendi.";

        } catch (PDOException $e) {
            $hata = "Ürün ekleme hatası: " . $e->getMessage();
            error_log("Döviz sipariş satır ekleme hatası: " . $e->getMessage());
        }
    } else {
        $hata = "Lütfen tüm alanları doldurun.";
    }

    // Sayfayı yenile
    header("Location: fis.php?id=" . $siparisId . (isset($hata) ? "&hata=" . urlencode($hata) : "&basari=1"));
    exit;
}

// Satır silme işlemi
if (isset($_GET['sil'])) {
    $silId = (int)$_GET['sil'];
    if ($silId > 0) {
        try {
            $stmtSil = $dbh->prepare("DELETE FROM {$firmadonem}ORFLINE WHERE LOGICALREF = :id AND ORDFICHEREF = :fisid");
            $stmtSil->execute([':id' => $silId, ':fisid' => $siparisId]);

            // Fiş toplamlarını güncelle
            $stmtGuncelle = $dbh->prepare("
                UPDATE {$firmadonem}ORFICHE
                SET NETTOTAL = (SELECT ISNULL(SUM(LINENET + VATAMNT), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id1 AND LINETYPE = 0),
                    GROSSTOTAL = (SELECT ISNULL(SUM(TOTAL), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id2 AND LINETYPE = 0),
                    TOTALVAT = (SELECT ISNULL(SUM(VATAMNT), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id3 AND LINETYPE = 0),
                    TRNET = (SELECT ISNULL(SUM(PRPRICE * AMOUNT), 0) FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :id4 AND LINETYPE = 0)
                WHERE LOGICALREF = :id5
            ");
            $stmtGuncelle->execute([':id1' => $siparisId, ':id2' => $siparisId, ':id3' => $siparisId, ':id4' => $siparisId, ':id5' => $siparisId]);

        } catch (PDOException $e) {
            error_log("Döviz sipariş satır silme hatası: " . $e->getMessage());
        }
    }
    header("Location: fis.php?id=" . $siparisId);
    exit;
}

// Sipariş satırlarını çek
$stmtSatirlar = $dbh->prepare("
    SELECT
        L.LOGICALREF, L.LINENO_, L.STOCKREF,
        L.AMOUNT, L.PRICE, L.PRPRICE, L.TOTAL, L.LINENET,
        L.VAT, L.VATAMNT, L.LINEEXP,
        I.CODE AS STOK_KOD, I.NAME AS STOK_ADI,
        U.CODE AS BIRIM
    FROM {$firmadonem}ORFLINE L
    LEFT JOIN {$firma}ITEMS I ON I.LOGICALREF = L.STOCKREF
    LEFT JOIN {$firma}UNITSETL U ON U.LOGICALREF = L.UOMREF
    WHERE L.ORDFICHEREF = :id AND L.LINETYPE = 0
    ORDER BY L.LINENO_
");
$stmtSatirlar->execute([':id' => $siparisId]);
$satirlar = $stmtSatirlar->fetchAll(PDO::FETCH_ASSOC);

// Güncel toplamları yeniden çek
$stmtFis->execute([':id' => $siparisId]);
$siparis = $stmtFis->fetch(PDO::FETCH_ASSOC);

// Format fonksiyonları
function formatTL($n) {
    return number_format((float)$n, 2, ',', '.') . ' ₺';
}

function formatDoviz($n, $sembol) {
    return number_format((float)$n, 2, ',', '.') . ' ' . $sembol;
}

// Tarih formatı
$siparisTarihi = '';
if (!empty($siparis['DATE_'])) {
    try {
        $dt = new DateTime((string)$siparis['DATE_']);
        $siparisTarihi = $dt->format('d.m.Y');
    } catch (Exception $e) {
        $siparisTarihi = (string)$siparis['DATE_'];
    }
}

$brutToplam = (float)$siparis['GROSSTOTAL'];
$iskonto = (float)$siparis['TOTALDISCOUNTS'];
$kdv = (float)$siparis['TOTALVAT'];
$netToplam = (float)$siparis['NETTOTAL'];
$dovizNet = $trrate > 0 ? $netToplam / $trrate : 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars((string)$siparis['FICHENO']); ?> - Dövizli Sipariş</title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <script src="/tm/css/tailwind.js"></script>
    <script>
        if (typeof tailwind !== 'undefined') {
            tailwind.config = { corePlugins: { preflight: false } };
        } else {
            // CDN fallback
            var s = document.createElement('script');
            s.src = 'https://cdn.tailwindcss.com';
            document.head.appendChild(s);
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --text-1: #0f172a;
            --text-2: #475569;
            --text-3: #94a3b8;
            --border: #e2e8f0;
            /* Ana tema kirmizi: --sky* degisken degerleri kirmiziya cevrildi (ad korundu) */
            --sky: var(--red,#ef4444);
            --sky-600: var(--red,#6F1022);
            --sky-700: #b91c1c;
            --sky-soft: #fef2f2;
            --sky-border: rgba(248, 113, 113, 0.22);
            --emerald: #10b981;
            --emerald-600: #059669;
            --amber: #f59e0b;
            --purple: #8b5cf6;
            --red: #ef4444;
        }

        * { box-sizing: border-box; margin: 0; }

        body {
            font-family: 'Avenir Next', 'Montserrat', 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ═══════════ ANIMATIONS ═══════════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 18px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes headerSlide {
            from { opacity: 0; transform: translateY(-100%); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ═══════════ STICKY HEADER (SKY) ═══════════ */
        .sky-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--sky-border);
            box-shadow: 0 2px 10px rgba(111, 16, 34, 0.06);
            animation: headerSlide 0.46s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .sky-header-inner {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 20px;
            flex-wrap: wrap;
        }

        .header-back {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: var(--sky-700);
            text-decoration: none;
            flex-shrink: 0;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .header-back:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(111, 16, 34, 0.2); }

        .header-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--sky), var(--sky-700));
            color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 6px 18px rgba(111, 16, 34, 0.25);
        }

        .header-titles { display: flex; flex-direction: column; min-width: 0; flex: 1; }
        .header-titles h1 {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .header-titles .sub {
            font-size: 12.5px;
            color: var(--text-2);
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .header-kur {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-end;
            padding: 6px 14px;
            border-radius: 12px;
            background: var(--sky-soft);
            border: 1px solid var(--sky-border);
            flex-shrink: 0;
        }
        .header-kur .k-label { font-size: 10.5px; font-weight: 600; color: var(--sky-700); letter-spacing: .08em; text-transform: uppercase; }
        .header-kur .k-value { font-size: 14px; font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; }

        /* ═══════════ CONTAINER ═══════════ */
        .app-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 20px 60px;
        }

        /* ═══════════ GLASS CARD ═══════════ */
        .glass-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--sky-border);
            border-radius: 16px;
            box-shadow: 0 6px 22px rgba(111, 16, 34, 0.06);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            position: relative;
            overflow: hidden;
        }
        .glass-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent 15%, rgba(255,255,255,0.45) 45%, transparent 75%);
            transform: translateX(-130%);
            transition: transform 0.85s ease;
            pointer-events: none;
            z-index: 1;
        }
        .glass-card:hover::before { transform: translateX(130%); }
        .glass-card-body { padding: 20px 24px; position: relative; z-index: 2; }

        .stagger-1 { animation-delay: .05s; }
        .stagger-2 { animation-delay: .12s; }
        .stagger-3 { animation-delay: .19s; }
        .stagger-4 { animation-delay: .26s; }

        /* ═══════════ HERO CARD ═══════════ */
        .hero-card {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 55%, #ffffff 100%);
            border: 1px solid var(--sky-border);
            border-radius: 20px;
            padding: 22px 24px;
            margin-bottom: 18px;
            position: relative;
            overflow: hidden;
            will-change: transform, opacity;
            animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .hero-card::after {
            content: "";
            position: absolute;
            top: -60px; right: -60px;
            width: 220px; height: 220px;
            background: radial-gradient(circle, rgba(111,16,34,0.18), transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-top {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            position: relative;
            z-index: 2;
        }

        .ficheno-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            border-radius: 100px;
            background: #fff;
            border: 1px solid var(--sky-border);
            color: var(--sky-700);
            font-weight: 700;
            font-size: 13px;
            font-variant-numeric: tabular-nums;
            box-shadow: 0 2px 8px rgba(111,16,34,0.08);
        }

        .doviz-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            border-radius: 100px;
            background: linear-gradient(135deg, var(--emerald), var(--emerald-600));
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25);
        }

        .kur-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 100px;
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-2);
            font-weight: 600;
            font-size: 12.5px;
            font-variant-numeric: tabular-nums;
        }
        .kur-badge b { color: var(--text-1); }

        .hero-cari {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            position: relative;
            z-index: 2;
        }
        .hero-cari .cari-avatar {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #fff, #fee2e2);
            border: 1px solid var(--sky-border);
            display: inline-flex; align-items: center; justify-content: center;
            color: var(--sky-700);
            font-size: 18px;
            flex-shrink: 0;
        }
        .hero-cari .cari-text { min-width: 0; }
        .hero-cari .cari-name {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .hero-cari .cari-meta {
            font-size: 12.5px;
            color: var(--text-2);
            margin-top: 3px;
        }
        .hero-cari .cari-meta i { margin-right: 4px; color: var(--sky); }

        .hero-net {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 14px;
            padding-top: 14px;
            border-top: 1px dashed var(--sky-border);
            position: relative;
            z-index: 2;
            flex-wrap: wrap;
        }
        .hero-net .label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--sky-700);
            font-weight: 700;
        }
        .hero-net .value {
            font-size: 30px;
            font-weight: 700;
            color: var(--text-1);
            font-variant-numeric: tabular-nums;
            letter-spacing: -0.01em;
        }
        .hero-net .value-tl {
            font-size: 13px;
            color: var(--text-2);
            margin-left: 6px;
            font-weight: 600;
        }

        /* ═══════════ INFO GRID ═══════════ */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .info-tile {
            padding: 14px 16px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            will-change: transform, opacity;
            animation: cardIn .45s cubic-bezier(0.22,1,0.36,1) both;
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .info-tile:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(0,0,0,0.05);
        }
        .info-tile .ic {
            width: 38px; height: 38px;
            border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .info-tile .ic.sky { background: var(--sky-soft); color: var(--sky-700); }
        .info-tile .ic.emerald { background: #ecfdf5; color: var(--emerald-600); }
        .info-tile .ic.purple { background: #f5f3ff; color: var(--purple); }
        .info-tile .tx { min-width: 0; }
        .info-tile .tx .l { font-size: 11.5px; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; }
        .info-tile .tx .v { font-size: 14px; color: var(--text-1); font-weight: 700; margin-top: 2px; }

        /* ═══════════ ACTION BAR ═══════════ */
        .action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
            padding: 14px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(0,0,0,0.03);
            will-change: transform, opacity;
            animation: cardIn .45s cubic-bezier(0.22,1,0.36,1) both;
        }

        .btn-flat {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            min-height: 44px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 600;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: transform .18s ease, box-shadow .2s ease, background .2s ease;
            white-space: nowrap;
        }
        .btn-flat:hover { transform: translateY(-1px); }
        .btn-flat:active { transform: translateY(0); }

        .btn-emerald { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 4px 14px rgba(16, 185, 129, 0.25); }
        .btn-emerald:hover { box-shadow: 0 8px 22px rgba(16, 185, 129, 0.35); color: #fff; }

        .btn-sky { background: linear-gradient(135deg, var(--red,#ef4444), var(--red,#6F1022)); color: #fff; box-shadow: 0 4px 14px rgba(111, 16, 34, 0.25); }
        .btn-sky:hover { box-shadow: 0 8px 22px rgba(111, 16, 34, 0.35); color: #fff; }

        .btn-amber { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.25); }
        .btn-amber:hover { box-shadow: 0 8px 22px rgba(245, 158, 11, 0.35); color: #fff; }

        .btn-purple { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: #fff; box-shadow: 0 4px 14px rgba(139, 92, 246, 0.25); }
        .btn-purple:hover { box-shadow: 0 8px 22px rgba(139, 92, 246, 0.35); color: #fff; }

        .btn-slate { background: #fff; color: var(--text-1); border: 1px solid var(--border); }
        .btn-slate:hover { background: var(--sky-soft); border-color: var(--sky-border); color: var(--sky-700); }

        /* ═══════════ LINES LAYOUT ═══════════ */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 18px;
        }
        @media (min-width: 1024px) {
            .content-grid { grid-template-columns: 2fr 1fr; align-items: start; }
        }

        /* ═══════════ LINES TABLE ═══════════ */
        .lines-wrap {
            background: #fff;
            border: 1px solid var(--sky-border);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 6px 22px rgba(111, 16, 34, 0.05);
            will-change: transform, opacity;
            animation: cardIn .45s cubic-bezier(0.22,1,0.36,1) both;
        }

        .lines-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: linear-gradient(90deg, var(--sky-soft), #ffffff);
            border-bottom: 1px solid var(--sky-border);
        }
        .lines-head h3 {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-1);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .lines-head h3 i { color: var(--sky); }
        .lines-head .count {
            padding: 4px 12px;
            border-radius: 100px;
            background: #fff;
            border: 1px solid var(--sky-border);
            color: var(--sky-700);
            font-size: 12px;
            font-weight: 700;
        }

        .lines-table-wrap { overflow-x: auto; }
        .lines-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .lines-table thead th {
            background: linear-gradient(135deg, var(--sky), var(--sky-700));
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 12px 14px;
            white-space: nowrap;
            text-align: left;
        }
        .lines-table thead th.right { text-align: right; }
        .lines-table thead th.center { text-align: center; }
        .lines-table tbody td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: var(--text-1);
            vertical-align: middle;
        }
        .lines-table tbody tr { transition: background .18s ease; }
        .lines-table tbody tr:hover { background: var(--sky-soft); }
        .lines-table tbody tr:last-child td { border-bottom: none; }
        .lines-table .num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
        .lines-table .center { text-align: center; }

        .row-num {
            display: inline-flex;
            align-items: center; justify-content: center;
            width: 28px; height: 28px;
            border-radius: 10px;
            background: var(--sky-soft);
            color: var(--sky-700);
            font-size: 11.5px;
            font-weight: 700;
            border: 1px solid var(--sky-border);
        }

        .stok-kod { font-weight: 700; color: var(--text-1); font-size: 13px; }
        .stok-ad { font-size: 12px; color: var(--text-2); margin-top: 2px; white-space: normal; max-width: 280px; }
        .stok-note { font-size: 11px; color: var(--text-3); margin-top: 3px; font-style: italic; }

        .unit-suffix { font-size: 11px; color: var(--text-3); margin-left: 4px; font-weight: 500; }
        .price-doviz { color: var(--emerald-600); font-weight: 700; }
        .price-tl { color: var(--text-2); font-size: 12.5px; }
        .total-doviz { color: var(--emerald-600); font-weight: 700; font-size: 14px; }
        .total-tl { color: var(--sky-700); font-weight: 700; font-size: 14px; }

        .btn-del {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: #fef2f2;
            color: var(--red);
            border: 1px solid rgba(239,68,68,0.2);
            display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none;
            transition: transform .18s ease, background .2s ease;
        }
        .btn-del:hover { background: var(--red); color: #fff; transform: translateY(-1px); }

        .empty-state {
            padding: 60px 24px;
            text-align: center;
            color: var(--text-2);
        }
        .empty-state i { font-size: 42px; color: var(--sky-border); margin-bottom: 12px; }
        .empty-state p { margin-bottom: 4px; font-size: 14px; }
        .empty-state .muted { font-size: 12.5px; color: var(--text-3); }

        /* ═══════════ SUMMARY ═══════════ */
        .summary-card {
            background: #fff;
            border: 1px solid var(--sky-border);
            border-radius: 16px;
            box-shadow: 0 6px 22px rgba(111, 16, 34, 0.05);
            padding: 20px 22px;
            position: sticky;
            top: 96px;
            will-change: transform, opacity;
            animation: cardIn .45s cubic-bezier(0.22,1,0.36,1) both;
            animation-delay: .1s;
        }
        .summary-card h3 {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-1);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px;
        }
        .summary-card h3 i { color: var(--sky); }

        .metric-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
        }
        .metric-row + .metric-row { border-top: 1px solid #f1f5f9; }
        .metric-label { font-size: 13px; color: var(--text-2); font-weight: 500; }
        .metric-value { font-size: 14px; font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; }
        .metric-value.red { color: var(--red); }
        .metric-value.emerald { color: var(--emerald-600); }

        .grand-total {
            margin-top: 10px;
            padding: 14px 16px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--sky-soft), #fee2e2);
            border: 1px solid var(--sky-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .grand-total .gl { font-size: 12.5px; font-weight: 700; color: var(--sky-700); text-transform: uppercase; letter-spacing: .08em; }
        .grand-total .gv { font-size: 22px; font-weight: 700; color: var(--text-1); font-variant-numeric: tabular-nums; }

        .doviz-total {
            margin-top: 10px;
            padding: 16px;
            border-radius: 12px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 1px solid rgba(16, 185, 129, 0.22);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .doviz-total .dl { font-size: 12.5px; font-weight: 700; color: var(--emerald-600); text-transform: uppercase; letter-spacing: .08em; }
        .doviz-total .dv { font-size: 26px; font-weight: 700; color: var(--emerald-600); font-variant-numeric: tabular-nums; }

        .note-card {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #fffbeb;
            border: 1px solid rgba(245,158,11,0.22);
        }
        .note-card .nt { font-size: 11.5px; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
        .note-card .nv { font-size: 13px; color: #78350f; line-height: 1.5; }

        /* ═══════════ ALERT ═══════════ */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
            font-weight: 500;
            animation: fadeUp .35s ease both;
        }
        .alert.success { background: #ecfdf5; color: #065f46; border: 1px solid rgba(16,185,129,0.22); }
        .alert.error { background: #fef2f2; color: #991b1b; border: 1px solid rgba(239,68,68,0.22); }

        /* ═══════════ MOBILE CARDS ═══════════ */
        .lines-mobile { display: none; }

        @media (max-width: 767px) {
            .sky-header-inner { padding: 10px 12px; gap: 10px; }
            .header-titles h1 { font-size: 15px; }
            .header-titles .sub { font-size: 11.5px; }
            .header-kur { padding: 4px 10px; }
            .header-kur .k-value { font-size: 12.5px; }
            .app-container { padding: 14px 12px 60px; }
            .hero-card { padding: 16px 16px; border-radius: 16px; }
            .hero-net .value { font-size: 24px; }
            .glass-card-body { padding: 14px 16px; }
            .action-bar { padding: 10px; gap: 8px; }
            .btn-flat { padding: 10px 14px; font-size: 13px; flex: 1 1 calc(50% - 8px); min-width: 0; }
            .summary-card { position: static; padding: 16px 18px; }
            .grand-total .gv { font-size: 19px; }
            .doviz-total .dv { font-size: 22px; }

            /* hide table, show cards */
            .lines-table-wrap { display: none; }
            .lines-mobile { display: block; padding: 12px; }
            .line-card {
                padding: 14px;
                border: 1px solid var(--border);
                border-radius: 14px;
                margin-bottom: 10px;
                background: #fff;
            }
            .line-card:last-child { margin-bottom: 0; }
            .line-card .lc-head {
                display: flex; align-items: center; justify-content: space-between; gap: 10px;
                margin-bottom: 10px;
            }
            .line-card .lc-left { display: flex; gap: 10px; align-items: center; min-width: 0; }
            .line-card .lc-info { min-width: 0; }
            .line-card .lc-info .k { font-weight: 700; font-size: 13.5px; color: var(--text-1); }
            .line-card .lc-info .a { font-size: 12px; color: var(--text-2); margin-top: 2px; }
            .line-card .lc-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .line-card .lc-cell { padding: 8px 10px; background: var(--sky-soft); border-radius: 10px; }
            .line-card .lc-cell .l { font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); font-weight: 600; }
            .line-card .lc-cell .v { font-size: 13px; font-weight: 700; color: var(--text-1); margin-top: 2px; font-variant-numeric: tabular-nums; }
            .line-card .lc-cell.emerald { background: #ecfdf5; }
            .line-card .lc-cell.emerald .v { color: var(--emerald-600); }
        }
    </style>
</head>
<body>

    <!-- ═══════════ STICKY SKY HEADER ═══════════ -->
    <header class="sky-header">
        <div class="sky-header-inner">
            <a href="<?php echo htmlspecialchars($dovizFisBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="header-back" title="Geri">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-icon">
                <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
            <div class="header-titles">
                <h1>Dövizli Sipariş</h1>
                <div class="sub">
                    <?php echo htmlspecialchars((string)$siparis['FICHENO']); ?>
                    <?php if (!empty($siparis['CARI_ISIM'])): ?>
                        · <?php echo htmlspecialchars((string)$siparis['CARI_ISIM']); ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="header-kur">
                <span class="k-label">Kur</span>
                <span class="k-value"><?php echo number_format($trrate, 4, ',', '.'); ?> ₺</span>
            </div>
        </div>
    </header>

    <main class="app-container">

        <?php if (isset($_GET['basari'])): ?>
        <div class="alert success">
            <i class="fa fa-check-circle"></i>
            <span>Ürün başarıyla eklendi.</span>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['hata'])): ?>
        <div class="alert error">
            <i class="fa fa-exclamation-triangle"></i>
            <span><?php echo htmlspecialchars($_GET['hata']); ?></span>
        </div>
        <?php endif; ?>

        <!-- ═══════════ HERO CARD ═══════════ -->
        <section class="hero-card">
            <div class="hero-top">
                <span class="ficheno-badge">
                    <i class="fa-solid fa-hashtag"></i>
                    <?php echo htmlspecialchars((string)$siparis['FICHENO']); ?>
                </span>
                <span class="doviz-chip">
                    <i class="fa-solid fa-coins"></i>
                    <?php echo $doviz['sembol']; ?> <?php echo $doviz['kod']; ?>
                </span>
                <span class="kur-badge">
                    <i class="fa-solid fa-arrow-right-arrow-left" style="color:var(--sky);"></i>
                    1 <?php echo $doviz['kod']; ?> = <b><?php echo number_format($trrate, 4, ',', '.'); ?> ₺</b>
                </span>
            </div>
            <div class="hero-cari">
                <div class="cari-avatar"><i class="fa-solid fa-building"></i></div>
                <div class="cari-text">
                    <div class="cari-name"><?php echo htmlspecialchars((string)($siparis['CARI_ISIM'] ?? '')); ?></div>
                    <div class="cari-meta">
                        <?php if (!empty($siparis['CARI_KOD'])): ?>
                            <i class="fa-solid fa-id-badge"></i><?php echo htmlspecialchars((string)$siparis['CARI_KOD']); ?>
                        <?php endif; ?>
                        <?php if (!empty($siparis['CARI_SEHIR'])): ?>
                            &nbsp;&nbsp;<i class="fa-solid fa-map-marker-alt"></i><?php echo htmlspecialchars((string)$siparis['CARI_SEHIR']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="hero-net">
                <div>
                    <div class="label">Net Toplam (<?php echo $doviz['kod']; ?>)</div>
                </div>
                <div>
                    <span class="value"><?php echo formatDoviz($dovizNet, $doviz['sembol']); ?></span>
                    <span class="value-tl">≈ <?php echo formatTL($netToplam); ?></span>
                </div>
            </div>
        </section>

        <!-- ═══════════ INFO GRID ═══════════ -->
        <div class="info-grid">
            <div class="info-tile stagger-1">
                <div class="ic sky"><i class="fa-solid fa-calendar-days"></i></div>
                <div class="tx">
                    <div class="l">Tarih</div>
                    <div class="v"><?php echo htmlspecialchars($siparisTarihi ?: '—'); ?></div>
                </div>
            </div>
            <div class="info-tile stagger-2">
                <div class="ic emerald"><i class="fa-solid fa-list-ul"></i></div>
                <div class="tx">
                    <div class="l">Satır Sayısı</div>
                    <div class="v"><?php echo count($satirlar); ?> ürün</div>
                </div>
            </div>
            <div class="info-tile stagger-3">
                <div class="ic purple"><i class="fa-solid fa-comment"></i></div>
                <div class="tx">
                    <div class="l">Not</div>
                    <div class="v" style="font-size:13px;font-weight:600;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px;">
                        <?php echo !empty($siparis['GENEXP1']) ? htmlspecialchars((string)$siparis['GENEXP1']) : '—'; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════ ACTION BAR ═══════════ -->
        <div class="action-bar">
            <a href="stok_bul.php?fisid=<?php echo $siparisId; ?>" class="btn-flat btn-emerald">
                <i class="fa-solid fa-plus"></i>
                <span>Ürün Ekle</span>
            </a>
            <a href="yazdir.php?id=<?php echo $siparisId; ?>" target="_blank" class="btn-flat btn-sky">
                <i class="fa-solid fa-print"></i>
                <span>Yazdır</span>
            </a>
            <a href="yazdirx.php?id=<?php echo $siparisId; ?>" class="btn-flat btn-amber">
                <i class="fa-solid fa-bolt"></i>
                <span>Hızlı Yazdır</span>
            </a>
            <a href="fis_excel_xlsx.php?id=<?php echo $siparisId; ?>" class="btn-flat btn-emerald">
                <i class="fa-solid fa-file-excel"></i>
                <span>Excel</span>
            </a>
            <a href="dizayn.php?id=<?php echo $siparisId; ?>" class="btn-flat btn-purple">
                <i class="fa-solid fa-palette"></i>
                <span>Dizayn</span>
            </a>
            <a href="<?php echo htmlspecialchars($dovizFisBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn-flat btn-slate">
                <i class="fa-solid fa-list"></i>
                <span>Tüm Siparişler</span>
            </a>
        </div>

        <div class="content-grid">

            <!-- ═══════════ LEFT: LINES ═══════════ -->
            <div class="lines-wrap">
                <div class="lines-head">
                    <h3>
                        <i class="fa-solid fa-list-check"></i>
                        Sipariş Satırları
                    </h3>
                    <span class="count"><?php echo count($satirlar); ?> ürün</span>
                </div>

                <?php if (empty($satirlar)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-box-open"></i>
                    <p>Henüz ürün eklenmemiş.</p>
                    <p class="muted">Yukarıdaki "Ürün Ekle" butonundan ürün ekleyin.</p>
                </div>
                <?php else: ?>

                <!-- desktop table -->
                <div class="lines-table-wrap">
                    <table class="lines-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Ürün</th>
                                <th class="right">Miktar</th>
                                <th class="right"><?php echo $doviz['sembol']; ?> Fiyat</th>
                                <th class="right">₺ Fiyat</th>
                                <th class="right"><?php echo $doviz['sembol']; ?> Toplam</th>
                                <th class="right">₺ Toplam</th>
                                <th class="center">İşlem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($satirlar as $satir):
                                $dovizFiyat = (float)$satir['PRPRICE'];
                                $dovizToplam = $dovizFiyat * (float)$satir['AMOUNT'];
                            ?>
                            <tr>
                                <td><span class="row-num"><?php echo (int)$satir['LINENO_']; ?></span></td>
                                <td>
                                    <div class="stok-kod"><?php echo htmlspecialchars((string)$satir['STOK_KOD']); ?></div>
                                    <div class="stok-ad"><?php echo htmlspecialchars((string)$satir['STOK_ADI']); ?></div>
                                    <?php if (!empty($satir['LINEEXP'])): ?>
                                    <div class="stok-note"><i class="fa-solid fa-comment-dots"></i> <?php echo htmlspecialchars((string)$satir['LINEEXP']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="num">
                                    <b><?php echo number_format((float)$satir['AMOUNT'], 0, ',', '.'); ?></b>
                                    <span class="unit-suffix"><?php echo htmlspecialchars((string)($satir['BIRIM'] ?? '')); ?></span>
                                </td>
                                <td class="num price-doviz"><?php echo formatDoviz($dovizFiyat, $doviz['sembol']); ?></td>
                                <td class="num price-tl"><?php echo formatTL($satir['PRICE']); ?></td>
                                <td class="num total-doviz"><?php echo formatDoviz($dovizToplam, $doviz['sembol']); ?></td>
                                <td class="num total-tl"><?php echo formatTL($satir['TOTAL']); ?></td>
                                <td class="center">
                                    <a href="?id=<?php echo $siparisId; ?>&sil=<?php echo (int)$satir['LOGICALREF']; ?>"
                                       onclick="return confirm('Bu ürünü silmek istediğinize emin misiniz?')"
                                       class="btn-del" title="Satırı Sil">
                                        <i class="fa fa-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- mobile cards -->
                <div class="lines-mobile">
                    <?php foreach ($satirlar as $satir):
                        $dovizFiyat = (float)$satir['PRPRICE'];
                        $dovizToplam = $dovizFiyat * (float)$satir['AMOUNT'];
                    ?>
                    <div class="line-card">
                        <div class="lc-head">
                            <div class="lc-left">
                                <span class="row-num"><?php echo (int)$satir['LINENO_']; ?></span>
                                <div class="lc-info">
                                    <div class="k"><?php echo htmlspecialchars((string)$satir['STOK_KOD']); ?></div>
                                    <div class="a"><?php echo htmlspecialchars((string)$satir['STOK_ADI']); ?></div>
                                </div>
                            </div>
                            <a href="?id=<?php echo $siparisId; ?>&sil=<?php echo (int)$satir['LOGICALREF']; ?>"
                               onclick="return confirm('Bu ürünü silmek istediğinize emin misiniz?')"
                               class="btn-del" title="Sil">
                                <i class="fa fa-trash"></i>
                            </a>
                        </div>
                        <div class="lc-grid">
                            <div class="lc-cell">
                                <div class="l">Miktar</div>
                                <div class="v"><?php echo number_format((float)$satir['AMOUNT'], 0, ',', '.'); ?> <?php echo htmlspecialchars((string)($satir['BIRIM'] ?? '')); ?></div>
                            </div>
                            <div class="lc-cell emerald">
                                <div class="l"><?php echo $doviz['sembol']; ?> Fiyat</div>
                                <div class="v"><?php echo formatDoviz($dovizFiyat, $doviz['sembol']); ?></div>
                            </div>
                            <div class="lc-cell">
                                <div class="l">₺ Fiyat</div>
                                <div class="v"><?php echo formatTL($satir['PRICE']); ?></div>
                            </div>
                            <div class="lc-cell emerald">
                                <div class="l"><?php echo $doviz['sembol']; ?> Toplam</div>
                                <div class="v"><?php echo formatDoviz($dovizToplam, $doviz['sembol']); ?></div>
                            </div>
                            <div class="lc-cell" style="grid-column: 1 / -1;">
                                <div class="l">₺ Toplam</div>
                                <div class="v" style="color:var(--sky-700);"><?php echo formatTL($satir['TOTAL']); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>
            </div>

            <!-- ═══════════ RIGHT: SUMMARY ═══════════ -->
            <aside>
                <div class="summary-card">
                    <h3>
                        <i class="fa-solid fa-calculator"></i>
                        Sipariş Özeti
                    </h3>

                    <div class="metric-row">
                        <span class="metric-label">Brüt Toplam</span>
                        <span class="metric-value"><?php echo formatTL($brutToplam); ?></span>
                    </div>

                    <?php if ($iskonto > 0): ?>
                    <div class="metric-row">
                        <span class="metric-label">İskonto</span>
                        <span class="metric-value red">-<?php echo formatTL($iskonto); ?></span>
                    </div>
                    <?php endif; ?>

                    <div class="metric-row">
                        <span class="metric-label">KDV</span>
                        <span class="metric-value emerald">+<?php echo formatTL($kdv); ?></span>
                    </div>

                    <div class="grand-total">
                        <span class="gl">Net (₺)</span>
                        <span class="gv"><?php echo formatTL($netToplam); ?></span>
                    </div>

                    <div class="doviz-total">
                        <span class="dl">Net (<?php echo $doviz['kod']; ?>)</span>
                        <span class="dv"><?php echo formatDoviz($dovizNet, $doviz['sembol']); ?></span>
                    </div>

                    <?php if (!empty($siparis['GENEXP1'])): ?>
                    <div class="note-card">
                        <div class="nt"><i class="fa-solid fa-note-sticky"></i> Sipariş Notu</div>
                        <div class="nv"><?php echo nl2br(htmlspecialchars((string)$siparis['GENEXP1'])); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </aside>

        </div>

    </main>

    <script>
        // RAF reflow trigger for will-change cards
        (function(){
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(function(){
                    document.querySelectorAll('.glass-card, .hero-card, .summary-card, .lines-wrap, .info-tile, .action-bar').forEach(function(el){
                        // trigger reflow
                        void el.offsetWidth;
                    });
                });
            }
        })();
    </script>

    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
</body>
</html>
