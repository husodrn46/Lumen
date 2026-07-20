<?php
declare(strict_types=1);

/** EMEKLİ (2026-07-07): Karşılaştırmalı Satış "Aylık Satış"a birleştirildi —
 *  Excel çıktısı için Aylık Satış Excel'ine yönlendirilir. Eski kod git geçmişinde. */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");

$year = (isset($_GET['year']) && is_numeric($_GET['year'])) ? (int) $_GET['year'] : (int) date('Y');
header('Location: rapor_satis_aylik_excel_xlsx.php?year=' . $year, true, 301);
exit;
