<?php

declare(strict_types=1);

$kok = dirname(__DIR__, 2);
$pwaBasligi = (string) file_get_contents($kok . '/pwa-header.php');
$ortakCss = (string) file_get_contents($kok . '/assets/css/lumen.css');

test('gizli mod düğmesi: ortak başlık bulunduğunda başlığa taşınır', function () use ($pwaBasligi, $ortakCss): void {
    dogru(str_contains($pwaBasligi, ".top-header .header-inner"), 'ortak başlık seçicisi eksik');
    dogru(
        str_contains($pwaBasligi, "b.classList.add('baslikta');baslik.appendChild(b);"),
        'düğme başlığa eklenmiyor'
    );
    dogru(
        preg_match('/#lumen-gizli-btn\\.baslikta\\s*\\{[^}]*position:\\s*static;/s', $ortakCss) === 1,
        'başlıktaki düğme sabit konumdan çıkarılmıyor'
    );
});

test('gizli mod düğmesi: ortak ayar başlıklarıyla uyumludur', function () use ($kok): void {
    foreach (['sistem_sekmeler.php', 'yetki_sekmeler.php'] as $dosya) {
        $baslik = (string) file_get_contents($kok . '/ayar/' . $dosya);
        dogru(str_contains($baslik, 'top-header'), $dosya . ': top-header sınıfı eksik');
        dogru(str_contains($baslik, 'header-inner'), $dosya . ': header-inner sınıfı eksik');
    }
});

test('gizli mod düğmesi: başlıksız sayfada içeriğe alt boşluk bırakır', function () use ($pwaBasligi, $ortakCss): void {
    dogru(
        str_contains($pwaBasligi, "document.body.classList.add('gizli-btn-yuzen');"),
        'yedek yerleşim sınıfı eklenmiyor'
    );
    dogru(
        preg_match('/body\\.gizli-btn-yuzen\\s*\\{[^}]*padding-bottom:\\s*76px;/s', $ortakCss) === 1,
        'yüzen düğme için içerik boşluğu eksik'
    );
});
