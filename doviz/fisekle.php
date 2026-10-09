<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Yeni Dövizli Sipariş Oluştur
 * Seçilen müşteri ve döviz tipi ile sipariş açar
 */

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . '/doviz_guard.php';
require_once __DIR__ . '/../siparis/kayit_lib.php';
require_once __DIR__ . '/../siparis/web_intent.php';
header('Cache-Control: no-store');
web_siparis_kapsam_dogrula($firmano, $firma, $firmadonem);
if (function_exists('m_p_yetki_cache_temizle')) { m_p_yetki_cache_temizle((int)$terminalkullanici); }

doviz_require_m21($terminalkullanici);

// Parametreleri al
$cariid = isset($_GET['cariid']) ? web_siparis_tamsayi($_GET['cariid']) : 0;
$dovizTipi = isset($_GET['doviz']) ? web_siparis_tamsayi($_GET['doviz']) : 1; // Varsayılan USD (LOGO: 1=USD, 20=EUR)
$dovizKuru = isset($_GET['kur']) ? web_siparis_kur($_GET['kur']) : 0;

// Validasyon
if ($cariid <= 0) {
    die('<script>if (window.toast) { toast("Hata: Müşteri seçilmedi!", "error"); } else { alert("Hata: Müşteri seçilmedi!"); } window.location="cari.php";</script>');
}

if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $cariid)) {
    http_response_code(404);
    exit('Müşteri bulunamadı.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!is_string($_POST['csrf_token'] ?? null) || !csrf_verify())) {
    http_response_code(403);
    exit('Geçersiz güvenlik doğrulaması.');
}

// Döviz bilgileri (LOGO kodları: 1=USD, 20=EUR)
$dovizBilgileri = [
    1 => ['kod' => 'USD', 'sembol' => '$', 'ad' => 'Amerikan Doları'],
    20 => ['kod' => 'EUR', 'sembol' => '€', 'ad' => 'Euro'],
];

if (!isset($dovizBilgileri[$dovizTipi])) { http_response_code(400); exit('Geçersiz döviz tipi.'); }
$seciliDoviz = $dovizBilgileri[$dovizTipi];

// Kur yoksa veritabanından çek
if ($dovizKuru <= 0) {
    try {
        $stmtKur = $dbh->prepare("
            SELECT TOP 1 RATES2 AS KUR
            FROM L_DAILYEXCHANGES
            WHERE CRTYPE = :doviz
            ORDER BY LREF DESC
        ");
        $stmtKur->execute([':doviz' => $dovizTipi]);
        $kurData = $stmtKur->fetch(PDO::FETCH_ASSOC);
        $dovizKuru = $kurData ? (float)$kurData['KUR'] : 0;
    } catch (Exception $e) {
        error_log("Döviz kur çekme hatası: " . $e->getMessage());
    }
}

// POST must carry its original rate; never substitute a newly fetched market rate.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dovizKuru = web_siparis_kur($_POST['kur'] ?? null);
    if (isset($_POST['genexp1']) && !is_string($_POST['genexp1'])) { http_response_code(400); exit('Geçersiz sipariş notu.'); }
}

$scope = 'web_doviz_baslik:v1';
$context = ['personel'=>(int)$terminalkullanici, 'firma'=>(int)$firmano, 'donem'=>$firmadonem, 'cari'=>$cariid, 'doviz'=>$dovizTipi, 'depo'=>(int)$depo];
try {
    $key = web_siparis_anahtari($_SESSION, $scope, $context,
        $_SERVER['REQUEST_METHOD'] === 'POST' ? web_siparis_post_anahtari($_POST) : null);
} catch (Throwable $e) { http_response_code(409); exit('Sipariş formu doğrulanamadı. Siparişleri kontrol edin.'); }
$genexp1 = isset($_POST['genexp1']) && is_string($_POST['genexp1']) ? mb_substr(trim($_POST['genexp1']), 0, 250) : '';
$pending = $_SESSION['web_siparis_intents'][$key]['payload'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $pending !== null) {
    $dovizKuru = $pending['fields']['kur']; $genexp1 = $pending['fields']['not'];
}
if (!is_finite($dovizKuru) || $dovizKuru <= 0) { $hata = 'Geçerli bir kur değeri giriniz.'; }
// Sipariş oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dovizKuru > 0 && !isset($hata)) {

    try {
        $hash = web_siparis_dondur($_SESSION, $key, ['context'=>$context, 'fields'=>['kur'=>$dovizKuru, 'not'=>$genexp1]]);
        $dbh->beginTransaction();
        $replay = siparis_idempotency_baslat($dbh, $key, (int)$firmano, $firmadonem, (int)$terminalkullanici, $hash, $scope);
        if ($replay !== null) {
            if (!$dbh->commit()) { throw new RuntimeException('Commit doğrulanamadı.'); }
            web_siparis_tamam($_SESSION, $key);
            header('Location: fis.php?id=' . (int)$replay['fis']['id'], true, 303); exit;
        }
        $fisOneki = 'DV' . $seciliDoviz['kod'];
        $fisno = siparis_baslik_numarasi_ayir($dbh, $firmadonem, $fisOneki);

        // Zaman bilgileri
        $saat = (int)date('H');
        $dakika = (int)date('i');
        $saniye = (int)date('s');
        $kayitsaat = $saat * 65536 + $dakika * 256 + $saniye;
        $guid = function_exists('guid') ? guid() : uniqid('', true);

        $siparisdurum = 1; // satış
        $status = 4; // SEVKEDİLEBİLİR

        // GENEXP1 not alanı


        // Sipariş oluştur - ../siparis/fisekle.php ile AYNI yapı
        $stmtEkle = siparis_baslik_insert_hazirla($dbh, "INSERT INTO {$firmadonem}ORFICHE (
TRCODE,FICHENO,DATE_,TIME_,DOCODE,SPECODE,CYPHCODE,CLIENTREF,RECVREF,ACCOUNTREF,CENTERREF,SOURCEINDEX,SOURCECOSTGRP,UPDCURR,ADDDISCOUNTS,TOTALDISCOUNTS,TOTALDISCOUNTED,ADDEXPENSES,TOTALEXPENSES,TOTALPROMOTIONS,TOTALVAT,GROSSTOTAL,NETTOTAL,REPORTRATE,REPORTNET,GENEXP1,GENEXP2,GENEXP3,GENEXP4,EXTENREF,PAYDEFREF,PRINTCNT,BRANCH,DEPARTMENT,STATUS,CAPIBLOCK_CREATEDBY,CAPIBLOCK_CREADEDDATE,CAPIBLOCK_CREATEDHOUR,CAPIBLOCK_CREATEDMIN,CAPIBLOCK_CREATEDSEC,CAPIBLOCK_MODIFIEDBY,CAPIBLOCK_MODIFIEDDATE,CAPIBLOCK_MODIFIEDHOUR,CAPIBLOCK_MODIFIEDMIN,CAPIBLOCK_MODIFIEDSEC,SALESMANREF,SHPTYPCOD,SHPAGNCOD,GENEXCTYP,LINEEXCTYP,TRADINGGRP,TEXTINC,SITEID,RECSTATUS,ORGLOGICREF,FACTORYNR,WFSTATUS,SHIPINFOREF,CUSTORDNO,SENDCNT,DLVCLIENT,DOCTRACKINGNR,CANCELLED,ORGLOGOID,OFFERREF,OFFALTREF,TYP,ALTNR,ADVANCEPAYM,TRCURR,TRRATE,TRNET,PAYMENTTYPE,ONLYONEPAYLINE,OPSTAT,WITHPAYTRANS,PROJECTREF,WFLOWCRDREF,UPDTRCURR,AFFECTCOLLATRL,POFFERBEGDT,POFFERENDDT,REVISNR,LASTREVISION,CHECKAMOUNT,SLSOPPRREF,SLSACTREF,SLSCUSTREF,AFFECTRISK,TOTALADDTAX,TOTALEXADDTAX,APPROVE,APPROVEDATE,CHECKPRICE,GUID,EINVOICE
) VALUES (
:siparisdurum,:fisno,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,'','','',:cariid,0,0,0,:depo,0,0,0,0,0,0,0,0,0,0,0,1,0,:genexp1,'','','',0,0,0,0,0,:status,1,DATEADD(day,0,datediff(day,0,GETDATE())),:saat,:dakika,:saniye,0,NULL,0,0,0,:terminalkullanici,'','',2,0,'',0,0,1,0,0,0,0,'',0,0,'',0,'',0,0,0,0,0,:doviz,:dovizkuru,0,0,0,0,0,0,0,0,0,NULL,NULL,'',0,0,0,0,0,1,0,0,0,NULL,0,:guid,0
)");

        $ekle = $stmtEkle->execute([
            ':siparisdurum' => $siparisdurum,
            ':fisno' => $fisno,
            ':kayitsaat' => (int)$kayitsaat,
            ':cariid' => $cariid,
            ':depo' => (int)$depo,
            ':status' => $status,
            ':genexp1' => $genexp1,
            ':saat' => (int)$saat,
            ':dakika' => (int)$dakika,
            ':saniye' => (int)$saniye,
            ':terminalkullanici' => (int)$terminalkullanici,
            ':doviz' => (int)$dovizTipi,
            ':dovizkuru' => (float)$dovizKuru,
            ':guid' => (string)$guid,
        ]);

        if ($ekle) {
            $yeniSiparisId = siparis_baslik_eklenen_id($stmtEkle);
            $fisno = siparis_baslik_numarasini_kesinlestir($dbh, $firmadonem, $yeniSiparisId, $fisOneki);
            siparis_idempotency_tamamla($dbh, $key, (int)$firmano, $firmadonem, (int)$terminalkullanici, $hash,
                ['ok'=>true, 'fis'=>['id'=>$yeniSiparisId]], $scope);
            if (!$dbh->commit()) { throw new RuntimeException('Commit doğrulanamadı.'); }
            web_siparis_tamam($_SESSION, $key);

            // Loglama
            if (function_exists('logFisOlusturma')) {
                try { logFisOlusturma(
                    $yeniSiparisId,
                    $fisno,
                    $cariid,
                    $terminalkullanici,
                    [
                        'doviz' => $seciliDoviz['kod'],
                        'dovizKuru' => $dovizKuru,
                        'depo' => $depo,
                        'durum' => 4,
                        'aciklama' => "Dövizli sipariş oluşturuldu (Fiş No: {$fisno})"
                    ]
                ); } catch (Throwable $ignored) { error_log('Sipariş audit kaydı yazılamadı.'); }
            }

            // Başarılı - fis.php'ye yönlendir
            header('Location: fis.php?id=' . $yeniSiparisId, true, 303);
            exit;
        } else {
            throw new RuntimeException('Sipariş başlığı yazılamadı.');
        }

    } catch (Throwable $e) {
        try { if ($dbh->inTransaction()) { $dbh->rollBack(); } } catch (Throwable $ignored) {}
        $hata = web_siparis_hata($e);
        error_log("Döviz sipariş oluşturma hatası: " . $e->getMessage());
    }
}

// Preserve exact attempted rate and note, including a refresh after an uncertain response.
$pending = $_SESSION['web_siparis_intents'][$key]['payload'] ?? null;
if ($pending !== null) { $dovizKuru = $pending['fields']['kur']; $genexp1 = $pending['fields']['not']; }
// Müşteri bilgisini çek
$stmt = $dbh->prepare("SELECT CODE, DEFINITION_, CITY FROM {$firma}CLCARD WHERE LOGICALREF = :id");
$stmt->execute([':id' => $cariid]);
$musteri = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$musteri) {
    die('<script>if (window.toast) { toast("Hata: Müşteri bulunamadı!", "error"); } else { alert("Hata: Müşteri bulunamadı!"); } window.location="cari.php";</script>');
}

$kurFormatli = $dovizKuru > 0 ? number_format($dovizKuru, 4, ',', '.') : '';
$kurInput = ($pending !== null || $_SERVER['REQUEST_METHOD'] === 'POST') ? (string)$dovizKuru : str_replace('.', '', $kurFormatli);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Yeni Dövizli Sipariş - <?= htmlspecialchars($seciliDoviz['kod'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            /* Ana tema kirmizi: --emerald* degiskenleri (header/hero/buton) kirmiziya cevrildi */
            --emerald: var(--red,#6F1022);
            --emerald-dark: #b91c1c;
            --emerald-soft: #fef2f2;
            /* Gercek basari/pozitif gostergeler icin yesil korunur */
            --success: #059669;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: var(--red,#6F1022);
            --sky-soft: #fef2f2;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background:
                radial-gradient(1200px 600px at 8% -10%, rgba(111, 16, 34, .12), transparent 60%),
                radial-gradient(900px 500px at 92% 110%, rgba(111, 16, 34, .06), transparent 55%),
                var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            padding-bottom: 40px;
        }

        /* STICKY EMERALD HEADER */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 12px 18px;
            margin-bottom: 18px;
        }
        .top-header-inner {
            max-width: 640px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .back-btn {
            width: 38px;
            height: 38px;
            min-width: 38px;
            border-radius: 10px;
            background: var(--emerald-soft);
            color: var(--emerald);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s ease;
            border: 1px solid rgba(248, 113, 113, 0.22);
        }
        .back-btn:hover {
            background: #fee2e2;
            transform: translateX(-2px);
        }
        .top-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--emerald), var(--emerald-dark));
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 0 4px 14px rgba(111, 16, 34, 0.32);
        }
        .top-header-text { flex: 1; min-width: 0; }
        .top-header-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
            letter-spacing: -0.2px;
            line-height: 1.2;
        }
        .top-header-sub {
            font-size: 11.5px;
            font-weight: 500;
            color: var(--text-3);
            margin-top: 2px;
        }

        /* PAGE WRAPPER */
        .page-wrapper {
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
            padding: 0 18px;
        }

        /* GLASS CARD */
        .glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.16);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            padding: 26px 24px;
            animation: cardIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
            margin-bottom: 18px;
        }
        .glass-card:nth-of-type(2) { animation-delay: 0.08s; }
        .glass-card:nth-of-type(3) { animation-delay: 0.16s; }

        /* HERO */
        .hero {
            background: linear-gradient(135deg, var(--emerald-soft) 0%, #fee2e2 100%);
            border: 1px solid rgba(248, 113, 113, 0.20);
        }
        .hero-inner {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .hero .icon-box {
            width: 60px;
            height: 60px;
            min-width: 60px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--emerald), var(--emerald-dark));
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            box-shadow: 0 8px 22px rgba(111, 16, 34, 0.32);
        }
        .hero h1 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            letter-spacing: -0.2px;
        }
        .hero .subtitle {
            margin-top: 4px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text-2);
            line-height: 1.45;
        }
        .hero .kur-line {
            margin-top: 8px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--emerald-dark);
            background: rgba(255, 255, 255, 0.6);
            padding: 6px 10px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(248, 113, 113, 0.18);
        }

        /* ALERTS */
        .alert-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 18px;
            animation: fadeUp 0.42s ease;
        }
        .alert-bar i { font-size: 15px; flex-shrink: 0; }
        .alert-bar span { font-size: 12.5px; font-weight: 500; line-height: 1.4; }
        .alert-error {
            border: 1px solid rgba(111, 16, 34, 0.22);
            background: var(--red-soft);
            animation: shake 0.42s ease;
        }
        .alert-error i { color: var(--red); }
        .alert-error span { color: #991b1b; }

        /* INFO GRID */
        .section-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title i { color: var(--emerald); font-size: 12px; }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 18px;
        }
        @media (min-width: 520px) {
            .info-grid { grid-template-columns: 1fr 1fr; }
            .info-grid .info-item.full { grid-column: 1 / -1; }
        }
        .info-item {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .info-item:nth-of-type(1) { animation-delay: 0.10s; }
        .info-item:nth-of-type(2) { animation-delay: 0.14s; }
        .info-item:nth-of-type(3) { animation-delay: 0.18s; }
        .info-item:nth-of-type(4) { animation-delay: 0.22s; }
        .info-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .info-label i { color: var(--emerald); font-size: 11px; }
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-1);
            word-break: break-word;
        }
        .info-value.mono {
            font-family: 'Avenir Next', 'Montserrat', monospace;
            font-size: 13px;
            color: var(--text-2);
        }

        /* DOVIZ CHIP BIG */
        .doviz-chip-big {
            background: linear-gradient(135deg, var(--emerald), var(--emerald-dark));
            color: #fff;
            padding: 14px 16px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 8px 22px rgba(111, 16, 34, 0.26);
        }
        .doviz-chip-big .sym {
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
            min-width: 36px;
            text-align: center;
        }
        .doviz-chip-big .kd { font-size: 17px; font-weight: 700; letter-spacing: -0.2px; }
        .doviz-chip-big .ad { font-size: 11.5px; font-weight: 500; opacity: 0.92; margin-top: 2px; }

        .kur-box {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            justify-content: center;
        }
        .kur-box .kur-lbl {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kur-box .kur-val {
            font-size: 20px;
            font-weight: 700;
            color: var(--emerald-dark);
            letter-spacing: -0.3px;
        }
        .kur-box .kur-val .tl { font-size: 13px; color: var(--text-2); font-weight: 500; margin-left: 2px; }

        /* FIELDS */
        .field {
            margin-bottom: 14px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
            animation-delay: 0.24s;
        }
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .field-label .required { color: var(--red); margin-left: 2px; }
        .input-wrap { position: relative; }
        .input-wrap .input-icon {
            position: absolute;
            left: 14px;
            top: 16px;
            color: var(--text-3);
            font-size: 14px;
            transition: color 0.25s ease;
            pointer-events: none;
        }
        .field-control {
            width: 100%;
            padding: 13px 14px 13px 42px;
            min-height: 46px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-1);
            background: #fff;
            outline: none;
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            appearance: none;
            -webkit-appearance: none;
        }
        .field-control::placeholder { color: var(--text-3); font-weight: 400; }
        .field-control:focus {
            border-color: rgba(111, 16, 34, 0.5);
            box-shadow: 0 0 0 3px rgba(111, 16, 34, 0.14);
        }
        .field-control:focus ~ .input-icon { color: var(--emerald); }
        textarea.field-control {
            min-height: 90px;
            resize: vertical;
            padding-top: 13px;
            line-height: 1.45;
        }
        .kur-input-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 6px;
        }
        .kur-suffix {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
            font-weight: 600;
            font-size: 13px;
            pointer-events: none;
        }
        .field-hint {
            margin-top: 6px;
            font-size: 11.5px;
            color: var(--text-3);
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.4;
        }
        .field-hint.success { color: var(--success); }
        .field-hint.warn { color: var(--amber); }

        /* SUBMIT */
        .btn-submit {
            width: 100%;
            margin-top: 12px;
            padding: 14px 22px;
            min-height: 48px;
            border: none;
            border-radius: 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14.5px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, var(--emerald), var(--emerald-dark));
            box-shadow: 0 8px 22px rgba(111, 16, 34, 0.32);
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.28s both;
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px rgba(111, 16, 34, 0.42);
        }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit i { font-size: 13px; }
        .btn-submit:disabled {
            background: #9ca3af;
            box-shadow: none;
            cursor: not-allowed;
        }

        /* BACK ROW */
        .back-row {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px dashed var(--border);
            text-align: center;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-2);
            text-decoration: none;
            padding: 8px 14px;
            min-height: 36px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .back-link:hover {
            color: var(--emerald);
            background: var(--emerald-soft);
        }
        .back-link i { font-size: 12px; }

        /* ANIMATIONS */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 14px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        /* RESPONSIVE */
        @media (max-width: 767px) {
            .top-header { padding: 10px 14px; margin-bottom: 14px; }
            .top-header-title { font-size: 14px; }
            .top-header-sub { font-size: 11px; }
            .top-header-icon { width: 36px; height: 36px; font-size: 14px; }
            .page-wrapper { padding: 0 14px; }
            .glass-card { padding: 22px 18px; border-radius: 16px; }
            .hero .icon-box { width: 54px; height: 54px; min-width: 54px; font-size: 22px; }
            .hero h1 { font-size: 16px; }
            .hero .subtitle { font-size: 12px; }
            .field-control {
                font-size: 16px; /* iOS zoom engeli */
                padding: 13px 14px 13px 40px;
                min-height: 48px;
            }
            .btn-submit { font-size: 15px; padding: 14px 20px; min-height: 48px; }
            .back-btn { width: 44px; height: 44px; min-width: 44px; }
            .doviz-chip-big .sym { font-size: 28px; }
            .kur-box .kur-val { font-size: 18px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .glass-card, .field, .btn-submit, .alert-bar, .info-item {
                animation: none !important;
                transition: none !important;
                transform: none !important;
                opacity: 1 !important;
            }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="top-header-inner">
            <a href="cari.php" class="back-btn" aria-label="Geri">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <span class="top-header-icon"><i class="fa-solid fa-file-circle-plus"></i></span>
            <div class="top-header-text">
                <div class="top-header-title">Yeni Dövizli Siparis</div>
                <div class="top-header-sub"><?= htmlspecialchars($seciliDoviz['ad'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($seciliDoviz['kod'], ENT_QUOTES, 'UTF-8') ?>)</div>
            </div>
        </div>
    </header>

    <div class="page-wrapper">

        <!-- HERO -->
        <div class="glass-card hero">
            <div class="hero-inner">
                <span class="icon-box"><i class="fa-solid fa-globe"></i></span>
                <div style="flex:1; min-width:0;">
                    <h1><?= htmlspecialchars($seciliDoviz['sembol'], ENT_QUOTES, 'UTF-8') ?> Yeni Siparis Olusturuluyor</h1>
                    <div class="subtitle"><?= htmlspecialchars((string)$musteri['DEFINITION_'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if ($dovizKuru > 0): ?>
                    <div class="kur-line">
                        <i class="fa-solid fa-arrow-right-arrow-left"></i>
                        1 <?= htmlspecialchars($seciliDoviz['kod'], ENT_QUOTES, 'UTF-8') ?> = <?= $kurFormatli ?> TL
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ALERT -->
        <?php if (isset($hata) && $hata !== ''): ?>
            <div class="alert-bar alert-error" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($hata, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- CUSTOMER + CURRENCY INFO -->
        <div class="glass-card">
            <div class="section-title"><i class="fa-solid fa-circle-info"></i> Siparis Bilgileri</div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-hashtag"></i> Musteri Kodu</div>
                    <div class="info-value mono"><?= htmlspecialchars((string)$musteri['CODE'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="fa-solid fa-location-dot"></i> Sehir</div>
                    <div class="info-value"><?= $musteri['CITY'] ? htmlspecialchars((string)$musteri['CITY'], ENT_QUOTES, 'UTF-8') : '-' ?></div>
                </div>
                <div class="info-item full">
                    <div class="info-label"><i class="fa-solid fa-user-tie"></i> Unvan</div>
                    <div class="info-value"><?= htmlspecialchars((string)$musteri['DEFINITION_'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>

            <div class="section-title"><i class="fa-solid fa-coins"></i> Doviz ve Kur</div>

            <div class="info-grid">
                <div class="info-item" style="padding:0; border:none; background:transparent;">
                    <div class="doviz-chip-big">
                        <div class="sym"><?= htmlspecialchars($seciliDoviz['sembol'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div style="flex:1; min-width:0;">
                            <div class="kd"><?= htmlspecialchars($seciliDoviz['kod'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="ad"><?= htmlspecialchars($seciliDoviz['ad'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                </div>
                <div class="info-item" style="padding:0; border:none; background:transparent;">
                    <div class="kur-box">
                        <div class="kur-lbl">Guncel Kur</div>
                        <div class="kur-val">
                            <?= $dovizKuru > 0 ? $kurFormatli : '—' ?><span class="tl"> TL</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FORM CARD -->
        <div class="glass-card">
            <form method="POST" action="">
                <?= csrf_field() ?>
                <?= web_siparis_hidden($key, []) ?>

                <div class="field">
                    <label for="kur" class="field-label">Doviz Kuru (1 <?= htmlspecialchars($seciliDoviz['kod'], ENT_QUOTES, 'UTF-8') ?> = ? TL) <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="text" id="kur" name="kur" required <?= $pending !== null ? 'readonly' : '' ?>
                               value="<?= htmlspecialchars($kurInput, ENT_QUOTES, 'UTF-8') ?>"
                               class="field-control" placeholder="Orn: 32,5000"
                               inputmode="decimal"
                               autocomplete="off">
                        <i class="fa-solid fa-money-bill-trend-up input-icon"></i>
                        <span class="kur-suffix">TL</span>
                    </div>
                    <?php if ($dovizKuru > 0): ?>
                        <div class="field-hint success">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>Guncel kur otomatik yuklendi. Gerekirse duzenleyebilirsiniz.</span>
                        </div>
                    <?php else: ?>
                        <div class="field-hint warn">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span>Kur bulunamadi. Lutfen manuel giriniz.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="field">
                    <label for="genexp1" class="field-label">Siparis Notu</label>
                    <div class="input-wrap">
                        <textarea id="genexp1" name="genexp1" maxlength="250" <?= $pending !== null ? 'readonly' : '' ?>
                                  class="field-control" placeholder="Siparise ozel not (istege bagli, maks. 250 karakter)"><?= htmlspecialchars($genexp1, ENT_QUOTES, 'UTF-8') ?></textarea>
                        <i class="fa-solid fa-pen-to-square input-icon" style="top:16px;"></i>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-circle-check"></i>
                    <span>Siparisi Olustur</span>
                </button>

                <div class="back-row">
                    <a href="cari.php" class="back-link">
                        <i class="fa-solid fa-xmark"></i>
                        <span>Vazgec ve Musteri Listesine Don</span>
                    </a>
                </div>
            </form>
        </div>

    </div>

    <script src="../siparis/web_intent.js"></script>
    <script>
        // RAF reflow fix: kart animasyonu GPU katmaninda tetiklensin
        requestAnimationFrame(function () {
            document.querySelectorAll('.glass-card').forEach(function (el) {
                // forceReflow
                void el.offsetWidth;
                setTimeout(function(){ el.style.willChange = 'auto'; }, 600);
            });
        });
    </script>

    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
</body>
</html>
