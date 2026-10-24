<?php

declare(strict_types=1);

/**
 * LOGO'nun TIME_/FTIME tam sayısını saat, dakika ve saniyeye çevirir.
 * Kodlama: saat * 2^24 + dakika * 2^16 + saniye * 2^8.
 *
 * @return array{saat:int, dakika:int, saniye:int}|null
 */
function lumen_logo_saat_coz(int|string|null $kod): ?array
{
    if ($kod === null || $kod === '' || !is_numeric($kod)) {
        return null;
    }

    $deger = (int) $kod;
    if ($deger < 0) {
        return null;
    }

    $saat = intdiv($deger, 16777216);
    $dakika = intdiv($deger % 16777216, 65536);
    $saniye = intdiv($deger % 65536, 256);
    if ($saat > 23 || $dakika > 59 || $saniye > 59) {
        return null;
    }

    return ['saat' => $saat, 'dakika' => $dakika, 'saniye' => $saniye];
}

/**
 * İşlem tarihini LOGO saat koduyla birleştirir. Saat kaynağı yoksa 00:00
 * varsaymak yerine bilinmediğini açıkça gösterir.
 */
function lumen_logo_tarih_saat_goster(?string $tarih, int|string|null $kod): string
{
    if ($tarih === null || trim($tarih) === '') {
        return '-';
    }

    try {
        $tarihNesnesi = new DateTimeImmutable($tarih);
    } catch (Throwable) {
        return '-';
    }

    $saat = lumen_logo_saat_coz($kod);
    if ($saat === null) {
        return $tarihNesnesi->format('d.m.Y') . ' --:--';
    }

    return $tarihNesnesi
        ->setTime($saat['saat'], $saat['dakika'], $saat['saniye'])
        ->format('d.m.Y H:i');
}
