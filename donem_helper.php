<?php
/**
 * Dönem Helper - 2025/2026 dönem geçişi için yardımcı dosya
 *
 * Bu dosyayı yazdırma ve görüntüleme sayfalarına include edin.
 * donem=2025 parametresi geldiğinde $firmadonem değişkenini eski döneme çevirir.
 *
 * Kullanım: include_once(__DIR__ . "/donem_helper.php");
 */

// Dönem parametresini kontrol et (çoklu kaynak desteği)
$donemParam = '';

// 1. Önce URL'den kontrol et
if (isset($_GET['donem'])) {
    $donemParam = $_GET['donem'];
}
// 2. Session'daki siparis_donem (lg_fis.php için)
elseif (isset($_SESSION['siparis_donem'])) {
    $donemParam = $_SESSION['siparis_donem'];
}
// 3. migrateUrlToSession ile kaydedilen pageParams (diğer sayfalar için)
elseif (function_exists('getPageParamString')) {
    $donemParam = getPageParamString('donem');
}

// 2025 dönemi için eski tabloları kullan
if ($donemParam === '2025' && isset($eskifirmadonem)) {
    $firmadonem = $eskifirmadonem;
}
