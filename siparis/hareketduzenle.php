<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");  // DB bağlantısı ve loglama fonksiyonları için GEREKLİ!
require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../log_ip.php");
if(isset($_POST['stkduzenle'])){
	// CSRF koruması
	if (!csrf_verify()) {
		http_response_code(403);
		die("Geçersiz güvenlik doğrulaması.");
	}

	// Input validation
	$stokhareket = isset($_POST['stokhareket']) ? (int)$_POST['stokhareket'] : 0;
	$hrktid = isset($_POST['stkduzenle']) ? (int)$_POST['stkduzenle'] : 0;
	$stokadet = isset($_POST['stkadt']) ? (float)$_POST['stkadt'] : 0;
	$stokfiyat = isset($_POST['stkfyt']) ? max(0.0, (float)$_POST['stkfyt']) : 0;
	$stokkdv = isset($_POST['stkkdv']) ? max(0.0, min(100.0, (float)$_POST['stkkdv'])) : 0;
	$stokaciklama = isset($_POST['aciklama']) ? trim((string) $_POST['aciklama']) : '';

	// Resmi modulde KDV zorunlu ve sabit (%20).
	if (defined('RESMI_FORCE_KDV')) {
		$stokkdv = (float) RESMI_FORCE_KDV;
	}

	if ($hrktid <= 0 || $stokhareket <= 0) {
		die("Geçersiz ID");
	}

	// DÜZELTME: Miktar 0 olamaz (SQL Server trigger'da sıfıra bölme hatasına yol açar)
	if ($stokadet <= 0) {
		echo '<script>alert("Miktar 0 veya negatif olamaz!"); window.history.back();</script>';
		exit;
	}

	// Eski değerleri al (loglama için)
	$stmt = $dbh->prepare("SELECT L.ORDFICHEREF, L.LINETYPE, F.FICHENO, L.AMOUNT, L.PRICE, L.VAT, L.TOTAL, L.LINEEXP, I.CODE, I.NAME
	                       FROM ".$firmadonem."ORFLINE L
	                       LEFT JOIN ".$firma."ITEMS I ON I.LOGICALREF = L.STOCKREF
	                       LEFT JOIN ".$firmadonem."ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF
	                       WHERE L.LOGICALREF = :hrktid");
	$stmt->bindParam(':hrktid', $hrktid, PDO::PARAM_INT);
	$stmt->execute();
	$eskiSatir = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($eskiSatir === false || $eskiSatir === null) {
		die('Veri tutarsiz; islem iptal edildi.');
	}

	if ((int)$eskiSatir['LINETYPE'] !== 0) {
		exit('Gecersiz satir tipi.');
	}

	$stokhareket = (int)$eskiSatir['ORDFICHEREF'];
	if (!m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
		header('Location: 403.html');
		exit;
	}

	 $yenirakamx=round($stokadet*$stokfiyat, 2);
	$stoktoplamkdv=round((($yenirakamx/100)*$stokkdv), 2);
	 $yenirakam=round($stokadet*$stokfiyat, 2);

	// DÜZELTME: $reservemiktar ve $reservetarih her durumda tanımlanmalı
	$reservemiktar = 0;
	$reservetarih = '';
	$tarih = date("Y-m-d");
	$reserve = $reserve ?? '0';

	if($reserve == '1')
	{
		$reservemiktar = $stokadet;
		$reservetarih = $tarih;
	}

	$stmt = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET
	                       AMOUNT = :stokadet,
	                       PRICE = :stokfiyat,
	                       VAT = :stokkdv,
	                       VATAMNT = :stoktoplamkdv,
	                       LINEEXP = :stokaciklama,
	                       TOTAL = :yenirakam,
	                       VATMATRAH = :yenirakam2,
	                       LINENET = :yenirakam3,
	                       RESERVEAMOUNT = :reservemiktar,
	                       RESERVEDATE = GETDATE()
	                       WHERE LOGICALREF = :hrktid");

	$stmt->bindParam(':stokadet', $stokadet, PDO::PARAM_STR);
	$stmt->bindParam(':stokfiyat', $stokfiyat, PDO::PARAM_STR);
	$stmt->bindParam(':stokkdv', $stokkdv, PDO::PARAM_STR);
	$stmt->bindParam(':stoktoplamkdv', $stoktoplamkdv, PDO::PARAM_STR);
	$stmt->bindParam(':stokaciklama', $stokaciklama, PDO::PARAM_STR);
	$stmt->bindParam(':yenirakam', $yenirakam, PDO::PARAM_STR);
	$stmt->bindParam(':yenirakam2', $yenirakam, PDO::PARAM_STR);
	$stmt->bindParam(':yenirakam3', $yenirakam, PDO::PARAM_STR);
	$stmt->bindParam(':reservemiktar', $reservemiktar, PDO::PARAM_STR);
	$stmt->bindParam(':hrktid', $hrktid, PDO::PARAM_INT);

	// Try-catch ile SQL hatalarını yakala
	try {
		$ekled = $stmt->execute();
	} catch (PDOException $e) {
		// Sıfıra bölme hatası kontrolü
		if (strpos($e->getMessage(), 'Divide by zero') !== false) {
			error_log("Sıfıra bölme hatası - Miktar: {$stokadet}, Fiyat: {$stokfiyat}, KDV: {$stokkdv}, Toplam: {$yenirakam}");
			echo '<script>alert("Hata: Miktar veya fiyat değerleri geçersiz! Miktar: '.$stokadet.', Fiyat: '.$stokfiyat.'"); window.history.back();</script>';
			exit;
		}
		throw $e; // Diğer hataları yeniden fırlat
	}

	// Loglama yap
	if ($ekled && $eskiSatir) {
		// LEFT JOIN sonucu NULL gelebilir; string parametreleri guvene al
		$ficheNo  = (string) ($eskiSatir['FICHENO'] ?? '');
		$stokKodu = (string) ($eskiSatir['CODE']    ?? '');
		$stokAdi  = (string) ($eskiSatir['NAME']    ?? '');

		// Değişiklikleri belirle
		$degisiklikler = [];

		if ($eskiSatir['AMOUNT'] != $stokadet) {
			$degisiklikler[] = 'Miktar: ' . $eskiSatir['AMOUNT'] . ' -> ' . $stokadet;
		}
		if ($eskiSatir['PRICE'] != $stokfiyat) {
			$degisiklikler[] = 'Fiyat: ' . $eskiSatir['PRICE'] . ' -> ' . $stokfiyat;

			// ========================================
			// Fiyat değişikliğini ayrıca logla
			// ========================================
			if (function_exists('logFiyatDegisiklik')) {
				logFiyatDegisiklik(
					$stokhareket,                                      // FIS_REF
					$hrktid,                                           // SATIR_REF
					$ficheNo,                                          // FICHENO
					$stokKodu,                                         // STOK_KODU
					$stokAdi,                                          // STOK_ADI
					$eskiSatir['PRICE'],                               // ESKI_FIYAT
					$stokfiyat,                                        // YENI_FIYAT
					$stokadet,                                         // MIKTAR
					$terminalkullanici,                                // KULLANICI_ID
					'Satır düzenlerken fiyat değiştirildi'
				);
			}
			// ========================================
		}
		if ($eskiSatir['VAT'] != $stokkdv) {
			$degisiklikler[] = 'KDV: ' . $eskiSatir['VAT'] . ' -> ' . $stokkdv;

			// ========================================
			// KDV değişikliğini ayrıca logla
			// ========================================
			logKdvDegisiklik(
				$stokhareket,                                      // FIS_REF
				$hrktid,                                           // SATIR_REF
				$ficheNo,                                          // FICHENO
				$stokKodu,                                         // STOK_KODU
				$stokAdi,                                          // STOK_ADI
				$eskiSatir['VAT'],                                 // ESKI_KDV
				$stokkdv,                                          // YENI_KDV
				$terminalkullanici,                                // KULLANICI_ID
				'Satır düzenlerken KDV değiştirildi: %' . $eskiSatir['VAT'] . ' -> %' . $stokkdv
			);
			// ========================================
		}
		if ($eskiSatir['LINEEXP'] != $stokaciklama) {
			$degisiklikler[] = 'Açıklama değişti';
		}

		if ($degisiklikler !== []) {
			logSatirDuzenleme(
				$stokhareket,                    // FIS_REF
				$hrktid,                         // SATIR_REF
				$ficheNo,                        // FICHENO
				$stokKodu,                       // STOK_KODU
				$stokAdi,                        // STOK_ADI
				$eskiSatir['AMOUNT'],            // ESKI_MIKTAR
				$stokadet,                       // YENI_MIKTAR
				$eskiSatir['PRICE'],             // ESKI_FIYAT
				$stokfiyat,                      // YENI_FIYAT
				$eskiSatir['TOTAL'],             // ESKI_TOTAL
				$yenirakam,                      // YENI_TOTAL
				$terminalkullanici,              // KULLANICI_ID
				implode(', ', $degisiklikler)    // ACIKLAMA
			);
		}
	}

header('Location: lg_fis.php?stokhareket=' . $stokhareket);
exit;
}
