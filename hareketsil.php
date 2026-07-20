<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");  // DB bağlantısı ve loglama fonksiyonları için GEREKLİ!
require_once __DIR__ . '/kontrol.php';

// Silme işlemi POST ile ve CSRF doğrulaması ile yapılmalı
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hareketsil'])) {
	// CSRF kontrolü
	if (!csrf_verify()) {
		die("Geçersiz CSRF token. Lütfen sayfayı yenileyip tekrar deneyin.");
	}

	// Input validation
	$hareketsil = (int)$_POST['hareketsil'];
	$stokhareket = (int)($_POST['stokhareket'] ?? 0);

	if ($hareketsil <= 0) {
		die("Geçersiz ID");
	}

	// Silinecek satır bilgilerini al (loglama için)
	$stmt = $dbh->prepare("SELECT L.ORDFICHEREF, L.LINETYPE, F.FICHENO, L.AMOUNT, L.PRICE, L.TOTAL, L.LINEEXP, I.CODE, I.NAME
	                        FROM ".$firmadonem."ORFLINE L
	                        LEFT JOIN ".$firma."ITEMS I ON I.LOGICALREF = L.STOCKREF
	                        LEFT JOIN ".$firmadonem."ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF
	                        WHERE L.LOGICALREF = :hareketsil");
	$stmt->bindParam(':hareketsil', $hareketsil, PDO::PARAM_INT);
	$stmt->execute();
	$silinecekSatir = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($silinecekSatir === false || $silinecekSatir === null) {
		die('Veri tutarsiz; islem iptal edildi.');
	}

	if ((int)$silinecekSatir['LINETYPE'] !== 0) {
		exit('Gecersiz satir tipi.');
	}

	// DÜZELTME: $stokhareket değişkeni tanımlanmamıştı!
	$stokhareket = (int)($silinecekSatir['ORDFICHEREF'] ?? 0);
	if ($stokhareket <= 0) {
		die("Geçersiz fiş ID");
	}

	if (!m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
		header('Location: 403.html');
		exit;
	}

	// Satiri sil + fis toplamlarini kalan urun satirlarindan YENIDEN hesapla.
	// Tek bir tutari cikarmak yerine SUM ile yeniden hesaplamak KDV/iskonto
	// bilesenlerinin dogru kalmasini garanti eder (lg_fis ile ayni formul).
	// Tum islem transaction icinde: yari kalmis guncelleme olmaz.
	try {
		$dbh->beginTransaction();

		$stmtDel = $dbh->prepare("DELETE FROM ".$firmadonem."ORFLINE WHERE LOGICALREF = :hareketsil");
		$stmtDel->bindParam(':hareketsil', $hareketsil, PDO::PARAM_INT);
		$sil = $stmtDel->execute();

		$stmtTop = $dbh->prepare("SELECT ISNULL(SUM(VATAMNT),0) AS KDV, ISNULL(SUM(TOTAL),0) AS BRUT, ISNULL(SUM(DISTDISC),0) AS ISK
			FROM ".$firmadonem."ORFLINE WHERE ORDFICHEREF = :stokhareket AND LINETYPE = 0");
		$stmtTop->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
		$stmtTop->execute();
		$top = $stmtTop->fetch(PDO::FETCH_ASSOC) ?: ['KDV' => 0, 'BRUT' => 0, 'ISK' => 0];

		$fKdv   = round((float) $top['KDV'], 2);
		$fBrut  = round((float) $top['BRUT'], 2);
		$fIsk   = round((float) $top['ISK'], 2);
		$fNet   = round($fBrut - $fIsk, 2);
		$fNetKdv = round($fNet + $fKdv, 2);

		$stmtUpd = $dbh->prepare("UPDATE ".$firmadonem."ORFICHE
			SET ADDDISCOUNTS = :isk1, TOTALDISCOUNTS = :isk2, TOTALDISCOUNTED = :brut1,
			    TOTALVAT = :kdv, GROSSTOTAL = :brut2, NETTOTAL = :net1, REPORTNET = :net2, TRNET = :net3
			WHERE LOGICALREF = :stokhareket");
		$gncl = $stmtUpd->execute([
			':isk1' => $fIsk, ':isk2' => $fIsk, ':brut1' => $fBrut, ':kdv' => $fKdv,
			':brut2' => $fBrut, ':net1' => $fNetKdv, ':net2' => $fNetKdv, ':net3' => $fNetKdv,
			':stokhareket' => $stokhareket,
		]);

		$dbh->commit();
	} catch (Throwable $e) {
		if ($dbh->inTransaction()) {
			$dbh->rollBack();
		}
		error_log('hareketsil.php toplam guncelleme hatasi: ' . $e->getMessage());
		die('Satir silinirken hata olustu; islem geri alindi.');
	}

	// Loglama yap
	if ($sil && $silinecekSatir) {
		// LEFT JOIN sonucu NULL gelebilir; string parametreleri guvene al
		logSatirSilme(
			$stokhareket,                                       // FIS_REF
			$hareketsil,                                        // SATIR_REF
			(string) ($silinecekSatir['FICHENO'] ?? ''),        // FICHENO
			(string) ($silinecekSatir['CODE']    ?? ''),        // STOK_KODU
			(string) ($silinecekSatir['NAME']    ?? ''),        // STOK_ADI
			$silinecekSatir['AMOUNT'] ?? 0,                     // MIKTAR
			$silinecekSatir['PRICE']  ?? 0,                     // FIYAT
			$silinecekSatir['TOTAL']  ?? 0,                     // TOTAL
			$terminalkullanici,                                 // KULLANICI_ID
			'Satır silindi'                                     // ACIKLAMA
		);
	}


	$yenisirano = 0;
	$stmt = $dbh->prepare("SELECT LOGICALREF FROM ".$firmadonem."ORFLINE WHERE ORDFICHEREF = :stokhareket AND LINETYPE = 0 ORDER BY LOGICALREF ASC");
	$stmt->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
	$stmt->execute();
	$sirano = $stmt;

	WHILE($siranobul = $sirano->fetch(PDO::FETCH_ASSOC))
	{
		$yenisirano += 1;
		$siraid1 = intcevir($siranobul['LOGICALREF']);

		$stmtUpdate = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET LINENO_ = :yenisirano WHERE LOGICALREF = :siraid1");
		$stmtUpdate->bindParam(':yenisirano', $yenisirano, PDO::PARAM_INT);
		$stmtUpdate->bindParam(':siraid1', $siraid1, PDO::PARAM_INT);
		$siragncl = $stmtUpdate->execute();
	}

	$yenisirano += 1;
	$stmt = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET LINENO_ = :yenisirano WHERE ORDFICHEREF = :stokhareket AND LINETYPE = 2");
	$stmt->bindParam(':yenisirano', $yenisirano, PDO::PARAM_INT);
	$stmt->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
	$siragncl = $stmt->execute();

	echo '<script>window.location="?stokhareket='.$stokhareket.'";</script>';

	}     
