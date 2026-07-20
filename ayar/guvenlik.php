<?php
declare(strict_types=1);

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

// ============================================================
// Sabitler (hesap kilidi)
// Bu iki değer giris.php'deki $hesap_kilit_sure / $hesap_kilit_esik ile AYNI olmalı.
// Biri değişirse diğeri de güncellenmeli (kilit penceresi ve eşik tutarlılığı için).
// ============================================================
const KILIT_SURE_DK = 15;
const KILIT_ESIK    = 5;

// ============================================================
// Yardımcı fonksiyonlar (ayr.php'de varsa çakışmasın diye guard'lı)
// ============================================================

/** Kalan saniyeyi "X dk" metnine çevir (hesap kilidi) */
if (!function_exists('kilit_kalan_metin')) {
    function kilit_kalan_metin(int $saniye): string
    {
        if ($saniye <= 0) {
            return 'birazdan';
        }
        $dk = (int) ceil($saniye / 60);
        return $dk . ' dk';
    }
}

/** Saldırı modu aç/kapat işlemini M_GIRIS_LOG'a denetim kaydı olarak yazar. */
if (!function_exists('saldiri_modu_audit')) {
    function saldiri_modu_audit(PDO $dbh, int $adminId, string $adminAdi, string $islem, string $ip): void
    {
        try {
            $stmt = $dbh->prepare("
                INSERT INTO M_GIRIS_LOG
                  (KULLANICI_ID, KULLANICI_ADI, ISLEM_TIPI, BASARILI, IP_ADRESI, TARAYICI, TARIH, ACIKLAMA)
                VALUES
                  (:id, :adi, 'SALDIRI_MODU', 1, :ip, :ua, GETDATE(), :ack)
            ");
            $stmt->execute([
                ':id'  => $adminId,
                ':adi' => $adminAdi,
                ':ip'  => $ip,
                ':ua'  => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '',
                ':ack' => "Saldiri modu {$islem} ({$adminAdi})",
            ]);
        } catch (Throwable $e) {
            error_log("saldiri_modu_audit hatasi: " . $e->getMessage());
        }
    }
}

// ============================================================
// Aktif sekme (sunucu-taraflı, whitelist)
// ============================================================
$activeTab = $_GET['tab'] ?? 'kilit';
if (!in_array($activeTab, ['kilit', 'saldiri'], true)) {
    $activeTab = 'kilit';
}

$durum      = null; // 'ok' | 'info' | 'err'
$durumMesaj = '';

// Saldırı modu için bağlantı IP'si / yerel-ağ tespiti (her iki render'da da gerekebilir)
$istemciIp = getUserIP();
$yerelMi   = ip_yerel_mi($istemciIp);

// ============================================================
// POST handler (tek blok) — action'a göre dallan
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ayar_require_csrf();

    $action   = (string) $_POST['action'];
    $adminId  = (int) ($terminalkullanici ?? 0);
    $adminAdi = (string) ($_SESSION['kullanici_adi'] ?? '');

    // ---- HESAP KİLİDİ: kilidi aç ----
    if ($action === 'kilit_ac') {
        // Kullanıcı adını giris.php'deki normalize_login_username ile aynı şekilde temizle
        $hedef = strtoupper(trim((string) ($_POST['kullanici'] ?? '')));
        $hedef = preg_replace('/[^A-Z0-9_.-]/', '', $hedef) ?? '';

        if ($hedef === '') {
            header('Location: guvenlik.php?tab=kilit&durum=bos', true, 303);
            exit;
        }

        try {
            // 1) Kilidi aç: kilit penceresindeki (son 15 dk) başarısız giriş denemelerini sil.
            //    Bu sayede giris.php'deki kilit sorgusu o hesap için 0 sayar ve kilit kalkar.
            //    DATEADD dakika argümanı literal gömülür (PDO sqlsrv parametre kabul etmez).
            $del = $dbh->prepare("
                DELETE FROM M_GIRIS_LOG
                WHERE KULLANICI_ADI = :kullanici
                  AND ISLEM_TIPI = 'GIRIS'
                  AND BASARILI = 0
                  AND TARIH > DATEADD(MINUTE, -" . KILIT_SURE_DK . ", GETDATE())
            ");
            $del->bindValue(':kullanici', $hedef);
            $del->execute();
            $silinen = $del->rowCount();

            // 2) Audit: kilidi kimin, kimi, ne zaman açtığını kaydet (KILIT_ACMA tipi).
            $ins = $dbh->prepare("
                INSERT INTO M_GIRIS_LOG
                  (KULLANICI_ID, KULLANICI_ADI, ISLEM_TIPI, BASARILI, IP_ADRESI, TARAYICI, TARIH, ACIKLAMA)
                VALUES
                  (:adminId, :hedef, 'KILIT_ACMA', 1, :ip, :ua, GETDATE(), :aciklama)
            ");
            $ins->bindValue(':adminId', $adminId, PDO::PARAM_INT);
            $ins->bindValue(':hedef', $hedef);
            $ins->bindValue(':ip', $istemciIp);
            $ins->bindValue(':ua', isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : '');
            $ins->bindValue(':aciklama', "Hesap kilidi '{$adminAdi}' tarafindan acildi ({$silinen} hatali deneme temizlendi)");
            $ins->execute();

            $redirectDurum = $silinen > 0 ? 'acildi' : 'zaten_acik';
            header('Location: guvenlik.php?tab=kilit&durum=' . $redirectDurum . '&k=' . urlencode($hedef), true, 303);
            exit;
        } catch (Throwable $e) {
            error_log("Kilit acma hatasi: " . $e->getMessage());
            header('Location: guvenlik.php?tab=kilit&durum=hata', true, 303);
            exit;
        }
    }

    // ---- SALDIRI MODU: aç ----
    if ($action === 'ac') {
        // KRİTİK: Saldırı modu yalnızca yerel ağdan açılabilir. Aksi halde modu açan
        // admin (public IP'de) bir sonraki istekte kendini dışarıda bırakır.
        if (!$yerelMi) {
            header('Location: guvenlik.php?tab=saldiri&durum=uzak_yasak', true, 303);
            exit;
        }
        saldiri_modu_ayarla(true, $adminId, $adminAdi);

        // Tüm "beni hatırla" token'larını iptal et (çalınmış olabilecek oturumları kapat)
        try {
            $dbh->exec("DELETE FROM M_REMEMBER_TOKEN");
        } catch (Throwable $e) {
            error_log("saldiri_modu token temizleme hatasi: " . $e->getMessage());
        }

        saldiri_modu_audit($dbh, $adminId, $adminAdi, 'ACILDI', $istemciIp);
        header('Location: guvenlik.php?tab=saldiri&durum=acildi', true, 303);
        exit;
    }

    // ---- SALDIRI MODU: kapat ----
    if ($action === 'kapat') {
        saldiri_modu_ayarla(false, $adminId, $adminAdi);
        saldiri_modu_audit($dbh, $adminId, $adminAdi, 'KAPATILDI', $istemciIp);
        header('Location: guvenlik.php?tab=saldiri&durum=kapandi', true, 303);
        exit;
    }
}

// ============================================================
// GET: durum mesajını çöz
// ============================================================
if (isset($_GET['durum'])) {
    $k = htmlspecialchars((string) ($_GET['k'] ?? ''), ENT_QUOTES, 'UTF-8');
    switch ($_GET['durum']) {
        // Hesap kilidi durumları
        case 'acildi':
            if ($activeTab === 'saldiri') {
                $durum = 'ok';
                $durumMesaj = 'Saldırı modu açıldı. Sistem artık yalnızca yerel ağdan erişilebilir; tüm "beni hatırla" oturumları kapatıldı.';
            } else {
                $durum = 'ok';
                $durumMesaj = "<strong>{$k}</strong> hesabının kilidi açıldı. Kullanıcı artık giriş yapabilir.";
            }
            break;
        case 'zaten_acik': $durum = 'info'; $durumMesaj = "<strong>{$k}</strong> için aktif kilit bulunamadı (hesap zaten açık)."; break;
        case 'bos':        $durum = 'err';  $durumMesaj = "Kullanıcı adı boş olamaz."; break;
        case 'hata':       $durum = 'err';  $durumMesaj = "İşlem sırasında bir hata oluştu. Lütfen tekrar deneyin."; break;
        // Saldırı modu durumları
        case 'kapandi':    $durum = 'ok';   $durumMesaj = 'Saldırı modu kapatıldı. Sistem normal çalışmaya döndü.'; break;
        case 'uzak_yasak': $durum = 'err';  $durumMesaj = 'Saldırı modunu yalnızca yerel ağdan (ofis ağı) açabilirsiniz. Şu anki bağlantınız yerel ağ dışında görünüyor; kendinizi kilitlememeniz için işlem engellendi.'; break;
    }
}

// ============================================================
// HESAP KİLİDİ verisi: kilitli hesapları listele
// (giris.php'deki kilit mantığının aynısı, tüm kullanıcılar için)
// Yalnızca kilit sekmesi aktifken sorgula.
// ============================================================
$kilitliler = [];
$listeHata  = false;
if ($activeTab === 'kilit') {
    try {
        $stmt = $dbh->query("
            SELECT g.KULLANICI_ADI,
                   COUNT(*)            AS basarisiz,
                   MAX(g.TARIH)        AS son_deneme,
                   MAX(g.IP_ADRESI)    AS son_ip,
                   DATEDIFF(SECOND, GETDATE(), DATEADD(MINUTE, " . KILIT_SURE_DK . ", MAX(g.TARIH))) AS kalan_saniye
            FROM M_GIRIS_LOG g
            WHERE g.ISLEM_TIPI = 'GIRIS'
              AND g.BASARILI = 0
              AND g.TARIH > DATEADD(MINUTE, -" . KILIT_SURE_DK . ", GETDATE())
              AND g.TARIH > ISNULL((
                    SELECT MAX(b.TARIH) FROM M_GIRIS_LOG b
                    WHERE b.KULLANICI_ADI = g.KULLANICI_ADI
                      AND b.ISLEM_TIPI = 'GIRIS'
                      AND b.BASARILI = 1
                  ), CONVERT(datetime, '1900-01-01'))
            GROUP BY g.KULLANICI_ADI
            HAVING COUNT(*) >= " . KILIT_ESIK . "
            ORDER BY MAX(g.TARIH) DESC
        ");
        $kilitliler = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("Kilitli liste hatasi: " . $e->getMessage());
        $listeHata = true;
    }
}

// ============================================================
// SALDIRI MODU verisi: mevcut durum
// ============================================================
$modDurum = saldiri_modu_durum();
$aktif    = $modDurum['aktif'] === true;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Güvenlik</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
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
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; background: var(--bg); color: var(--text-1); min-height: 100vh; }

        /* ═══════ HEADER ═══════ */
        .top-header {
            position: sticky; top: 0; z-index: 40; height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner { max-width: 880px; margin: 0 auto; height: 100%; display: flex; align-items: center; gap: 14px; padding: 0 24px; }
        .header-back { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; color: var(--text-2); text-decoration: none; transition: all 0.2s ease; }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; color: var(--text-1); display: inline-flex; align-items: center; gap: 8px; }
        .header-title i { color: var(--red,#ef4444); font-size: 16px; }

        main { max-width: 880px; margin: 0 auto; padding: 22px 24px 60px; }

        /* ═══════ TAB NAV ═══════ */
        .tab-nav {
            display: flex; gap: 6px; margin-bottom: 20px;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 14px; padding: 6px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .tab-nav a {
            flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 14px; border-radius: 10px; text-decoration: none;
            font-size: 13.5px; font-weight: 600; color: var(--text-2);
            transition: all 0.18s ease;
        }
        .tab-nav a i { font-size: 14px; }
        .tab-nav a:hover { background: rgba(0,0,0,0.03); color: var(--text-1); }
        .tab-nav a.active { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: var(--red); box-shadow: 0 2px 8px rgba(111,16,34,0.08); }
        .tab-nav a.active i { color: var(--red); }

        /* ═══════ HERO ═══════ */
        .hero-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 20px 22px; margin-bottom: 18px;
            display: flex; align-items: center; gap: 16px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .hero-card .hero-ico { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, #fef2f2, #fee2e2); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .hero-card .hero-text h1 { font-size: 18px; font-weight: 700; line-height: 1.2; }
        .hero-card .hero-text p { margin-top: 4px; font-size: 12px; color: var(--text-2); }

        /* ═══════ DURUM BAR ═══════ */
        .durum-bar { display: flex; align-items: center; gap: 10px; padding: 13px 16px; border-radius: 12px; margin-bottom: 18px; font-size: 13px; font-weight: 500; animation: cardIn 0.35s ease both; }
        .durum-bar i { font-size: 15px; flex-shrink: 0; }
        .durum-ok   { background: var(--emerald-soft); border: 1px solid rgba(5,150,105,0.25); color: #065f46; }
        .durum-info { background: var(--sky-soft);     border: 1px solid rgba(2,132,199,0.22);  color: #075985; }
        .durum-err  { background: var(--red-soft);     border: 1px solid rgba(111,16,34,0.22);  color: #991b1b; }

        /* ═══════ PANEL (hesap kilidi) ═══════ */
        .panel {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            padding: 20px 22px; margin-bottom: 18px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .panel-head { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
        .panel-head .p-ico { width: 38px; height: 38px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
        .panel-head.tone-amber .p-ico { background: var(--amber-soft); color: var(--amber); }
        .panel-head.tone-emerald .p-ico { background: var(--emerald-soft); color: var(--emerald); }
        .panel-head h2 { font-size: 15px; font-weight: 700; }
        .panel-head p { font-size: 11.5px; color: var(--text-2); margin-top: 1px; }

        /* ═══════ MANUEL FORM (hesap kilidi) ═══════ */
        .manuel-form { display: flex; gap: 10px; flex-wrap: wrap; }
        .manuel-form input[type="text"] {
            flex: 1; min-width: 200px; padding: 12px 14px;
            border: 1px solid var(--border); border-radius: 11px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 16px; font-weight: 500;
            color: var(--text-1); background: #fff; outline: none; text-transform: uppercase;
            transition: all 0.2s ease;
        }
        .manuel-form input[type="text"]:focus { border-color: rgba(5,150,105,0.5); box-shadow: 0 0 0 3px rgba(5,150,105,0.1); }
        /* .btn-ac burada YEŞİL "kilidi aç" butonu (hesap kilidi). Saldırı modunun
           kırmızı "modu aç" butonu .sm-btn-ac olarak ayrı tanımlandı (çakışma önlendi). */
        .btn-ac {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 20px; border: none; border-radius: 11px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13.5px; font-weight: 600; color: #fff;
            background: var(--emerald); cursor: pointer; white-space: nowrap;
            box-shadow: 0 4px 14px rgba(5,150,105,0.25); transition: all 0.2s ease;
        }
        .btn-ac:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(5,150,105,0.32); }
        .btn-ac:active { transform: translateY(0); }
        .btn-ac.sm { padding: 9px 16px; font-size: 12.5px; }

        /* ═══════ KILITLI LISTE (hesap kilidi) ═══════ */
        .kilit-row {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 16px; border: 1px solid var(--border); border-radius: 12px;
            margin-bottom: 10px; background: #fff; transition: all 0.2s ease;
        }
        .kilit-row:last-child { margin-bottom: 0; }
        .kilit-row:hover { border-color: rgba(111,16,34,0.3); box-shadow: 0 4px 14px rgba(111,16,34,0.06); }
        .kilit-avatar { width: 44px; height: 44px; border-radius: 12px; background: var(--red-soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .kilit-info { flex: 1; min-width: 0; }
        .kilit-info .ku { font-size: 14.5px; font-weight: 700; color: var(--text-1); }
        .kilit-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; margin-top: 4px; font-size: 11.5px; color: var(--text-2); }
        .kilit-meta span { display: inline-flex; align-items: center; gap: 5px; }
        .kilit-meta i { font-size: 11px; color: var(--text-3); }
        .badge-fail { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; background: var(--red-soft); color: var(--red); font-size: 11px; font-weight: 700; }
        .badge-time { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; background: var(--amber-soft); color: var(--amber); font-size: 11px; font-weight: 600; }

        /* ═══════ BOŞ DURUM (hesap kilidi) ═══════ */
        .empty { text-align: center; padding: 30px 16px; color: var(--text-2); }
        .empty .e-ico { width: 60px; height: 60px; margin: 0 auto 12px; border-radius: 16px; background: var(--emerald-soft); color: var(--emerald); display: inline-flex; align-items: center; justify-content: center; font-size: 26px; }
        .empty h3 { font-size: 14px; font-weight: 600; color: var(--text-1); }
        .empty p { font-size: 12px; margin-top: 4px; }

        /* ═══════ DURUM KARTI (saldırı modu) ═══════ */
        .status-card {
            border-radius: 20px; padding: 30px 26px; margin-bottom: 20px; text-align: center;
            border: 1px solid var(--border); background: #fff;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .status-card.is-active {
            background: linear-gradient(180deg, #fef2f2, #fff);
            border-color: rgba(111,16,34,0.35);
            box-shadow: 0 14px 40px rgba(111,16,34,0.12);
        }
        .status-ico {
            width: 88px; height: 88px; border-radius: 24px; margin: 0 auto 18px;
            display: inline-flex; align-items: center; justify-content: center; font-size: 40px;
        }
        .is-active .status-ico { background: rgba(111,16,34,0.12); color: var(--red); animation: pulse 1.8s ease-in-out infinite; }
        .is-idle .status-ico  { background: var(--emerald-soft); color: var(--emerald); }
        .status-card h1 { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
        .is-active h1 { color: var(--red); }
        .is-idle h1  { color: var(--emerald); }
        .status-card .sub { font-size: 13px; color: var(--text-2); line-height: 1.6; max-width: 480px; margin: 0 auto; }
        .status-meta { margin-top: 16px; display: inline-flex; flex-wrap: wrap; justify-content: center; gap: 8px 16px; font-size: 12px; color: var(--text-2); }
        .status-meta span { display: inline-flex; align-items: center; gap: 6px; }
        .status-meta i { color: var(--text-3); }

        /* ═══════ TOGGLE ALANI (saldırı modu) ═══════ */
        .action-card {
            background: #fff; border: 1px solid var(--border); border-radius: 16px;
            padding: 22px; margin-bottom: 18px; animation: cardIn 0.4s ease both;
        }
        .ip-info { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 12px; margin-bottom: 18px; font-size: 13px; }
        .ip-local  { background: var(--emerald-soft); color: #065f46; border: 1px solid rgba(5,150,105,0.2); }
        .ip-remote { background: var(--amber-soft); color: #92400e; border: 1px solid rgba(217,119,6,0.25); }
        .ip-info i { font-size: 18px; }
        .ip-info .mono { font-weight: 700; font-family: ui-monospace, monospace; }

        .btn-big {
            width: 100%; padding: 16px; border: none; border-radius: 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 15px; font-weight: 700; color: #fff;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 10px;
            transition: all 0.2s ease;
        }
        /* sm- prefix: saldırı modu butonları (hesap kilidinin .btn-ac yeşiliyle çakışmasın) */
        .sm-btn-ac   { background: var(--red); box-shadow: 0 8px 22px rgba(111,16,34,0.3); }
        .sm-btn-ac:hover { background: #b91c1c; transform: translateY(-1px); }
        .btn-kapat { background: var(--emerald); box-shadow: 0 8px 22px rgba(5,150,105,0.28); }
        .btn-kapat:hover { background: #047857; transform: translateY(-1px); }
        .btn-disabled { background: #cbd5e1; color: #64748b; cursor: not-allowed; box-shadow: none; }
        .btn-disabled:hover { transform: none; }

        /* ═══════ ETKİ LİSTESİ (saldırı modu) ═══════ */
        .effects { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 20px 22px; animation: cardIn 0.4s ease both; }
        .effects h3 { font-size: 14px; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .effects h3 i { color: var(--red); }
        .effects ul { list-style: none; padding: 0; margin: 0; }
        .effects li { display: flex; align-items: flex-start; gap: 11px; padding: 9px 0; font-size: 13px; color: var(--text-1); border-bottom: 1px dashed var(--border); }
        .effects li:last-child { border-bottom: none; }
        .effects li i { color: var(--red); font-size: 13px; margin-top: 3px; flex-shrink: 0; width: 16px; text-align: center; }
        .effects li b { font-weight: 600; }
        .effects li small { color: var(--text-2); display: block; margin-top: 1px; }

        .warn-note { margin-top: 16px; padding: 12px 14px; border-radius: 10px; background: var(--amber-soft); border: 1px solid rgba(217,119,6,0.2); font-size: 12px; color: #92400e; line-height: 1.6; display: flex; gap: 9px; }
        .warn-note i { font-size: 14px; flex-shrink: 0; margin-top: 1px; }

        @keyframes cardIn { from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); } to { opacity: 1; transform: translate3d(0,0,0) scale(1); } }
        @keyframes pulse { 0%,100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.06); opacity: 0.85; } }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            main { padding: 14px 12px 40px; }
            .tab-nav a { padding: 10px 8px; font-size: 12.5px; }
            .hero-card { padding: 16px; gap: 12px; }
            .hero-card .hero-ico { width: 46px; height: 46px; font-size: 18px; }
            .hero-card .hero-text h1 { font-size: 15px; }
            .panel { padding: 16px; }
            .kilit-row { flex-wrap: wrap; }
            .kilit-row form { width: 100%; }
            .btn-ac.sm { width: 100%; }
            .status-card { padding: 24px 18px; }
            .status-ico { width: 72px; height: 72px; font-size: 32px; }
            .status-card h1 { font-size: 19px; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Yönetim Paneli">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-shield-halved"></i>Güvenlik
            </span>
        </div>
    </header>

    <main>

        <!-- Sekme navigasyonu -->
        <nav class="tab-nav">
            <a href="guvenlik.php?tab=kilit" class="<?php echo $activeTab === 'kilit' ? 'active' : ''; ?>">
                <i class="fa-solid fa-unlock-keyhole"></i> Hesap Kilidi
            </a>
            <a href="guvenlik.php?tab=saldiri" class="<?php echo $activeTab === 'saldiri' ? 'active' : ''; ?>">
                <i class="fa-solid fa-tower-broadcast"></i> Saldırı Modu
            </a>
        </nav>

        <!-- Durum mesajı (her iki sekme için ortak) -->
        <?php if ($durum !== null && $durumMesaj !== ''): ?>
            <div class="durum-bar durum-<?php echo $durum; ?>">
                <i class="fa-solid <?php echo $durum === 'ok' ? 'fa-circle-check' : ($durum === 'err' ? 'fa-circle-exclamation' : 'fa-circle-info'); ?>"></i>
                <span><?php echo $durumMesaj; /* sabit metin + escape edilmiş kullanıcı adı */ ?></span>
            </div>
        <?php endif; ?>

        <?php if ($activeTab === 'kilit'): ?>
        <!-- ============================================================ -->
        <!-- SEKME: HESAP KİLİDİ                                          -->
        <!-- ============================================================ -->

            <!-- Hero -->
            <div class="hero-card">
                <span class="hero-ico"><i class="fa-solid fa-user-lock"></i></span>
                <div class="hero-text">
                    <h1>Hesap Kilidi Yönetimi</h1>
                    <p><?php echo KILIT_ESIK; ?> kez hatalı şifre giren hesaplar <?php echo KILIT_SURE_DK; ?> dakika geçici kilitlenir. Buradan kilidi elle açabilirsiniz.</p>
                </div>
            </div>

            <!-- Manuel kilit açma -->
            <div class="panel">
                <div class="panel-head tone-emerald">
                    <span class="p-ico"><i class="fa-solid fa-key"></i></span>
                    <div>
                        <h2>Kullanıcı Adı ile Aç</h2>
                        <p>Kilitlenen çalışanın kullanıcı adını yazıp kilidini sıfırlayın</p>
                    </div>
                </div>
                <form method="POST" action="guvenlik.php?tab=kilit" class="manuel-form" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="kilit_ac">
                    <input type="text" name="kullanici" placeholder="Örn: AHMET" required
                           autocapitalize="characters" spellcheck="false" pattern="[A-Za-z0-9_.\-]*">
                    <button type="submit" class="btn-ac"><i class="fa-solid fa-unlock"></i> Kilidi Aç</button>
                </form>
            </div>

            <!-- Kilitli hesaplar -->
            <div class="panel">
                <div class="panel-head tone-amber">
                    <span class="p-ico"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div>
                        <h2>Şu An Kilitli Hesaplar</h2>
                        <p>Son <?php echo KILIT_SURE_DK; ?> dakikada <?php echo KILIT_ESIK; ?>+ kez hatalı giriş yapılmış hesaplar</p>
                    </div>
                </div>

                <?php if ($listeHata): ?>
                    <div class="durum-bar durum-err" style="margin:0;">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>Kilitli hesaplar listelenirken bir hata oluştu (giriş kayıt tablosu erişilemedi olabilir).</span>
                    </div>
                <?php elseif ($kilitliler === []): ?>
                    <div class="empty">
                        <div class="e-ico"><i class="fa-solid fa-circle-check"></i></div>
                        <h3>Kilitli hesap yok</h3>
                        <p>Şu anda geçici olarak kilitlenmiş bir hesap bulunmuyor.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($kilitliler as $row):
                        $ku        = htmlspecialchars((string) $row['KULLANICI_ADI'], ENT_QUOTES, 'UTF-8');
                        $basarisiz = (int) $row['basarisiz'];
                        $kalan     = kilit_kalan_metin((int) $row['kalan_saniye']);
                        $sonIp     = htmlspecialchars((string) ($row['son_ip'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $sonZaman  = htmlspecialchars((string) ($row['son_deneme'] ?? ''), ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="kilit-row">
                            <span class="kilit-avatar"><i class="fa-solid fa-user-lock"></i></span>
                            <div class="kilit-info">
                                <div class="ku"><?php echo $ku; ?></div>
                                <div class="kilit-meta">
                                    <span class="badge-fail"><i class="fa-solid fa-xmark"></i><?php echo $basarisiz; ?> hatalı</span>
                                    <span class="badge-time"><i class="fa-solid fa-hourglass-half"></i><?php echo $kalan; ?> kaldı</span>
                                    <?php if ($sonIp !== ''): ?><span><i class="fa-solid fa-network-wired"></i><?php echo $sonIp; ?></span><?php endif; ?>
                                    <?php if ($sonZaman !== ''): ?><span><i class="fa-solid fa-clock"></i><?php echo $sonZaman; ?></span><?php endif; ?>
                                </div>
                            </div>
                            <form method="POST" action="guvenlik.php?tab=kilit" onsubmit="return confirm('<?php echo $ku; ?> hesabının kilidi açılsın mı?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="kilit_ac">
                                <input type="hidden" name="kullanici" value="<?php echo $ku; ?>">
                                <button type="submit" class="btn-ac sm"><i class="fa-solid fa-unlock"></i> Kilidi Aç</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php else: ?>
        <!-- ============================================================ -->
        <!-- SEKME: SALDIRI MODU                                         -->
        <!-- ============================================================ -->

            <!-- Durum kartı -->
            <div class="status-card <?php echo $aktif ? 'is-active' : 'is-idle'; ?>">
                <div class="status-ico">
                    <i class="fa-solid <?php echo $aktif ? 'fa-triangle-exclamation' : 'fa-shield-halved'; ?>"></i>
                </div>
                <h1><?php echo $aktif ? 'SALDIRI MODU AKTİF' : 'Sistem Normal'; ?></h1>
                <p class="sub">
                    <?php if ($aktif): ?>
                        Sistem şu anda yalnızca yerel ağdan erişilebilir. İnternet üzerinden tüm giriş ve portal erişimi engelleniyor.
                    <?php else: ?>
                        Sistem normal modda çalışıyor. Saldırı veya yoğun hatalı giriş durumunda aşağıdan acil modu etkinleştirebilirsiniz.
                    <?php endif; ?>
                </p>
                <?php if ($aktif): ?>
                    <div class="status-meta">
                        <?php if (!empty($modDurum['zaman'])): ?><span><i class="fa-solid fa-clock"></i><?php echo htmlspecialchars((string) $modDurum['zaman'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        <?php if (!empty($modDurum['acan_adi'])): ?><span><i class="fa-solid fa-user-shield"></i><?php echo htmlspecialchars((string) $modDurum['acan_adi'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        <?php if (!empty($modDurum['ip'])): ?><span><i class="fa-solid fa-network-wired"></i><?php echo htmlspecialchars((string) $modDurum['ip'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Aksiyon -->
            <div class="action-card">
                <div class="ip-info <?php echo $yerelMi ? 'ip-local' : 'ip-remote'; ?>">
                    <i class="fa-solid <?php echo $yerelMi ? 'fa-house-laptop' : 'fa-globe'; ?>"></i>
                    <div>
                        Bağlantınız: <span class="mono"><?php echo htmlspecialchars($istemciIp, ENT_QUOTES, 'UTF-8'); ?></span>
                        — <?php echo $yerelMi ? '<b>yerel ağ</b> (modu açabilirsiniz)' : '<b>yerel ağ dışı</b> (modu açamazsınız)'; ?>
                    </div>
                </div>

                <?php if ($aktif): ?>
                    <form method="POST" action="guvenlik.php?tab=saldiri" onsubmit="return confirm('Saldırı modunu KAPATMAK istediğinize emin misiniz? Sistem normal erişime dönecek.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="kapat">
                        <button type="submit" class="btn-big btn-kapat"><i class="fa-solid fa-unlock"></i> Saldırı Modunu Kapat</button>
                    </form>
                <?php elseif ($yerelMi): ?>
                    <form method="POST" action="guvenlik.php?tab=saldiri" onsubmit="return confirm('Saldırı modunu AÇMAK üzeresiniz. Sisteme internet üzerinden erişim kesilecek, tüm beni-hatırla oturumları kapanacak. Devam edilsin mi?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="ac">
                        <button type="submit" class="btn-big sm-btn-ac"><i class="fa-solid fa-tower-broadcast"></i> Saldırı Modunu Aç</button>
                    </form>
                <?php else: ?>
                    <button type="button" class="btn-big btn-disabled" disabled><i class="fa-solid fa-ban"></i> Açmak için yerel ağdan bağlanın</button>
                <?php endif; ?>

                <div class="warn-note">
                    <i class="fa-solid fa-circle-info"></i>
                    <span>Saldırı modu yalnızca <b>yerel ağdan (ofis)</b> açılıp kapatılabilir; bu, sizi yanlışlıkla dışarıda bırakmamak içindir. Acil durumda sunucudan <span class="mono">tmp/saldiri_modu.json</span> dosyasını silmek de modu kapatır.</span>
                </div>
            </div>

            <!-- Etkiler -->
            <div class="effects">
                <h3><i class="fa-solid fa-bolt"></i> Mod açıkken neler değişir?</h3>
                <ul>
                    <li><i class="fa-solid fa-globe"></i><div><b>Yalnızca yerel ağ</b><small>İnternet/dış erişim tamamen kapanır</small></div></li>
                    <li><i class="fa-solid fa-lock"></i><div><b>Sertleştirilmiş kilit</b><small>Hesap kilidi 2 hatalı denemede devreye girer, süre 60 dakikaya çıkar</small></div></li>
                    <li><i class="fa-solid fa-key"></i><div><b>Beni hatırla iptal</b><small>Tüm otomatik giriş oturumları kapatılır, herkes şifre girer</small></div></li>
                    <li><i class="fa-solid fa-store-slash"></i><div><b>Müşteri portalı kapalı</b><small>Müşteri girişi geçici olarak hizmet dışı bırakılır</small></div></li>
                </ul>
            </div>

        <?php endif; ?>

    </main>

</body>
</html>
