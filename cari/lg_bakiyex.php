<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../log_ip.php");

// YETKI KONTROLÜ: CR1 (Cari Bakiye) yetkisi kontrolü
if (m_p_yetki($terminalkullanici, 'CR1') != 1) {
    echo '<div class="text-center text-red-600 p-4">Bu bilgileri görme yetkiniz yok!</div>';
    exit;
}

	$tarih = date("Y.m.d");
		$tarih2 = date("Y.m.d");

		$id = isset($_POST['userid']) ? (int) $_POST['userid'] : 0;
        if ($id <= 0 || !m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $id, 'M4')) {
            http_response_code(403);
            echo '<div class="text-center text-red-600 p-4">Bu cari detayini gorme yetkiniz yok!</div>';
            exit;
        }

	// Not: eskiden burada çıktıda hiç kullanılmayan 10 günlük toplam ve bir satır
	// sorgusu daha çalışıyordu (her pencere açılışında); kaldırıldı.
	if (lumen_bagimsiz_mi()) {
	// Bağımsız mod: aynı değerler Lumen tablolarından (LM_BELGE, LM_CARI_HAREKET, LM_CEK).
	$tek = static function (string $sql) use ($dbh, $id): array {
		$st = $dbh->prepare($sql);
		$st->execute([':id' => $id]);
		return ['TOPLAM' => (float) ($st->fetchColumn() ?: 0)];
	};
	$satis = $tek("SELECT SUM(NET) FROM dbo.LM_BELGE WHERE CARI_ID = :id AND TUR = 3 AND YON = 1 AND DURUM = 1");
	$alis = $tek("SELECT SUM(NET) FROM dbo.LM_BELGE WHERE CARI_ID = :id AND TUR = 3 AND YON = 2 AND DURUM = 1");
	// Tahsilat/ödeme: finans fişi (KAYNAK 2) ve çek hareketi (KAYNAK 3) — alacak tahsilat, borç ödeme.
	$tahsilat = $tek("SELECT SUM(ALACAK) FROM dbo.LM_CARI_HAREKET WHERE CARI_ID = :id AND KAYNAK IN (2, 3)");
	$odeme = $tek("SELECT SUM(BORC) FROM dbo.LM_CARI_HAREKET WHERE CARI_ID = :id AND KAYNAK IN (2, 3)");
	$bekleyen_cek = $tek("SELECT SUM(TUTAR) FROM dbo.LM_CEK WHERE ALINAN_CARI_ID = :id AND TUR = 1 AND DURUM = 1");
	$odenen_cek = $tek("SELECT SUM(TUTAR) FROM dbo.LM_CEK WHERE ALINAN_CARI_ID = :id AND TUR = 1 AND DURUM = 3");

	$stmtFavoriUrunler = $dbh->prepare("
	    SELECT TOP 5 S.KOD AS STOK_KODU, S.AD AS STOK_ADI,
	           SUM(L.MIKTAR * L.CARPAN) AS TOPLAM_MIKTAR, COUNT(DISTINCT B.ID) AS SIPARIS_SAYISI,
	           AVG(L.FIYAT) AS ORTALAMA_FIYAT, MAX(B.TARIH) AS SON_ALIS_TARIHI
	    FROM dbo.LM_BELGE_SATIR L
	    INNER JOIN dbo.LM_BELGE B ON B.ID = L.BELGE_ID
	    INNER JOIN dbo.LM_STOK S ON S.ID = L.STOK_ID
	    WHERE B.CARI_ID = :id AND B.YON = 1 AND B.TUR IN (1, 3) AND B.DURUM <> 9
	    GROUP BY S.KOD, S.AD
	    ORDER BY TOPLAM_MIKTAR DESC");
	$stmtFavoriUrunler->execute([':id' => $id]);
	$favoriUrunler = $stmtFavoriUrunler->fetchAll(PDO::FETCH_ASSOC);
	} else {
		// Satış - İki dönemi birleştir (2025 + 2026)
	$stmtSatis = $dbh->prepare("SELECT SUM(TRNET) AS TOPLAM FROM (
		SELECT F.TRNET FROM " . $firmadonem . "INVOICE F WHERE F.CLIENTREF = :id1 AND F.CANCELLED = 0 AND F.TRCODE IN(7,8)
		UNION ALL
		SELECT F.TRNET FROM " . $eskifirmadonem . "INVOICE F WHERE F.CLIENTREF = :id2 AND F.CANCELLED = 0 AND F.TRCODE IN(7,8)
	) AS COMBINED");
	$stmtSatis->execute([':id1' => $id, ':id2' => $id]);
	$satis = $stmtSatis->fetch(PDO::FETCH_ASSOC);

	// Tahsilat - İki dönemi birleştir (2025 + 2026)
	$stmtTahsilat = $dbh->prepare("SELECT SUM(TRNET) AS TOPLAM FROM (
		SELECT F.TRNET FROM " . $firmadonem . "CLFLINE F WHERE F.CLIENTREF = :id1 AND F.CANCELLED = 0 AND F.TRCODE IN (1,4,20,61,62,70)
		UNION ALL
		SELECT F.TRNET FROM " . $eskifirmadonem . "CLFLINE F WHERE F.CLIENTREF = :id2 AND F.CANCELLED = 0 AND F.TRCODE IN (1,4,20,61,62,70)
	) AS COMBINED");
	$stmtTahsilat->execute([':id1' => $id, ':id2' => $id]);
	$tahsilat = $stmtTahsilat->fetch(PDO::FETCH_ASSOC);

	// Alış - İki dönemi birleştir (2025 + 2026)
	$stmtAlis = $dbh->prepare("SELECT SUM(AMOUNT) AS TOPLAM FROM (
		SELECT AMOUNT FROM " . $firmadonem . "CLFLINE WHERE CLIENTREF = :id1 AND (CANCELLED = 0) AND TRCODE ='31'
		UNION ALL
		SELECT AMOUNT FROM " . $eskifirmadonem . "CLFLINE WHERE CLIENTREF = :id2 AND (CANCELLED = 0) AND TRCODE ='31'
	) AS COMBINED");
	$stmtAlis->execute([':id1' => $id, ':id2' => $id]);
	$alis = $stmtAlis->fetch(PDO::FETCH_ASSOC);

	// Ödeme - İki dönemi birleştir (2025 + 2026)
	$stmtOdeme = $dbh->prepare("SELECT SUM(AMOUNT) AS TOPLAM FROM (
		SELECT AMOUNT FROM " . $firmadonem . "CLFLINE WHERE CLIENTREF = :id1 AND (CANCELLED = 0) AND TRCODE IN(2,3,21,63,64,72)
		UNION ALL
		SELECT AMOUNT FROM " . $eskifirmadonem . "CLFLINE WHERE CLIENTREF = :id2 AND (CANCELLED = 0) AND TRCODE IN(2,3,21,63,64,72)
	) AS COMBINED");
	$stmtOdeme->execute([':id1' => $id, ':id2' => $id]);
	$odeme = $stmtOdeme->fetch(PDO::FETCH_ASSOC);

	// Bekleyen Çek - İki dönemi birleştir (2025 + 2026)
	$stmtBekleyenCek = $dbh->prepare("SELECT
		C.LOGICALREF as CARI_ID,
		C.CODE AS KODU,
		C.DEFINITION_ AS UNVANI,
		(SELECT ISNULL(SUM(TRNET), 0) FROM (
			SELECT LGMAIN.TRNET FROM {$firmadonem}CSCARD LGMAIN WITH(NOLOCK)
			WHERE LGMAIN.CURRSTAT IN (1) AND LGMAIN.STATUS IN (0, 1) AND LGMAIN.DOC = 1
			AND C.LOGICALREF = (SELECT TOP 1 CSTRANS.CARDREF FROM {$firmadonem}CSTRANS CSTRANS WHERE CSTRANS.CSREF = LGMAIN.LOGICALREF)
			UNION ALL
			SELECT LGMAIN2.TRNET FROM {$eskifirmadonem}CSCARD LGMAIN2 WITH(NOLOCK)
			WHERE LGMAIN2.CURRSTAT IN (1) AND LGMAIN2.STATUS IN (0, 1) AND LGMAIN2.DOC = 1
			AND C.LOGICALREF = (SELECT TOP 1 CSTRANS2.CARDREF FROM {$eskifirmadonem}CSTRANS CSTRANS2 WHERE CSTRANS2.CSREF = LGMAIN2.LOGICALREF)
		) AS COMBINED) AS TOPLAM
	FROM {$firma}CLCARD C WITH(NOLOCK)
	WHERE C.ACTIVE = 0 AND C.LOGICALREF = :id
	");
	$stmtBekleyenCek->execute([':id' => $id]);
	$bekleyen_cek = $stmtBekleyenCek->fetch(PDO::FETCH_ASSOC);

	// Ödenen/Tahsil Edilmiş Çek - İki dönemi birleştir (2025 + 2026)
	$stmtOdenenCek = $dbh->prepare("SELECT
		C.LOGICALREF as CARI_ID,
		C.CODE AS KODU,
		C.DEFINITION_ AS UNVANI,
		(SELECT ISNULL(SUM(TRNET), 0) FROM (
			SELECT LGMAIN.TRNET FROM {$firmadonem}CSCARD LGMAIN WITH(NOLOCK)
			WHERE LGMAIN.CURRSTAT IN (8) AND LGMAIN.STATUS IN (0, 1) AND LGMAIN.DOC = 1
			AND C.LOGICALREF = (SELECT TOP 1 CSTRANS.CARDREF FROM {$firmadonem}CSTRANS CSTRANS WHERE CSTRANS.CSREF = LGMAIN.LOGICALREF)
			UNION ALL
			SELECT LGMAIN2.TRNET FROM {$eskifirmadonem}CSCARD LGMAIN2 WITH(NOLOCK)
			WHERE LGMAIN2.CURRSTAT IN (8) AND LGMAIN2.STATUS IN (0, 1) AND LGMAIN2.DOC = 1
			AND C.LOGICALREF = (SELECT TOP 1 CSTRANS2.CARDREF FROM {$eskifirmadonem}CSTRANS CSTRANS2 WHERE CSTRANS2.CSREF = LGMAIN2.LOGICALREF)
		) AS COMBINED) AS TOPLAM
	FROM {$firma}CLCARD C WITH(NOLOCK)
	WHERE C.ACTIVE = 0 AND C.LOGICALREF = :id
	");
	$stmtOdenenCek->execute([':id' => $id]);
	$odenen_cek = $stmtOdenenCek->fetch(PDO::FETCH_ASSOC);

	// Müşterinin favori ürünleri - İki dönemi birleştir (2025 + 2026)
	$stmtFavoriUrunler = $dbh->prepare("
	    SELECT TOP 5
	        STOK_KODU,
	        STOK_ADI,
	        SUM(TOPLAM_MIKTAR) AS TOPLAM_MIKTAR,
	        SUM(SIPARIS_SAYISI) AS SIPARIS_SAYISI,
	        AVG(ORTALAMA_FIYAT) AS ORTALAMA_FIYAT,
	        MAX(SON_ALIS_TARIHI) AS SON_ALIS_TARIHI
	    FROM (
	        SELECT
	            I.CODE AS STOK_KODU,
	            I.NAME AS STOK_ADI,
	            SUM(L.AMOUNT) AS TOPLAM_MIKTAR,
	            COUNT(DISTINCT F.LOGICALREF) AS SIPARIS_SAYISI,
	            AVG(L.PRICE) AS ORTALAMA_FIYAT,
	            MAX(F.DATE_) AS SON_ALIS_TARIHI
	        FROM " . $firmadonem . "ORFLINE L
	        INNER JOIN " . $firmadonem . "ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF
	        INNER JOIN " . $firma . "ITEMS I ON I.LOGICALREF = L.STOCKREF
	        WHERE F.CLIENTREF = :id1
	            AND L.LINETYPE = 0
	            AND F.TRCODE IN (1,7,8)
	        GROUP BY I.CODE, I.NAME
	        UNION ALL
	        SELECT
	            I.CODE AS STOK_KODU,
	            I.NAME AS STOK_ADI,
	            SUM(L.AMOUNT) AS TOPLAM_MIKTAR,
	            COUNT(DISTINCT F.LOGICALREF) AS SIPARIS_SAYISI,
	            AVG(L.PRICE) AS ORTALAMA_FIYAT,
	            MAX(F.DATE_) AS SON_ALIS_TARIHI
	        FROM " . $eskifirmadonem . "ORFLINE L
	        INNER JOIN " . $eskifirmadonem . "ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF
	        INNER JOIN " . $firma . "ITEMS I ON I.LOGICALREF = L.STOCKREF
	        WHERE F.CLIENTREF = :id2
	            AND L.LINETYPE = 0
	            AND F.TRCODE IN (1,7,8)
	        GROUP BY I.CODE, I.NAME
	    ) AS COMBINED
	    GROUP BY STOK_KODU, STOK_ADI
	    ORDER BY TOPLAM_MIKTAR DESC
	");
	$stmtFavoriUrunler->execute([':id1' => $id, ':id2' => $id]);
	$favoriUrunler = $stmtFavoriUrunler->fetchAll(PDO::FETCH_ASSOC);
	}

// Başlangıç response
$response = '<div class="space-y-4">';

// Finansal Özet Kartı
$response .= '<div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-lg border border-blue-200">';
$response .= '<h3 class="text-lg font-bold text-gray-800 mb-3"><i class="fas fa-chart-bar text-blue-600 mr-2"></i>Finansal Özet</h3>';
$response .= '<div class="grid grid-cols-2 gap-3">';

// Satış
$response .= '<div class="bg-white p-3 rounded-lg shadow-sm text-center">';
$response .= '<p class="text-xs text-gray-500 mb-1">Satış</p>';
$response .= '<p class="text-lg font-bold text-blue-600">' . number_format((float) ($satis['TOPLAM'] ?? 0), 2, ',', '.') . ' ₺</p>';
$response .= '</div>';

// Tahsilat
$response .= '<div class="bg-white p-3 rounded-lg shadow-sm text-center">';
$response .= '<p class="text-xs text-gray-500 mb-1">Tahsilat</p>';
$response .= '<p class="text-lg font-bold text-green-600">' . number_format((float) ($tahsilat['TOPLAM'] ?? 0), 2, ',', '.') . ' ₺</p>';
$response .= '</div>';

// Alış
$response .= '<div class="bg-white p-3 rounded-lg shadow-sm text-center">';
$response .= '<p class="text-xs text-gray-500 mb-1">Alış</p>';
$response .= '<p class="text-lg font-bold text-orange-600">' . number_format((float) ($alis['TOPLAM'] ?? 0), 2, ',', '.') . ' ₺</p>';
$response .= '</div>';

// Ödeme
$response .= '<div class="bg-white p-3 rounded-lg shadow-sm text-center">';
$response .= '<p class="text-xs text-gray-500 mb-1">Ödeme</p>';
$response .= '<p class="text-lg font-bold text-red-600">' . number_format((float) ($odeme['TOPLAM'] ?? 0), 2, ',', '.') . ' ₺</p>';
$response .= '</div>';

// Bekleyen Çek
$response .= '<div class="bg-white p-3 rounded-lg shadow-sm text-center">';
$response .= '<p class="text-xs text-gray-500 mb-1">Bekleyen Çek</p>';
$response .= '<p class="text-lg font-bold text-yellow-600">' . number_format((float) ($bekleyen_cek['TOPLAM'] ?? 0), 2, ',', '.') . ' ₺</p>';
$response .= '</div>';

// Tahsil Edilmiş Çek
$response .= '<div class="bg-white p-3 rounded-lg shadow-sm text-center">';
$response .= '<p class="text-xs text-gray-500 mb-1">Tahsil Edilmiş Çek</p>';
$response .= '<p class="text-lg font-bold text-purple-600">' . number_format((float) ($odenen_cek['TOPLAM'] ?? 0), 2, ',', '.') . ' ₺</p>';
$response .= '</div>';

$response .= '</div></div>';

// Favori Ürünler Bölümü
if (!empty($favoriUrunler)) {
    $response .= '<div class="bg-gradient-to-r from-yellow-50 to-orange-50 p-4 rounded-lg border border-yellow-200">';
    $response .= '<h3 class="text-lg font-bold text-gray-800 mb-3"><i class="fas fa-star text-yellow-500 mr-2"></i>Favori Ürünler</h3>';
    $response .= '<div class="space-y-2">';

    foreach ($favoriUrunler as $index => $urun) {
        $badge_color = match ($index) {
            0 => 'bg-yellow-500 text-white',
            1 => 'bg-gray-400 text-white',
            2 => 'bg-orange-600 text-white',
            default => 'bg-blue-100 text-blue-800',
        };
        $response .= '<div class="bg-white p-3 rounded-lg shadow-sm border border-gray-200">';
        $response .= '<div class="flex items-center gap-3">';
        $response .= '<span class="' . $badge_color . ' px-2 py-1 rounded-full text-xs font-bold">#' . ($index + 1) . '</span>';
        $response .= '<div class="flex-1">';
        $response .= '<p class="font-semibold text-gray-800 text-sm">' . htmlspecialchars((string) $urun['STOK_KODU']) . '</p>';
        $response .= '<p class="text-xs text-gray-600">' . htmlspecialchars((string) $urun['STOK_ADI']) . '</p>';
        $response .= '</div>';
        $response .= '</div>';
        $response .= '<div class="grid grid-cols-3 gap-2 mt-2 text-xs">';
        $response .= '<div class="text-center">';
        $response .= '<p class="text-gray-500">Toplam</p>';
        $response .= '<p class="font-bold text-blue-600">' . kusuratadet($urun['TOPLAM_MIKTAR']) . '</p>';
        $response .= '</div>';
        $response .= '<div class="text-center">';
        $response .= '<p class="text-gray-500">Sipariş</p>';
        $response .= '<p class="font-bold text-green-600">' . $urun['SIPARIS_SAYISI'] . 'x</p>';
        $response .= '</div>';
        $response .= '<div class="text-center">';
        $response .= '<p class="text-gray-500">Ort. Fiyat</p>';
        $response .= '<p class="font-bold text-purple-600">' . number_format((float) ($urun['ORTALAMA_FIYAT'] ?? 0), 2, ',', '.') . ' ₺</p>';
        $response .= '</div>';
        $response .= '</div>';
        $response .= '<p class="text-xs text-gray-500 mt-2"><i class="far fa-calendar-alt mr-1"></i>Son: ' . date('d.m.Y', strtotime((string) $urun['SON_ALIS_TARIHI'])) . '</p>';
        $response .= '</div>';
    }

    $response .= '</div></div>';
} else {
    $response .= '<div class="bg-gray-50 p-4 rounded-lg border border-gray-200 text-center">';
    $response .= '<i class="fas fa-shopping-basket text-gray-300 text-3xl mb-2"></i>';
    $response .= '<p class="text-gray-500 text-sm">Bu müşterinin henüz sipariş geçmişi bulunmuyor.</p>';
    $response .= '</div>';
}

// Response sonlandırma
$response .= '</div>';

echo $response;
exit;
