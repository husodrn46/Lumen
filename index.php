<?php
declare(strict_types=1);

// log_ip.php dosyasını dahil et
include_once(__DIR__ . "/log_ip.php");

// Ayr.php ve kontrol.php dahilinden gelen istem dışı HTML çıktısını tamponla ve temizle
ob_start();
include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");
ob_end_clean();
include_once(__DIR__ . "/gorev_lib.php");


// Çıkış işlemi: oturumu sonlandır ve giriş sayfasına yönlendir
if (isset($_GET['cikis'])) {
    // Çıkışı logla (session destroy'dan önce)
    if (function_exists('logCikis')) {
        $kullaniciId = $_SESSION['plasiyer_id'] ?? 0;
        $kullaniciAdi = $_SESSION['kullanici_adi'] ?? '';
        logCikis($kullaniciId, $kullaniciAdi, 'Kullanıcı çıkış yaptı');
    }

    // Remember me tokenlarini temizle
    if (isset($_SESSION['plasiyer_id']) && function_exists('remember_token_sil_personel')) {
        remember_token_sil_personel((int)$_SESSION['plasiyer_id']);
    }
    // Token cookie'lerini temizle
    if (isset($_COOKIE['remember_selector'])) {
        setcookie('remember_selector', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    }
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
    }

    session_unset();
    session_destroy();
    header('Location: ' . APP_ROOT_URL . '/giris.php');
    exit;
}

// Kişisel açılış ekranı: login sonrası (?giris=basarili) kullanıcının seçtiği
// sayfaya yönlendir. YALNIZCA giriş anında; normal ana sayfa ziyaretlerinde
// çalışmaz (yoksa panoya hiç ulaşılamaz). Hedef whitelist'li (tema_acilis_hedef).
if (isset($_GET['giris']) && $_GET['giris'] === 'basarili' && function_exists('tema_acilis_hedef')) {
    $__acilisHedef = tema_acilis_hedef(kisisel_ayar('gor_acilis'));
    if ($__acilisHedef !== '') {
        header('Location: ' . APP_ROOT_URL . '/' . $__acilisHedef);
        exit;
    }
}

// Müşteri talep modülü Lumen v1'de yok
$bekleyen_talep_sayisi = 0;

// Cek gorseli eksik portfoy cekleri (yalnizca yonetici icin uyari)
$cekGorselEksik = 0;
if ((int) ($yetkidurum ?? 1) === 0) {
    try {
        $cekGorselEksik = (int) $dbh->query("SELECT COUNT(*) FROM {$firmadonem}CSCARD C WITH(NOLOCK) WHERE C.CURRSTAT IN (1) AND C.STATUS IN (0,1) AND C.DOC = 1 AND NOT EXISTS (SELECT 1 FROM M_CEK_EK E WITH(NOLOCK) WHERE E.CEK_REF = C.LOGICALREF)")->fetchColumn();
    } catch (Throwable $e) {
        $cekGorselEksik = 0;
    }
}

// Negatif stok uyarisi (M17 veya yonetici) - hizli sayim, banner icin
$stokNegatifSayi = 0;
if (m_p_yetki($terminalkullanici, 'M17') == 1 || (int) ($yetkidurum ?? 1) === 0) {
    try {
        $stokNegatifSayi = (int) $dbh->query("SELECT COUNT(*) FROM (SELECT S.STOCKREF FROM {$firmadonemx}STINVTOT S WITH(NOLOCK) JOIN {$firma}ITEMS I WITH(NOLOCK) ON I.LOGICALREF = S.STOCKREF WHERE S.INVENNO <> -1 GROUP BY S.STOCKREF HAVING SUM(S.ONHAND) < 0) x")->fetchColumn();
    } catch (Throwable $e) {
        $stokNegatifSayi = 0;
    }
}

// Müşteri geri bildirim modülü Lumen v1'de yok
$bekleyen_geribildirim_sayisi = 0;

// Bugunku magaza satisi kasa ozeti (cariid=$magaza_cari) - Sadece M24 yetkisi olanlar gorur
// $magaza_cari 0 ise magaza satis ozelligi kapalidir; sorgular calistirilmaz.
$kasa_ozet = ['nakit' => 0.0, 'kart' => 0.0, 'havale' => 0.0, 'karma' => 0.0, 'bilinmiyor' => 0.0, 'toplam' => 0.0, 'adet' => 0];
$kasa_ozet_yetki = (m_p_yetki($terminalkullanici, 'M24') == 1);
$magaza_cari = (int) ($magaza_cari ?? 0);
// Kullanicinin KENDI isaretsiz siparisleri (uyari icin, kullaniciya ozel)
$kendi_isaretsiz_refler = [];
$kendi_isaretsiz_tutar = 0.0;
if ($kasa_ozet_yetki && $magaza_cari > 0) {
    try {
        $stmt = $dbh->prepare("
            SELECT ISNULL(DOCODE, '') AS DOCODE, COUNT(*) AS ADET, ISNULL(SUM(NETTOTAL), 0) AS TUTAR
            FROM {$firmadonem}ORFICHE WITH(NOLOCK)
            WHERE CLIENTREF = {$magaza_cari}
              AND CAST(DATE_ AS DATE) = CAST(GETDATE() AS DATE)
              AND TRCODE IN (1, 20, 21, 22)
              AND NETTOTAL > 0
            GROUP BY DOCODE
        ");
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tutar = (float)$row['TUTAR'];
            $adet = (int)$row['ADET'];
            $docode = strtoupper(trim((string)$row['DOCODE']));
            $kasa_ozet['toplam'] += $tutar;
            $kasa_ozet['adet'] += $adet;
            if ($docode === 'NAKIT') $kasa_ozet['nakit'] += $tutar;
            elseif ($docode === 'KART') $kasa_ozet['kart'] += $tutar;
            elseif ($docode === 'HAVALE') $kasa_ozet['havale'] += $tutar;
            elseif ($docode === 'KARMA') $kasa_ozet['karma'] += $tutar;
            else $kasa_ozet['bilinmiyor'] += $tutar;
        }

        // Kullanicinin KENDI isaretsiz siparis ID'leri
        // NOT: lg_essiparis.php ile AYNI filtre kullanilir -
        //      STLINE'da karsiligi olan (faturalanmis/sevk edilmis) siparisler haric tutulur
        $stmt2 = $dbh->prepare("
            SELECT LOGICALREF, NETTOTAL
            FROM {$firmadonem}ORFICHE FS WITH(NOLOCK)
            WHERE CLIENTREF = {$magaza_cari}
              AND TRCODE IN (1, 20, 21, 22)
              AND NETTOTAL > 0
              AND SALESMANREF = :userid
              AND (DOCODE IS NULL OR DOCODE = '' OR DOCODE NOT IN ('NAKIT','KART','HAVALE','KARMA'))
              AND FS.LOGICALREF NOT IN (
                  SELECT ST.ORDFICHEREF
                  FROM {$firmadonem}STLINE ST WITH(NOLOCK)
                  WHERE ST.ORDFICHEREF IS NOT NULL AND ST.ORDFICHEREF <> 0
              )
        ");
        $stmt2->execute([':userid' => (int)$terminalkullanici]);
        while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $kendi_isaretsiz_refler[] = (int)$row['LOGICALREF'];
            $kendi_isaretsiz_tutar += (float)$row['NETTOTAL'];
        }
    } catch (Exception $e) {}
}

$menu_items = [
    ['M1', 'Yeni Sipariş', 'fa-cart-plus', 'cari.php', 'theme-red'],
    ['M2', 'Siparişler', 'fa-list-check', 'lg_essiparis.php', 'theme-red'],
    ['M21', 'Döviz İşlemleri', 'fa-dollar-sign', 'doviz/index.php', 'theme-emerald'],
    ['M3', 'Mağaza Satış', 'fa-store', 'fisekle.php?cariid=' . $magaza_cari . '&stokhareket=0', 'theme-red'],
    ['M4', 'Müşteri Bakiye', 'fa-wallet', 'lg_bakiye.php', 'theme-emerald'],
    ['M5', 'Tüm Siparişler', 'fa-box-archive', 'lg_tumsiparisler.php', 'theme-red'],
    ['M6', 'Barkodlar', 'fa-barcode', 'barkod/barkodlar.php', 'theme-amber'],
    ['M7', 'Stok Ara', 'fa-search', 'stok_tara.php', 'theme-amber'],
    ['M26', 'Fiyat Listesi', 'fa-tags', 'fiyat_listesi.php', 'theme-amber'],
    ['M8', 'Bekleyen Ürünler', 'fa-clock', 'bekleyen_siparis.php', 'theme-red'],
    ['M10', 'Sil', 'fa-trash', 'lg_geridonusum.php', 'theme-amber'],
    ['M13', 'Günlük İşlemler', 'fa-calendar-day', 'gunluk_islemler.php', 'theme-red'],
    ['M28', 'Görevler', 'fa-clipboard-list', 'gorevler.php', 'theme-indigo'],
    ['M15', 'Stoklar', 'fa-boxes-stacked', 'stok/index.php', 'theme-amber'],
    ['M16', 'Sistem Ayarları', 'fa-cog', 'ayar/', 'theme-indigo'],
    ['M17', 'Raporlar', 'fa-chart-line', 'rapor/dashboard.php', 'theme-emerald'],
];

// Magaza satis carisi tanimli degilse (0) "Magaza Satis" (M3) kutusunu gizle
if ($magaza_cari <= 0) {
    $menu_items = array_values(array_filter($menu_items, static fn($m) => ($m[0] ?? '') !== 'M3'));
}

$themeMap = [
    'theme-red' => 't-red',
    'theme-amber' => 't-amber',
    'theme-emerald' => 't-emerald',
    'theme-indigo' => 't-indigo',
    'theme-slate' => 't-slate',
];

$visible_menu_items = [];
foreach ($menu_items as $item) {
    $menuVisible = ((string) $item[3] === 'lg_bakiye.php')
        ? m_p_bakiye_erisim_var_mi($terminalkullanici)
        : (m_p_yetki($terminalkullanici, $item[0]) == 1);

    if (!$menuVisible) {
        continue;
    }

    $visible_menu_items[] = [
        'label' => (string) $item[1],
        'icon' => (string) $item[2],
        'href' => (string) $item[3],
        'theme' => $themeMap[(string)($item[4] ?? 'theme-red')] ?? 't-red',
        'badge' => ($item[0] === 'M28' && function_exists('gorev_bekleyen_sayisi')) ? gorev_bekleyen_sayisi($dbh, (int) $terminalkullanici) : 0,
    ];
}

// Dosya Portali ARSIVLENDI (cok kullanilmadigi icin erisim kapatildi).
// Erisim engeli: portal/.htaccess (Apache) + portal/web.config (IIS).
// Geri acmak icin: asagidaki blogu geri al + portal/.htaccess ve web.config'deki
// erisim engellerini kaldir.
// if (m_p_yetki($terminalkullanici, 'M25') == 1) {
//     $visible_menu_items[] = [
//         'label' => 'Dosya Portali', 'icon' => 'fa-folder-open',
//         'href' => 'portal/gecis.php', 'theme' => 't-indigo',
//     ];
// }

if (m_p_yetki($terminalkullanici, 'M18') == 1 || $yetkidurum === 0) {
    $visible_menu_items[] = [
        'label' => 'Loglar',
        'icon' => 'fa-list',
        'href' => 'loglar.php',
        'theme' => 't-slate',
    ];
}

if (m_p_yetki($terminalkullanici, 'M18') == 1 || m_p_yetki($terminalkullanici, 'M23') == 1 || $yetkidurum === 0) {
    $visible_menu_items[] = [
        'label' => 'Personel İzleme',
        'icon' => 'fa-user-clock',
        'href' => 'loglar.php?grup=personel',
        'theme' => 't-indigo',
    ];
}

$kisisel_kisayollar = function_exists('akl_page_visit_shortcuts')
    ? akl_page_visit_shortcuts($dbh, $terminalkullanici, $visible_menu_items, 5)
    : [];

// Günün saatine göre selamlama
$saat = (int) date('H');
if ($saat < 12) {
    $selamlama = 'Günaydın';
    $selamlama_ikon = 'fa-sun';
} elseif ($saat < 18) {
    $selamlama = 'İyi Günler';
    $selamlama_ikon = 'fa-cloud-sun';
} else {
    $selamlama = 'İyi Akşamlar';
    $selamlama_ikon = 'fa-moon';
}

// Hava durumu ikonu: sadece cache'ten oku, API cagrisi AJAX ile arka planda yapilir
// ajax_hava.php ile ayni fallback sirasini takip et: tmp/ yazilabilirse orada, degilse sys_get_temp_dir()
$hava_cache_dosya = __DIR__ . '/tmp/hava_cache.json';
if (!is_file($hava_cache_dosya)) {
    $altCache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'akl_hava_cache.json';
    if (is_file($altCache)) {
        $hava_cache_dosya = $altCache;
    }
}
$hava_ikon_haritasi = [
    '113' => ['gunduz' => 'fa-sun',               'gece' => 'fa-moon'],
    '116' => ['gunduz' => 'fa-cloud-sun',          'gece' => 'fa-cloud-moon'],
    '119' => ['gunduz' => 'fa-cloud',              'gece' => 'fa-cloud'],
    '122' => ['gunduz' => 'fa-cloud',              'gece' => 'fa-cloud'],
    '143' => ['gunduz' => 'fa-smog',               'gece' => 'fa-smog'],
    '176' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-moon-rain'],
    '179' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '182' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '185' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '200' => ['gunduz' => 'fa-cloud-bolt',         'gece' => 'fa-cloud-bolt'],
    '227' => ['gunduz' => 'fa-wind',               'gece' => 'fa-wind'],
    '230' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '248' => ['gunduz' => 'fa-smog',               'gece' => 'fa-smog'],
    '260' => ['gunduz' => 'fa-smog',               'gece' => 'fa-smog'],
    '263' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '266' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '281' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '284' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '293' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '296' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '299' => ['gunduz' => 'fa-cloud-showers-heavy','gece' => 'fa-cloud-showers-heavy'],
    '302' => ['gunduz' => 'fa-cloud-showers-heavy','gece' => 'fa-cloud-showers-heavy'],
    '305' => ['gunduz' => 'fa-cloud-showers-heavy','gece' => 'fa-cloud-showers-heavy'],
    '308' => ['gunduz' => 'fa-cloud-showers-heavy','gece' => 'fa-cloud-showers-heavy'],
    '311' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '314' => ['gunduz' => 'fa-cloud-showers-heavy','gece' => 'fa-cloud-showers-heavy'],
    '317' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '320' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '323' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '326' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '329' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '332' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '335' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '338' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '350' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '353' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '356' => ['gunduz' => 'fa-cloud-showers-heavy','gece' => 'fa-cloud-showers-heavy'],
    '359' => ['gunduz' => 'fa-cloud-showers-heavy','gece' => 'fa-cloud-showers-heavy'],
    '362' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '365' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '368' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '371' => ['gunduz' => 'fa-snowflake',          'gece' => 'fa-snowflake'],
    '374' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '377' => ['gunduz' => 'fa-cloud-rain',         'gece' => 'fa-cloud-rain'],
    '386' => ['gunduz' => 'fa-cloud-bolt',         'gece' => 'fa-cloud-bolt'],
    '389' => ['gunduz' => 'fa-cloud-bolt',         'gece' => 'fa-cloud-bolt'],
    '392' => ['gunduz' => 'fa-cloud-bolt',         'gece' => 'fa-cloud-bolt'],
    '395' => ['gunduz' => 'fa-cloud-bolt',         'gece' => 'fa-cloud-bolt'],
];
try {
    if (is_file($hava_cache_dosya) && (time() - filemtime($hava_cache_dosya)) < 1800) {
        $hava_veri = json_decode((string) file_get_contents($hava_cache_dosya), true);
        if ($hava_veri !== null && isset($hava_ikon_haritasi[$hava_veri['kod']])) {
            $gece_mi = ($saat < 6 || $saat >= 20) ? 'gece' : 'gunduz';
            $selamlama_ikon = $hava_ikon_haritasi[$hava_veri['kod']][$gece_mi];
        }
    }
} catch (Throwable $e) {}

// Kullanıcının gerçek ismini LG_SLSMAN tablosundan çek
$kullanici_adi = '';
try {
    $stmt = $dbh->prepare("SELECT DEFINITION_ FROM LG_SLSMAN WHERE LOGICALREF = :id AND ACTIVE = 0");
    $stmt->bindParam(':id', $terminalkullanici, PDO::PARAM_INT);
    $stmt->execute();
    $slsman = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($slsman && !empty($slsman['DEFINITION_'])) {
        $kullanici_adi = $slsman['DEFINITION_'];
    }
} catch (Exception $e) {}
if (empty($kullanici_adi)) {
    $kullanici_adi = $_SESSION['kullanici_adi'] ?? 'Kullanıcı';
}
$kullanici_adi = htmlspecialchars($kullanici_adi, ENT_QUOTES, 'UTF-8');

$gun_isimleri = ['Monday'=>'Pazartesi','Tuesday'=>'Salı','Wednesday'=>'Çarşamba','Thursday'=>'Perşembe','Friday'=>'Cuma','Saturday'=>'Cumartesi','Sunday'=>'Pazar'];
$ay_isimleri = ['January'=>'Ocak','February'=>'Şubat','March'=>'Mart','April'=>'Nisan','May'=>'Mayıs','June'=>'Haziran','July'=>'Temmuz','August'=>'Ağustos','September'=>'Eylül','October'=>'Ekim','November'=>'Kasım','December'=>'Aralık'];
$bugun_tr = date('j') . ' ' . ($ay_isimleri[date('F')] ?? date('F')) . ' ' . date('Y') . ', ' . ($gun_isimleri[date('l')] ?? date('l'));
$toplam_bildirim = (int)$bekleyen_talep_sayisi + (int)$bekleyen_geribildirim_sayisi;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="googlebot" content="noindex, nofollow">
    <title>Lumen - Ana Sayfa</title>
    <?php include_once(__DIR__ . '/pwa-header.php'); ?>
    <script src="tm/css/tailwind.js"></script>
    <style>
        :root {
            --font-sans: "Segoe UI", Roboto, Arial, sans-serif;
            --bg: #f9fafb;
            --surface: #ffffff;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --border-hover: #d1d5db;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --red-border: rgba(248, 113, 113, 0.25);
            --red-hover: rgba(239, 68, 68, 0.5);
            --red-shadow: rgba(111, 16, 34, 0.45);
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --amber-border: rgba(251, 191, 36, 0.3);
            --amber-hover: rgba(245, 158, 11, 0.5);
            --amber-shadow: rgba(217, 119, 6, 0.36);
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --emerald-border: rgba(16, 185, 129, 0.3);
            --emerald-hover: rgba(5, 150, 105, 0.5);
            --emerald-shadow: rgba(5, 150, 105, 0.35);
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --indigo-border: rgba(99, 102, 241, 0.28);
            --indigo-hover: rgba(79, 70, 229, 0.5);
            --indigo-shadow: rgba(79, 70, 229, 0.34);
            --slate: #475569;
            --slate-soft: #f1f5f9;
            --slate-border: rgba(100, 116, 139, 0.28);
            --slate-hover: rgba(71, 85, 105, 0.5);
            --slate-shadow: rgba(51, 65, 85, 0.32);
        }

        * { box-sizing: border-box; margin: 0; }

        body {
            font-family: var(--font-sans);
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* ═══════════ HEADER ═══════════ */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
            animation: headerSlide 0.46s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .header-inner {
            max-width: 1280px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .header-logo { height: 34px; width: auto; }

        .header-divider {
            width: 1px;
            height: 24px;
            background: var(--border);
        }

        .header-date {
            font-size: 13px;
            color: var(--text-2);
            font-weight: 500;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .h-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-2);
            text-decoration: none;
            border: none;
            background: none;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }

        .h-btn:hover {
            background: rgba(0,0,0,0.04);
            color: var(--text-1);
        }

        .h-btn i { font-size: 15px; }

        .h-btn-badge {
            position: absolute;
            top: 3px; right: 4px;
            min-width: 18px;
            height: 18px;
            border-radius: 9px;
            background: var(--red);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }

        .h-btn-badge-amber { background: var(--amber); }

        .h-btn-logout { color: var(--red); }
        .h-btn-logout:hover { background: var(--red-soft); color: var(--red); }

        .h-btn-text { display: inline; }

        .h-hamburger {
            display: none;
            padding: 8px;
            border-radius: 8px;
            background: none;
            border: none;
            color: var(--text-2);
            font-size: 20px;
            cursor: pointer;
        }

        /* ═══════════ HERO ═══════════ */
        .hero {
            max-width: 1280px;
            margin: 0 auto;
            padding: 40px 24px 0;
            animation: pageRise 0.55s cubic-bezier(0.22, 1, 0.36, 1) 0.05s both;
        }

        .hero-greeting {
            font-size: 30px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
        }

        .hero-greeting-icon {
            display: inline-block;
            margin-right: 4px;
            font-size: 28px;
        }

        .hero-sub {
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 100px;
            font-size: 12.5px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .hero-chip-red {
            background: var(--red-soft);
            color: var(--red);
            border: 1px solid var(--red-border);
        }

        .hero-chip-red:hover {
            background: var(--red);
            color: #fff;
            border-color: var(--red);
        }

        .hero-chip-amber {
            background: var(--amber-soft);
            color: var(--amber);
            border: 1px solid var(--amber-border);
        }

        .hero-chip-amber:hover {
            background: var(--amber);
            color: #fff;
            border-color: var(--amber);
        }

        .hero-chip-neutral {
            background: rgba(0,0,0,0.03);
            color: var(--text-2);
            border: 1px solid var(--border);
        }

        @keyframes chipPulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(111, 16, 34, 0.2); }
            50% { box-shadow: 0 0 0 6px rgba(111, 16, 34, 0); }
        }

        .chip-pulse { animation: chipPulse 2.5s ease-in-out infinite; }

        /* ═══════════ CARD GRID ═══════════ */
        .grid-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 32px 24px 80px;
        }

        /* ═══════════ KASA OZET WIDGET ═══════════ */
        .kasa-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
            animation: pageRise 0.6s cubic-bezier(0.22, 1, 0.36, 1) 0.15s both;
        }
        .kasa-section .grid-label { margin-bottom: 12px; }
        .kasa-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
        }
        .kasa-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: rgba(255,255,255,0.88);
            border: 1px solid var(--border);
            border-radius: 14px;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .kasa-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -12px rgba(0,0,0,0.12);
        }
        .kasa-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .kasa-nakit .kasa-icon { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #059669; }
        .kasa-kart .kasa-icon { background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #2563eb; }
        .kasa-havale .kasa-icon { background: linear-gradient(135deg, #faf5ff, #f3e8ff); color: #7c3aed; }
        .kasa-karma .kasa-icon { background: linear-gradient(135deg, #fff7ed, #ffedd5); color: #ea580c; }
        .kasa-toplam .kasa-icon { background: linear-gradient(135deg, #fffbeb, #fef3c7); color: #d97706; }
        .kasa-info { flex: 1; min-width: 0; }
        .kasa-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-3);
        }
        .kasa-value {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            margin-top: 2px;
        }
        .kasa-toplam { border-color: rgba(217, 119, 6, 0.3); background: linear-gradient(135deg, rgba(255,251,235,0.95), rgba(255,255,255,0.88)); }
        .kasa-toplam .kasa-value { color: #92400e; }
        .kasa-uyari {
            margin-top: 12px;
            padding: 12px 16px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border: 1px solid rgba(248, 113, 113, 0.35);
            border-radius: 12px;
            font-size: 13px;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.08);
            cursor: pointer;
            animation: uyariPulse 2s ease-in-out infinite;
        }
        .kasa-uyari:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -6px rgba(111, 16, 34, 0.25);
            border-color: rgba(239, 68, 68, 0.5);
        }
        .kasa-uyari > span {
            flex: 1;
            min-width: 0;
        }
        .kasa-uyari i.fa-triangle-exclamation {
            color: var(--red,#6F1022);
            font-size: 16px;
            flex-shrink: 0;
        }
        .kasa-uyari i.fa-arrow-right {
            color: var(--red,#6F1022);
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }
        .kasa-uyari:hover i.fa-arrow-right {
            transform: translateX(4px);
        }
        @keyframes uyariPulse {
            0%, 100% { box-shadow: 0 2px 8px rgba(111, 16, 34, 0.08); }
            50% { box-shadow: 0 2px 16px rgba(111, 16, 34, 0.2); }
        }

        /* ═══════════ "BUGÜN" PANELİ — satış kartı + uyarı kartları (yeni widget dili) ═══════════ */
        .bugun-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 18px 24px 0;
            animation: pageRise 0.58s cubic-bezier(0.22, 1, 0.36, 1) 0.10s both;
        }
        .bugun-section .grid-label { margin-bottom: 12px; }
        .satis-kart {
            display: flex; align-items: center; gap: 22px;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--card-border, #e2e8f0); border-radius: 16px;
            padding: 16px 20px; text-decoration: none; color: inherit;
            box-shadow: 0 8px 20px -14px rgba(15, 23, 42, 0.4);
            transition: transform .3s cubic-bezier(0.22,1,0.36,1), box-shadow .3s ease, border-color .3s ease;
        }
        .satis-kart:hover { transform: translateY(-3px); border-color: #cbd5e1; box-shadow: 0 14px 28px -16px rgba(15,23,42,.35); }
        .satis-sol { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .satis-ikon {
            width: 52px; height: 52px; border-radius: 15px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 21px;
            background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #059669;
        }
        .satis-baslik { font-size: 11.5px; font-weight: 600; letter-spacing: .4px; text-transform: uppercase; color: var(--text-3, #94a3b8); }
        .satis-tutar { font-size: 26px; font-weight: 800; color: var(--text-1, #0f172a); line-height: 1.15; white-space: nowrap; }
        .satis-alt { font-size: 12px; color: var(--text-3, #94a3b8); font-weight: 500; }
        .satis-kalemler {
            margin-left: auto; display: flex; flex-direction: column; gap: 5px;
            padding-left: 22px; border-left: 1px solid #eef2f7; min-width: 230px;
        }
        .sk-row { display: flex; align-items: center; gap: 8px; font-size: 13px; }
        .sk-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .sk-ad { color: var(--text-2, #64748b); font-weight: 500; }
        .sk-deger { margin-left: auto; font-weight: 700; color: var(--text-1, #0f172a); white-space: nowrap; }
        .satis-ok { color: #cbd5e1; font-size: 14px; margin-left: 6px; transition: transform .2s, color .2s; }
        .satis-kart:hover .satis-ok { transform: translateX(3px); color: var(--red, #6F1022); }

        .uyari-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 12px; margin-top: 12px;
        }
        .uyari-kart {
            display: flex; align-items: center; gap: 12px;
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid var(--card-border, #e2e8f0); border-radius: 14px;
            padding: 12px 14px; text-decoration: none; color: inherit;
            box-shadow: 0 6px 16px -12px rgba(15, 23, 42, 0.35);
            transition: transform .25s cubic-bezier(0.22,1,0.36,1), box-shadow .25s ease, border-color .25s ease;
        }
        .uyari-kart:hover { transform: translateY(-2px); }
        .uk-ikon {
            width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .uk-metin { display: flex; flex-direction: column; min-width: 0; }
        .uk-metin b { font-size: 13.5px; font-weight: 700; color: var(--text-1, #0f172a); }
        .uk-metin span { font-size: 12px; color: var(--text-3, #94a3b8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .uk-sayi { margin-left: auto; font-size: 17px; font-weight: 800; flex-shrink: 0; }
        .uk-ok { color: #cbd5e1; font-size: 12px; flex-shrink: 0; transition: transform .2s; }
        .uyari-kart:hover .uk-ok { transform: translateX(3px); }
        .u-amber .uk-ikon { background: linear-gradient(135deg, #fffbeb, #fef3c7); color: #d97706; }
        .u-amber .uk-sayi { color: #d97706; }
        .u-amber:hover { border-color: rgba(217, 119, 6, 0.4); }
        .u-rose .uk-ikon { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: var(--red, #6F1022); }
        .u-rose .uk-sayi { color: var(--red, #6F1022); }
        .u-rose:hover { border-color: rgba(111, 16, 34, 0.4); }
        .u-orange .uk-ikon { background: linear-gradient(135deg, #fff7ed, #ffedd5); color: #ea580c; }
        .u-orange .uk-sayi { color: #ea580c; }
        .u-orange:hover { border-color: rgba(234, 88, 12, 0.4); }

        @media (max-width: 767px) {
            .bugun-section { padding: 12px 16px 0; }
            .satis-kart { flex-wrap: wrap; gap: 12px; padding: 14px 16px; }
            .satis-kalemler { margin-left: 0; padding-left: 0; border-left: none; border-top: 1px solid #eef2f7; padding-top: 10px; min-width: 100%; }
            .satis-ok { display: none; }
            .satis-tutar { font-size: 23px; }
            .uyari-grid { grid-template-columns: 1fr; gap: 8px; }
        }
        html.akl-dark .satis-kart, html.akl-dark .uyari-kart { background: #1e293b; border-color: var(--border, #2f3d52); }
        html.akl-dark .satis-kalemler { border-left-color: #2f3d52; }

        /* ═══════════ KISISEL KISAYOLLAR ═══════════ */
        .quick-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 18px 24px 0;
            animation: pageRise 0.58s cubic-bezier(0.22, 1, 0.36, 1) 0.12s both;
        }
        .quick-widget {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(217, 119, 6, 0.18);
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        }
        .quick-head {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #92400e;
            font-size: 12.5px;
            font-weight: 800;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .quick-head i {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--red);
            background: linear-gradient(135deg, #f6e6e9, #efd6db);
        }
        .quick-links {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1 1 auto;
            overflow: hidden;
        }
        .quick-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
            max-width: 190px;
            padding: 7px 10px;
            border-radius: 100px;
            color: #334155;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
        }
        .quick-link:hover {
            transform: translateY(-1px);
            background: #fff;
            border-color: rgba(111, 16, 34, 0.32);
        }
        .quick-link i { color: var(--red); font-size: 12px; flex-shrink: 0; }
        .quick-link span {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ═══════════ ITHALAT WIDGET (M27) ═══════════ */
        /* Kompakt tek-satir ithalat widget */
        .ithalat-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 24px;
            animation: pageRise 0.6s cubic-bezier(0.22, 1, 0.36, 1) 0.2s both;
        }
        .ithalat-widget {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(2, 132, 199, 0.18);
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-decoration: none;
            color: inherit;
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }
        .ithalat-widget:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px -10px rgba(2, 132, 199, 0.25);
            border-color: rgba(2, 132, 199, 0.35);
        }
        .ithalat-widget .w-icon {
            width: 34px; height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: #0284c7;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .ithalat-widget .w-title {
            font-size: 12.5px; font-weight: 700; color: #0f172a;
            white-space: nowrap; flex-shrink: 0;
        }
        .ithalat-widget .w-pills {
            display: flex; align-items: center; gap: 6px;
            flex: 1 1 auto; min-width: 0;
            overflow: hidden;
        }
        .ithalat-widget .w-pill {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 9px;
            border-radius: 100px;
            font-size: 11px; font-weight: 700;
            white-space: nowrap;
            border: 1px solid transparent;
        }
        .ithalat-widget .w-pill .n { font-weight: 800; }
        .ithalat-widget .w-pill.yolda   { background: #eff6ff; color: #0284c7; border-color: rgba(2,132,199,0.18); }
        .ithalat-widget .w-pill.teslim  { background: #ecfdf5; color: #059669; border-color: rgba(5,150,105,0.18); }
        .ithalat-widget .w-pill.geciken { background: #fef2f2; color: var(--red,#6F1022); border-color: rgba(111,16,34,0.18); }
        .ithalat-widget .w-pill.toplam  { background: #f8fafc; color: #475569; border-color: #e2e8f0; margin-left: auto; }
        .ithalat-widget .w-arrow {
            color: #0284c7; font-size: 12px; flex-shrink: 0;
            transition: transform 0.2s ease;
        }
        .ithalat-widget:hover .w-arrow { transform: translateX(3px); }
        /* Cek gorsel uyarisi -- ithalat widget ile ayni dil, amber tema */
        .ithalat-widget.cek { border-color: rgba(217,119,6,0.22); }
        .ithalat-widget.cek:hover { box-shadow: 0 6px 18px -10px rgba(217,119,6,0.30); border-color: rgba(217,119,6,0.42); }
        .ithalat-widget.cek .w-icon { background: linear-gradient(135deg,#fffbeb,#fef3c7); color: #d97706; }
        .ithalat-widget.cek .w-arrow { color: #d97706; }
        .ithalat-widget.cek .w-pill { background:#fffbeb; color:#b45309; border-color:rgba(217,119,6,0.22); }
        /* Negatif stok uyarisi -- kirmizi tema */
        .ithalat-widget.stoku { border-color:rgba(111,16,34,0.22); }
        .ithalat-widget.stoku:hover { box-shadow:0 6px 18px -10px rgba(111,16,34,0.30); border-color:rgba(111,16,34,0.42); }
        .ithalat-widget.stoku .w-icon { background:linear-gradient(135deg,#fef2f2,#fee2e2); color:var(--red,#6F1022); }
        .ithalat-widget.stoku .w-arrow { color:var(--red,#6F1022); }
        .ithalat-widget.stoku .w-pill { background:#fef2f2; color:#b91c1c; border-color:rgba(111,16,34,0.22); }

        @media (max-width: 767px) {
            .kasa-section { padding: 0 16px; }
            .kasa-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .kasa-card { padding: 12px; gap: 10px; border-radius: 12px; }
            .kasa-icon { width: 38px; height: 38px; font-size: 15px; border-radius: 10px; }
            .kasa-label { font-size: 10px; }
            .kasa-value { font-size: 15px; }
            .kasa-uyari { font-size: 12px; padding: 8px 12px; }

            .quick-section { padding: 14px 16px 0; }
            .quick-widget { align-items: flex-start; gap: 10px; padding: 12px; }
            .quick-head span { display: none; }
            .quick-links {
                overflow-x: auto;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }
            .quick-links::-webkit-scrollbar { display: none; }
            .quick-link { flex: 0 0 auto; max-width: 160px; }

            .ithalat-section { padding: 12px 16px 0; }
            .ithalat-widget { padding: 12px 14px; gap: 12px; border-radius: 12px; flex-wrap: wrap; }
            .ithalat-widget .w-icon { width: 34px; height: 34px; font-size: 14px; }
            .ithalat-widget .w-title { display: inline; font-size: 13px; }
            .ithalat-widget .w-arrow { display: none; }
            .ithalat-widget .w-pills {
                gap: 8px;
                flex-wrap: nowrap;
                overflow-x: auto;
                scrollbar-width: none;
                flex-basis: 100%;
                order: 3;
                padding-bottom: 2px;
                -webkit-overflow-scrolling: touch;
            }
            .ithalat-widget .w-pills::-webkit-scrollbar { display: none; }
            .ithalat-widget .w-pill { padding: 5px 10px; font-size: 11.5px; }
            .ithalat-widget .w-pill.toplam { margin-left: 0; }
        }

        .grid-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: var(--text-3);
            margin-bottom: 16px;
            animation: pageRise 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.12s both;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(175px, 1fr));
            gap: 14px;
        }

        /* ─── Menu Card ─── */
        .mc {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 22px 18px 18px;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: 14px;
            box-shadow: 0 8px 20px -14px rgba(15, 23, 42, 0.4), 0 2px 8px var(--card-glow, rgba(111, 16, 34, 0.07));
            opacity: 0;
            transform: translate3d(0, 16px, 0) scale(0.985);
            animation: cardIn 0.62s cubic-bezier(0.22, 1, 0.36, 1) both;
            animation-delay: calc(var(--d) * 55ms);
            transition: transform 0.35s cubic-bezier(0.22, 1, 0.36, 1),
                        box-shadow 0.35s ease,
                        border-color 0.35s ease,
                        background 0.35s ease;
        }

        /* Shimmer efekti */
        .mc::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent 15%, rgba(255, 255, 255, 0.5) 45%, transparent 75%);
            transform: translateX(-130%);
            transition: transform 0.75s ease;
            pointer-events: none;
        }

        .mc:hover::before {
            transform: translateX(130%);
        }

        .mc:hover {
            transform: translate3d(0, -8px, 0) scale(1.022);
            border-color: var(--card-hover-border);
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 24px 44px -18px var(--card-shadow), 0 10px 22px rgba(15, 23, 42, 0.14);
        }

        .mc:active {
            transform: translate3d(0, -3px, 0) scale(1.008);
        }

        /* Favori (yıldız pin) — favoriler grid'de öne (order) */
        .mc.mc-fav { order: -1; }
        .mc-star {
            position: absolute; top: 8px; right: 8px; width: 26px; height: 26px;
            display: flex; align-items: center; justify-content: center; font-size: 12.5px;
            color: #cbd5e1; background: transparent; border: none; border-radius: 50%;
            cursor: pointer; z-index: 4; opacity: .5;
            transition: opacity .15s ease, transform .12s ease, color .15s ease;
        }
        .mc-star:hover { opacity: 1; transform: scale(1.15); color: var(--red); }
        .mc-star.on { color: var(--red); opacity: 1; }

        .mc-icon {
            position: relative;
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: linear-gradient(135deg, var(--card-soft-from), var(--card-soft-to));
            color: var(--card-icon);
            transition: transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1),
                        background 0.35s ease, color 0.35s ease, box-shadow 0.35s ease;
            /* Dinlenme halinde de renkli "isik" — soluk gorunmesin */
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.6),
                        0 6px 14px -8px var(--card-shadow);
        }

        .mc-badge {
            position: absolute;
            top: -6px;
            right: -6px;
            min-width: 20px;
            height: 20px;
            padding: 0 5px;
            border-radius: 10px;
            background: var(--red,#ef4444);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.4);
        }

        .mc:hover .mc-icon {
            background: linear-gradient(135deg, var(--card-strong-from), var(--card-strong-to));
            color: #fff;
            transform: translateY(-3px) scale(1.15) rotate(-5deg);
            box-shadow: 0 16px 26px -12px var(--card-shadow), inset 0 0 0 1px rgba(255, 255, 255, 0.7);
        }

        .mc-title {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-1);
            transition: color 0.3s ease;
        }

        .mc:hover .mc-title {
            color: var(--card-title);
        }

        /* Kişisel vurgu rengi SEÇİLİYSE (html.akl-accent): TÜM menü kart ikonları +
           başlık hover accent rengini izler. Renk seçilmemişse çok-renkli varsayılan korunur. */
        html.akl-accent .mc .mc-icon {
            color: var(--red);
            background: linear-gradient(135deg, #fee2e2, #fecaca);
        }
        html.akl-accent .mc:hover .mc-icon {
            background: linear-gradient(135deg, var(--red), var(--red));
            color: #fff;
        }
        html.akl-accent .mc:hover .mc-title { color: var(--red); }

        /* Card themes — Lumen tek renk (bordo); tüm kutular ve hover'lar aynı */
        .mc.t-red,
        .mc.t-amber,
        .mc.t-emerald,
        .mc.t-indigo,
        .mc.t-slate {
            --card-title: var(--red);
            --card-icon: var(--red);
            --card-border: rgba(111, 16, 34, 0.18);
            --card-hover-border: rgba(111, 16, 34, 0.5);
            --card-shadow: rgba(111, 16, 34, 0.30);
            --card-glow: rgba(111, 16, 34, 0.06);
            --card-soft-from: #f6e6e9;
            --card-soft-to: #efd6db;
            --card-strong-from: var(--red);
            --card-strong-to: var(--red);
        }

        /* ═══════════ MOBILE DROPDOWN ═══════════ */
        .mobile-dropdown {
            display: none;
            position: absolute;
            right: 16px;
            top: 58px;
            width: 210px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 16px 48px -8px rgba(0,0,0,0.12);
            padding: 8px;
            z-index: 45;
        }

        .mobile-dropdown.open { display: block; }

        .mobile-dropdown a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 10px;
            color: var(--text-1);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.15s ease;
        }

        .mobile-dropdown a:hover { background: rgba(0,0,0,0.03); }
        .mobile-dropdown a i { width: 18px; text-align: center; color: var(--text-2); font-size: 14px; }
        .mobile-dropdown .dd-sep { height: 1px; background: var(--border); margin: 4px 8px; }
        .mobile-dropdown a.dd-logout { color: var(--red); }
        .mobile-dropdown a.dd-logout i { color: var(--red); }

        /* ═══════════ TOAST ═══════════ */
        .toast-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            padding: 14px 20px;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 13px;
            box-shadow: 0 10px 30px -5px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: toastIn 0.4s ease-out, toastOut 0.4s ease-in 2.6s forwards;
            max-width: 350px;
        }

        .toast-success { background: linear-gradient(135deg, #10b981, #059669); }
        .toast-error   { background: linear-gradient(135deg, var(--red,#ef4444), var(--red,#6F1022)); }
        .toast-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .toast-info    { background: linear-gradient(135deg, #3b82f6, #2563eb); }

        /* ═══════════ ANIMATIONS ═══════════ */
        @keyframes headerSlide {
            from { opacity: 0; transform: translateY(-14px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes pageRise {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 16px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @keyframes toastIn {
            from { opacity: 0; transform: translateX(100px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        @keyframes toastOut {
            from { opacity: 1; transform: translateX(0); }
            to   { opacity: 0; transform: translateX(100px); }
        }

        /* ═══════════ RESPONSIVE ═══════════ */
        @media (max-width: 768px) {
            .top-header { height: 56px; }
            .h-btn-text { display: none; }
            .h-btn { padding: 10px 12px; min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
            .header-date, .header-divider { display: none; }
            .h-hamburger { display: block; }
            .h-desktop-only { display: none !important; }

            .hero { padding: 24px 16px 0; }
            .hero-greeting { font-size: 22px; line-height: 1.35; }

            .grid-section { padding: 24px 16px 40px; }

            .card-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .mc {
                padding: 16px 14px 14px;
                gap: 10px;
            }

            .mc-icon {
                width: 42px; height: 42px;
                font-size: 17px;
                border-radius: 12px;
            }

            .mc-title { font-size: 12.5px; }
            .header-logo { height: 28px; }
        }

        @media (max-width: 380px) {
            .card-grid { gap: 8px; }
            .mc { padding: 14px 12px; gap: 8px; }
            .mc-icon { width: 38px; height: 38px; font-size: 15px; }
            .mc-title { font-size: 12px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .top-header, .hero, .grid-label, .mc, .mc::before, .mc-icon, .toast {
                animation: none !important;
                transition: none !important;
                transform: none !important;
            }
            .mc { opacity: 1 !important; }
        }
        /* ═══════ KOYU TEMA (kişisel ayar: html.akl-dark) — pano yüzeyleri ═══════ */
        html.akl-dark .top-header { background: rgba(15,23,42,0.9); }
        html.akl-dark .mc { background: #1e293b; }
        html.akl-dark .mc:hover { background: #232f43; }
        html.akl-dark .mc::before { background: linear-gradient(120deg, transparent 15%, rgba(255,255,255,0.06) 45%, transparent 75%); }
        html.akl-dark .kasa-card,
        html.akl-dark .quick-widget,
        html.akl-dark .ithalat-widget { background: #1e293b; border-color: var(--border); color: var(--text-1); }
        html.akl-dark .kasa-toplam { background: #232f43; }
    </style>
</head>
<body>

    <!-- Toast -->
    <div id="toast-container" class="toast-container"></div>

    <!-- ═══════════ HEADER ═══════════ -->
    <header class="top-header">
        <div class="header-inner">
            <div class="header-left">
                <img src="logo.png" alt="Lumen" class="header-logo">
                <div class="header-divider"></div>
                <span class="header-date"><?php echo $bugun_tr; ?></span>
            </div>

            <div class="header-right">
                <button type="button" onclick="if(window.lumenTur)window.lumenTur()" class="h-btn h-desktop-only" title="Tanıtım turu"><i class="fa-solid fa-circle-question"></i><span class="h-btn-text">Tur</span></button>
                <a href="ayar/gorunum.php" class="h-btn h-desktop-only"><i class="fa-solid fa-sliders"></i><span class="h-btn-text">Ayarlar</span></a>
                <a href="sifre_degistir.php" class="h-btn h-desktop-only"><i class="fa-solid fa-key"></i><span class="h-btn-text">Şifre</span></a>
                <a href="?cikis=1" class="h-btn h-btn-logout h-desktop-only"><i class="fa-solid fa-arrow-right-from-bracket"></i><span class="h-btn-text">Çıkış</span></a>

                <button class="h-hamburger" id="hamburgerBtn" aria-label="Menü">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>

        <div class="mobile-dropdown" id="mobileDropdown">
            <a href="ayar/gorunum.php"><i class="fa-solid fa-sliders"></i> Ayarlar</a>
            <a href="sifre_degistir.php"><i class="fa-solid fa-key"></i> Şifre Değiştir</a>
            <div class="dd-sep"></div>
            <a href="?cikis=1" class="dd-logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Çıkış Yap</a>
        </div>
    </header>

    <!-- ═══════════ HERO ═══════════ -->
    <section class="hero">
        <h1 class="hero-greeting">
            <i id="havaIkon" class="fa-solid <?php echo $selamlama_ikon; ?> hero-greeting-icon" style="color: var(--red);"></i>
            <?php echo $selamlama; ?>, <?php echo $kullanici_adi; ?>
        </h1>
        <div class="hero-sub">
            <span class="hero-chip hero-chip-neutral">
                <i class="fa-solid fa-hand-sparkles" style="color: var(--red);"></i>
                Hoş geldiniz
            </span>
        </div>
    </section>

    <?php
    // ═══════════ BAŞLANGIÇ ADIMLARI (kurulum kontrol listesi — yalnız yönetici) ═══════════
    if ((int) ($yetkidurum ?? 1) === 0 && is_file(__DIR__ . '/includes/kurulum_durumu.php')) {
        include_once __DIR__ . '/includes/kurulum_durumu.php';
        $kurulum_adimlar = function_exists('kurulum_eksik_adimlar') ? kurulum_eksik_adimlar($dbh, $firmadonem) : [];
        $kurulum_toplam  = count($kurulum_adimlar);
        $kurulum_tamam   = count(array_filter($kurulum_adimlar, static fn($a) => $a['tamam']));
        if ($kurulum_adimlar && $kurulum_tamam < $kurulum_toplam):
            $kurulum_yuzde = $kurulum_toplam > 0 ? (int) round(100 * $kurulum_tamam / $kurulum_toplam) : 0;
    ?>
    <section class="kurulum-rehber" id="kurulumRehber" style="display:none;">
        <div class="kr-kart">
            <button type="button" class="kr-gizle" id="krGizle" title="Gizle" aria-label="Gizle"><i class="fa-solid fa-xmark"></i></button>
            <div class="kr-bas">
                <span class="kr-ico"><i class="fa-solid fa-flag-checkered"></i></span>
                <div>
                    <div class="kr-baslik">Başlangıç Adımları</div>
                    <div class="kr-alt">Lumen'i kendinize göre ayarlamak için birkaç adım kaldı.</div>
                </div>
                <span class="kr-rozet"><?php echo $kurulum_tamam; ?>/<?php echo $kurulum_toplam; ?></span>
            </div>
            <div class="kr-bar"><span style="width:<?php echo $kurulum_yuzde; ?>%;"></span></div>
            <div class="kr-liste">
                <?php foreach ($kurulum_adimlar as $ad): ?>
                <div class="kr-adim <?php echo $ad['tamam'] ? 'tamam' : ''; ?>">
                    <span class="kr-adim-ico"><i class="fa-solid <?php echo $ad['tamam'] ? 'fa-circle-check' : $ad['ikon']; ?>"></i></span>
                    <div class="kr-adim-metin">
                        <div class="kr-adim-baslik"><?php echo htmlspecialchars($ad['baslik'], ENT_QUOTES, 'UTF-8'); ?><?php echo $ad['ops'] ? ' <span class="kr-ops">opsiyonel</span>' : ''; ?></div>
                        <div class="kr-adim-aciklama"><?php echo htmlspecialchars($ad['aciklama'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <?php if ($ad['tamam']): ?>
                        <span class="kr-durum tamam"><i class="fa-solid fa-check"></i> Tamam</span>
                    <?php else: ?>
                        <a class="kr-durum ayarla" href="<?php echo htmlspecialchars($ad['link'], ENT_QUOTES, 'UTF-8'); ?>">Ayarla <i class="fa-solid fa-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <style>
        .kurulum-rehber { max-width:980px; margin:0 auto 4px; padding:0 24px; }
        .kr-kart { position:relative; background:var(--surface,#fff); border:1px solid rgba(111,16,34,.18); border-radius:16px; padding:20px 22px; box-shadow:0 6px 20px rgba(111,16,34,.06); animation:cardIn .45s cubic-bezier(.22,1,.36,1) both; }
        .kr-gizle { position:absolute; top:14px; right:14px; width:28px; height:28px; border:none; background:transparent; color:var(--text-3,#9ca3af); border-radius:8px; cursor:pointer; font-size:14px; }
        .kr-gizle:hover { background:rgba(0,0,0,.05); color:var(--red); }
        .kr-bas { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
        .kr-ico { flex-shrink:0; width:46px; height:46px; border-radius:12px; background:linear-gradient(135deg,#f6e6e9,#efd6db); color:var(--red); display:inline-flex; align-items:center; justify-content:center; font-size:20px; }
        .kr-baslik { font-size:16px; font-weight:700; color:var(--text-1,#1f2937); }
        .kr-alt { font-size:12.5px; color:var(--text-2,#6b7280); margin-top:2px; }
        .kr-rozet { margin-left:auto; margin-right:26px; background:var(--red,#6F1022); color:#fff; font-size:12.5px; font-weight:700; padding:4px 12px; border-radius:20px; }
        .kr-bar { height:6px; background:#f1e6e9; border-radius:20px; overflow:hidden; margin-bottom:16px; }
        .kr-bar span { display:block; height:100%; background:var(--red,#6F1022); border-radius:20px; transition:width .5s ease; }
        .kr-liste { display:flex; flex-direction:column; gap:8px; }
        .kr-adim { display:flex; align-items:center; gap:12px; padding:11px 12px; border:1px solid var(--border,#e5e7eb); border-radius:12px; background:#fff; }
        .kr-adim.tamam { background:#fafafa; border-color:#eee; }
        .kr-adim-ico { flex-shrink:0; width:34px; height:34px; border-radius:9px; background:var(--red-soft,#fef2f2); color:var(--red); display:inline-flex; align-items:center; justify-content:center; font-size:14px; }
        .kr-adim.tamam .kr-adim-ico { background:#ecfdf5; color:#059669; }
        .kr-adim-metin { min-width:0; flex:1; }
        .kr-adim-baslik { font-size:13.5px; font-weight:600; color:var(--text-1,#1f2937); }
        .kr-adim.tamam .kr-adim-baslik { color:var(--text-2,#6b7280); text-decoration:line-through; }
        .kr-ops { font-size:10.5px; font-weight:600; color:var(--text-3,#9ca3af); border:1px solid var(--border,#e5e7eb); padding:1px 6px; border-radius:10px; text-decoration:none; }
        .kr-adim-aciklama { font-size:11.5px; color:var(--text-2,#6b7280); margin-top:2px; }
        .kr-durum { flex-shrink:0; font-size:12px; font-weight:600; text-decoration:none; white-space:nowrap; display:inline-flex; align-items:center; gap:5px; }
        .kr-durum.tamam { color:#059669; }
        .kr-durum.ayarla { color:var(--red); background:var(--red-soft,#fef2f2); padding:7px 12px; border-radius:9px; transition:.15s; }
        .kr-durum.ayarla:hover { background:#fbe0e5; }
        @media (max-width:600px){ .kurulum-rehber { padding:0 12px; } .kr-adim-aciklama { display:none; } }
    </style>
    <script>
        (function(){
            var el = document.getElementById('kurulumRehber');
            if (!el) return;
            try { if (localStorage.getItem('lumen_kurulum_gizli') === '1') { el.remove(); return; } } catch(e){}
            el.style.display = '';
            var b = document.getElementById('krGizle');
            if (b) b.addEventListener('click', function(){ try{ localStorage.setItem('lumen_kurulum_gizli','1'); }catch(e){} el.remove(); });
        })();
    </script>
    <?php endif; } ?>

    <?php if (!empty($kisisel_kisayollar)): ?>
    <!-- ═══════════ KISISEL KISAYOLLAR ═══════════ -->
    <section class="quick-section">
        <div class="quick-widget">
            <div class="quick-head">
                <i class="fa-solid fa-bolt"></i>
                <span>Sık kullandıklarım</span>
            </div>
            <div class="quick-links">
                <?php foreach ($kisisel_kisayollar as $shortcut): ?>
                    <a href="<?php echo htmlspecialchars((string) $shortcut['href'], ENT_QUOTES, 'UTF-8'); ?>" class="quick-link">
                        <i class="fa-solid <?php echo htmlspecialchars((string) $shortcut['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                        <span><?php echo htmlspecialchars((string) $shortcut['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php
    // ═══════════ "BUGÜN" PANELİ — satış kartı + uyarı kartları (tek görsel dil) ═══════════
    $satisVar = ($kasa_ozet_yetki && $kasa_ozet['adet'] > 0);
    $uyariVar = ($cekGorselEksik > 0 || $stokNegatifSayi > 0 || !empty($kendi_isaretsiz_refler));
    ?>
    <?php if ($satisVar || $uyariVar): ?>
    <section class="bugun-section">
        <div class="grid-label">Bugün</div>

        <?php if ($satisVar): ?>
        <a href="rapor/rapor_kasa.php" class="satis-kart" title="Kasa raporuna git">
            <div class="satis-sol">
                <span class="satis-ikon"><i class="fa-solid fa-cash-register"></i></span>
                <div class="satis-ozet">
                    <div class="satis-baslik">Bugünkü Mağaza Satışı</div>
                    <div class="satis-tutar"><?php echo number_format($kasa_ozet['toplam'], 2, ',', '.'); ?> ₺</div>
                    <div class="satis-alt"><?php echo (int) $kasa_ozet['adet']; ?> sipariş</div>
                </div>
            </div>
            <div class="satis-kalemler">
                <div class="sk-row"><span class="sk-dot" style="background:#059669;"></span><span class="sk-ad">Nakit</span><span class="sk-deger"><?php echo number_format($kasa_ozet['nakit'], 2, ',', '.'); ?> ₺</span></div>
                <div class="sk-row"><span class="sk-dot" style="background:#2563eb;"></span><span class="sk-ad">Kredi Kartı</span><span class="sk-deger"><?php echo number_format($kasa_ozet['kart'], 2, ',', '.'); ?> ₺</span></div>
                <div class="sk-row"><span class="sk-dot" style="background:#7c3aed;"></span><span class="sk-ad">Havale/EFT</span><span class="sk-deger"><?php echo number_format($kasa_ozet['havale'], 2, ',', '.'); ?> ₺</span></div>
                <?php if ($kasa_ozet['karma'] > 0): ?>
                <div class="sk-row"><span class="sk-dot" style="background:#ea580c;"></span><span class="sk-ad">Karma</span><span class="sk-deger"><?php echo number_format($kasa_ozet['karma'], 2, ',', '.'); ?> ₺</span></div>
                <?php endif; ?>
                <?php if ($kasa_ozet['bilinmiyor'] > 0): ?>
                <div class="sk-row"><span class="sk-dot" style="background:#94a3b8;"></span><span class="sk-ad">İşaretsiz</span><span class="sk-deger"><?php echo number_format($kasa_ozet['bilinmiyor'], 2, ',', '.'); ?> ₺</span></div>
                <?php endif; ?>
            </div>
            <i class="fa-solid fa-chevron-right satis-ok"></i>
        </a>
        <?php endif; ?>

        <?php if ($uyariVar): ?>
        <div class="uyari-grid">
            <?php if ($cekGorselEksik > 0): ?>
            <a href="cek_gorsel.php" class="uyari-kart u-amber" title="Çek görsellerini ekle">
                <span class="uk-ikon"><i class="fa-solid fa-camera"></i></span>
                <span class="uk-metin"><b>Çek Görselleri</b><span><?php echo (int) $cekGorselEksik; ?> çekin görseli eksik</span></span>
                <span class="uk-sayi"><?php echo (int) $cekGorselEksik; ?></span>
                <i class="fa-solid fa-chevron-right uk-ok"></i>
            </a>
            <?php endif; ?>
            <?php if ($stokNegatifSayi > 0): ?>
            <a href="rapor/rapor_stok_izleme.php" class="uyari-kart u-rose" title="Stok izleme raporu">
                <span class="uk-ikon"><i class="fa-solid fa-arrow-trend-down"></i></span>
                <span class="uk-metin"><b>Negatif Stok</b><span>eriyen ürünleri gör</span></span>
                <span class="uk-sayi"><?php echo (int) $stokNegatifSayi; ?></span>
                <i class="fa-solid fa-chevron-right uk-ok"></i>
            </a>
            <?php endif; ?>
            <?php if (!empty($kendi_isaretsiz_refler)): ?>
            <?php $vurgulaUrl = 'lg_essiparis.php?vurgula=' . urlencode(implode(',', $kendi_isaretsiz_refler)); ?>
            <a href="<?php echo $vurgulaUrl; ?>" class="uyari-kart u-orange" title="Ödeme şekli işaretlenmemiş siparişler">
                <span class="uk-ikon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                <span class="uk-metin"><b>Ödeme İşaretsiz</b><span><?php echo number_format($kendi_isaretsiz_tutar, 2, ',', '.'); ?> ₺ — tıkla, göster</span></span>
                <span class="uk-sayi"><?php echo count($kendi_isaretsiz_refler); ?></span>
                <i class="fa-solid fa-chevron-right uk-ok"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ═══════════ MENU GRID ═══════════ -->
    <section class="grid-section">
        <div class="grid-label">Modüller</div>
        <div class="card-grid">
            <?php
            $ci = 0;
            // Favori (yıldız pin) — anahtar = href'in query'siz küçük-harf hali (kararlı, sabit)
            $favRaw = (string) kisisel_ayar('gor_kart_favori');
            $favSet = [];
            foreach (explode(',', $favRaw) as $__fk) { $__fk = trim($__fk); if ($__fk !== '') { $favSet[$__fk] = true; } }
            ?>
            <?php foreach ($visible_menu_items as $item): ?>
                    <?php $favKey = strtolower(explode('?', (string)$item['href'])[0]); $isFav = isset($favSet[$favKey]); ?>
                    <a href="<?php echo htmlspecialchars((string)$item['href'], ENT_QUOTES, 'UTF-8'); ?>"
                       style="--d:<?php echo $ci++; ?>;"
                       data-fav="<?php echo htmlspecialchars($favKey, ENT_QUOTES, 'UTF-8'); ?>"
                       class="mc <?php echo htmlspecialchars((string)$item['theme'], ENT_QUOTES, 'UTF-8'); ?><?php echo $isFav ? ' mc-fav' : ''; ?>">
                        <span class="mc-star<?php echo $isFav ? ' on' : ''; ?>" role="button" aria-label="Favori" title="Favorilere ekle / çıkar"><i class="fa-solid fa-star"></i></span>
                        <div class="mc-icon">
                            <i class="fa-solid <?php echo htmlspecialchars((string)$item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                            <?php if ((int)($item['badge'] ?? 0) > 0): ?><span class="mc-badge"><?php echo (int)$item['badge'] > 99 ? '99+' : (int)$item['badge']; ?></span><?php endif; ?>
                        </div>
                        <div class="mc-title"><?php echo htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </a>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ═══════════ SCRIPTS ═══════════ -->
    <script>
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;
            const icons = {
                success: '<i class="fa-solid fa-check-circle"></i>',
                error:   '<i class="fa-solid fa-times-circle"></i>',
                warning: '<i class="fa-solid fa-exclamation-triangle"></i>',
                info:    '<i class="fa-solid fa-info-circle"></i>'
            };
            toast.innerHTML = `${icons[type] || icons.info}<span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_GET['giris']) && $_GET['giris'] === 'basarili'): ?>
                showToast('Hoş geldiniz! Giriş başarılı.', 'success');
            <?php endif; ?>

            // Ana sayfa favori (yıldız pin) — favoriler CSS order ile anında üste çıkar
            var KART_FAV_CSRF = <?php echo json_encode(function_exists('csrf_token') ? csrf_token() : ''); ?>;
            document.querySelectorAll('.card-grid .mc-star').forEach(function (s) {
                s.addEventListener('click', function (e) {
                    e.preventDefault(); e.stopPropagation();
                    var mc = s.closest('.mc'); if (!mc) { return; }
                    var fav = !mc.classList.contains('mc-fav');
                    mc.classList.toggle('mc-fav', fav);
                    s.classList.toggle('on', fav);
                    var keys = [];
                    document.querySelectorAll('.card-grid .mc.mc-fav').forEach(function (m) {
                        var k = m.getAttribute('data-fav'); if (k) { keys.push(k); }
                    });
                    fetch('ayar/kart_favori_kaydet.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ csrf_token: KART_FAV_CSRF, favoriler: keys.join(',') })
                    }).then(function (r) { return r.json(); })
                      .then(function (j) { showToast(fav ? 'Favorilere eklendi' : 'Favoriden çıkarıldı', (j && j.ok) ? 'success' : 'error'); })
                      .catch(function () { showToast('Kaydedilemedi', 'error'); });
                });
            });

            const hamburger = document.getElementById('hamburgerBtn');
            const dropdown = document.getElementById('mobileDropdown');

            if (hamburger && dropdown) {
                hamburger.addEventListener('click', function(e) {
                    e.stopPropagation();
                    dropdown.classList.toggle('open');
                    const icon = hamburger.querySelector('i');
                    icon.classList.toggle('fa-bars');
                    icon.classList.toggle('fa-times');
                });

                document.addEventListener('click', function(e) {
                    if (!dropdown.contains(e.target) && !hamburger.contains(e.target)) {
                        dropdown.classList.remove('open');
                        const icon = hamburger.querySelector('i');
                        icon.classList.remove('fa-times');
                        icon.classList.add('fa-bars');
                    }
                });
            }
        });
    </script>

<script>
// Hava durumu ikonunu arka planda guncelle (sayfa yuklenmesini beklemez)
(function(){
    var ikonMap = <?php echo json_encode(array_map(function($v){return $v['gunduz'];}, $hava_ikon_haritasi)); ?>;
    var geceMap = <?php echo json_encode(array_map(function($v){return $v['gece'];}, $hava_ikon_haritasi)); ?>;
    var saat = new Date().getHours();
    var gece = (saat < 6 || saat >= 20);

    function havaUygula(kod){
        var cls = gece ? geceMap[kod] : ikonMap[kod];
        if(!cls) return;
        var el = document.getElementById('havaIkon');
        if(el) el.className = 'fa-solid ' + cls + ' hero-greeting-icon';
    }
    (function(){
        // Client-side cache: hava durumunu 2 saat localStorage'da tut, her sayfa acilisinda
        // sunucuya gitme (ajax_hava.php cagri sayisini buyuk olcude azaltir).
        var KEY = 'akl_hava_v1', TTL = 7200000; // 2 saat
        try {
            var c = JSON.parse(localStorage.getItem(KEY) || 'null');
            if (c && c.kod && (Date.now() - c.t) < TTL) { havaUygula(c.kod); return; }
        } catch (e) {}
        fetch('ajax_hava.php').then(function(r){return r.json()}).then(function(d){
            if(!d.ok) return;
            try { localStorage.setItem(KEY, JSON.stringify({kod:d.kod, t:Date.now()})); } catch(e){}
            havaUygula(d.kod);
        }).catch(function(){});
    })();
})();
</script>

<?php include_once __DIR__ . '/includes/tur.php'; ?>

</body>
</html>
