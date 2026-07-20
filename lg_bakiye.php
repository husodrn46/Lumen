<?php
declare(strict_types=1);

// Gerekli dosyaları ve ayarları dahil et
include_once(__DIR__ . "/log_ip.php");
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");

// Eski ?cari=... bağlantıları bozulmasın; yeni arama lg_essiparis.php gibi ?q=... ile çalışır.
if (isset($_GET['cari']) && !isset($_GET['q'])) {
    $legacyCari = trim((string) $_GET['cari']);
    $target = 'lg_bakiye.php' . ($legacyCari !== '' ? '?q=' . rawurlencode($legacyCari) : '');
    header('Location: ' . $target);
    exit;
}

// YETKI KONTROLÜ: Tek bakiye ekranı M4 veya M20 ile açılır
if (!m_p_bakiye_erisim_var_mi($terminalkullanici)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// (Debug removed)

// Para birimini formatlamak için kullanılan fonksiyon
function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat; // Bu değişkenin 'ayr.php' içinde tanımlı olması gerekir.
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0;
    }
    return number_format($kusurat, $parakusurat ?? 2, ',', '.');
}

// --- VERİTABANI SORGUSU VE VERİ ÇEKME ---
$rows = [];
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchQuery = function_exists('mb_substr') ? mb_substr($searchQuery, 0, 120) : substr($searchQuery, 0, 120);
$raw_cari = $searchQuery;
$hasActiveFilters = $searchQuery !== '';
$highBalanceThreshold = 100000.0;

// Sadece arama kutusunda bir değer varsa sorguyu çalıştır
if ($raw_cari !== '' && $raw_cari !== '0') {
    // Türkçe karakterleri ASCII'ye çevir (mağaza -> magaza, İSTANBUL -> istanbul)
    $cari = turkce($raw_cari);
    $like = "%{$cari}%";
    // 'ayr.php' dosyasından gelmesi beklenen listeleme limiti
    $limit = isset($carilistesayisi) ? (int)$carilistesayisi : 100;
    $ozelCariFiltresi = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'C.LOGICALREF', 'bakiye_gizli', 'M4');

    // Güvenli ve parametreli SQL sorgusu
    $sql = "
        SELECT TOP {$limit}
          C.LOGICALREF    AS CARIID,
          C.CITY          AS SEHIR,
          C.DISTRICT      AS AMBAR,
          C.CODE          AS KODU,
          C.TELNRS1,
          C.EMAILADDR,
          C.DEFINITION_   AS UNVANI,
          LS.SON_URUN_ALIMI,
          (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE
        FROM {$firma}CLCARD C WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
        OUTER APPLY (
            SELECT TOP 1 I.DATE_ AS SON_URUN_ALIMI
            FROM {$firmadonem}INVOICE I WITH(NOLOCK)
            WHERE I.CLIENTREF = C.LOGICALREF
              AND I.CANCELLED = 0
              AND I.TRCODE IN (7, 8)
              AND EXISTS (
                  SELECT 1
                  FROM {$firmadonem}STLINE L WITH(NOLOCK)
                  WHERE L.INVOICEREF = I.LOGICALREF
                    AND L.CANCELLED = 0
                    AND L.LINETYPE = 0
              )
            ORDER BY I.DATE_ DESC, I.LOGICALREF DESC
        ) LS
        WHERE C.ACTIVE = 0
          " . ($ozelCariFiltresi['sql'] !== '' ? "AND {$ozelCariFiltresi['sql']}" : '') . "
          AND (
               REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p1
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p2
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.CITY, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p3
          )
        ORDER BY C.DEFINITION_ ASC
    ";
    $params = array_merge($ozelCariFiltresi['params'], [':p1' => $like, ':p2' => $like, ':p3' => $like]);

    try {
        // Veritabanı nesnesinin 'ayr.php' içinde $dbh olarak tanımlandığından emin olun
        try {
	            $info = '['.date('Y-m-d H:i:s').'] DBG VARS: isset($dbh)=' . (isset($dbh) ? '1' : '0') . ', firma=' . ($firma ?? '(undef)') . ', firmadonemx=' . ($firmadonemx ?? '(undef)') . "\n";
	            if (isset($__durna_dbg) && is_string($__durna_dbg) && $__durna_dbg !== '') {
	                set_error_handler(static fn(): bool => true);
	                file_put_contents($__durna_dbg, $info, FILE_APPEND);
	                restore_error_handler();
	            }
	        } catch (Exception) {}

        if (isset($dbh)) {
            $stmt = $dbh->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        // Hata durumunda kullanıcıya dostane bir mesaj göster ve işlemi durdur.
        // Gerçek hatayı loglamak daha güvenli bir yöntemdir.
        die('<div class="p-4 bg-red-800 text-white text-center">Veritabanı Hatası: ' . htmlspecialchars($e->getMessage()) . '</div>');
    }
}

// --- CEK/SENET OZETI: Listelenen carilerden ALINAN cek/senetler ve akibeti ---
// CSTRANS.TRCODE=1 => cariden giris (alindi), TRCODE=3 => ciro/cikis (kime verildi).
// CSCARD.CURRSTAT: 1=portfoyde, 2=ciro edildi, diger (6/8)=tahsil/kapandi. DOC: 1=cek, 2=senet.
$cekSenetOzet = [];
if (!empty($rows) && isset($dbh)) {
    $cariIdler = array_values(array_unique(array_map(static fn($r) => (int) $r['CARIID'], $rows)));
    if (!empty($cariIdler)) {
        $ph = implode(',', array_fill(0, count($cariIdler), '?'));
        try {
            $csSql = "
                SELECT
                    G.CARDREF AS ALAN_CARI,
                    C.DOC AS DOC,
                    C.CURRSTAT AS CURRSTAT,
                    C.TRNET AS TUTAR,
                    (SELECT TOP 1 H.DEFINITION_
                       FROM {$firmadonem}CSTRANS TC WITH(NOLOCK)
                       LEFT JOIN {$firma}CLCARD H WITH(NOLOCK) ON H.LOGICALREF = TC.CARDREF
                      WHERE TC.CSREF = C.LOGICALREF AND TC.TRCODE = 3
                      ORDER BY TC.LOGICALREF DESC) AS CIRO_HEDEF
                FROM {$firmadonem}CSCARD C WITH(NOLOCK)
                JOIN {$firmadonem}CSTRANS G WITH(NOLOCK) ON G.CSREF = C.LOGICALREF AND G.TRCODE = 1
                WHERE G.CARDREF IN ({$ph})";
            $csStmt = $dbh->prepare($csSql);
            $csStmt->execute($cariIdler);
            while ($cs = $csStmt->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) $cs['ALAN_CARI'];
                if (!isset($cekSenetOzet[$cid])) {
                    $cekSenetOzet[$cid] = ['adet' => 0, 'tutar' => 0.0, 'cek' => 0, 'senet' => 0, 'portfoy' => 0, 'ciro' => 0, 'tahsil' => 0, 'hedefler' => []];
                }
                $tutar = (float) $cs['TUTAR'];
                $cekSenetOzet[$cid]['adet']++;
                $cekSenetOzet[$cid]['tutar'] += $tutar;
                if ((int) $cs['DOC'] === 2) {
                    $cekSenetOzet[$cid]['senet']++;
                } else {
                    $cekSenetOzet[$cid]['cek']++;
                }
                $st = (int) $cs['CURRSTAT'];
                if ($st === 1) {
                    $cekSenetOzet[$cid]['portfoy']++;
                } elseif ($st === 2) {
                    $cekSenetOzet[$cid]['ciro']++;
                    $hedef = trim((string) ($cs['CIRO_HEDEF'] ?? ''));
                    if ($hedef !== '') {
                        if (!isset($cekSenetOzet[$cid]['hedefler'][$hedef])) {
                            $cekSenetOzet[$cid]['hedefler'][$hedef] = ['adet' => 0, 'tutar' => 0.0];
                        }
                        $cekSenetOzet[$cid]['hedefler'][$hedef]['adet']++;
                        $cekSenetOzet[$cid]['hedefler'][$hedef]['tutar'] += $tutar;
                    }
                } else {
                    $cekSenetOzet[$cid]['tahsil']++;
                }
            }
        } catch (Throwable $e) {
            error_log('lg_bakiye cek/senet ozet: ' . $e->getMessage());
        }
    }
}

// --- LISTE OZETI: arama sonucu toplamlari (bakiye>0=Borclu, <0=Alacakli; sayfa semantigi) ---
$hasBalancePermission = (m_p_yetki($terminalkullanici, 'CR1') == 1);
$ozetBorclu = 0.0;
$ozetAlacakli = 0.0;
foreach ($rows as $__r) {
    $__b = (float) $__r['BAKIYE'];
    if ($__b > 0) {
        $ozetBorclu += $__b;
    } elseif ($__b < 0) {
        $ozetAlacakli += abs($__b);
    }
}
$ozetNet = $ozetBorclu - $ozetAlacakli;

// Cari İşlemleri hub — en az bir özellik (tahsilat / çek giriş / çek çıkış) DURNA'ya açıksa karta "İşlemler" pill'i çıkar
global $tahsilat_beta, $tahsilat_beta_kullanicilar, $cek_beta, $cek_beta_kullanicilar, $cek_cikis_beta, $cek_cikis_beta_kullanicilar;
$__hubIzin = static function ($flag, $list) use ($terminalkullanici): bool {
    return !empty($flag) && is_array($list) && !empty($list) && in_array((int) $terminalkullanici, array_map('intval', $list), true);
};
$duranaHub = ((int) ($yetkidurum ?? 1) === 0) && (
    $__hubIzin($tahsilat_beta ?? null, $tahsilat_beta_kullanicilar ?? []) ||
    $__hubIzin($cek_beta ?? null, $cek_beta_kullanicilar ?? []) ||
    $__hubIzin($cek_cikis_beta ?? null, $cek_cikis_beta_kullanicilar ?? [])
);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <link rel="icon" type="image/png" href="icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bakiye Listesi</title>
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
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
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* ═══════ HEADER ═══════ */
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
        }
        .header-inner {
            max-width: 1200px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .header-title i { color: var(--red,#ef4444); font-size: 16px; }

        /* ═══════ SEARCH PANEL ═══════ */
        .search-panel {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px;
            margin-bottom: 22px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .search-wrap { position: relative; flex: 1 1 auto; }
        .search-wrap i.fa-search {
            position: absolute;
            top: 50%; left: 14px;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 12px 14px 12px 40px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: all 0.2s ease;
            outline: none;
        }
        .search-input:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .btn-search {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 22px;
            background: var(--red,#ef4444);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            white-space: nowrap;
        }
        .btn-search:hover { background: var(--red); box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25); transform: translateY(-1px); }
        .btn-search:active { transform: translateY(0); }

        .filter-panel {
            background: #ffffff;
            backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.24);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .filter-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            margin-bottom: 6px;
        }
        .filter-input {
            width: 100%;
            padding: 10px 12px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            transition: all 0.2s ease;
            outline: none;
        }
        .filter-input:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .btn-flat {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            white-space: nowrap;
        }
        .btn-flat:hover { box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .btn-flat:active { transform: scale(0.98); }
        .btn-red { background: var(--red,#ef4444); color: #fff; }
        .btn-red:hover { background: var(--red,#6F1022); }
        .btn-light { background: #fff; color: var(--text-2); border: 1px solid var(--border); }
        .btn-light:hover { background: #f9fafb; color: var(--text-1); }
        .filter-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 600;
            background: var(--red-soft);
            color: var(--red);
            border: 1px solid rgba(248, 113, 113, 0.28);
        }

        /* ═══════ GRID & CARDS ═══════ */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 18px;
        }
        .firma-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease;
        }
        .firma-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(239, 68, 68, 0.10);
            border-color: rgba(239, 68, 68, 0.35);
        }
        .firma-card.high-balance {
            border-color: rgba(245, 158, 11, 0.5);
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.10);
        }

        .firma-head {
            padding: 16px 18px 14px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #fffafa);
        }
        .firma-title {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.35;
        }
        .firma-title .ico {
            flex-shrink: 0;
            width: 30px; height: 30px;
            border-radius: 9px;
            background: var(--red-soft);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }
        .firma-title span {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
        .firma-sehir {
            margin-top: 8px;
            padding-left: 40px;
            font-size: 12px;
            color: var(--text-2);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .firma-sehir i { color: var(--text-3); font-size: 11px; }
        .firma-badges {
            margin-top: 10px;
            padding-left: 40px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 100%;
            padding: 4px 9px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 700;
            line-height: 1.2;
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
        }
        .mini-badge i { font-size: 10px; color: #f59e0b; }

        .firma-body {
            padding: 18px;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .bakiye-box {
            text-align: center;
            padding: 14px 10px;
            background: #fafafa;
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        .bakiye-box .label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-2);
        }
        .bakiye-box .amount {
            margin-top: 4px;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
        }
        .bakiye-box.pozitif .amount { color: var(--emerald); }
        .bakiye-box.negatif .amount { color: var(--red); }
        .bakiye-box.sifir .amount { color: var(--text-2); }
        .bakiye-box.hidden-box .amount { color: var(--text-3); }
        .cek-senet-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 11px 13px; }
        .cs-baslik { font-size: 11.5px; font-weight: 700; color: #92400e; display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
        .cs-ozet { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
        .cs-tutar { font-size: 17px; font-weight: 700; color: #b45309; }
        .cs-adet { font-size: 12px; color: var(--text-2); }
        .cs-durum { display: flex; gap: 6px; flex-wrap: wrap; }
        .cs-rozet { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px; }
        .cs-rozet i { font-size: 9px; }
        .cs-rozet.portfoy { background: #eff6ff; color: #1d4ed8; }
        .cs-rozet.ciro { background: #fef2f2; color: #b91c1c; }
        .cs-rozet.tahsil { background: #ecfdf5; color: #047857; }
        .cs-hedefler { display: flex; flex-direction: column; gap: 3px; margin-top: 8px; padding-top: 8px; border-top: 1px dashed #fcd34d; }
        .cs-hedef { font-size: 11.5px; color: var(--text-1); }
        .cs-hedef i { color: #d97706; font-size: 10px; margin-right: 3px; }
        .bakiye-box .note {
            margin-top: 4px;
            font-size: 10px;
            color: var(--text-3);
        }

        .meta-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 12.5px;
            color: var(--text-2);
        }
        .meta-list .row {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
            padding: 2px 0;
        }
        .meta-list .row .ico {
            flex-shrink: 0;
            width: 22px;
            text-align: center;
            color: var(--text-3);
            font-size: 11px;
        }
        .meta-list .row .val {
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text-1);
        }
        .meta-list .row .val a {
            color: inherit;
            text-decoration: none;
        }
        .meta-list .row .val a:hover { color: var(--red); }
        .meta-list .row .val.muted { color: var(--text-3); font-style: italic; }

        /* Action buttons row */
        .firma-footer {
            padding: 14px 14px 16px;
            border-top: 1px solid var(--border);
            background: #fafafa;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .action-pill {
            flex: 1 1 84px;
            min-width: 84px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 6px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            color: var(--text-1);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            white-space: nowrap;
        }
        .action-pill i { font-size: 13px; }
        .action-pill:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .action-pill:active { transform: translateY(0); }
        .action-pill.pill-red i { color: var(--red); }
        .action-pill.pill-red:hover { background: var(--red); color: #fff; border-color: var(--red); }
        .action-pill.pill-red:hover i { color: #fff; }
        .action-pill.pill-indigo i { color: var(--indigo); }
        .action-pill.pill-indigo:hover { background: var(--indigo); color: #fff; border-color: var(--indigo); }
        .action-pill.pill-indigo:hover i { color: #fff; }
        .action-pill.pill-emerald i { color: var(--emerald); }
        .action-pill.pill-emerald:hover { background: var(--emerald); color: #fff; border-color: var(--emerald); }
        .action-pill.pill-emerald:hover i { color: #fff; }
        .action-pill.pill-amber i { color: #d97706; }
        .action-pill.pill-amber:hover { background: #d97706; color: #fff; border-color: #d97706; }
        .action-pill.pill-amber:hover i { color: #fff; }

        /* Liste ozet bandi */
        .ozet-bandi { display: flex; gap: 14px; flex-wrap: wrap; background: rgba(255,255,255,0.92); border: 1px solid rgba(248,113,113,0.18); border-radius: 14px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 14px 20px; margin-bottom: 20px; animation: cardIn 0.4s cubic-bezier(0.22,1,0.36,1) both; }
        .oz-item { flex: 1 1 130px; display: flex; flex-direction: column; gap: 3px; }
        .oz-item + .oz-item { border-left: 1px solid var(--border); padding-left: 14px; }
        .oz-lbl { font-size: 10.5px; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.4px; font-weight: 600; }
        .oz-val { font-size: 18px; font-weight: 700; color: var(--text-1); line-height: 1.15; }
        .oz-val.borclu { color: var(--emerald); }
        .oz-val.alacakli { color: var(--red); }

        /* ═══════ EMPTY STATE ═══════ */
        .empty-state {
            grid-column: 1 / -1;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px dashed rgba(248, 113, 113, 0.30);
            border-radius: 16px;
            padding: 40px 24px;
            text-align: center;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .empty-state .big-icon {
            width: 64px; height: 64px;
            margin: 0 auto 14px;
            border-radius: 16px;
            background: var(--red-soft);
            color: var(--red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .empty-state .title { font-size: 15px; font-weight: 700; color: var(--text-1); }
        .empty-state .desc { margin-top: 4px; font-size: 13px; color: var(--text-2); }

        /* ═══════ MODAL ═══════ */
        .akl-modal {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.42);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 60;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .akl-modal.is-open { display: flex; animation: fadeIn 0.2s ease both; }
        .akl-modal-dialog {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
            width: 100%;
            max-width: 560px;
            max-height: 88vh;
            display: flex;
            flex-direction: column;
            animation: modalIn 0.28s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .akl-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
        }
        .akl-modal-head h5 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .akl-modal-head h5 i { color: var(--red); font-size: 14px; }
        .modal-close {
            background: transparent;
            border: none;
            font-size: 22px;
            line-height: 1;
            color: var(--text-2);
            cursor: pointer;
            width: 32px; height: 32px;
            border-radius: 8px;
            transition: all 0.15s ease;
        }
        .modal-close:hover { background: rgba(0,0,0,0.05); color: var(--red); }
        .akl-modal-body {
            padding: 18px;
            overflow-y: auto;
            font-size: 13.5px;
            color: var(--text-1);
            line-height: 1.6;
        }
        .akl-modal-foot {
            padding: 12px 18px;
            border-top: 1px solid var(--border);
            background: #fafafa;
            display: flex;
            justify-content: flex-end;
            border-radius: 0 0 16px 16px;
        }
        .btn-modal-close {
            padding: 9px 20px;
            background: var(--red,#ef4444);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-modal-close:hover { background: var(--red); }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes modalIn {
            from { opacity: 0; transform: translate3d(0, 14px, 0) scale(0.97); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-back { width: 40px; height: 40px; border-radius: 8px; }
            .header-divider { height: 18px; }

            main { padding: 14px 12px 40px !important; }

            .search-panel { padding: 12px; margin-bottom: 16px; }
            .search-form { gap: 8px; }
            .search-input { font-size: 16px; padding: 11px 14px 11px 38px; }
            .btn-search { padding: 11px 16px; font-size: 13px; }
            .btn-search span { display: none; }
            .filter-panel { padding: 12px; margin-bottom: 12px; border-radius: 12px; }
            .filter-input { padding: 10px 10px 10px 36px; font-size: 16px; border-radius: 8px; }
            .filter-label { font-size: 11px; margin-bottom: 3px; }
            .filter-badge { font-size: 11px; padding: 3px 10px; }
            .btn-flat { padding: 8px 12px; font-size: 12px; border-radius: 8px; }

            .card-grid { grid-template-columns: 1fr; gap: 14px; }
            .firma-card:hover { transform: none; }
            .firma-head { padding: 14px 16px 12px; }
            .firma-title { font-size: 13.5px; }
            .firma-body { padding: 16px; gap: 12px; }
            .bakiye-box .amount { font-size: 20px; }

            .firma-footer { padding: 12px; gap: 6px; flex-wrap: wrap; }
            .action-pill { font-size: 11px; padding: 12px 4px; gap: 5px; flex: 1 1 42%; min-height: 44px; overflow: hidden; text-overflow: ellipsis; }
            .action-pill i { font-size: 12px; }
            .action-pill:hover { transform: none; }
            .ozet-bandi { padding: 12px 14px; gap: 10px; }
            .oz-item { flex: 1 1 42%; }
            .oz-item + .oz-item { border-left: none; padding-left: 0; }
            .oz-val { font-size: 16px; }

            .akl-modal-dialog { max-height: 92vh; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Ana Sayfa">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-scale-balanced"></i>Bakiye Listesi
            </span>
        </div>
    </header>

    <main style="max-width:1200px;margin:0 auto;padding:22px 24px 60px;">

        <!-- Filter Panel -->
        <div class="filter-panel">
            <form method="get" id="filterForm" style="display:flex;flex-direction:column;gap:14px;" autocomplete="off">
                <div>
                    <label for="barkod" class="filter-label">Listede Ara</label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-3);">
                            <i class="fa-solid fa-search"></i>
                        </span>
                        <input type="text" name="q" id="barkod" class="filter-input"
                            value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="Firma adı, kodu veya şehir..." autofocus>
                    </div>
                </div>

                <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
                    <button type="submit" class="btn-flat btn-red">
                        <i class="fa-solid fa-filter"></i> Filtre Uygula
                    </button>
                    <a href="lg_bakiye.php" class="btn-flat btn-light">
                        <i class="fa-solid fa-rotate-left"></i> Temizle
                    </a>
                </div>
            </form>

            <?php if ($hasActiveFilters): ?>
                <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                    <span style="font-size:11px;font-weight:600;letter-spacing:0.5px;text-transform:uppercase;color:var(--text-3);">Aktif filtreler:</span>
                    <span class="filter-badge">Arama: "<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>"</span>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows) && $hasBalancePermission): ?>
        <div class="ozet-bandi">
            <div class="oz-item"><span class="oz-lbl">Listelenen Cari</span><span class="oz-val"><?php echo count($rows); ?></span></div>
            <div class="oz-item"><span class="oz-lbl">Toplam Borçlu</span><span class="oz-val borclu"><?php echo paraformat($ozetBorclu); ?> &#8378;</span></div>
            <div class="oz-item"><span class="oz-lbl">Toplam Alacaklı</span><span class="oz-val alacakli"><?php echo paraformat($ozetAlacakli); ?> &#8378;</span></div>
            <div class="oz-item"><span class="oz-lbl">Net</span><span class="oz-val"><?php echo paraformat(abs($ozetNet)); ?> &#8378;<?php echo $ozetNet > 0 ? ' (Borçlu)' : ($ozetNet < 0 ? ' (Alacaklı)' : ''); ?></span></div>
        </div>
        <?php endif; ?>

        <!-- Firma Kartlari Listesi -->
        <div id="firma-listesi" class="card-grid">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <?php if ($raw_cari !== '' && $raw_cari !== '0'): ?>
                        <div class="big-icon"><i class="fa-solid fa-user-slash"></i></div>
                        <div class="title">Musteri Bulunamadi</div>
                        <div class="desc">Girdiginiz kriterlere uygun bir musteri bulunamadi.</div>
                    <?php else: ?>
                        <div class="big-icon" style="background:#f3f4f6;color:#9ca3af;"><i class="fa-solid fa-magnifying-glass"></i></div>
                        <div class="title">Arama Yapiniz</div>
                        <div class="desc">Musterileri listelemek icin yukaridaki alana bir arama terimi girin.</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php
                $cardIndex = 0;
                foreach ($rows as $rowx):
                    $bakiye = (float) $rowx['BAKIYE'];
                    // ORIJINAL semantik korundu: bakiye>0 => (Borclu) yesil, bakiye<0 => (Alacakli) kirmizi
                    if ($bakiye > 0) {
                        $bakiyeClass = 'pozitif';
                        $bakiyeDurum = 'Borclu';
                    } elseif ($bakiye < 0) {
                        $bakiyeClass = 'negatif';
                        $bakiyeDurum = 'Alacakli';
                    } else {
                        $bakiyeClass = 'sifir';
                        $bakiyeDurum = '';
                    }
                    if (!$hasBalancePermission) {
                        $bakiyeClass = 'hidden-box';
                    }
                    $unvanText = tr($rowx['UNVANI']);
                    $sehirText = function_exists('trcevir') ? trcevir($rowx['SEHIR']) : (string)$rowx['SEHIR'];
                    $ambarText = function_exists('trcevir') ? trcevir($rowx['AMBAR']) : (string)$rowx['AMBAR'];
                    $kodu = (string) $rowx['KODU'];
                    $telefon = (string) ($rowx['TELNRS1'] ?? '');
                    $telefonKisa = preg_replace('/\s+/', '', $telefon);
                    $telefonLink = preg_replace('/[^\d+]/', '', $telefon);
                    $email = trim((string) ($rowx['EMAILADDR'] ?? ''));
                    $sonUrunAlimi = tarihcevir($rowx['SON_URUN_ALIMI'] ?? null);
                    $isHighBalance = $hasBalancePermission && abs($bakiye) >= $highBalanceThreshold;
                    $delay = min($cardIndex * 40, 280);
                    $cardIndex++;
                ?>
                <article class="firma-card<?php echo $isHighBalance ? ' high-balance' : ''; ?>" style="animation-delay: <?php echo $delay; ?>ms;">
                    <div class="firma-head">
                        <div class="firma-title">
                            <span class="ico"><i class="fa-regular fa-building"></i></span>
                            <span title="<?php echo htmlspecialchars($unvanText); ?>"><?php echo htmlspecialchars($unvanText); ?></span>
                        </div>
                        <?php if (trim($sehirText) !== ''): ?>
                        <div class="firma-sehir">
                            <i class="fa-solid fa-location-dot"></i>
                            <?php echo htmlspecialchars($sehirText); ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($isHighBalance): ?>
                        <div class="firma-badges">
                            <span class="mini-badge"><i class="fa-solid fa-triangle-exclamation"></i> Yüksek bakiye</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="firma-body">
                        <div class="bakiye-box <?php echo $bakiyeClass; ?>">
                            <?php if ($hasBalancePermission): ?>
                                <div class="label">Bakiye<?php echo $bakiyeDurum !== '' ? ' ('.$bakiyeDurum.')' : ''; ?></div>
                                <div class="amount"><?php echo paraformat(abs($bakiye)); ?> &#8378;</div>
                            <?php else: ?>
                                <div class="label">Bakiye</div>
                                <div class="amount">—</div>
                                <div class="note">Bakiye gorme yetkiniz yok.</div>
                            <?php endif; ?>
                        </div>

                        <div class="meta-list">
                            <div class="row" title="Musteri Kodu">
                                <span class="ico"><i class="fa-solid fa-hashtag"></i></span>
                                <span class="val<?php echo trim($kodu) === '' ? ' muted' : ''; ?>">
                                    <?php echo trim($kodu) === '' ? '-' : htmlspecialchars($kodu); ?>
                                </span>
                            </div>
                            <div class="row" title="Ambar">
                                <span class="ico"><i class="fa-solid fa-warehouse"></i></span>
                                <span class="val<?php echo trim($ambarText) === '' ? ' muted' : ''; ?>">
                                    <?php echo trim($ambarText) === '' ? '-' : htmlspecialchars($ambarText); ?>
                                </span>
                            </div>
                            <div class="row" title="Telefon">
                                <span class="ico"><i class="fa-solid fa-phone"></i></span>
                                <span class="val<?php echo trim($telefon) === '' ? ' muted' : ''; ?>">
                                    <?php if (trim($telefon) === ''): ?>
                                        -
                                    <?php else: ?>
                                        <a href="tel:<?php echo htmlspecialchars($telefonLink); ?>"><?php echo htmlspecialchars($telefon); ?></a>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="row" title="E-posta">
                                <span class="ico"><i class="fa-regular fa-envelope"></i></span>
                                <span class="val<?php echo $email === '' ? ' muted' : ''; ?>">
                                    <?php if ($email === ''): ?>
                                        -
                                    <?php else: ?>
                                        <a href="mailto:<?php echo htmlspecialchars($email); ?>"><?php echo htmlspecialchars($email); ?></a>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="row" title="Son Ürün Alımı">
                                <span class="ico"><i class="fa-solid fa-clock-rotate-left"></i></span>
                                <span class="val<?php echo $sonUrunAlimi === '' ? ' muted' : ''; ?>">
                                    <?php echo $sonUrunAlimi === '' ? 'Son alış yok' : htmlspecialchars($sonUrunAlimi); ?>
                                </span>
                            </div>
                        </div>
                        <?php $csOz = $cekSenetOzet[(int) $rowx['CARIID']] ?? null; ?>
                        <?php if ($csOz !== null && $csOz['adet'] > 0): ?>
                        <div class="cek-senet-box">
                            <div class="cs-baslik"><i class="fa-solid fa-money-check-dollar"></i> Bu cariden alınan çek/senet</div>
                            <div class="cs-ozet">
                                <span class="cs-tutar"><?php echo paraformat($csOz['tutar']); ?> &#8378;</span>
                                <span class="cs-adet"><?php echo (int) $csOz['adet']; ?> adet<?php echo ($csOz['senet'] > 0 && $csOz['cek'] > 0) ? ' (' . (int) $csOz['cek'] . ' çek, ' . (int) $csOz['senet'] . ' senet)' : ($csOz['senet'] > 0 ? ' (senet)' : ''); ?></span>
                            </div>
                            <div class="cs-durum">
                                <?php if ($csOz['portfoy'] > 0): ?><span class="cs-rozet portfoy"><i class="fa-solid fa-wallet"></i> Portföyde <?php echo (int) $csOz['portfoy']; ?></span><?php endif; ?>
                                <?php if ($csOz['ciro'] > 0): ?><span class="cs-rozet ciro"><i class="fa-solid fa-share-from-square"></i> Ciro <?php echo (int) $csOz['ciro']; ?></span><?php endif; ?>
                                <?php if ($csOz['tahsil'] > 0): ?><span class="cs-rozet tahsil"><i class="fa-solid fa-circle-check"></i> Tahsil <?php echo (int) $csOz['tahsil']; ?></span><?php endif; ?>
                            </div>
                            <?php if (!empty($csOz['hedefler'])): ?>
                            <div class="cs-hedefler">
                                <?php foreach ($csOz['hedefler'] as $hedefAd => $hedefBilgi): ?>
                                    <div class="cs-hedef"><i class="fa-solid fa-arrow-right-long"></i> <?php echo htmlspecialchars($hedefAd); ?>: <strong><?php echo paraformat($hedefBilgi['tutar']); ?> &#8378;</strong><?php echo $hedefBilgi['adet'] > 1 ? ' · ' . (int) $hedefBilgi['adet'] . ' adet' : ''; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="firma-footer">
                        <a href="lg_hareket.php?cariid=<?php echo (int)$rowx['CARIID']; ?>" class="action-pill pill-red" title="Hareketler">
                            <i class="fa-solid fa-list-ul"></i> Hareket
                        </a>
                        <?php if ($duranaHub): ?>
                        <a href="cari_islemleri.php?cari=<?php echo (int)$rowx['CARIID']; ?>" class="action-pill pill-emerald" title="Cari İşlemleri: tahsilat, ödeme, çek giriş/çıkış">
                            <i class="fa-solid fa-right-left"></i> İşlemler
                        </a>
                        <?php endif; ?>
                        <a href="lg_hareket_ekstre_pdf.php?cariid=<?php echo (int)$rowx['CARIID']; ?>" target="_blank" rel="noopener" class="action-pill pill-amber" title="Cari Hareket Ekstresi (PDF)">
                            <i class="fa-solid fa-file-pdf"></i> Ekstre
                        </a>
                        <button type="button" data-id="<?php echo (int)$rowx['CARIID']; ?>" class="userinfo action-pill pill-indigo" title="Cari Detay">
                            <i class="fa-solid fa-circle-info"></i> Detay
                        </button>
                        <a href="https://api.whatsapp.com/send?phone=<?php echo htmlspecialchars($telefonKisa); ?>" target="_blank" rel="noopener" class="action-pill pill-emerald" title="WhatsApp">
                            <i class="fab fa-whatsapp"></i> Whatsapp
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal (Detay Penceresi) -->
    <div id="empModal" class="akl-modal" role="dialog" aria-modal="true" aria-labelledby="empModalTitle">
        <div class="akl-modal-dialog">
            <div class="akl-modal-head">
                <h5 id="empModalTitle"><i class="fa-solid fa-circle-info"></i> Cari Kart Detayi</h5>
                <button id="empModalClose" type="button" class="modal-close" aria-label="Kapat">&times;</button>
            </div>
            <div class="akl-modal-body" id="empModalBody">
                <!-- AJAX ile yuklenecek icerik -->
            </div>
            <div class="akl-modal-foot">
                <button id="empModalCloseBtn" type="button" class="btn-modal-close">Kapat</button>
            </div>
        </div>
    </div>

    <!-- Gerekli Scriptler -->
    <script src="/tm/js/jquery-3.7.1.min.js"></script>
    <script>
        // Animation render bug fix: force reflow
        window.addEventListener('load', function(){
            requestAnimationFrame(function(){
                document.querySelectorAll('.firma-card, .filter-panel, .empty-state').forEach(function(el){ void el.offsetHeight; });
            });
        });

        (function() {
            var searchInput = document.getElementById('barkod');
            var filterForm = document.getElementById('filterForm');
            if (searchInput && filterForm) {
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        filterForm.submit();
                    }
                });
            }
        })();

        $(document).ready(function () {
            // Modal acma (event delegation)
            $('#firma-listesi').on('click', '.userinfo', function (e) {
                e.preventDefault();
                var userid = $(this).data('id');
                $('#empModalBody').html('<p style="text-align:center;padding:24px;color:#6b7280;"><i class="fa-solid fa-circle-notch fa-spin" style="margin-right:8px;"></i> Yukleniyor...</p>');
                $('#empModal').addClass('is-open');

                $.ajax({
                    url: 'lg_bakiyex.php',
                    type: 'POST',
                    data: { userid: userid },
                    success: function (response) {
                        $('#empModalBody').html(response);
                    },
                    error: function() {
                        $('#empModalBody').html('<p style="text-align:center;padding:24px;color:var(--red,#6F1022);">Detaylar yuklenirken bir hata olustu.</p>');
                    }
                });
            });

            function closeModal() {
                $('#empModal').removeClass('is-open');
            }

            $('#empModalClose, #empModalCloseBtn').click(closeModal);

            $('#empModal').click(function(event) {
                if ($(event.target).is('#empModal')) {
                    closeModal();
                }
            });

            $(document).keyup(function(e) {
                if (e.key === "Escape") { closeModal(); }
            });
        });
    </script>

</body>
</html>
