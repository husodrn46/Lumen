<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Doğrudan Yazıcıya Yazdırma
 * Windows printer extension kullanır
 */

include_once(__DIR__ . "/../ayr.php");
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

$reqId = (int)($_GET['id'] ?? 0);

// Windows printer extension kontrolü
if (!function_exists('printer_open')) {
    ?>
    <!DOCTYPE html>
    <html lang="tr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Yazıcı Eklentisi Yok - Lumen</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
          // Tailwind CDN fallback (offline / CSP)
          window.addEventListener('load', function(){
            if (typeof tailwind === 'undefined') {
              var s = document.createElement('script');
              s.src = '/tm/css/tailwind.js';
              document.head.appendChild(s);
            }
          });
        </script>
        <style>
            :root {
                --bg: #f9fafb;
                --text-1: #1f2937;
                --text-2: #6b7280;
                --text-3: #9ca3af;
                --border: #e5e7eb;
                --sky: #0284c7;
                --sky-soft: #e0f2fe;
                --amber: #d97706;
                --amber-soft: #fffbeb;
                --red: #6F1022;
                --red-soft: #fef2f2;
                --emerald: #059669;
                --emerald-soft: #ecfdf5;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            html, body { font-family: 'Avenir Next', 'Montserrat', 'Segoe UI', Tahoma, sans-serif; }
            body {
                min-height: 100vh;
                background:
                    radial-gradient(1200px 600px at 8% -10%, rgba(251, 191, 36, .14), transparent 60%),
                    radial-gradient(900px 500px at 92% 110%, rgba(248, 113, 113, .10), transparent 55%),
                    var(--bg);
                color: var(--text-1);
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                -webkit-font-smoothing: antialiased;
            }
            .glass-card {
                background: rgba(255, 255, 255, 0.94);
                backdrop-filter: blur(6px);
                -webkit-backdrop-filter: blur(6px);
                border: 1px solid rgba(251, 191, 36, 0.25);
                border-radius: 20px;
                box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
                padding: 40px 32px;
                text-align: center;
                max-width: 520px;
                width: 100%;
                will-change: transform, opacity;
                animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
            }
            .brand {
                display: inline-flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 20px;
                font-size: 11px;
                font-weight: 600;
                color: var(--text-2);
                text-transform: uppercase;
                letter-spacing: 1.2px;
            }
            .brand img { width: 28px; height: 28px; border-radius: 7px; object-fit: cover; }
            .icon-box {
                width: 88px;
                height: 88px;
                margin: 0 auto 20px;
                border-radius: 22px;
                background: linear-gradient(135deg, var(--amber-soft) 0%, var(--red-soft) 100%);
                color: var(--amber);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 40px;
                box-shadow: inset 0 0 0 1px rgba(217, 119, 6, 0.18);
            }
            .title {
                font-size: 22px;
                font-weight: 700;
                color: var(--text-1);
                margin-bottom: 10px;
                letter-spacing: -0.01em;
            }
            .desc {
                font-size: 14px;
                color: var(--text-2);
                line-height: 1.65;
                margin-bottom: 10px;
            }
            .desc.small {
                font-size: 12.5px;
                color: var(--text-3);
                margin-bottom: 26px;
            }
            .actions {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-top: 8px;
            }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                width: 100%;
                min-height: 44px;
                padding: 12px 22px;
                border-radius: 12px;
                font-family: inherit;
                font-size: 14px;
                font-weight: 600;
                text-decoration: none;
                cursor: pointer;
                border: 1px solid transparent;
                transition: all 0.2s ease;
            }
            .btn-primary {
                background: var(--sky);
                color: #fff;
                box-shadow: 0 4px 14px rgba(2, 132, 199, 0.28);
            }
            .btn-primary:hover { background: #0369a1; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(2, 132, 199, 0.34); }
            .btn-ghost {
                background: #fff;
                color: var(--text-1);
                border-color: var(--border);
            }
            .btn-ghost:hover { border-color: var(--sky); color: var(--sky); }
            @keyframes cardIn {
                from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
                to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
            }
            @media (max-width: 480px) {
                .glass-card { padding: 32px 22px; border-radius: 16px; }
                .title { font-size: 19px; }
                .desc { font-size: 13px; }
                .btn { padding: 12px 18px; font-size: 14px; min-height: 44px; }
                .icon-box { width: 72px; height: 72px; margin-bottom: 16px; font-size: 34px; }
            }
        </style>
    </head>
    <body>
        <main class="glass-card" id="errorCard">
            <div class="brand">
                <img src="../logo.png" alt="Lumen" onerror="this.style.display='none'">
                Lumen Siparis Sistemi
            </div>

            <div class="icon-box">
                <i class="fa-solid fa-circle-exclamation"></i>
            </div>

            <h1 class="title">Yazıcı Eklentisi Yüklü Değil</h1>
            <p class="desc">
                Doğrudan yazıcıya yazdırma için PHP Printer eklentisi (<code>php_printer.dll</code>) gereklidir.
                Bu eklenti sunucuda yüklü değil.
            </p>
            <p class="desc small">
                Alternatif olarak HTML yazdırma seçeneğini kullanabilirsiniz.
            </p>

            <div class="actions">
                <a class="btn btn-primary" href="yazdir.php?id=<?php echo $reqId; ?>" target="_blank">
                    <i class="fa-solid fa-print"></i>
                    HTML Yazdırmaya Geç
                </a>
                <a class="btn btn-ghost" href="siparisler.php">
                    <i class="fa-solid fa-arrow-left"></i>
                    Siparişlere Dön
                </a>
            </div>
        </main>
        <script>
            // RAF reflow for animation stability
            requestAnimationFrame(function(){
                var c = document.getElementById('errorCard');
                if (c) { void c.offsetWidth; }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

if(!isset($_GET['id'])){
    echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>';
    exit;
}

$stokhareket = (int)$_GET['id'];

// Sipariş bilgilerini çek (döviz dahil)
$stmtFis = $dbh->prepare("SELECT
    CLIENTREF, GROSSTOTAL, GENEXP1, DATE_, FICHENO, TRCURR, TRRATE
    FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
$stmtFis->execute([':stokhareket' => $stokhareket]);
$listfis = $stmtFis->fetch(PDO::FETCH_ASSOC);

if(!$listfis){
    echo '<div class="notification msgerror">Fiş bulunamadı.</div>';
    exit;
}

// Döviz kontrolü
$trcurr = (int)$listfis['TRCURR'];
$trrate = (float)$listfis['TRRATE'];

if ($trcurr == 0 || $trrate <= 0) {
    echo '<div class="notification msgerror">Bu sipariş dövizli değil.</div>';
    exit;
}

$cariid = intcevir($listfis['CLIENTREF']);
$stmtCari = $dbh->prepare("SELECT CLCARD.CODE AS KODU, CLCARD.LOGICALREF AS CARIID, CLCARD.DEFINITION_ AS UNVANI,
    SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT) - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE
    FROM {$firma}CLCARD CLCARD
    LEFT JOIN {$firmadonem}CLFLINE CLFLINE ON CLCARD.LOGICALREF=CLFLINE.CLIENTREF AND CLFLINE.CANCELLED = 0
    GROUP BY CLCARD.CODE, CLCARD.DEFINITION_, CLCARD.ACTIVE, CLCARD.LOGICALREF
    HAVING CLCARD.LOGICALREF = :cariid AND CLCARD.ACTIVE = 0");
$stmtCari->execute([':cariid' => $cariid]);
$sqlx = $stmtCari->fetch(PDO::FETCH_ASSOC);

$stmtSatirSay = $dbh->prepare("SELECT COUNT(*) AS SAYI FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :stokhareket");
$stmtSatirSay->execute([':stokhareket' => $stokhareket]);
$satirsay = $stmtSatirSay->fetch(PDO::FETCH_ASSOC);
$satirtoplam = $satirsay["SAYI"];

// Döviz kuru ORFICHE'den al
$dovizfiyat = $trrate;

// Döviz bilgileri (LOGO: 1=USD, 20=EUR)
$dovizBilgileri = [
    1 => ['kod' => 'USD', 'sembol' => '$'],
    20 => ['kod' => 'EUR', 'sembol' => '€'],
];
$dovizInfo = $dovizBilgileri[$trcurr] ?? ['kod' => 'DV', 'sembol' => '?'];
$dovizKod = $dovizInfo['kod'];
$dovizSembol = $dovizInfo['sembol'];

$satirmiktari = 40;
$sayfano = 1;
$satiryukseklik = 130;
$baslikyuksekligi = 630;
$stokfont = 120;
$stokfontx = 40;
$tarih = date("d/m/Y H:i:s");

$handle = printer_open($fisyazici);
printer_start_doc($handle, "Lumen-doviz");
printer_start_page($handle);
printer_set_option($handle, PRINTER_MODE, "RAW");
printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT);

$font = printer_create_font("Tahoma", 280, 55, 100, false, false, false, 1);
printer_select_font($handle, $font);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "DOVIZLI TEKLIF FISI ({$dovizKod})"), 1800, 320);

$font = printer_create_font("Tahoma", $stokfont, $stokfontx, 00, false, false, false, 1);
printer_select_font($handle, $font);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "Sayin:"), 100, 400);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", (string) $sqlx['UNVANI']), 400, 400);
printer_draw_text($handle, tarihcevir($listfis['DATE_']), 4400, 200);
printer_draw_text($handle, "sayfa : " . $sayfano, 4400, 400);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "Kur: 1 {$dovizKod} = " . kusuratpara($dovizfiyat) . " TL"), 100, 500);

$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);

printer_draw_text($handle, "KODU", 350, 590);
printer_draw_text($handle, "ACIKLAMA", 800, 590);
printer_draw_text($handle, "MIKTAR", 3500, 590);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "FIYAT ({$dovizSembol})"), 4000, 590);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "TOPLAM ({$dovizSembol})"), 4400, 590);
printer_draw_line($handle, 30, 590, 4800, 590);

$font = printer_create_font("Tahoma", 95, 25, 00, false, false, false, 1);
printer_select_font($handle, $font);

$i = 0;
$a = $baslikyuksekligi;
$b = $satiryukseklik;

$hareketFisToplami = 0;
$toplam = '';
$gtoplam = '';
$gisk = '';
$adetmiktar = 0;

$stmtList = $dbh->prepare("SELECT
    O.PRICE, O.AMOUNT, O.TOTAL, O.VATMATRAH, O.STOCKREF, O.LOGICALREF, O.ORDFICHEREF, O.LINEEXP, O.PRPRICE,
    I.CODE AS SKODU, I.NAME AS SADI, U.CODE AS BIRIM
    FROM {$firmadonem}ORFLINE O
    LEFT JOIN {$firma}ITEMS I ON O.STOCKREF = I.LOGICALREF
    LEFT JOIN {$firma}UNITSETL U ON O.UOMREF = U.LOGICALREF
    WHERE O.ORDFICHEREF = :stokhareket AND O.LINETYPE = 0");
$stmtList->execute([':stokhareket' => $stokhareket]);

while($liste = $stmtList->fetch(PDO::FETCH_ASSOC)) {
    $toplam = $liste['AMOUNT'] * $liste['PRICE'];
    $gtoplam += $toplam;
    $gisk += $liste['VATMATRAH'];
    $stokhareketid = intcevir($liste['LOGICALREF']);
    $stokhid = intcevir($liste['STOCKREF']);
    $kodu = trcevir($liste['SKODU']);
    $adi = trcevir($liste['SADI']);
    $aciklama = trcevir($liste['LINEEXP']);
    $adetmiktar += $liste['AMOUNT'];

    // Döviz fiyatları
    $dovizBirimFiyat = $liste['PRPRICE'] > 0 ? $liste['PRPRICE'] : ($liste['PRICE'] / $dovizfiyat);
    $dovizToplam = $liste['TOTAL'] / $dovizfiyat;

    // Çoklu sayfa döngüsü
    if($satirmiktari == $i) {
        $sayfano += 1;
        $satirmiktari += $satirmiktari;
        printer_delete_font($font);
        printer_delete_pen($pen);
        printer_end_page($handle);
        printer_end_doc($handle);
        printer_close($handle);

        $handle = printer_open($fisyazici);
        printer_start_doc($handle, "Lumen-doviz");
        printer_start_page($handle);
        printer_set_option($handle, PRINTER_MODE, "RAW");
        printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT);

        $font = printer_create_font("Tahoma", 100, 35, 00, false, false, false, 1);
        printer_select_font($handle, $font);
        $pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
        printer_select_pen($handle, $pen);

        printer_draw_text($handle, "sayfa : " . $sayfano, 4400, 400);
        printer_draw_text($handle, "KODU", 350, 590);
        printer_draw_text($handle, "ACIKLAMA", 800, 590);
        printer_draw_text($handle, "MIKTAR", 3500, 590);
        printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "FIYAT ({$dovizSembol})"), 4000, 590);
        printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "TOPLAM ({$dovizSembol})"), 4400, 590);
        printer_draw_line($handle, 30, 590, 4800, 590);

        $font = printer_create_font("Tahoma", 95, 25, 00, false, false, false, 1);
        printer_select_font($handle, $font);
        $pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
        printer_select_pen($handle, $pen);

        $a = $baslikyuksekligi;
        $b = $satiryukseklik;
    }

    $i++;
    $a += $b;
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "[      ]"), 10, $a);
    printer_draw_text($handle, $i, 180, $a);
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", (string) $kodu), 350, $a);
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", $adi . '  ' . $aciklama), 800, $a);
    printer_draw_text($handle, kusuratsifir($liste['AMOUNT']), 3550, $a);
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", (string) $liste['BIRIM']), 3750, $a);
    printer_draw_text($handle, $dovizSembol . ' ' . kusuratpara($dovizBirimFiyat), 4000, $a);
    printer_draw_text($handle, $dovizSembol . ' ' . kusuratpara($dovizToplam), 4400, $a);
    printer_draw_line($handle, 30, $a, 4800, $a);
}

$durum = $sqlx['BAKIYE'] + $listfis['GROSSTOTAL'];
$dovizGrossToplam = $listfis['GROSSTOTAL'] / $dovizfiyat;

$a = $a + $b + 10;
$c = $a + $b + 10;

printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "bu fis ({$dovizKod}):"), 3300, $a + 100);
printer_draw_text($handle, $dovizSembol . ' ' . kusuratpara($dovizGrossToplam), 4300, $a + 100);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "bu fis (TL):"), 3300, $c + 100);
printer_draw_text($handle, kusuratpara($listfis['GROSSTOTAL']) . ' TL', 4300, $c + 100);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "*dovizli teklif fisidir resmi belge yerine gecmez"), 100, $a + 100);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "*KIRIK VE EKSIK URUNLERI 7 GUN ICINDE BILDIRINIZ"), 100, $a + 450);
printer_draw_text($handle, trcevir($listfis['GENEXP1']), 2000, $a + 170);

printer_delete_font($font);
printer_delete_pen($pen);
printer_end_page($handle);
printer_end_doc($handle);
printer_close($handle);

$ficheNoDisplay = htmlspecialchars((string)($listfis['FICHENO'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yazıcıya Gönderildi - Lumen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      window.addEventListener('load', function(){
        if (typeof tailwind === 'undefined') {
          var s = document.createElement('script');
          s.src = '/tm/css/tailwind.js';
          document.head.appendChild(s);
        }
      });
    </script>
    <meta http-equiv="refresh" content="4;url=fis.php?id=<?php echo $stokhareket; ?>">
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --sky: #0284c7;
            --sky-soft: #e0f2fe;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { font-family: 'Avenir Next', 'Montserrat', 'Segoe UI', Tahoma, sans-serif; }
        body {
            min-height: 100vh;
            background:
                radial-gradient(1200px 600px at 8% -10%, rgba(56, 189, 248, .14), transparent 60%),
                radial-gradient(900px 500px at 92% 110%, rgba(16, 185, 129, .10), transparent 55%),
                var(--bg);
            color: var(--text-1);
            -webkit-font-smoothing: antialiased;
        }
        .pwa-header {
            position: sticky;
            top: 0;
            z-index: 40;
            background: linear-gradient(135deg, #0ea5e9 0%, var(--sky) 100%);
            color: #fff;
            padding: 14px 16px;
            padding-top: calc(14px + env(safe-area-inset-top, 0px));
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.22);
        }
        .pwa-header .back {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255,255,255,0.16);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: background 0.2s;
        }
        .pwa-header .back:hover { background: rgba(255,255,255,0.26); }
        .pwa-header .title-block { display: flex; flex-direction: column; min-width: 0; }
        .pwa-header .title-block .eyebrow {
            font-size: 10.5px;
            font-weight: 600;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            opacity: 0.8;
        }
        .pwa-header .title-block h1 {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }
        .container-wrap {
            max-width: 560px;
            margin: 0 auto;
            padding: 32px 20px 40px;
            display: flex;
            justify-content: center;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(14, 165, 233, 0.20);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            padding: 40px 32px;
            text-align: center;
            max-width: 520px;
            width: 100%;
            will-change: transform, opacity;
            animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .icon-box {
            width: 92px;
            height: 92px;
            margin: 0 auto 22px;
            border-radius: 24px;
            background: linear-gradient(135deg, var(--emerald-soft) 0%, #d1fae5 100%);
            color: var(--emerald);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 44px;
            box-shadow: inset 0 0 0 1px rgba(5, 150, 105, 0.18);
        }
        .success-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-1);
            margin-bottom: 10px;
            letter-spacing: -0.01em;
        }
        .success-desc {
            font-size: 14px;
            color: var(--text-2);
            line-height: 1.65;
            margin-bottom: 22px;
        }
        .info-row {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: var(--sky-soft);
            color: var(--sky);
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 26px;
        }
        .info-row i { opacity: 0.85; }
        .actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            min-height: 44px;
            padding: 12px 22px;
            border-radius: 12px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: var(--sky);
            color: #fff;
            box-shadow: 0 4px 14px rgba(2, 132, 199, 0.28);
        }
        .btn-primary:hover { background: #0369a1; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(2, 132, 199, 0.34); }
        .btn-ghost {
            background: #fff;
            color: var(--text-1);
            border-color: var(--border);
        }
        .btn-ghost:hover { border-color: var(--sky); color: var(--sky); }
        .redirect-note {
            margin-top: 22px;
            font-size: 12px;
            color: var(--text-3);
        }
        .redirect-note span { color: var(--sky); font-weight: 700; }
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @media (max-width: 480px) {
            .container-wrap { padding: 24px 16px 32px; }
            .glass-card { padding: 32px 22px; border-radius: 16px; }
            .success-title { font-size: 19px; }
            .success-desc { font-size: 13px; }
            .btn { padding: 12px 18px; font-size: 14px; min-height: 44px; }
            .icon-box { width: 76px; height: 76px; margin-bottom: 18px; font-size: 36px; }
            .pwa-header { padding: 12px 14px; padding-top: calc(12px + env(safe-area-inset-top, 0px)); }
            .pwa-header .title-block h1 { font-size: 16px; }
        }
    </style>
</head>
<body>
    <?php if (!headers_sent()): /* guarded pwa-header */ endif; ?>
    <header class="pwa-header" role="banner">
        <a href="siparisler.php" class="back" aria-label="Geri">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div class="title-block">
            <span class="eyebrow"><i class="fa-solid fa-print"></i>&nbsp; Döviz Yazdırma</span>
            <h1>Yazıcıya Gönderildi</h1>
        </div>
    </header>

    <div class="container-wrap">
        <main class="glass-card" id="successCard">
            <div class="icon-box">
                <i class="fa-solid fa-circle-check"></i>
            </div>

            <h2 class="success-title">Başarıyla Yazdırıldı</h2>
            <p class="success-desc">
                Dövizli teklif fişi yazıcıya gönderildi. Fiş çıktısını kontrol edebilirsiniz.
            </p>

            <?php if ($ficheNoDisplay !== ''): ?>
            <div class="info-row">
                <i class="fa-solid fa-receipt"></i>
                Fiş No: <?php echo $ficheNoDisplay; ?>
            </div>
            <?php endif; ?>

            <div class="actions">
                <a class="btn btn-primary" href="fis.php?id=<?php echo $stokhareket; ?>">
                    <i class="fa-solid fa-file-invoice"></i>
                    Fişe Dön
                </a>
                <a class="btn btn-ghost" href="siparisler.php">
                    <i class="fa-solid fa-list"></i>
                    Siparişlere Dön
                </a>
            </div>

            <p class="redirect-note"><span id="timer">4</span> saniye içinde fiş sayfasına yönlendirileceksiniz</p>
        </main>
    </div>

    <script>
        requestAnimationFrame(function(){
            var c = document.getElementById('successCard');
            if (c) { void c.offsetWidth; }
        });
        (function(){
            var s = 4;
            var t = document.getElementById('timer');
            var iv = setInterval(function(){
                s--;
                if (t) t.textContent = s;
                if (s <= 0) clearInterval(iv);
            }, 1000);
        })();
    </script>
</body>
</html>
