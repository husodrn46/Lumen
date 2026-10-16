<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");  // DB bağlantısı ve loglama fonksiyonları için GEREKLİ!
require_once __DIR__ . '/../kontrol.php';

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

	// YETKI: satir silmek yazma islemidir (fisekle.php kapisi: admin / M1 / M3) + cari gorunurlugu.
	if (
		!m_p_siparis_duzenleyebilir_mi($terminalkullanici, $yetkidurum ?? null)
		|| !m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)
	) {
		header('Location: ' . APP_ROOT_URL . '/403.html');
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

		// Satır numaraları: ürün satırları 1..N, ardından iskonto satırları KENDİ
		// SIRALARIYLA (1. iskonto, 2. iskonto ...). Eskiden tüm iskonto satırlarına
		// aynı numara veriliyordu; iskonto uygulama sırası (LINENO_) belirsizleşiyordu.
		// Satırlar önce fetchAll ile alınır (açık imleç varken UPDATE MARS'sız
		// bağlantıda 'connection busy' veriyordu).
		$stmtSira = $dbh->prepare("SELECT LOGICALREF FROM ".$firmadonem."ORFLINE WHERE ORDFICHEREF = :stokhareket
			ORDER BY CASE WHEN LINETYPE = 2 THEN 1 ELSE 0 END, CASE WHEN LINETYPE = 2 THEN LINENO_ ELSE LOGICALREF END, LOGICALREF");
		$stmtSira->execute([':stokhareket' => $stokhareket]);
		$siraSatirlari = $stmtSira->fetchAll(PDO::FETCH_COLUMN);
		$stmtSiraUp = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET LINENO_ = :no WHERE LOGICALREF = :ref");
		foreach ($siraSatirlari as $i => $ref) {
			$stmtSiraUp->execute([':no' => $i + 1, ':ref' => (int) $ref]);
		}

		// Silinen satır iskonto dağıtımından çıkar: iskonto satırlarının TOTAL'ı ve
		// kalan satırların DISTDISC/LINENET/KDV'si yeniden hesaplanır (hareketekle ile aynı).
		require_once __DIR__ . '/iskonto_lib.php';
		fis_iskonto_oranlari_uygula($dbh, $firmadonem, (int) $stokhareket);

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


	// Satır numaralandırma silme transaction'ı içinde yapılır (yukarıda).

	echo '<script>window.location="?stokhareket='.$stokhareket.'";</script>';

	}     
