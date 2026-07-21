<?php
declare(strict_types=1);

/**
 * fiyat_sec.php — KALDIRILDI (2026-07-13).
 *
 * "Sipariş Seçenekleri" (döviz / fiyat grubu) ara ekranıydı; akış artık
 * bu adımı kullanmıyor: Yeni Sipariş cari/cari.php'den doğrudan siparis/fisekle.php'ye
 * gider (TL), dövizli sipariş doviz/ modülünde açılır, fiyat grubu
 * siparis/fisekle.php?fiyat= parametresiyle opsiyonel geçilir.
 *
 * Kanıt (kaldırma öncesi denetim): kod tabanında hiçbir link/yönlendirme
 * kalmamıştı; 30 günlük IIS erişim logunda tek istek yetki denetim
 * probe'uydu. Eski yer imleri kırılmasın diye kalıcı yönlendirme bırakıldı.
 * Tam hali git geçmişinde (8ce232c öncesi).
 */

include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");

$cariid = isset($_GET['cariid']) ? (int) $_GET['cariid'] : 0;
$stokhareket = isset($_GET['stokhareket']) ? (int) $_GET['stokhareket'] : 0;

$hedef = $cariid > 0
    ? 'siparis/fisekle.php?cariid=' . $cariid . '&stokhareket=' . $stokhareket
    : 'cari/cari.php';
header('Location: ' . $hedef, true, 301);
exit;
