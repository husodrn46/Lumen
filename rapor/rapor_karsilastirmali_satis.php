<?php
declare(strict_types=1);

/**
 * EMEKLİ (2026-07-07): Karşılaştırmalı Satış, "Aylık Satış" raporuna BİRLEŞTİRİLDİ.
 *
 * Neden: iki rapor aynı soruyu (aylara göre satış) FARKLI tanımlarla cevaplıyordu →
 * çelişen rakamlar. Bu rapor NET'i KDV HARİÇ ham hesapla (PRICE*AMOUNT-DISTDISC,
 * LINETYPE filtresiz) alıyordu; üstelik "geçen yıl"ı YEAR() ile MEVCUT dönem
 * tablosunda arıyordu — 2025 verisi LG_001_01_'de olduğundan geçen yıl sütunu
 * fiilen boş/yanıltıcıydı. Aylık Satış artık geçen yılı DOĞRU dönem tablosundan,
 * aynı tanımla (LINENET+VATAMNT, KDV dahil) gösteriyor. Eski kod git geçmişinde.
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");

$year = (isset($_GET['year']) && is_numeric($_GET['year'])) ? (int) $_GET['year'] : (int) date('Y');
header('Location: rapor_satis_aylik.php?year=' . $year, true, 301);
exit;
