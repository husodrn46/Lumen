<?php

declare(strict_types=1);

/**
 * Tanımlar (bağımsız mod): firma bilgileri, kasa/banka hesapları, depolar, birimler,
 * döviz kurları. Her işlev ['ok' => bool, 'mesaj' => string] döner.
 */

require_once __DIR__ . '/../veri/veri.php';

if (!function_exists('lumen_tanim_firma_alanlari')) {
    /** LM_AYAR anahtarı (firma_*) → etiket, en fazla uzunluk */
    function lumen_tanim_firma_alanlari(): array
    {
        return [
            'unvan' => ['Ticari unvan', 200], 'vergi_dairesi' => ['Vergi dairesi', 80], 'vergi_no' => ['Vergi no / TCKN', 11],
            'mersis' => ['MERSİS no', 16], 'adres' => ['Adres', 400], 'telefon' => ['Telefon', 40], 'eposta' => ['E-posta', 160],
        ];
    }
}

if (!function_exists('lumen_tanim_firma_kaydet')) {
    function lumen_tanim_firma_kaydet(PDO $dbh, array $v): array
    {
        $alanlar = lumen_tanim_firma_alanlari();
        $temiz = [];
        foreach ($alanlar as $k => [$etiket, $uzunluk]) {
            $temiz[$k] = mb_substr(trim((string) ($v[$k] ?? '')), 0, $uzunluk);
        }
        if ($temiz['unvan'] === '') {
            return ['ok' => false, 'mesaj' => 'Ticari unvan boş olamaz.'];
        }
        if ($temiz['vergi_no'] !== '' && preg_match('/^[0-9]{10,11}$/', $temiz['vergi_no']) !== 1) {
            return ['ok' => false, 'mesaj' => 'Vergi no 10 haneli, TCKN 11 haneli olmalı.'];
        }
        if ($temiz['vergi_no'] !== '' && !lumen_vergi_no_gecerli($temiz['vergi_no'])) {
            return ['ok' => false, 'mesaj' => 'Vergi no / TCKN geçersiz (kontrol basamağı tutmuyor); numarayı kontrol edin.'];
        }
        if ($temiz['eposta'] !== '' && filter_var($temiz['eposta'], FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'mesaj' => 'E-posta adresi geçersiz.'];
        }
        // Dönem kilidi: bu tarih ve öncesine belge/fiş girilmez, iptal edilmez (boş: kilit yok).
        $kilit = trim((string) ($v['donem_kilit'] ?? ''));
        if ($kilit !== '' && (DateTime::createFromFormat('!Y-m-d', $kilit) === false || $kilit > date('Y-m-d'))) {
            return ['ok' => false, 'mesaj' => 'Dönem kilidi geçerli ve bugünden ileri olmayan bir tarih olmalı.'];
        }
        $st = $dbh->prepare("MERGE dbo.LM_AYAR AS H USING (SELECT :a AS ANAHTAR, :d AS DEGER) AS K ON H.ANAHTAR = K.ANAHTAR
                             WHEN MATCHED THEN UPDATE SET DEGER = K.DEGER WHEN NOT MATCHED THEN INSERT (ANAHTAR, DEGER) VALUES (K.ANAHTAR, K.DEGER);");
        foreach ($temiz as $k => $d) {
            $st->execute([':a' => 'firma_' . $k, ':d' => $d]);
        }
        if ($kilit === '') {
            $dbh->exec("DELETE FROM dbo.LM_AYAR WHERE ANAHTAR = 'donem_kilit_tarihi'");
        } else {
            $st->execute([':a' => 'donem_kilit_tarihi', ':d' => $kilit]);
        }
        return ['ok' => true, 'mesaj' => 'Firma bilgileri kaydedildi.'];
    }
}

if (!function_exists('lumen_iban_gecerli')) {
    /** TR IBAN: TR + 24 hane, mod-97 denetimi. Boşluklar yok sayılır. */
    function lumen_iban_gecerli(string $iban): bool
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        if (preg_match('/^TR[0-9]{24}$/', $iban) !== 1) {
            return false;
        }
        $sayi = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $c) {
            $sayi .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }
        $kalan = 0;
        foreach (str_split($sayi, 7) as $parca) {
            $kalan = (int) ((string) $kalan . $parca) % 97;
        }
        return $kalan === 1;
    }
}

if (!function_exists('lumen_tanim_hesap_kaydet')) {
    /** Kasa/banka hesabı ekle (id 0) ya da güncelle. */
    function lumen_tanim_hesap_kaydet(PDO $dbh, int $id, array $v): array
    {
        $tur = (int) ($v['tur'] ?? 1) === 2 ? 2 : 1;
        $kod = mb_strtoupper(trim((string) ($v['kod'] ?? '')), 'UTF-8');
        $ad = trim((string) ($v['ad'] ?? ''));
        $iban = strtoupper(str_replace(' ', '', trim((string) ($v['iban'] ?? ''))));
        if (preg_match('/^[A-Z0-9._-]{1,30}$/', $kod) !== 1 || $ad === '') {
            return ['ok' => false, 'mesaj' => 'Kod (harf, rakam, . _ -) ve ad zorunlu.'];
        }
        if ($iban !== '' && !lumen_iban_gecerli($iban)) {
            return ['ok' => false, 'mesaj' => 'IBAN geçersiz (TR ile başlayan 26 karakter).'];
        }
        $q = $dbh->prepare('SELECT COUNT(*) FROM dbo.LM_FINANS_HESAP WHERE KOD = :k AND ID <> :id');
        $q->execute([':k' => $kod, ':id' => $id]);
        if ((int) $q->fetchColumn() > 0) {
            return ['ok' => false, 'mesaj' => "'{$kod}' kodu başka bir hesapta kullanılıyor."];
        }
        $p = [':t' => $tur, ':k' => $kod, ':a' => mb_substr($ad, 0, 120), ':b' => $tur === 2 ? (mb_substr(trim((string) ($v['banka_adi'] ?? '')), 0, 80) ?: null) : null,
              ':i' => $tur === 2 && $iban !== '' ? $iban : null];
        if ($id > 0) {
            $dbh->prepare('UPDATE dbo.LM_FINANS_HESAP SET TUR = :t, KOD = :k, AD = :a, BANKA_ADI = :b, IBAN = :i WHERE ID = :id')->execute($p + [':id' => $id]);
            return ['ok' => true, 'mesaj' => 'Hesap güncellendi.'];
        }
        $dbh->prepare('INSERT INTO dbo.LM_FINANS_HESAP (TUR, KOD, AD, BANKA_ADI, IBAN) VALUES (:t, :k, :a, :b, :i)')->execute($p);
        return ['ok' => true, 'mesaj' => ($tur === 2 ? 'Banka hesabı' : 'Kasa') . " '{$kod}' eklendi."];
    }
}

if (!function_exists('lumen_tanim_hesap_aktiflik')) {
    function lumen_tanim_hesap_aktiflik(PDO $dbh, int $id, bool $aktif): array
    {
        if (!$aktif) {
            $q = $dbh->prepare('SELECT ISNULL(SUM(GIRIS - CIKIS), 0) FROM dbo.LM_FINANS_HAREKET WHERE HESAP_ID = :id');
            $q->execute([':id' => $id]);
            if (abs((float) $q->fetchColumn()) >= 0.005) {
                return ['ok' => false, 'mesaj' => 'Bakiyesi olan hesap pasifleştirilemez; önce bakiyeyi başka hesaba virmanlayın.'];
            }
            if ((int) $dbh->query('SELECT COUNT(*) FROM dbo.LM_FINANS_HESAP WHERE AKTIF = 1 AND TUR = 1')->fetchColumn() <= 1
                && (int) $dbh->query("SELECT TUR FROM dbo.LM_FINANS_HESAP WHERE ID = {$id}")->fetchColumn() === 1) {
                return ['ok' => false, 'mesaj' => 'En az bir etkin kasa kalmalı.'];
            }
        }
        $dbh->prepare('UPDATE dbo.LM_FINANS_HESAP SET AKTIF = :a WHERE ID = :id')->execute([':a' => $aktif ? 1 : 0, ':id' => $id]);
        return ['ok' => true, 'mesaj' => $aktif ? 'Hesap etkinleştirildi.' : 'Hesap pasifleştirildi.'];
    }
}

if (!function_exists('lumen_tanim_depo_kaydet')) {
    function lumen_tanim_depo_kaydet(PDO $dbh, int $id, array $v): array
    {
        $kod = mb_strtoupper(trim((string) ($v['kod'] ?? '')), 'UTF-8');
        $ad = trim((string) ($v['ad'] ?? ''));
        $no = (int) ($v['no'] ?? -1);
        if (preg_match('/^[A-Z0-9._-]{1,20}$/', $kod) !== 1 || $ad === '' || $no < 0 || $no > 999) {
            return ['ok' => false, 'mesaj' => 'Kod, ad ve ambar no (0–999) zorunlu.'];
        }
        $q = $dbh->prepare('SELECT COUNT(*) FROM dbo.LM_DEPO WHERE (KOD = :k OR NO = :n) AND ID <> :id');
        $q->execute([':k' => $kod, ':n' => $no, ':id' => $id]);
        if ((int) $q->fetchColumn() > 0) {
            return ['ok' => false, 'mesaj' => 'Bu kod ya da ambar numarası başka bir depoda kullanılıyor.'];
        }
        if ($id > 0) {
            $dbh->prepare('UPDATE dbo.LM_DEPO SET KOD = :k, AD = :a, NO = :n WHERE ID = :id')->execute([':k' => $kod, ':a' => mb_substr($ad, 0, 80), ':n' => $no, ':id' => $id]);
            return ['ok' => true, 'mesaj' => 'Depo güncellendi.'];
        }
        $dbh->prepare('INSERT INTO dbo.LM_DEPO (KOD, AD, NO) VALUES (:k, :a, :n)')->execute([':k' => $kod, ':a' => mb_substr($ad, 0, 80), ':n' => $no]);
        return ['ok' => true, 'mesaj' => "Depo '{$ad}' eklendi."];
    }
}

if (!function_exists('lumen_tanim_depo_aktiflik')) {
    function lumen_tanim_depo_aktiflik(PDO $dbh, int $id, bool $aktif): array
    {
        if (!$aktif) {
            $q = $dbh->prepare('SELECT NO FROM dbo.LM_DEPO WHERE ID = :id');
            $q->execute([':id' => $id]);
            if ((int) $q->fetchColumn() === 0) {
                return ['ok' => false, 'mesaj' => 'Merkez depo (ambar 0) pasifleştirilemez.'];
            }
            $q = $dbh->prepare('SELECT COUNT(*) FROM dbo.LMV_STOK_MIKTAR WHERE DEPO_ID = :id AND ABS(MIKTAR) > 0.000001');
            $q->execute([':id' => $id]);
            if ((int) $q->fetchColumn() > 0) {
                return ['ok' => false, 'mesaj' => 'İçinde stok olan depo pasifleştirilemez; önce stokları sayımla sıfırlayın ya da aktarın.'];
            }
        }
        $dbh->prepare('UPDATE dbo.LM_DEPO SET AKTIF = :a WHERE ID = :id')->execute([':a' => $aktif ? 1 : 0, ':id' => $id]);
        return ['ok' => true, 'mesaj' => $aktif ? 'Depo etkinleştirildi.' : 'Depo pasifleştirildi.'];
    }
}

if (!function_exists('lumen_tanim_birim_kaydet')) {
    function lumen_tanim_birim_kaydet(PDO $dbh, int $id, array $v): array
    {
        $kod = mb_strtoupper(trim((string) ($v['kod'] ?? '')), 'UTF-8');
        $ad = trim((string) ($v['ad'] ?? ''));
        if (preg_match('/^[A-ZÇĞİÖŞÜ0-9._-]{1,20}$/u', $kod) !== 1 || $ad === '') {
            return ['ok' => false, 'mesaj' => 'Birim kodu (en fazla 20 karakter) ve adı zorunlu.'];
        }
        $q = $dbh->prepare('SELECT COUNT(*) FROM dbo.LM_BIRIM WHERE KOD = :k AND ID <> :id');
        $q->execute([':k' => $kod, ':id' => $id]);
        if ((int) $q->fetchColumn() > 0) {
            return ['ok' => false, 'mesaj' => "'{$kod}' birimi zaten var."];
        }
        if ($id > 0) {
            $dbh->prepare('UPDATE dbo.LM_BIRIM SET KOD = :k, AD = :a WHERE ID = :id')->execute([':k' => $kod, ':a' => mb_substr($ad, 0, 60), ':id' => $id]);
            return ['ok' => true, 'mesaj' => 'Birim güncellendi.'];
        }
        $dbh->prepare('INSERT INTO dbo.LM_BIRIM (KOD, AD) VALUES (:k, :a)')->execute([':k' => $kod, ':a' => mb_substr($ad, 0, 60)]);
        return ['ok' => true, 'mesaj' => "Birim '{$kod}' eklendi."];
    }
}

if (!function_exists('lumen_tanim_birim_sil')) {
    function lumen_tanim_birim_sil(PDO $dbh, int $id): array
    {
        $q = $dbh->prepare('SELECT (SELECT COUNT(*) FROM dbo.LM_STOK_BIRIM WHERE BIRIM_ID = :a) + (SELECT COUNT(*) FROM dbo.LM_STOK WHERE ANA_BIRIM_ID = :b)
                                 + (SELECT COUNT(*) FROM dbo.LM_BELGE_SATIR WHERE BIRIM_ID = :c)');
        $q->execute([':a' => $id, ':b' => $id, ':c' => $id]);
        if ((int) $q->fetchColumn() > 0) {
            return ['ok' => false, 'mesaj' => 'Bu birim ürünlerde ya da belgelerde kullanılıyor; silinemez.'];
        }
        $dbh->prepare('DELETE FROM dbo.LM_BIRIM WHERE ID = :id')->execute([':id' => $id]);
        return ['ok' => true, 'mesaj' => 'Birim silindi.'];
    }
}

if (!function_exists('lumen_tanim_kur_kaydet')) {
    function lumen_tanim_kur_kaydet(PDO $dbh, string $tarih, string $pb, float $alis, float $satis): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) !== 1 || $pb === 'TRY' || $alis <= 0 || $satis <= 0) {
            return ['ok' => false, 'mesaj' => 'Tarih, para birimi ve sıfırdan büyük alış/satış kuru girin.'];
        }
        $q = $dbh->prepare('SELECT COUNT(*) FROM dbo.LM_PARA_BIRIMI WHERE KOD = :k');
        $q->execute([':k' => $pb]);
        if ((int) $q->fetchColumn() === 0) {
            return ['ok' => false, 'mesaj' => 'Para birimi tanımlı değil.'];
        }
        $dbh->prepare("MERGE dbo.LM_KUR AS H USING (SELECT CAST(:t AS DATE) AS TARIH, :p AS PARA_BIRIMI) AS K
                         ON H.TARIH = K.TARIH AND H.PARA_BIRIMI = K.PARA_BIRIMI
                       WHEN MATCHED THEN UPDATE SET ALIS = :a, SATIS = :s
                       WHEN NOT MATCHED THEN INSERT (TARIH, PARA_BIRIMI, ALIS, SATIS) VALUES (K.TARIH, K.PARA_BIRIMI, :a2, :s2);")
            ->execute([':t' => $tarih, ':p' => $pb, ':a' => round($alis, 6), ':s' => round($satis, 6), ':a2' => round($alis, 6), ':s2' => round($satis, 6)]);
        return ['ok' => true, 'mesaj' => "{$pb} kuru kaydedildi (" . date('d.m.Y', (int) strtotime($tarih)) . ').'];
    }
}

if (!function_exists('lumen_tcmb_kurlari_cozumle')) {
    /**
     * TCMB today.xml içeriğinden döviz alış/satış kurları. @return array{tarih:string, kurlar:array<string, array{0:float,1:float}>}|null
     */
    function lumen_tcmb_kurlari_cozumle(string $xml): ?array
    {
        $eski = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($eski);
        if ($x === false) {
            return null;
        }
        $tarih = (string) ($x['Date'] ?? '');            // 10/17/2026
        $t = DateTime::createFromFormat('m/d/Y', $tarih);
        $kurlar = [];
        foreach ($x->Currency as $c) {
            $kod = (string) $c['CurrencyCode'];
            $birim = max(1, (int) $c->Unit);
            $alis = (float) str_replace(',', '.', (string) $c->ForexBuying);
            $satis = (float) str_replace(',', '.', (string) $c->ForexSelling);
            if ($alis > 0 && $satis > 0) {
                $kurlar[$kod] = [$alis / $birim, $satis / $birim];
            }
        }
        return $t && $kurlar !== [] ? ['tarih' => $t->format('Y-m-d'), 'kurlar' => $kurlar] : null;
    }
}

if (!function_exists('lumen_tcmb_kurlari_al')) {
    /** TCMB'nin günlük kurlarını (today.xml) indirip tanımlı para birimleri için kaydeder. */
    function lumen_tcmb_kurlari_al(PDO $dbh): array
    {
        $baglam = stream_context_create(['http' => ['timeout' => 8, 'header' => "User-Agent: Lumen\r\n"],
                                         'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $xml = @file_get_contents('https://www.tcmb.gov.tr/kurlar/today.xml', false, $baglam);
        if ($xml === false || $xml === '') {
            return ['ok' => false, 'mesaj' => 'TCMB\'ye ulaşılamadı (internet bağlantısını kontrol edin); kurları elle girebilirsiniz.'];
        }
        $c = lumen_tcmb_kurlari_cozumle($xml);
        if ($c === null) {
            return ['ok' => false, 'mesaj' => 'TCMB yanıtı okunamadı; kurları elle girin.'];
        }
        $pbler = $dbh->query("SELECT KOD FROM dbo.LM_PARA_BIRIMI WHERE KOD <> 'TRY'")->fetchAll(PDO::FETCH_COLUMN);
        $yazilan = [];
        foreach ($pbler as $pb) {
            if (isset($c['kurlar'][$pb])) {
                lumen_tanim_kur_kaydet($dbh, $c['tarih'], (string) $pb, $c['kurlar'][$pb][0], $c['kurlar'][$pb][1]);
                $yazilan[] = $pb;
            }
        }
        return $yazilan === []
            ? ['ok' => false, 'mesaj' => 'TCMB listesinde tanımlı para birimlerinin kuru yok.']
            : ['ok' => true, 'mesaj' => 'TCMB kurları alındı (' . date('d.m.Y', (int) strtotime($c['tarih'])) . '): ' . implode(', ', $yazilan) . '.'];
    }
}
