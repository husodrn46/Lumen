<?php
declare(strict_types=1);

function eanonuc(): string{
    global $dbh, $firma;

    try {
        // Veritabanından en son barkod numarasını al
        $stmt = $dbh->prepare("SELECT TOP 1 BARCODE FROM ".$firma."UNITBARCODE WHERE LEN(BARCODE) = 13 AND BARCODE LIKE '869%' ORDER BY BARCODE DESC");
        $stmt->execute();
        $sonuc = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($sonuc && !empty($sonuc['BARCODE'])) {
            // Son barkodun ilk 12 hanesini al
            $sonBarkod = substr((string) $sonuc['BARCODE'], 0, 12);
            $yeniBarkod12 = str_pad((int)$sonBarkod + 1, 12, '0', STR_PAD_LEFT);
        } else {
            // Hiç barkod yoksa varsayılan başlangıç (869 Türkiye kodu ile)
            $yeniBarkod12 = '869000000001';
        }

        // EAN-13 kontrol hanesi hesapla
        $arrbar = str_split($yeniBarkod12);
        $cda = (int)$arrbar[1] + (int)$arrbar[3] + (int)$arrbar[5] + (int)$arrbar[7] + (int)$arrbar[9] + (int)$arrbar[11];
        $cda *= 3;
        $cdb = (int)$arrbar[0] + (int)$arrbar[2] + (int)$arrbar[4] + (int)$arrbar[6] + (int)$arrbar[8] + (int)$arrbar[10];
        $cd = $cda + $cdb;
        $cd = (int)substr((string)$cd, -1);
        $cd = (10 - $cd) % 10;

        return $yeniBarkod12 . $cd;
    } catch(PDOException) {
        // Hata durumunda varsayılan barkod
        return '8690000000013';
    }
}
?>
 <SCRIPT language="javascript"> 
function eanonucbar() {

   frm.barkod.value = frm.stokbarkodx.value;

 }
	</script>
