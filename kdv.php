<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");
include_once(__DIR__ . "/log_ip.php");
if(!empty($_POST['kdviptal'])){
	 // CSRF koruması (POST istekleri için)
	 if (!csrf_verify()) {
		 http_response_code(403);
		 die('Geçersiz güvenlik doğrulaması.');
	 }

	 $stokhareket = isset($_POST['stokhareket']) ? (int)$_POST['stokhareket'] : 0;
	 $yenikdv = isset($_POST['kdviptal']) ? (float)virgul($_POST['kdviptal']) : 0.0;

		 // Fiş bilgisini ve eski KDV'yi al
		 $stmtFis = $dbh->prepare("SELECT FICHENO FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket");
		 $stmtFis->execute([':stokhareket' => $stokhareket]);
		 $fisInfo = $stmtFis->fetch(PDO::FETCH_ASSOC);

		 $stmtSatirlar = $dbh->prepare("SELECT LOGICALREF, VAT, VATAMNT, TOTAL, L.STOCKREF, I.CODE, I.NAME
		                           FROM ".$firmadonem."ORFLINE L
		                           LEFT JOIN ".$firma."ITEMS I ON I.LOGICALREF = L.STOCKREF
		                           WHERE L.ORDFICHEREF = :stokhareket AND L.LINETYPE=0");
		 $stmtSatirlar->execute([':stokhareket' => $stokhareket]);

		 $stmtGuncelle = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE  SET   VAT = :yenikdv, VATAMNT = :vatamnt WHERE LOGICALREF = :satirRef");
		 WHILE($kdvgncl=$stmtSatirlar->fetch(PDO::FETCH_ASSOC))
	 {
		 $satirRef = $kdvgncl['LOGICALREF'];
		 $eskiKdv = $kdvgncl['VAT'];
	$stoktoplamfy=$kdvgncl['TOTAL'];
	 $stoktoplamkdvfy=(($stoktoplamfy/100)*$yenikdv);
	$gnclkdv=$stmtGuncelle->execute([':yenikdv' => $yenikdv, ':vatamnt' => $stoktoplamkdvfy, ':satirRef' => (int)$satirRef]);

		 // Loglama yap (sadece KDV değişmişse)
		 if ($gnclkdv && $eskiKdv != $yenikdv) {
			 logKdvDegisiklik(
				 $stokhareket,                                    // FIS_REF
				 $satirRef,                                       // SATIR_REF
				 $fisInfo['FICHENO'] ?? '', // FICHENO
				 $kdvgncl['CODE'],                                // STOK_KODU
				 $kdvgncl['NAME'],                                // STOK_ADI
				 $eskiKdv,                                        // ESKI_KDV
				 $yenikdv,                                        // YENI_KDV
			 $terminalkullanici,                              // KULLANICI_ID
			 'KDV değiştirildi: %' . $eskiKdv . ' -> %' . $yenikdv  // ACIKLAMA
		 );
	 }
 }


echo  '<script>window.location="?stokhareket='.$stokhareket.'";</script>' ;
//echo  '<script>window.location="index.php";</script>' ;
}
