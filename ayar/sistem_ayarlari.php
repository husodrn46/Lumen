<?php
declare(strict_types=1);

// Gerekli yapılandırma dosyalarını ve güvenlik kontrolünü yükle.
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

// Yetki kontrolü - M16 (Ayarlar) yetkisi gerekli
ayar_require_m16($terminalkullanici);

// _bilgi_.inc dosyasını oku
$bilgi_file = __DIR__ . '/../_bilgi_.inc';
$bilgi_content = file_get_contents($bilgi_file);

// Düzenlenebilir ayarların tipleri (key => tip). Tipler:
//   'bool' (0/1) · 'boolyn' (evet/hayir) · 'int' (sayı) · 'text' (metin)
$ayar_tipleri = [
    'fisyazici' => 'text', 'fisyazici2' => 'text', 'fisyazici3' => 'text', 'fisyazici4' => 'text',
    'barkodyazici' => 'text', 'barkodyazici2' => 'text',
    'adetkusurat' => 'int', 'parakusurat' => 'int',
    'dovizlicalis' => 'bool', 'dovizfirma' => 'int', 'doviztipleri' => 'text', 'tlkodu' => 'int',
    'kullanicigirisli' => 'bool', 'benihatirla' => 'bool',
    'bakiyegorunsun' => 'bool', 'extregorunsun' => 'bool', 'carilistesayisi' => 'int',
    'yenicariac' => 'bool', 'carikoduontaki' => 'text', 'hizlicari' => 'int', 'iletisim' => 'bool',
    'stoklistesayisi' => 'int', 'stoksonalisfiyati' => 'bool', 'stoksonsatisfiyati' => 'bool',
    'stokekle' => 'boolyn', 'yenistokac' => 'bool', 'stokkoduontaki' => 'text',
    'resimlistoklistesi' => 'bool', 'coklustokgiris' => 'boolyn',
    'sonsiparis_aktif' => 'bool', 'favoriler_aktif' => 'bool',
    'iskontolu' => 'int', 'iskontolu2' => 'int', 'iskontolu3' => 'int', 'topluiskonto' => 'bool', 'iskontoartir' => 'bool',
    'sipno' => 'text', 'siparistefiyatduzenle' => 'bool', 'yenialissiparisi' => 'bool', 'reserve' => 'bool',
    'exceleaktar' => 'bool', 'koliyazdir' => 'bool', 'resimliyazdir' => 'bool', 'barkodyazdir' => 'bool',
    'depo' => 'int', 'cokludepo' => 'bool',
    'fiyatgruplu' => 'bool', 'odemekodu' => 'text', 'tanimlialantoplam' => 'bool', 'tanimlialan' => 'bool',
    'yenifiyatduzenle' => 'bool', 'toplukdv' => 'bool', 'eksik' => 'bool', 'maliyetkontrol' => 'bool',
    'cokluekledeayniurun' => 'bool', 'dil' => 'int',
];

/** Değeri tipine göre GÜVENLİ iç-değere (tırnaksız) dönüştür — PHP literalini bozacak karakterler elenir. */
function sa_temiz(string $tip, $ham): string
{
    if ($tip === 'int') {
        $n = (int) preg_replace('/[^0-9-]/', '', (string) $ham);
        return (string) max(0, min(999999, $n));
    }
    // text: tırnak / ters bölü / ; / kontrol karakterlerini at, 60 karakterle sınırla
    $s = preg_replace('/[\'"\\\\;\r\n\x00-\x1F]/', '', (string) $ham) ?? '';
    return mb_substr(trim($s), 0, 60);
}

/** _bilgi_.inc içinde `$var = ...;` değerini, MEVCUT tırnak stilini koruyarak değiştir. */
function sa_yaz(string $content, string $var, string $ic): string
{
    $pattern = '/(\$' . preg_quote($var, '/') . '\s*=\s*)([\'"]?)(.*?)(\2)(\s*;)/s';
    $yeni = preg_replace_callback($pattern, static function (array $m) use ($ic): string {
        return $m[1] . $m[2] . $ic . $m[2] . $m[5];
    }, $content, 1);
    return $yeni ?? $content;
}

// Form gönderildiğinde ayarları güncelle
$guncelleme_mesaji = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guncelle'])) {
    ayar_require_csrf();

    $icerik = (string) file_get_contents($bilgi_file);
    $orijinal = $icerik;

    foreach ($ayar_tipleri as $key => $tip) {
        if ($tip === 'bool') {
            $ic = isset($_POST[$key]) ? '1' : '0';
        } elseif ($tip === 'boolyn') {
            $ic = isset($_POST[$key]) ? 'evet' : 'hayir';
        } elseif (isset($_POST[$key])) {
            $ic = sa_temiz($tip, $_POST[$key]);
        } else {
            continue;
        }
        $icerik = sa_yaz($icerik, $key, $ic);
    }

    // GÜVENLİK: yeni içerik geçerli PHP mi? (bozuk yazma önlenir)
    $gecerli = true;
    try {
        token_get_all($icerik, TOKEN_PARSE);
    } catch (\ParseError $e) {
        $gecerli = false;
    }

    if (!$gecerli || strlen($icerik) < strlen($orijinal) * 0.7) {
        $guncelleme_mesaji = 'error';
    } else {
        @copy($bilgi_file, $bilgi_file . '.bak'); // yedek
        if (file_put_contents($bilgi_file, $icerik) !== false) {
            $guncelleme_mesaji = 'success';
            // Bu istek için güncel değerleri belleğe yükle (form güncel görünsün)
            foreach ($ayar_tipleri as $key => $tip) {
                if ($tip === 'bool') {
                    ${$key} = isset($_POST[$key]) ? '1' : '0';
                } elseif ($tip === 'boolyn') {
                    ${$key} = isset($_POST[$key]) ? 'evet' : 'hayir';
                } elseif (isset($_POST[$key])) {
                    ${$key} = sa_temiz($tip, $_POST[$key]);
                }
            }
        } else {
            $guncelleme_mesaji = 'error';
        }
    }
}

// Ayarları kategorilere ayır
$ayarlar = ['Yazıcı Ayarları' => [['fisyazici', 'Fiş Yazıcı 1', $fisyazici], ['fisyazici2', 'Fiş Yazıcı 2', $fisyazici2], ['fisyazici3', 'Fiş Yazıcı 3', $fisyazici3], ['fisyazici4', 'Fiş Yazıcı 4', $fisyazici4], ['barkodyazici', 'Barkod Yazıcı 1', $barkodyazici], ['barkodyazici2', 'Barkod Yazıcı 2', $barkodyazici2]], 'Küsurat Ayarları' => [['adetkusurat', 'Adet Küsurat', $adetkusurat], ['parakusurat', 'Para Küsurat', $parakusurat]], 'Döviz Ayarları' => [['dovizlicalis', 'Dövizli Çalış', $dovizlicalis], ['dovizfirma', 'Döviz Firma No', $dovizfirma], ['doviztipleri', 'Döviz Tipleri', $doviztipleri], ['tlkodu', 'TL Kodu', $tlkodu]], 'Kullanıcı Ayarları' => [['kullanicigirisli', 'Kullanıcı Girişli', $kullanicigirisli], ['benihatirla', 'Beni Hatırla', $benihatirla]], 'Cari Ayarları' => [['bakiyegorunsun', 'Bakiye Görünsün', $bakiyegorunsun], ['extregorunsun', 'Ekstre Görünsün', $extregorunsun], ['carilistesayisi', 'Cari Liste Sayısı', $carilistesayisi], ['yenicariac', 'Yeni Cari Aç', $yenicariac], ['carikoduontaki', 'Cari Kodu Ön Takı', $carikoduontaki], ['hizlicari', 'Hızlı Satış Carisi', $hizlicari], ['iletisim', 'İletişim (Telefon)', $iletisim]], 'Stok Ayarları' => [['stoklistesayisi', 'Stok Liste Sayısı', $stoklistesayisi], ['stoksonalisfiyati', 'Stok Son Alış Fiyatı', $stoksonalisfiyati], ['stoksonsatisfiyati', 'Stok Son Satış Fiyatı', $stoksonsatisfiyati], ['stokekle', 'Stok Ekle', $stokekle], ['yenistokac', 'Yeni Stok Aç', $yenistokac], ['stokkoduontaki', 'Stok Kodu Ön Takı', $stokkoduontaki], ['resimlistoklistesi', 'Resimli Stok Listesi', $resimlistoklistesi], ['coklustokgiris', 'Çoklu Stok Giriş', $coklustokgiris]], 'Stok Arama Ayarları' => [['sonsiparis_aktif', 'Son Sipariş Yükle Butonu', $sonsiparis_aktif, true], ['favoriler_aktif', 'Favori Ürünler Butonu', $favoriler_aktif, true]], 'İskonto Ayarları' => [['iskontolu', 'İskonto 1 (%)', $iskontolu], ['iskontolu2', 'İskonto 2 (%)', $iskontolu2], ['iskontolu3', 'İskonto 3 (%)', $iskontolu3], ['topluiskonto', 'Toplu İskonto', $topluiskonto], ['iskontoartir', 'İskonto Artır', $iskontoartir]], 'Sipariş Ayarları' => [['sipno', 'Sipariş No Prefix', $sipno], ['siparistefiyatduzenle', 'Siparişte Fiyat Düzenle', $siparistefiyatduzenle], ['yenialissiparisi', 'Yeni Alış Siparişi', $yenialissiparisi], ['reserve', 'Rezerve', $reserve]], 'Yazdırma Ayarları' => [['exceleaktar', 'Excel\'e Aktar', $exceleaktar], ['koliyazdir', 'Koli Yazdır', $koliyazdir], ['resimliyazdir', 'Resimli Yazdır', $resimliyazdir], ['barkodyazdir', 'Barkod Yazdır', $barkodyazdir]], 'Depo Ayarları' => [['depo', 'Varsayılan Depo', $depo], ['cokludepo', 'Çoklu Depo', $cokludepo]], 'Diğer Ayarlar' => [['fiyatgruplu', 'Fiyat Gruplu', $fiyatgruplu], ['odemekodu', 'Ödeme Kodu', $odemekodu], ['tanimlialantoplam', 'Tanımlı Alan Toplam', $tanimlialantoplam], ['tanimlialan', 'Tanımlı Alan', $tanimlialan], ['yenifiyatduzenle', 'Yeni Fiyat Düzenle', $yenifiyatduzenle], ['toplukdv', 'Toplu KDV', $toplukdv], ['eksik', 'Eksik', $eksik], ['maliyetkontrol', 'Maliyet Kontrol', $maliyetkontrol], ['cokluekledeayniurun', 'Çoklu Ekle Aynı Ürün', $cokluekledeayniurun], ['dil', 'Dil', $dil]]];

// Kategori ikonlari
$kategori_ikon = [
    'Yazıcı Ayarları'       => 'fa-print',
    'Küsurat Ayarları'      => 'fa-percent',
    'Döviz Ayarları'        => 'fa-dollar-sign',
    'Kullanıcı Ayarları'    => 'fa-user',
    'Cari Ayarları'         => 'fa-handshake',
    'Stok Ayarları'         => 'fa-boxes-stacked',
    'Stok Arama Ayarları'   => 'fa-magnifying-glass',
    'İskonto Ayarları'      => 'fa-tag',
    'Sipariş Ayarları'      => 'fa-file-invoice',
    'Yazdırma Ayarları'     => 'fa-print',
    'Depo Ayarları'         => 'fa-warehouse',
    'Diğer Ayarlar'         => 'fa-ellipsis-h',
];

/**
 * Ayar degerini insan okunabilir formata cevirir.
 */
function sa_render_value($deger): string
{
    if ($deger === '1' || $deger === 1 || $deger === 'evet') {
        return '<span class="chip chip-emerald"><i class="fa-solid fa-check"></i>Aktif</span>';
    }
    if ($deger === '0' || $deger === 0 || $deger === 'hayır') {
        return '<span class="chip chip-mute"><i class="fa-solid fa-minus"></i>Pasif</span>';
    }
    $str = (string) $deger;
    if (trim($str) === '') {
        return '<span class="setting-empty">-</span>';
    }
    return '<span class="setting-value mono">' . htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem Ayarlari</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <script src="/tm/css/tailwind.js"></script>
    <script>
        if (typeof tailwind === 'undefined') {
            var s = document.createElement('script');
            s.src = 'https://cdn.tailwindcss.com';
            document.head.appendChild(s);
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
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
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }
        .mono { font-family: 'JetBrains Mono', 'Courier New', monospace; }

        /* Sticky top header (indigo tone) */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(79, 70, 229, 0.18);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.04);
        }
        .header-inner {
            max-width: 1180px; margin: 0 auto;
            height: 100%; display: flex; align-items: center; gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: var(--indigo-soft); color: var(--indigo); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title-wrap { display: flex; flex-direction: column; line-height: 1.15; }
        .header-title {
            font-size: 17px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .header-title i { color: var(--indigo); font-size: 15px; }
        .header-sub { font-size: 11.5px; color: var(--text-2); margin-top: 1px; }
        .header-badge {
            margin-left: auto;
            padding: 5px 12px;
            font-size: 11px;
            font-weight: 600;
            color: var(--indigo);
            background: var(--indigo-soft);
            border: 1px solid rgba(79, 70, 229, 0.22);
            border-radius: 100px;
            display: inline-flex; align-items: center; gap: 6px;
        }

        /* Glass card */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(79, 70, 229, 0.14);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 16px;
        }
        .card-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
        }
        .card-head .icon-box {
            width: 36px; height: 36px;
            flex-shrink: 0;
            border-radius: 10px;
            background: var(--indigo-soft);
            color: var(--indigo);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        .card-head h2 { font-size: 14px; font-weight: 700; color: var(--text-1); }
        .card-head p { font-size: 11.5px; color: var(--text-2); margin-top: 1px; }
        .card-head .head-right { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; }
        .card-body { padding: 16px 20px; }

        /* Tone variants per category */
        .tone-amber .card-head .icon-box { background: var(--amber-soft); color: var(--amber); }
        .tone-emerald .card-head .icon-box { background: var(--emerald-soft); color: var(--emerald); }
        .tone-sky .card-head .icon-box { background: var(--sky-soft); color: var(--sky); }
        .tone-red .card-head .icon-box { background: var(--red-soft); color: var(--red); }
        .tone-purple .card-head .icon-box { background: var(--purple-soft); color: var(--purple); }

        /* Settings grid */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 18px;
        }
        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            min-height: 44px;
            transition: border-color 0.15s ease;
        }
        .setting-row:hover { border-color: rgba(79, 70, 229, 0.28); }
        .setting-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            flex: 1 1 auto;
            min-width: 0;
        }
        .setting-value {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-1);
            font-variant-numeric: tabular-nums;
            text-align: right;
            word-break: break-word;
            max-width: 55%;
        }
        .setting-empty { font-size: 13px; color: var(--text-3); font-weight: 600; }

        /* Chips */
        .chip {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px;
            border-radius: 100px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }
        .chip i { font-size: 9px; }
        .chip-emerald { background: var(--emerald-soft); color: var(--emerald); border: 1px solid rgba(5, 150, 105, 0.22); }
        .chip-mute { background: #f3f4f6; color: var(--text-3); border: 1px solid var(--border); }
        .chip-amber { background: var(--amber-soft); color: var(--amber); border: 1px solid rgba(217, 119, 6, 0.25); }
        .chip-indigo { background: var(--indigo-soft); color: var(--indigo); border: 1px solid rgba(79, 70, 229, 0.22); }

        /* Alerts */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 16px;
            border: 1px solid;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .alert i { font-size: 16px; margin-top: 1px; flex-shrink: 0; }
        .alert .a-title { font-size: 13px; font-weight: 700; margin-bottom: 2px; }
        .alert .a-body { font-size: 12px; line-height: 1.5; }
        .alert-success { background: var(--emerald-soft); border-color: rgba(5, 150, 105, 0.25); color: var(--emerald); }
        .alert-error   { background: var(--red-soft); border-color: rgba(111, 16, 34, 0.22); color: var(--red); }
        .alert-amber   { background: var(--amber-soft); border-color: rgba(217, 119, 6, 0.22); color: var(--amber); }
        .alert-amber .a-body code { background: rgba(217, 119, 6, 0.12); padding: 1px 6px; border-radius: 4px; font-family: 'JetBrains Mono', monospace; font-size: 11px; }

        /* Toggle switch */
        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 14px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 10px;
            min-height: 56px;
        }
        .toggle-row:hover { border-color: rgba(79, 70, 229, 0.3); }
        .toggle-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-1);
            flex: 1;
        }
        .toggle-label small {
            display: block;
            font-size: 11px;
            font-weight: 500;
            color: var(--text-2);
            margin-top: 2px;
        }
        .switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 28px;
            flex-shrink: 0;
        }
        .switch input { opacity: 0; width: 0; height: 0; position: absolute; }
        .switch .slider {
            position: absolute;
            inset: 0;
            background: #d1d5db;
            border-radius: 100px;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .switch .slider::before {
            content: '';
            position: absolute;
            top: 3px; left: 3px;
            width: 22px; height: 22px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.18);
            transition: transform 0.22s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .switch input:checked + .slider { background: var(--indigo); }
        .switch input:checked + .slider::before { transform: translateX(20px); }
        .switch input:focus-visible + .slider { box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.22); }

        /* Submit button */
        .btn-submit {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
            border: 1px solid #4338ca;
            cursor: pointer;
            min-height: 44px;
            transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.12s ease;
            box-shadow: 0 2px 0 #4338ca, 0 6px 14px rgba(79, 70, 229, 0.18);
        }
        .btn-submit:hover { transform: translateY(-1px); filter: brightness(1.04); }
        .btn-submit:active { transform: translateY(0); }

        /* Footer file note */
        .file-note {
            margin-top: 6px;
            padding: 12px 16px;
            border-radius: 12px;
            background: #fff;
            border: 1px dashed var(--border);
            font-size: 11.5px;
            color: var(--text-2);
            display: flex; align-items: center; gap: 10px;
        }
        .file-note i { color: var(--sky); }
        .file-note code {
            font-family: 'JetBrains Mono', monospace;
            background: #f3f4f6;
            padding: 2px 8px;
            border-radius: 5px;
            color: var(--text-1);
            font-size: 11px;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* Stagger: max 280ms */
        .glass-card:nth-child(1)  { animation-delay: 0ms; }
        .glass-card:nth-child(2)  { animation-delay: 40ms; }
        .glass-card:nth-child(3)  { animation-delay: 80ms; }
        .glass-card:nth-child(4)  { animation-delay: 120ms; }
        .glass-card:nth-child(5)  { animation-delay: 160ms; }
        .glass-card:nth-child(6)  { animation-delay: 200ms; }
        .glass-card:nth-child(7)  { animation-delay: 240ms; }
        .glass-card:nth-child(n+8) { animation-delay: 280ms; }

        /* Mobile */
        @media (max-width: 767px) {
            .top-header { height: 56px; }
            .header-inner { padding: 0 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-sub { font-size: 10.5px; }
            .header-back { width: 32px; height: 32px; border-radius: 8px; }
            .header-badge { display: none; }
            main { padding: 16px 12px 40px !important; }
            .settings-grid { grid-template-columns: 1fr; gap: 8px; }
            .card-head { padding: 12px 14px; }
            .card-body { padding: 14px; }
            .setting-row { padding: 10px 12px; }
            .setting-value { font-size: 13px; max-width: 60%; }
            /* iOS 16px zoom fix */
            input[type="text"], input[type="number"], input[type="password"], select, textarea {
                font-size: 16px;
            }
        }
        /* Düzenlenebilir alanlar */
        .sa-field { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:11px 14px; border:1px solid var(--border); border-radius:11px; background:#fff; margin-bottom:8px; }
        .sa-field .sa-lbl { font-size:13.5px; font-weight:600; color:var(--text-1); }
        .sa-field .sa-in { width:200px; max-width:50%; padding:9px 11px; border:1px solid var(--border); border-radius:9px; font-size:14px; font-family:inherit; outline:none; text-align:right; color:var(--text-1); background:#fff; }
        .sa-field .sa-in:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        .sa-kaydet-bar { position:sticky; bottom:0; z-index:30; background:rgba(255,255,255,.95); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); border-top:1px solid var(--border); margin:22px -24px -60px; padding:14px 24px; display:flex; align-items:center; gap:12px; box-shadow:0 -4px 16px rgba(0,0,0,.05); }
        .sa-kaydet-bar .sa-not { margin-right:auto; font-size:12.5px; color:var(--text-3); display:flex; align-items:center; gap:7px; }
        .sa-kaydet-bar code { background:var(--red-soft); color:var(--red); padding:1px 6px; border-radius:5px; font-size:12px; }
        @media (max-width:520px){ .sa-field .sa-in { width:130px; } .sa-kaydet-bar { margin:16px -12px -40px; padding:12px 14px; } .sa-kaydet-bar .sa-not { display:none; } }
    </style>
</head>
<body>

<?php $saAktifSekme = 'genel'; include __DIR__ . '/sistem_sekmeler.php'; ?>

<main style="max-width:1180px;margin:0 auto;padding:22px 24px 60px;">

    <?php if ($guncelleme_mesaji === 'success'): ?>
        <div class="alert alert-success" role="status">
            <i class="fa-solid fa-circle-check"></i>
            <div>
                <div class="a-title">Ayarlar kaydedildi.</div>
                <div class="a-body">Tum ayarlar _bilgi_.inc dosyasina yazildi.</div>
            </div>
        </div>
    <?php elseif ($guncelleme_mesaji === 'error'): ?>
        <div class="alert alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                <div class="a-title">Kayit basarisiz.</div>
                <div class="a-body">Dosya yazma izinlerini kontrol edin.</div>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <?php echo csrf_field(); ?>
        <?php foreach ($ayarlar as $kategori => $ayar_listesi):
            $ikon = $kategori_ikon[$kategori] ?? 'fa-folder-open'; ?>
        <section class="glass-card">
            <div class="card-head">
                <span class="icon-box"><i class="fa-solid <?php echo $ikon; ?>"></i></span>
                <div>
                    <h2><?php echo htmlspecialchars($kategori); ?></h2>
                    <p><?php echo count($ayar_listesi); ?> ayar</p>
                </div>
                <div class="head-right">
                    <span class="chip chip-amber"><i class="fa-solid fa-pen-to-square"></i>Duzenlenebilir</span>
                </div>
            </div>
            <div class="card-body">
                <?php foreach ($ayar_listesi as $ayar):
                    $key = (string) $ayar[0];
                    $label = (string) $ayar[1];
                    $deger = $ayar[2];
                    $tip = $ayar_tipleri[$key] ?? 'text';
                    if ($tip === 'bool' || $tip === 'boolyn'):
                        $isOn = ($tip === 'boolyn') ? ($deger === 'evet') : ($deger === '1' || $deger === 1);
                ?>
                    <label class="toggle-row">
                        <span class="toggle-label"><?php echo htmlspecialchars($label); ?></span>
                        <span class="switch">
                            <input type="checkbox" name="<?php echo htmlspecialchars($key); ?>" <?php echo $isOn ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </span>
                    </label>
                <?php else: ?>
                    <div class="sa-field">
                        <span class="sa-lbl"><?php echo htmlspecialchars($label); ?></span>
                        <input class="sa-in" type="<?php echo $tip === 'int' ? 'number' : 'text'; ?>"
                               name="<?php echo htmlspecialchars($key); ?>"
                               value="<?php echo htmlspecialchars((string) $deger, ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo $tip === 'int' ? 'inputmode="numeric"' : ''; ?> autocomplete="off">
                    </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>

        <div class="sa-kaydet-bar">
            <span class="sa-not"><i class="fa-solid fa-circle-info"></i> Değişiklikler <code>_bilgi_.inc</code> dosyasına yazılır.</span>
            <button type="submit" name="guncelle" class="btn-submit"><i class="fa-solid fa-floppy-disk"></i> Tümünü Kaydet</button>
        </div>
    </form>

    <div class="file-note">
        <i class="fa-solid fa-file-code"></i>
        <span>Ayar dosyasi: <code>_bilgi_.inc</code></span>
    </div>

</main>

<script>
    // Animation reflow fix
    document.addEventListener('DOMContentLoaded', function(){
        requestAnimationFrame(function(){
            document.querySelectorAll('.glass-card, .alert').forEach(function(el){
                void el.offsetHeight;
            });
        });
    });
</script>

</body>
</html>
