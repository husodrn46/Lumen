<?php
declare(strict_types=1);

/**
 * rapor_finans_kartlar.php — "Kasa Matrisi" (yeniden tasarım 2026-07-07).
 *
 * Kasa bazlı aylık GİRİŞ karşılaştırması (KSLINES SIGN=0, TRCODE=11 — POS/tahsilat).
 * Eski: çok-serili Chart.js + kasa başına KPI kartları + 2 ayrı tablo.
 * Yeni: grafik YOK (kullanıcı tercihi: liste konuşsun) — tek AY × KASA MATRİSİ,
 * hücre içi renk-dolgu (kasanın kendi zirvesine oranla) grafiği tablonun içine gömer;
 * altında KASA KARNESİ şeridi (yıl toplamları + YoY + pay), üstte kompakt vurgular.
 * Veri sorgusu ve kasa seçim mantığı korunmuştur. Kimlik: Lumen bordosu.
 */

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../_baglanti_.inc");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . '/../kontrol.php';

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$secilen_yil = isset($_GET['yil']) ? (int) $_GET['yil'] : (int) date('Y');
$karsilastirma_yili_sayisi = 3;
$baslangic_yili = $secilen_yil - ($karsilastirma_yili_sayisi - 1);

$baslangic_tarihi = $baslangic_yili . '-01-01';
$bitis_tarihi = $secilen_yil . '-12-31';

// Varsayılan kasalar — TRCODE=11 hareketi en yoğun gerçek kasalar (2026-07-07 düzeltme:
// eski [2,1019,1020,1021] listesindeki 1019-1021 KSCARD'da YOKTU, sayfa tek kasayla açılıyordu)
$varsayilan_kasa_ids = [4, 15, 1, 3];
$kasa_secim_uyari = '';

$secili_kasa_ham = $_GET['kasa'] ?? $varsayilan_kasa_ids;
if (!is_array($secili_kasa_ham)) {
    $secili_kasa_ham = [$secili_kasa_ham];
}

$secili_kasa_ids = [];
foreach ($secili_kasa_ham as $kasa_raw) {
    $kasa_id = (int) $kasa_raw;
    if ($kasa_id > 0) {
        $secili_kasa_ids[$kasa_id] = $kasa_id;
    }
}
$secili_kasa_ids = array_values($secili_kasa_ids);

$tum_kasalar = [];
$kasa_isimleri = [];
try {
    $kasa_sorgu = $dbh->prepare("SELECT LOGICALREF, CODE, NAME FROM {$firma}KSCARD ORDER BY CODE");
    $kasa_sorgu->execute();
    while ($kasa = $kasa_sorgu->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $kasa['LOGICALREF'];
        $tum_kasalar[$id] = ['CODE' => (string) $kasa['CODE'], 'NAME' => (string) $kasa['NAME']];
    }
} catch (PDOException $e) {
    die("Veritabanı hatası (Kasa isimleri): " . $e->getMessage());
}

if ($tum_kasalar !== []) {
    $kasa_ids = array_values(array_filter($secili_kasa_ids, static fn($id): bool => isset($tum_kasalar[$id])));
    if ($kasa_ids === []) {
        $kasa_ids = array_values(array_filter($varsayilan_kasa_ids, static fn($id): bool => isset($tum_kasalar[$id])));
        if ($kasa_ids === []) {
            $kasa_ids = array_slice(array_keys($tum_kasalar), 0, 4);
        }
    }
    if (count($kasa_ids) > 12) {
        $kasa_ids = array_slice($kasa_ids, 0, 12);
        $kasa_secim_uyari = 'En fazla 12 kasa gösterilebilir. İlk 12 seçim kullanıldı.';
    }
    foreach ($kasa_ids as $kasa_id) {
        $kasa_isimleri[$kasa_id] = $tum_kasalar[$kasa_id];
    }
} else {
    $kasa_ids = [];
    $kasa_secim_uyari = 'Kasa kartı bulunamadı.';
}

$secili_kasa_sayisi = count($kasa_ids);

// Her kasa için veri (sadece GİREN: SIGN=0, TRCODE=11)
$kasa_verileri = [];
try {
    foreach ($kasa_ids as $kasa_id) {
        $sorgu = $dbh->prepare("
            SELECT YEAR(KSLINES.DATE_) AS YIL, MONTH(KSLINES.DATE_) AS AY, SUM(KSLINES.AMOUNT) AS TUTAR
            FROM {$firmadonem}KSLINES KSLINES
            JOIN {$firma}KSCARD KSCARD ON KSLINES.CARDREF = KSCARD.LOGICALREF
            WHERE KSCARD.LOGICALREF = :kasa_id
              AND KSLINES.SIGN = 0
              AND KSLINES.TRCODE = 11
              AND KSLINES.DATE_ BETWEEN :baslangic AND :bitis
            GROUP BY YEAR(KSLINES.DATE_), MONTH(KSLINES.DATE_)
            ORDER BY YIL, AY
        ");
        $sorgu->execute([':kasa_id' => $kasa_id, ':baslangic' => $baslangic_tarihi, ':bitis' => $bitis_tarihi]);
        $kasa_verileri[$kasa_id] = $sorgu->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("Veritabanı hatası: " . $e->getMessage());
}

$turkce_aylar = [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];
$yillar = range($baslangic_yili, $secilen_yil);

// Kasa bazlı ay×yıl + yıllık toplamlar + seçili yıl serisi
$tum_kasalar_karsilastirma = [];
$tum_kasalar_yillik_toplamlar = [];
$tum_kasalar_secilen_yil = [];

foreach ($kasa_ids as $kasa_id) {
    $karsilastirma_verileri = [];
    $yillik_toplamlar = [];
    foreach ($yillar as $y) { $yillik_toplamlar[$y] = 0; }

    foreach ($kasa_verileri[$kasa_id] as $satir) {
        $yil = (int) $satir['YIL'];
        $ay = (int) $satir['AY'];
        $tutar = (float) $satir['TUTAR'];
        $karsilastirma_verileri[$ay][$yil] = $tutar;
        $yillik_toplamlar[$yil] += $tutar;
    }

    $secilen_yil_verileri = [];
    for ($ay = 1; $ay <= 12; $ay++) {
        $secilen_yil_verileri[$ay] = $karsilastirma_verileri[$ay][$secilen_yil] ?? 0;
    }

    $tum_kasalar_karsilastirma[$kasa_id] = $karsilastirma_verileri;
    $tum_kasalar_yillik_toplamlar[$kasa_id] = $yillik_toplamlar;
    $tum_kasalar_secilen_yil[$kasa_id] = $secilen_yil_verileri;
}

// Görünür yıllar (veri olan + seçili)
$toplam_yillik_tum = [];
foreach ($yillar as $yil) {
    $toplam_yillik_tum[$yil] = 0.0;
    foreach ($kasa_ids as $kasa_id) {
        $toplam_yillik_tum[$yil] += (float) ($tum_kasalar_yillik_toplamlar[$kasa_id][$yil] ?? 0);
    }
}
$gorunur_yillar = [];
foreach ($yillar as $yil) {
    if ($yil === $secilen_yil || abs((float) ($toplam_yillik_tum[$yil] ?? 0)) > 0.00001) {
        $gorunur_yillar[] = $yil;
    }
}
if ($gorunur_yillar === []) { $gorunur_yillar = [$secilen_yil]; }
$onceki_yillar = array_values(array_filter($gorunur_yillar, static fn($y): bool => $y !== $secilen_yil));

// ── Matris hesapları ──
// Ay bazında tüm kasalar toplamı (seçili yıl) + kasa başına zirve ay (dolgu oranı için)
$ay_toplamlari = array_fill(1, 12, 0.0);
$kasa_zirve = [];
foreach ($kasa_ids as $kasa_id) {
    $max = 0.0;
    for ($ay = 1; $ay <= 12; $ay++) {
        $v = (float) $tum_kasalar_secilen_yil[$kasa_id][$ay];
        $ay_toplamlari[$ay] += $v;
        if ($v > $max) { $max = $v; }
    }
    $kasa_zirve[$kasa_id] = max(1.0, $max);
}
$genel_toplam = array_sum($ay_toplamlari);
$toplam_zirve = max(1.0, max($ay_toplamlari));

// Vurgular: en iyi kasa + en iyi ay + YoY
$en_iyi_kasa = ['id' => 0, 'tutar' => 0.0];
foreach ($kasa_ids as $kasa_id) {
    $t = (float) ($tum_kasalar_yillik_toplamlar[$kasa_id][$secilen_yil] ?? 0);
    if ($t > $en_iyi_kasa['tutar']) { $en_iyi_kasa = ['id' => $kasa_id, 'tutar' => $t]; }
}
$en_iyi_ay = ['ay' => 0, 'tutar' => 0.0];
for ($ay = 1; $ay <= 12; $ay++) {
    if ($ay_toplamlari[$ay] > $en_iyi_ay['tutar']) { $en_iyi_ay = ['ay' => $ay, 'tutar' => $ay_toplamlari[$ay]]; }
}
$onceki_yil = $secilen_yil - 1;
$onceki_toplam = (float) ($toplam_yillik_tum[$onceki_yil] ?? 0);
$yoyGenel = $onceki_toplam > 0.005 ? 100 * ($genel_toplam - $onceki_toplam) / $onceki_toplam : null;

// Kasa görsel paleti (nokta + hücre dolgusu)
$palet = [
    ['nokta' => '#0284c7', 'dolgu' => 'rgba(2,132,199,.16)',  'ikon' => 'fa-building-columns'],
    ['nokta' => '#059669', 'dolgu' => 'rgba(5,150,105,.16)',  'ikon' => 'fa-vault'],
    ['nokta' => '#d97706', 'dolgu' => 'rgba(217,119,6,.18)',  'ikon' => 'fa-credit-card'],
    ['nokta' => '#7c3aed', 'dolgu' => 'rgba(124,58,237,.15)', 'ikon' => 'fa-wallet'],
    ['nokta' => '#e11d48', 'dolgu' => 'rgba(225,29,72,.14)',  'ikon' => 'fa-money-bill-wave'],
    ['nokta' => '#0d9488', 'dolgu' => 'rgba(13,148,136,.16)', 'ikon' => 'fa-coins'],
];
$kasa_gorsel = [];
$ri = 0;
foreach ($kasa_ids as $kasa_id) {
    $kasa_gorsel[$kasa_id] = $palet[$ri % count($palet)];
    $ri++;
}

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$para = static fn($x): string => number_format((float) $x, 2, ',', '.');
$kisa = static function ($v): string {
    $v = (float) $v;
    if (abs($v) >= 1_000_000) { return number_format($v / 1_000_000, 2, ',', '.') . ' M'; }
    if (abs($v) >= 1_000)     { return number_format($v / 1_000, 0, ',', '.') . ' B'; }
    return number_format($v, 0, ',', '.');
};
$yoyHtml = static function (float $simdiki, float $onceki): string {
    if ($onceki < 0.005) { return '<span class="yoy notr">&mdash;</span>'; }
    $v = 100 * ($simdiki - $onceki) / $onceki;
    $s = number_format(abs($v), 1, ',', '.') . '%';
    if ($v > 0.05)  { return '<span class="yoy up"><i class="fa-solid fa-arrow-trend-up"></i>' . $s . '</span>'; }
    if ($v < -0.05) { return '<span class="yoy down"><i class="fa-solid fa-arrow-trend-down"></i>' . $s . '</span>'; }
    return '<span class="yoy notr">0%</span>';
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasa Matrisi — <?php echo $h($secilen_yil); ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f8f6f7; --card:#fff; --text-1:#1c1220; --text-2:#6d6276; --text-3:#9c92a5; --border:#ebe4ea;
            --red:#6F1022; --red-koyu:#7f1d1d; --red-orta:#b91c1c; --red-soft:#fdf0f0;
            --emerald:#059669; --amber:#d97706; --amber-soft:#fffbeb;
            --golge:0 12px 30px -20px rgba(28,18,32,.35);
        }
        * { box-sizing:border-box; margin:0; }
        body {
            font-family:'Avenir Next','Montserrat',sans-serif; color:var(--text-1); min-height:100vh; font-size:14px;
            background:
                radial-gradient(820px 280px at 100% -70px, rgba(111,16,34,.07), transparent 60%),
                radial-gradient(560px 220px at -60px 25%, rgba(111,16,34,.05), transparent 55%),
                var(--bg);
        }

        .top { position:sticky; top:0; z-index:40; background:rgba(255,255,255,.85); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-bottom:1px solid var(--border); }
        .top-in { max-width:1220px; margin:0 auto; padding:12px 22px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .geri { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; color:var(--text-2); background:#fff; border:1px solid var(--border); text-decoration:none; transition:.18s; }
        .geri:hover { color:var(--red); border-color:var(--red); transform:translateX(-2px); }
        .t-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:17px; background:linear-gradient(135deg,var(--red-orta),var(--red-koyu)); color:#fff; box-shadow:0 6px 14px -6px rgba(185,28,28,.5); }
        .t-baslik { min-width:0; }
        .t-baslik h1 { font-size:16px; font-weight:700; line-height:1.2; }
        .t-baslik p { font-size:11.5px; color:var(--text-2); }
        .aksiyon { margin-left:auto; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .yil-sec { font-family:inherit; font-size:13.5px; font-weight:700; color:var(--text-1); background:#fff; border:1px solid var(--border); border-radius:11px; padding:9px 12px; cursor:pointer; outline:none; transition:.18s; }
        .yil-sec:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.12); }
        .btn { display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:12.5px; font-weight:600; padding:10px 14px; border-radius:11px; cursor:pointer; text-decoration:none; border:1px solid var(--border); background:#fff; color:var(--text-2); transition:.18s; }
        .btn:hover { color:var(--red); border-color:var(--red); transform:translateY(-1px); }

        main { max-width:1220px; margin:0 auto; padding:20px 22px 56px; }

        /* ── Üst vurgu şeridi ── */
        .vurgular { display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:12px; margin-bottom:16px; }
        .vurgu { background:var(--card); border:1px solid var(--border); border-radius:15px; padding:13px 16px; display:flex; align-items:center; gap:12px; box-shadow:var(--golge); animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .vurgu:nth-child(2) { animation-delay:50ms; } .vurgu:nth-child(3) { animation-delay:100ms; } .vurgu:nth-child(4) { animation-delay:150ms; }
        .vurgu-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
        .vurgu.kir .vurgu-ico { background:var(--red-soft); color:var(--red); }
        .vurgu.alt .vurgu-ico { background:var(--amber-soft); color:var(--amber); }
        .vurgu.yes .vurgu-ico { background:#ecfdf5; color:var(--emerald); }
        .vurgu-l { font-size:10px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); }
        .vurgu-v { font-size:16.5px; font-weight:800; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vurgu-s { font-size:10.5px; color:var(--text-3); }
        .yoy { font-size:11px; font-weight:700; display:inline-flex; align-items:center; gap:4px; }
        .yoy.up { color:var(--emerald); } .yoy.down { color:var(--red); } .yoy.notr { color:var(--text-3); font-weight:500; }

        /* ── Kasa seçimi (details) ── */
        .filtre-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); margin-bottom:16px; overflow:hidden; animation:giris .5s cubic-bezier(.22,1,.36,1) .08s both; }
        .filtre-kart summary { list-style:none; cursor:pointer; padding:14px 18px; display:flex; align-items:center; gap:10px; font-size:13px; font-weight:700; }
        .filtre-kart summary::-webkit-details-marker { display:none; }
        .filtre-kart summary i.baslik-ico { color:var(--red); }
        .filtre-kart summary .adet { font-size:11px; font-weight:700; background:var(--red-soft); color:var(--red); padding:3px 10px; border-radius:99px; }
        .filtre-kart summary .cevir { margin-left:auto; color:var(--text-3); transition:transform .2s; }
        details[open] summary .cevir { transform:rotate(180deg); }
        .filtre-ic { border-top:1px solid var(--border); padding:14px 18px 16px; }
        .filtre-satir { display:flex; gap:10px; flex-wrap:wrap; }
        .ara-kutu { position:relative; flex:1 1 240px; }
        .ara-kutu i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:13px; }
        .ara-kutu input { width:100%; padding:11px 13px 11px 36px; font-family:inherit; font-size:14px; border:1px solid var(--border); border-radius:11px; outline:none; transition:.16s; }
        .ara-kutu input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.1); }
        .f-btn { display:inline-flex; align-items:center; gap:7px; padding:10px 15px; border-radius:11px; border:1px solid var(--border); background:#fff; color:var(--text-2); font-family:inherit; font-size:12.5px; font-weight:700; cursor:pointer; transition:.16s; }
        .f-btn:hover { border-color:var(--red); color:var(--red); }
        .f-btn.birincil { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 6px 14px -8px rgba(111,16,34,.55); }
        .f-btn.birincil:hover { background:var(--red-orta); color:#fff; }
        .uyari-kutu { margin-top:10px; padding:9px 12px; background:var(--amber-soft); color:var(--amber); border:1px solid rgba(217,119,6,.25); border-radius:10px; font-size:12px; }
        .kasa-secenekler { margin-top:12px; max-height:270px; overflow-y:auto; border:1px solid var(--border); border-radius:12px; padding:8px; display:grid; grid-template-columns:repeat(auto-fill,minmax(215px,1fr)); gap:6px; }
        .kasa-sec { display:flex; align-items:center; gap:8px; padding:9px 10px; border:1px solid var(--border); border-radius:9px; font-size:12.5px; cursor:pointer; transition:.14s; }
        .kasa-sec:hover { background:var(--red-soft); border-color:rgba(111,16,34,.3); }
        .kasa-sec input { width:16px; height:16px; flex-shrink:0; accent-color:var(--red); }
        .kasa-sec .kod { font-size:10.5px; font-weight:700; color:var(--text-3); }
        .kasa-sec .ad { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* ── AY × KASA MATRİSİ ── */
        .matris-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); overflow:hidden; animation:giris .5s cubic-bezier(.22,1,.36,1) .12s both; }
        .matris-bas { padding:15px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:9px; font-size:13.5px; font-weight:700; flex-wrap:wrap; }
        .matris-bas i { color:var(--red); }
        .matris-bas .not { margin-left:auto; font-size:11px; font-weight:600; color:var(--text-3); }
        .mw { overflow-x:auto; }
        table.matris { width:100%; border-collapse:collapse; min-width:<?php echo 220 + $secili_kasa_sayisi * 130 + 130; ?>px; }
        .matris th {
            padding:11px 12px; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase;
            color:var(--text-3); background:#fbf8fa; border-bottom:1px solid var(--border);
            text-align:right; white-space:nowrap; position:sticky; top:0;
        }
        .matris th:first-child { text-align:left; }
        .matris th .k-bas { display:inline-flex; align-items:center; gap:6px; color:var(--text-2); font-size:10.5px; }
        .matris th .k-bas .nk { width:9px; height:9px; border-radius:3px; flex-shrink:0; }
        .matris td { padding:0; border-bottom:1px solid #f5f1f4; }
        .matris td:first-child { padding:10px 12px; font-size:12.5px; font-weight:700; color:var(--text-2); white-space:nowrap; }
        .matris td:first-child .yildiz { color:var(--amber); margin-left:5px; }
        .hucre { display:block; position:relative; padding:10px 12px; font-size:12.5px; font-weight:600; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .hucre .dolgu { position:absolute; inset:3px auto 3px 3px; border-radius:6px; z-index:0; }
        .hucre span { position:relative; z-index:1; }
        .hucre.sifir span { color:#c9bfd0; font-weight:500; }
        .matris tr:hover td { background:#fdfbfc; }
        .matris td.top-sutun .hucre { font-weight:800; }
        .matris td.top-sutun .dolgu { background:rgba(111,16,34,.10); }
        .matris tfoot td { border-top:2px solid var(--border); background:#fbf8fa; }
        .matris tfoot .hucre { font-weight:800; }
        .matris tfoot td:first-child { font-size:11px; letter-spacing:.5px; text-transform:uppercase; }

        /* ── Kasa karneleri ── */
        .karneler { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:12px; margin-top:16px; }
        .karne { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:15px 17px; box-shadow:var(--golge); animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .karne-bas { display:flex; align-items:center; gap:10px; }
        .karne-ico { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:15px; color:#fff; }
        .karne-ad { min-width:0; }
        .karne-ad b { font-size:13.5px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .karne-ad span { font-size:10.5px; color:var(--text-3); }
        .karne-tutar { font-size:20px; font-weight:800; margin-top:10px; white-space:nowrap; }
        .karne-tutar small { font-size:12px; color:var(--text-3); font-weight:600; }
        .karne-alt { display:flex; align-items:center; gap:8px; margin-top:4px; flex-wrap:wrap; font-size:11px; color:var(--text-3); }
        .pay-bar { margin-top:10px; position:relative; height:7px; border-radius:99px; background:#f3eef2; overflow:hidden; }
        .pay-bar .d { position:absolute; inset:0 auto 0 0; border-radius:99px; }
        .karne-yillar { margin-top:10px; padding-top:10px; border-top:1px dashed var(--border); display:flex; gap:14px; flex-wrap:wrap; }
        .karne-yil { font-size:11px; color:var(--text-3); }
        .karne-yil b { display:block; font-size:12.5px; color:var(--text-2); font-weight:700; }

        .bos-genel { background:var(--card); border:1px solid var(--border); border-radius:16px; padding:56px 20px; text-align:center; color:var(--text-3); box-shadow:var(--golge); }
        .bos-genel i { font-size:38px; display:block; margin-bottom:12px; color:#e6d8de; }

        @keyframes giris { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

        @media (max-width:640px) {
            main { padding:14px 12px 46px; }
            .aksiyon { margin-left:0; width:100%; }
            .aksiyon .btn span { display:none; }
            .matris td:first-child, .matris th:first-child { position:sticky; left:0; background:#fff; z-index:2; }
            .matris th:first-child { background:#fbf8fa; z-index:3; }
            .matris tfoot td:first-child { background:#fbf8fa; }
        }
        @media print {
            body { background:#fff; }
            .top, .filtre-kart { display:none !important; }
            .matris-kart, .karne, .vurgu { box-shadow:none; break-inside:avoid; }
            .hucre .dolgu { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
        html.akl-dark .vurgu, html.akl-dark .filtre-kart, html.akl-dark .matris-kart, html.akl-dark .karne, html.akl-dark .geri, html.akl-dark .btn, html.akl-dark .yil-sec { background:var(--card); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="t-ico"><i class="fa-solid fa-table-cells"></i></span>
            <div class="t-baslik">
                <h1>Kasa Matrisi</h1>
                <p><?php echo $h($secilen_yil); ?> · kasa bazlı aylık girişler (POS / tahsilat)</p>
            </div>
            <div class="aksiyon">
                <form method="get">
                    <?php foreach ($kasa_ids as $skid): ?><input type="hidden" name="kasa[]" value="<?php echo (int) $skid; ?>"><?php endforeach; ?>
                    <select name="yil" onchange="this.form.submit()" class="yil-sec" aria-label="Yıl seç">
                        <?php $nowYear = (int) date('Y'); for ($y = $nowYear; $y >= $nowYear - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $secilen_yil ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
                <button onclick="window.print()" class="btn" title="Yazdır"><i class="fa-solid fa-print"></i><span>Yazdır</span></button>
            </div>
        </div>
    </header>

    <main>
        <!-- Vurgular -->
        <div class="vurgular">
            <div class="vurgu kir">
                <span class="vurgu-ico"><i class="fa-solid fa-sack-dollar"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l"><?php echo $secilen_yil; ?> Toplam Giriş</div>
                    <div class="vurgu-v"><?php echo $kisa($genel_toplam); ?> ₺</div>
                    <div class="vurgu-s"><?php echo $yoyGenel !== null ? $yoyHtml($genel_toplam, $onceki_toplam) . ' ' . $onceki_yil . "'e göre" : $para($genel_toplam) . ' ₺'; ?></div>
                </div>
            </div>
            <div class="vurgu alt">
                <span class="vurgu-ico"><i class="fa-solid fa-trophy"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">En İyi Kasa</div>
                    <div class="vurgu-v"><?php echo $en_iyi_kasa['id'] ? $h($kasa_isimleri[$en_iyi_kasa['id']]['NAME']) : '—'; ?></div>
                    <div class="vurgu-s"><?php echo $en_iyi_kasa['id'] ? $kisa($en_iyi_kasa['tutar']) . ' ₺' : 'veri yok'; ?></div>
                </div>
            </div>
            <div class="vurgu yes">
                <span class="vurgu-ico"><i class="fa-solid fa-bolt"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">En Yoğun Ay</div>
                    <div class="vurgu-v"><?php echo $en_iyi_ay['ay'] ? $turkce_aylar[$en_iyi_ay['ay']] : '—'; ?></div>
                    <div class="vurgu-s"><?php echo $en_iyi_ay['ay'] ? $kisa($en_iyi_ay['tutar']) . ' ₺ · tüm kasalar' : 'veri yok'; ?></div>
                </div>
            </div>
            <div class="vurgu kir">
                <span class="vurgu-ico"><i class="fa-solid fa-cash-register"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">İzlenen Kasa</div>
                    <div class="vurgu-v"><?php echo $secili_kasa_sayisi; ?></div>
                    <div class="vurgu-s">aşağıdan değiştirilebilir</div>
                </div>
            </div>
        </div>

        <!-- Kasa seçimi -->
        <details class="filtre-kart">
            <summary>
                <i class="fa-solid fa-sliders baslik-ico"></i> Kasa Seçimi
                <span class="adet" id="seciliAdet"><?php echo $secili_kasa_sayisi; ?> kasa</span>
                <i class="fa-solid fa-chevron-down cevir"></i>
            </summary>
            <div class="filtre-ic">
                <form method="get" id="kasaForm">
                    <input type="hidden" name="yil" value="<?php echo (int) $secilen_yil; ?>">
                    <div class="filtre-satir">
                        <div class="ara-kutu">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="kasaAra" placeholder="Kasa ara (kod / ad)">
                        </div>
                        <button type="button" id="temizle" class="f-btn"><i class="fa-solid fa-eraser"></i> Temizle</button>
                        <button type="submit" class="f-btn birincil"><i class="fa-solid fa-check"></i> Uygula</button>
                    </div>
                    <?php if ($kasa_secim_uyari !== ''): ?><div class="uyari-kutu"><?php echo $h($kasa_secim_uyari); ?></div><?php endif; ?>
                    <div class="kasa-secenekler" id="kasaListe">
                        <?php foreach ($tum_kasalar as $kid => $kb):
                            $sec = in_array($kid, $kasa_ids, true);
                            $aranan = strtolower($kb['CODE'] . ' ' . $kb['NAME']); ?>
                            <label class="kasa-sec" data-ara="<?php echo $h($aranan); ?>">
                                <input type="checkbox" name="kasa[]" value="<?php echo (int) $kid; ?>" <?php echo $sec ? 'checked' : ''; ?>>
                                <span class="kod"><?php echo $h($kb['CODE']); ?></span>
                                <span class="ad"><?php echo $h($kb['NAME']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </details>

        <?php if ($secili_kasa_sayisi === 0): ?>
            <div class="bos-genel"><i class="fa-regular fa-folder-open"></i><b>Kasa seçilmedi</b></div>
        <?php else: ?>

        <!-- AY × KASA MATRİSİ -->
        <section class="matris-kart">
            <div class="matris-bas">
                <i class="fa-solid fa-table-cells"></i> <?php echo $secilen_yil; ?> — Ay × Kasa Matrisi
                <span class="not">hücre dolgusu = kasanın kendi zirvesine oranı · yalnız girişler (TRCODE 11)</span>
            </div>
            <div class="mw">
                <table class="matris">
                    <thead>
                        <tr>
                            <th>Ay</th>
                            <?php foreach ($kasa_ids as $kid): $g = $kasa_gorsel[$kid]; ?>
                                <th><span class="k-bas"><span class="nk" style="background:<?php echo $g['nokta']; ?>;"></span><?php echo $h($kasa_isimleri[$kid]['NAME']); ?></span></th>
                            <?php endforeach; ?>
                            <th>Toplam</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($ay = 1; $ay <= 12; $ay++): $enIyiAyMi = ($ay === $en_iyi_ay['ay'] && $en_iyi_ay['tutar'] > 0); ?>
                        <tr>
                            <td><?php echo $turkce_aylar[$ay]; ?><?php if ($enIyiAyMi): ?><i class="fa-solid fa-star yildiz" title="En yoğun ay"></i><?php endif; ?></td>
                            <?php foreach ($kasa_ids as $kid):
                                $v = (float) $tum_kasalar_secilen_yil[$kid][$ay];
                                $w = 100 * $v / $kasa_zirve[$kid];
                                $g = $kasa_gorsel[$kid]; ?>
                                <td>
                                    <span class="hucre <?php echo $v > 0 ? '' : 'sifir'; ?>">
                                        <?php if ($v > 0): ?><span class="dolgu" style="width:calc(<?php echo number_format(max(2, $w), 1, '.', ''); ?>% - 6px); background:<?php echo $g['dolgu']; ?>;"></span><?php endif; ?>
                                        <span><?php echo $v > 0 ? $para($v) : '—'; ?></span>
                                    </span>
                                </td>
                            <?php endforeach; ?>
                            <td class="top-sutun">
                                <span class="hucre <?php echo $ay_toplamlari[$ay] > 0 ? '' : 'sifir'; ?>">
                                    <?php if ($ay_toplamlari[$ay] > 0): ?><span class="dolgu" style="width:calc(<?php echo number_format(max(2, 100 * $ay_toplamlari[$ay] / $toplam_zirve), 1, '.', ''); ?>% - 6px);"></span><?php endif; ?>
                                    <span><?php echo $ay_toplamlari[$ay] > 0 ? $para($ay_toplamlari[$ay]) : '—'; ?></span>
                                </span>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Genel Toplam</td>
                            <?php foreach ($kasa_ids as $kid): ?>
                                <td><span class="hucre"><span><?php echo $para($tum_kasalar_yillik_toplamlar[$kid][$secilen_yil] ?? 0); ?></span></span></td>
                            <?php endforeach; ?>
                            <td class="top-sutun"><span class="hucre"><span style="color:var(--red);"><?php echo $para($genel_toplam); ?></span></span></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- Kasa karneleri (yıl kıyası + pay) -->
        <div class="karneler">
            <?php $ki = 0; foreach ($kasa_ids as $kid):
                $g = $kasa_gorsel[$kid];
                $yilT = (float) ($tum_kasalar_yillik_toplamlar[$kid][$secilen_yil] ?? 0);
                $oncekiT = (float) ($tum_kasalar_yillik_toplamlar[$kid][$onceki_yil] ?? 0);
                $pay = $genel_toplam > 0 ? 100 * $yilT / $genel_toplam : 0.0; ?>
            <div class="karne" style="animation-delay:<?php echo 100 + $ki * 50; ?>ms;">
                <div class="karne-bas">
                    <span class="karne-ico" style="background:<?php echo $g['nokta']; ?>;"><i class="fa-solid <?php echo $g['ikon']; ?>"></i></span>
                    <div class="karne-ad">
                        <b><?php echo $h($kasa_isimleri[$kid]['NAME']); ?></b>
                        <span><?php echo $h($kasa_isimleri[$kid]['CODE']); ?></span>
                    </div>
                </div>
                <div class="karne-tutar"><?php echo $para($yilT); ?> <small>₺</small></div>
                <div class="karne-alt">
                    <?php echo $yoyHtml($yilT, $oncekiT); ?>
                    <span>· pay %<?php echo number_format($pay, 1, ',', '.'); ?></span>
                </div>
                <div class="pay-bar"><span class="d" style="width:<?php echo number_format(max(1.5, min(100, $pay)), 1, '.', ''); ?>%; background:<?php echo $g['nokta']; ?>;"></span></div>
                <?php if ($onceki_yillar !== []): ?>
                <div class="karne-yillar">
                    <?php foreach ($onceki_yillar as $oy): ?>
                        <div class="karne-yil"><?php echo $oy; ?><b><?php echo $kisa($tum_kasalar_yillik_toplamlar[$kid][$oy] ?? 0); ?> ₺</b></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php $ki++; endforeach; ?>
        </div>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        var liste = document.getElementById('kasaListe');
        if (!liste) { return; }
        var satirlar = Array.from(liste.querySelectorAll('.kasa-sec'));
        var adetEl = document.getElementById('seciliAdet');
        var ara = document.getElementById('kasaAra');
        var temizle = document.getElementById('temizle');
        var form = document.getElementById('kasaForm');

        function say() {
            if (adetEl) { adetEl.textContent = liste.querySelectorAll('input:checked').length + ' kasa'; }
        }
        if (ara) {
            ara.addEventListener('input', function () {
                var q = this.value.trim().toLowerCase();
                satirlar.forEach(function (r) {
                    r.style.display = (q === '' || (r.getAttribute('data-ara') || '').indexOf(q) !== -1) ? '' : 'none';
                });
            });
        }
        liste.addEventListener('change', say);
        if (temizle) {
            temizle.addEventListener('click', function () {
                liste.querySelectorAll('input:checked').forEach(function (c) { c.checked = false; });
                say();
            });
        }
        if (form) {
            form.addEventListener('submit', function (e) {
                if (liste.querySelectorAll('input:checked').length === 0) {
                    e.preventDefault();
                    alert('En az bir kasa seçmelisiniz.');
                }
            });
        }
    })();
    </script>
</body>
</html>
