<?php
declare(strict_types=1);

// printer_open
if (!function_exists('printer_open')) {
    function printer_open($device)
    {
        // Normalde yazıcı açar, biz sadece geriye sahte bir kaynak döndürüyoruz
        return fopen('php://memory', 'r+');
    }
}

// printer_close
if (!function_exists('printer_close')) {
    function printer_close($handle): void
    {
        // Normalde yazıcıyı kapatır, biz sahte handle'ı kapatıyoruz
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
}

// printer_start_doc
if (!function_exists('printer_start_doc')) {
    function printer_start_doc($handle, $docName): bool
    {
        // Normalde yazıcıda doküman başlatır
        return true;
    }
}

// printer_end_doc
if (!function_exists('printer_end_doc')) {
    function printer_end_doc($handle): bool
    {
        // Normalde dokümanı sonlandırır
        return true;
    }
}

// printer_start_page
if (!function_exists('printer_start_page')) {
    function printer_start_page($handle): bool
    {
        // Normalde yeni sayfa açar
        return true;
    }
}

// printer_end_page
if (!function_exists('printer_end_page')) {
    function printer_end_page($handle): bool
    {
        // Normalde sayfayı kapatır
        return true;
    }
}

// printer_set_option
if (!function_exists('printer_set_option')) {
    function printer_set_option($handle, $option, $value): bool
    {
        // Normalde yazıcı ayarlarını değiştirir
        return true;
    }
}

// printer_create_pen
if (!function_exists('printer_create_pen')) {
    function printer_create_pen($style, $width, $color): string
    {
        // Normalde bir kalem nesnesi oluşturur
        return "fake_pen";
    }
}

// printer_create_font
if (!function_exists('printer_create_font')) {
    function printer_create_font($face, $height, $width, $fontWeight, $italic, $underline, $strikeout, $orientation): string
    {
        // Normalde bir font nesnesi oluşturur
        return "fake_font";
    }
}

// printer_select_pen
if (!function_exists('printer_select_pen')) {
    function printer_select_pen($handle, $pen): bool
    {
        // Normalde belirtilen pen'i seçer
        return true;
    }
}

// printer_select_font
if (!function_exists('printer_select_font')) {
    function printer_select_font($handle, $font): bool
    {
        // Normalde belirtilen fontu seçer
        return true;
    }
}

// printer_delete_font
if (!function_exists('printer_delete_font')) {
    function printer_delete_font($font): bool
    {
        // Normalde oluşturulan fontu siler
        return true;
    }
}

// printer_delete_pen
if (!function_exists('printer_delete_pen')) {
    function printer_delete_pen($pen): bool
    {
        // Normalde oluşturulan pen'i siler
        return true;
    }
}

// printer_draw_text
if (!function_exists('printer_draw_text')) {
    function printer_draw_text($handle, $text, $x, $y): bool
    {
        // Normalde yazıcıya metin basar
        return true;
    }
}

// printer_draw_line
if (!function_exists('printer_draw_line')) {
    function printer_draw_line($handle, $x1, $y1, $x2, $y2): bool
    {
        // Normalde iki nokta arasında çizgi çizer
        return true;
    }
}

// printer_draw_roundrect
if (!function_exists('printer_draw_roundrect')) {
    function printer_draw_roundrect($handle, $x, $y, $width, $height, $radius1, $radius2): bool
    {
        // Normalde yuvarlak köşeli dikdörtgen çizer
        return true;
    }
}
?>

<?php
require_once __DIR__ . '/kontrol.php';
// Güvenlik ve veritabanı bağlantısı, özel fonksiyonlar burada:
include_once(__DIR__ . "/ayr.php");

// stokhareket parametresini al, integer'a çevir
if (!isset($_GET['stokhareket'])) {
    echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>';
    exit;
}
$stokhareket = intval($_GET['stokhareket']);

// 1) Fiş bilgilerini çek
$stmtFis = $dbh->prepare("
    SELECT
        CLIENTREF,
        GROSSTOTAL,
        GENEXP1,
        DATE_,
        FICHENO
    FROM {$firmadonem}ORFICHE
    WHERE LOGICALREF = ?
");
$stmtFis->execute([$stokhareket]);
$listfis = $stmtFis->fetch(PDO::FETCH_ASSOC);

if (!$listfis) {
    echo '<div class="notification msgerror">Geçersiz fiş numarası!</div>';
    exit;
}

// 2) Cari ID'yi çek ve cari bakiye sorgusu yap
$cariid = intcevir($listfis['CLIENTREF']);

$stmtCari = $dbh->prepare("
    SELECT
        CLCARD.CODE        AS KODU,
        CLCARD.LOGICALREF  AS CARIID,
        CLCARD.DEFINITION_ AS UNVANI,
        SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT) - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE
    FROM {$firma}CLCARD CLCARD
    LEFT JOIN {$firmadonem}CLFLINE CLFLINE ON CLCARD.LOGICALREF = CLFLINE.CLIENTREF
        AND CLFLINE.CANCELLED = 0
    WHERE
        CLCARD.LOGICALREF = ?
        AND CLCARD.ACTIVE = 0
    GROUP BY
        CLCARD.CODE,
        CLCARD.DEFINITION_,
        CLCARD.ACTIVE,
        CLCARD.LOGICALREF
");
$stmtCari->execute([$cariid]);
$sqlx = $stmtCari->fetch(PDO::FETCH_ASSOC);

// 3) Satır sayısı sorgusu
$stmtSatir = $dbh->prepare("
    SELECT COUNT(*) AS SAYI
    FROM {$firmadonem}ORFLINE
    WHERE ORDFICHEREF = ?
");
$stmtSatir->execute([$stokhareket]);
$satirsay = $stmtSatir->fetch(PDO::FETCH_ASSOC);
$satirtoplam = $satirsay["SAYI"];

// Yazdırma ayarları
$satirmiktari = 40;   // Bir sayfada gösterilecek satır adedi
$yenisatirmiktari = $satirmiktari;
$sayfano = 1;
$satiryukseklik = 130;
$baslikyuksekligi = 630;
$stokfont = 120;
$stokfontx = 40;
$tarih = date("d/m/Y H:i:s");

// Yazıcı adı (ayr.php içinden geliyor olabilir)
$handle = printer_open($fisyazici);
printer_start_doc($handle, "Lumen");
printer_start_page($handle);
printer_set_option($handle, PRINTER_MODE, "RAW");

// Başlık Pen/Font
$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
$font = printer_create_font("Tahoma", 280, 55, 100, false, false, false, 1);
printer_select_pen($handle, $pen);
printer_select_font($handle, $font);

// Başlık
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "TEKLİF FİŞİ"), 2000, 100);

// Alt başlık font
printer_delete_font($font);
$font = printer_create_font("Tahoma", $stokfont, $stokfontx, 0, false, false, false, 1);
printer_select_font($handle, $font);

// Cari ve fiş bilgileri
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "Sayın:"), 100, 400);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", (string) $sqlx['UNVANI']), 400, 400);
printer_draw_text($handle, tarihcevir($listfis['DATE_']), 4200, 200);
printer_draw_text($handle, "sayfa : " . $sayfano, 4400, 400);

// Tablo üst çerçeve
printer_draw_roundrect($handle, 10, 580, 4800, 720, 0, 0);

// Tablo başlıkları
$font = printer_create_font("Tahoma", 98, 33, 0, false, false, false, 1);
printer_select_font($handle, $font);

printer_draw_text($handle, "KODU", 350, 590);
printer_draw_text($handle, "ACIKLAMA", 800, 590);
printer_draw_text($handle, "KOLI", 2400, 590);
printer_draw_text($handle, "BR", 2600, 590);
printer_draw_text($handle, "K.ICI", 2800, 590);
printer_draw_text($handle, "K.FIYAT", 3100, 590);
printer_draw_text($handle, "MIKTAR", 3500, 590);
printer_draw_text($handle, "FIYAT", 4100, 590);
printer_draw_text($handle, "TOPLAM", 4400, 590);

$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);
$font = printer_create_font("Tahoma", 98, 33, 0, false, false, false, 1);
printer_select_font($handle, $font);

// Değişkenler
$i = 0;
$a = $baslikyuksekligi;
$b = $satiryukseklik;
$hareketFisToplami = 0;
$gtoplam = 0;
$gisk = 0;
$kolitoplam = 0;
$adetmiktar = 0;
$koli = 0;

// Satırları çek
$stmtLines = $dbh->prepare("
    SELECT
        L.PRICE,
        L.AMOUNT,
        L.TOTAL,
        L.VATMATRAH,
        L.STOCKREF,
        L.LOGICALREF,
        L.ORDFICHEREF,
        L.LINEEXP,
        I.CODE AS SKODU,
        I.NAME AS SADI,
        U.CODE AS BIRIM
    FROM {$firmadonem}ORFLINE AS L
    LEFT JOIN {$firma}ITEMS I ON L.STOCKREF = I.LOGICALREF
    LEFT JOIN {$firma}UNITSETL U ON L.UOMREF = U.LOGICALREF
    WHERE L.ORDFICHEREF = ?
");
$stmtLines->execute([$stokhareket]);

while ($liste = $stmtLines->fetch(PDO::FETCH_ASSOC)) {
    // Hesaplamalar
    $toplam = $liste['AMOUNT'] * $liste['PRICE'];
    $gtoplam += $toplam;
    $gisk += $liste['VATMATRAH'];

    $stokhareketid = intcevir($liste['LOGICALREF']);
    $stokhid = intcevir($liste['STOCKREF']);
    $kodu = trcevir($liste['SKODU']);
    $adi = trcevir($liste['SADI']);
    $aciklama = trcevir($liste['LINEEXP']);

    // Koli içi bilgisi (sadece ürünün mevcut birim setinden)
    $stmtKoli = $dbh->prepare("
        SELECT
            ICBIRIM.CONVFACT2 AS CARPAN,
            BR.CODE
        FROM {$firma}ITMUNITA ICBIRIM
        LEFT JOIN {$firma}UNITSETL BR ON ICBIRIM.UNITLINEREF = BR.LOGICALREF
        LEFT JOIN {$firma}ITEMS STK ON ICBIRIM.ITEMREF = STK.LOGICALREF
        WHERE ICBIRIM.ITEMREF = ?
          AND ICBIRIM.LINENR  = 2
          AND BR.UNITSETREF = STK.UNITSETREF
    ");
    $stmtKoli->execute([$stokhid]);
    $koliici = $stmtKoli->fetch(PDO::FETCH_ASSOC);

    if ($koliici && $koliici['CARPAN'] > 1) {
        $koli = $liste['AMOUNT'] / $koliici['CARPAN'];
        $kolifiyat = $koliici['CARPAN'] * $liste['PRICE'];
        $kolibirim = $koliici['CODE'];
        $kolitoplam += ($liste['AMOUNT'] / $koliici['CARPAN']);
    } else {
        $koli = '';
        $kolifiyat = '';
        $kolibirim = '';
    }

    $adetmiktar += $liste['AMOUNT'];

    // Çoklu sayfa kontrolü
    if ($yenisatirmiktari == $i) {
        // Mevcut sayfayı sonlandır
        printer_delete_font($font);
        printer_delete_pen($pen);
        printer_end_page($handle);
        printer_end_doc($handle);
        printer_close($handle);

        // Yeni sayfa
        $sayfano++;
        $yenisatirmiktari += $satirmiktari;

        $handle = printer_open($fisyazici);
        printer_start_doc($handle, "My Document");
        printer_start_page($handle);
        printer_set_option($handle, PRINTER_MODE, "RAW");

        // Yeni sayfa font/pen
        $font = printer_create_font("Tahoma", 100, 35, 0, false, false, false, 1);
        printer_select_font($handle, $font);
        $pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
        printer_select_pen($handle, $pen);

        printer_draw_text($handle, "sayfa : " . $sayfano, 4400, 400);

        // Tablo başlıkları tekrar
        printer_draw_text($handle, "KODU", 350, 590);
        printer_draw_text($handle, "ACIKLAMA", 800, 590);
        printer_draw_text($handle, "KOLI", 2400, 590);
        printer_draw_text($handle, "BR", 2600, 590);
        printer_draw_text($handle, "K.ICI", 2800, 590);
        printer_draw_text($handle, "K.FIYAT", 3100, 590);
        printer_draw_text($handle, "MIKTAR", 3500, 590);
        printer_draw_text($handle, "FIYAT", 4100, 590);
        printer_draw_text($handle, "TOPLAM", 4400, 590);

        printer_draw_line($handle, 30, 590, 4800, 590);

        $font = printer_create_font("Tahoma", 95, 25, 0, false, false, false, 1);
        printer_select_font($handle, $font);

        $pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
        printer_select_pen($handle, $pen);

        $a = $baslikyuksekligi;
        $b = $satiryukseklik;
    }

    $i++;
    $a += $b;

    // Satır çizimi
    printer_draw_roundrect($handle, 5, $a + 5, 225, $a + 120, 0, 0);
    printer_draw_text($handle, $i, 250, $a);
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", (string) $kodu), 400, $a);
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", $adi . '  ' . $aciklama), 850, $a);
    printer_draw_text($handle, kusuratadet($koli), 2400, $a);
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", (string) $kolibirim), 2650, $a);
    printer_draw_text(
        $handle,
        kusuratsifir($koliici['CARPAN'] ?? ''),
        2900,
        $a
    );
    printer_draw_text($handle, kusuratpara($kolifiyat), 3100, $a);
    printer_draw_text($handle, kusuratsifir($liste['AMOUNT']), 3550, $a);
    printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", (string) $liste['BIRIM']), 3750, $a);
    printer_draw_text($handle, kusuratpara($liste['PRICE']), 4150, $a);
    printer_draw_text($handle, kusuratpara($liste['TOTAL']), 4400, $a);

    printer_draw_line($handle, 30, $a, 4800, $a);
}

// Son kısım çizimler
printer_draw_line($handle, 30, $a + 130, 4800, $a + 130);

$durum = $sqlx['BAKIYE'] + $listfis['GROSSTOTAL'];

$a = $a + $b + 10;
$c = $a + $b + 10;

printer_draw_roundrect($handle, 20, $a + 5, 4800, $a + 600, 0, 0);

// Bilgi metinleri
printer_draw_text($handle, "bu fis:", 3500, $a + 100);
printer_draw_text($handle, kusuratpara($listfis['GROSSTOTAL']), 4300, $a + 100);

printer_draw_text($handle, "eski  bakiye:", 3500, $c + 100);
printer_draw_text($handle, kusuratpara($sqlx['BAKIYE']), 4300, $c + 100);

printer_draw_text($handle, "son  bakiye:", 3500, $c + 220);
printer_draw_text($handle, kusuratpara($durum), 4300, $c + 220);

printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "*teklif fişidir resmi belge yerine geçmez"), 100, $a + 100);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9", "*KIRIK VE EKSİK ÜRÜNLERİ 7 GÜN İÇİNDE BİLDİRİNİZ AKSİ TAKTİRDE FİRMAMIZ MESUL DEĞİLDİR"), 100, $a + 450);

// Fiş GENEXP1 alanı
printer_draw_text($handle, trcevir($listfis['GENEXP1']), 2000, $a + 170);

// Yazdırma sonlandırma
printer_delete_font($font);
printer_delete_pen($pen);
printer_end_page($handle);
printer_end_doc($handle);
printer_close($handle);

// İşlem bittiğinde index'e yönlendir
echo '<script>window.location="index.php";</script>';
?>
