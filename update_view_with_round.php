<?php
declare(strict_types=1);

/**
 * update_view_with_round.php — TEK SEFERLİK BAKIM ARACI (kilitlendi 2026-07-13).
 *
 * M_FIS_HAREKETLERI view'ını DROP + CREATE eder (dövizli fiş yazdırma için).
 * Eskiden: yalnız login isterdi, display_errors AÇIK, GET ile üretim view'ını
 * düşürürdü — herhangi bir oturum sahibi (hatta tarayıcı ön-yüklemesi) view'ı
 * silebilirdi. Artık: yalnız ADMIN + POST + CSRF + açık onay. GET ise sadece
 * onay formunu gösterir, hiçbir şey değiştirmez.
 */

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';

// Yalnız yönetici (fail-closed)
if ((int) ($yetkidurum ?? 1) !== 0) {
    http_response_code(403);
    die('Bu bakim aracina yalnizca yonetici erisebilir.');
}

echo "<h1>M_FIS_HAREKETLERI VIEW - Yuvarlanmış Döviz Değerleri</h1>";
echo "<hr>";

// Değiştiren işlem yalnız POST + CSRF + onay ile çalışır (stray GET view'ı düşüremez)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['onay'] ?? '') !== 'EVET') {
    echo '<p><strong>Bu araç M_FIS_HAREKETLERI view\'ını siler ve yeniden oluşturur.</strong></p>';
    echo '<p>Devam etmek için onaylayın:</p>';
    echo '<form method="post">';
    echo csrf_field();
    echo '<input type="hidden" name="onay" value="EVET">';
    echo '<button type="submit" style="padding:10px 18px;background:#b91c1c;color:#fff;border:0;border-radius:8px;cursor:pointer;font-weight:700;">View\'ı Yeniden Oluştur</button>';
    echo '</form>';
    exit;
}
if (!csrf_verify()) {
    http_response_code(403);
    die('Geçersiz güvenlik doğrulaması (CSRF).');
}

try {
    // VIEW'ı sil
    echo "<h2>1. Eski VIEW siliniyor...</h2>";
    $dbh->exec("DROP VIEW IF EXISTS M_FIS_HAREKETLERI");
    echo "<p style='color:green;'>✓ Eski VIEW silindi</p>";

    echo "<hr>";
    echo "<h2>2. Yeni VIEW oluşturuluyor (yuvarlanmış değerlerle)...</h2>";

    // Yeni VIEW - Tüm döviz değerleri ROUND ile 2 ondalık basamağa yuvarlanmış
    $sql_new_view = "
    CREATE VIEW [dbo].[M_FIS_HAREKETLERI] AS
    -- SADECE 2026 Dönemi (LG_001_02_) + Döviz Desteği (Yuvarlanmış)
    SELECT
        I.LOGICALREF STOK_ID,
        O.ORDFICHEREF AS FIS_NO,
        O.LINENO_ AS SIRA_NO,
        O.LINEEXP AS SATIR_ACIKLAMA,
        I.CODE AS STOK_KODU,
        I.NAME + ' ' + ISNULL(O.LINEEXP, '') AS STOK_ADI,
        I.STGRPCODE,
        I.SPECODE,
        O.VAT,
        O.AMOUNT AS MIKTAR,
        L.CODE AS BIRIM,
        CASE S.CODE WHEN 'KOLI' THEN (O.AMOUNT * O.UINFO2 / O.UINFO1 / ISNULL(NULLIF(U.CONVFACT2,0),1) / ISNULL(NULLIF(U.CONVFACT1,0),1))
        ELSE (O.AMOUNT * O.UINFO2 / O.UINFO1 / ISNULL(NULLIF(U2.CONVFACT2,0),1) / ISNULL(NULLIF(U2.CONVFACT1,0),1)) END AS UNIT2AMOUNT,
        CASE S.CODE WHEN 'KOLI' THEN (ISNULL(NULLIF(U.CONVFACT2,0),1)/ISNULL(NULLIF(U.CONVFACT1,0),1))
        ELSE (ISNULL(NULLIF(U2.CONVFACT2,0),1)/ISNULL(NULLIF(U2.CONVFACT1,0),1)) END AS UNIT2FACTOR,
        S.CODE AS BIRIM2,
        ISNULL(NULLIF(U.CONVFACT2,0),1) / ISNULL(NULLIF(U.CONVFACT1,0),1) AS KOLIICI,
        O.AMOUNT / NULLIF(ISNULL(NULLIF(U.CONVFACT2,0),1) / ISNULL(NULLIF(U.CONVFACT1,0),1), 0) AS KOLI,
        O.PRICE AS FIYATI,
        O.TOTAL AS TUTAR,
        CASE O.AMOUNT WHEN 0 THEN 0 ELSE O.LINENET / O.AMOUNT END AS NETFIYATI,
        (SELECT TOP 1 BARCODE FROM LG_001_UNITBARCODE WHERE ITEMREF = I.LOGICALREF AND LINENR = 1) BARKODU,

        -- ===== DÖVİZ SÜTUNLARI (Yuvarlanmış) =====
        F.TRCODE,
        F.TRRATE,

        -- B2 Sütunları (Satır seviyesi döviz bilgileri) - 2 ondalık basamağa yuvarlanmış
        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN O.PRICE / F.TRRATE
                ELSE O.PRICE
            END, 2
        ) AS BRUT_DOVIZ_FIYATI,

        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN (CASE O.AMOUNT WHEN 0 THEN 0 ELSE O.LINENET / O.AMOUNT END) / F.TRRATE
                ELSE (CASE O.AMOUNT WHEN 0 THEN 0 ELSE O.LINENET / O.AMOUNT END)
            END, 2
        ) AS NET_DOVIZ_FIYATI,

        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN (O.TOTAL + O.VATAMNT) / F.TRRATE
                ELSE (O.TOTAL + O.VATAMNT)
            END, 2
        ) AS DOVIZLI_TUTAR,

        -- B1 Sütunları (Fiş seviyesi döviz bilgileri) - 2 ondalık basamağa yuvarlanmış
        CASE F.TRCODE
            WHEN 0 THEN N'₺'
            WHEN 1 THEN N'₺'
            WHEN 20 THEN N'$'
            WHEN 21 THEN N'€'
            WHEN 22 THEN N'£'
            ELSE N'₺'
        END AS DOVIZ_SEMBOL,

        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN F.GROSSTOTAL / F.TRRATE
                ELSE F.GROSSTOTAL
            END, 2
        ) AS TOPLAM_DVZ,

        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN F.TOTALDISCOUNTS / F.TRRATE
                ELSE F.TOTALDISCOUNTS
            END, 2
        ) AS ORDSCT1_DVZ,

        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN F.TOTALVAT / F.TRRATE
                ELSE F.TOTALVAT
            END, 2
        ) AS TOTALVAT_DVZ,

        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN F.NETTOTAL / F.TRRATE
                ELSE F.NETTOTAL
            END, 2
        ) AS NETTOPLAM_DVZ,

        -- Bakiye (yuvarlanmış)
        CAST(0.0 AS FLOAT) AS BAKIYE_DVZ,

        ROUND(
            CASE
                WHEN F.TRRATE > 0 THEN F.NETTOTAL / F.TRRATE
                ELSE F.NETTOTAL
            END, 2
        ) AS GENELTOPLAM_DVZ

    FROM dbo.LG_001_02_ORFLINE AS O WITH (NOLOCK)
    INNER JOIN dbo.LG_001_ITEMS AS I WITH (NOLOCK) ON O.STOCKREF = I.LOGICALREF AND O.LINETYPE = 0
    INNER JOIN dbo.LG_001_02_ORFICHE AS F WITH (NOLOCK) ON O.ORDFICHEREF = F.LOGICALREF
    LEFT OUTER JOIN dbo.LG_001_UNITSETL AS L WITH (NOLOCK) ON L.LOGICALREF = O.UOMREF
    LEFT OUTER JOIN dbo.LG_001_ITMUNITA AS U WITH (NOLOCK) ON I.LOGICALREF = U.ITEMREF AND U.LINENR = 2
    LEFT OUTER JOIN dbo.LG_001_ITMUNITA AS U2 WITH (NOLOCK) ON I.LOGICALREF = U2.ITEMREF AND U2.LINENR = 3
    LEFT OUTER JOIN dbo.LG_001_UNITSETL AS S WITH (NOLOCK) ON U.UNITLINEREF = S.LOGICALREF
    ";

    $dbh->exec($sql_new_view);
    echo "<p style='color:green;'>✓ Yeni VIEW başarıyla oluşturuldu (tüm değerler 2 ondalık basamağa yuvarlanmış)!</p>";

    echo "<hr>";
    echo "<h2>3. VIEW Test Ediliyor...</h2>";

    // VIEW'ı test et
    $stmt_test = $dbh->query("SELECT TOP 3 FIS_NO, BRUT_DOVIZ_FIYATI, NET_DOVIZ_FIYATI, DOVIZLI_TUTAR, TOPLAM_DVZ, ORDSCT1_DVZ, NETTOPLAM_DVZ, DOVIZ_SEMBOL FROM M_FIS_HAREKETLERI");
    $rows = $stmt_test->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 0) {
        echo "<p style='color:green;'>✓ VIEW başarıyla çalışıyor!</p>";

        echo "<h3>Örnek Döviz Değerleri (Yuvarlanmış):</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>FIS_NO</th><th>Brüt Fiyat</th><th>Net Fiyat</th><th>Satır Toplam</th><th>Fiş Toplam</th><th>İndirim</th><th>Net Toplam</th><th>Sembol</th></tr>";

        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>" . $row['FIS_NO'] . "</td>";
            echo "<td>" . number_format($row['BRUT_DOVIZ_FIYATI'], 2) . "</td>";
            echo "<td>" . number_format($row['NET_DOVIZ_FIYATI'], 2) . "</td>";
            echo "<td>" . number_format($row['DOVIZLI_TUTAR'], 2) . "</td>";
            echo "<td>" . number_format($row['TOPLAM_DVZ'], 2) . "</td>";
            echo "<td>" . number_format($row['ORDSCT1_DVZ'], 2) . "</td>";
            echo "<td>" . number_format($row['NETTOPLAM_DVZ'], 2) . "</td>";
            echo "<td>" . $row['DOVIZ_SEMBOL'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        echo "<p style='color:green;'><strong>✓ Artık tüm döviz değerleri 2 ondalık basamakla gösterilecek!</strong></p>";
    }

    echo "<hr>";
    echo "<h2 style='color:green;'>✓ İşlem Başarıyla Tamamlandı!</h2>";
    echo "<p>Şimdi mobilyazlogo.exe'den dövizli fiş yazdırmayı test edin. Fiyatlar düzgün formatlanmış olacak.</p>";

} catch (Exception $e) {
    echo "<p style='color:red;'>✗ HATA: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

?>
