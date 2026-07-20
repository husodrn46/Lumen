<?php

declare(strict_types=1);

/**
 * cari_olustur_lib.php — ANDL serisinde yeni cari olusturma (template-copy).
 * cariyeni.php ve cari_olustur_ve_aktar.php tarafindan paylasilir.
 * ayr.php (guid, $dbh) onceden include edilmis olmalidir.
 */

if (!function_exists('cariyeni_sonraki_kod')) {
    /**
     * Siradaki ANDL kodu uret: ANDL0001 formatinda, mevcut en buyukten +1.
     */
    function cariyeni_sonraki_kod(PDO $dbh, string $firma, string $onek): string
    {
        $like = $onek . str_repeat('[0-9]', 4);
        $max = $dbh->query("SELECT MAX(CODE) FROM {$firma}CLCARD WHERE CODE LIKE '{$like}'")->fetchColumn();
        $n = 1;
        if ($max && preg_match('/^' . preg_quote($onek, '/') . '(\d+)$/', (string) $max, $m)) {
            $n = (int) $m[1] + 1;
        }
        return $onek . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('cari_olustur_andl')) {
    /**
     * ANDL serisinde yeni cari olusturur (calisan bir ANDL carisini sablon alip
     * cari-spesifik alanlari override ederek; LOGO butunlugu korunur).
     * Kod cakismasinda (2601/2627) 3 kez tekrar dener.
     *
     * @return array{ok:bool, cariid:int, kod:string, ad:string, mesaj:string}
     */
    function cari_olustur_andl(
        PDO $dbh,
        string $firma,
        int $kullanici,
        string $unvan,
        string $telefon = '',
        string $sehir = '',
        string $ilce = '',
        string $onek = 'ANDL'
    ): array {
        $hata = static fn(string $m): array => ['ok' => false, 'cariid' => 0, 'kod' => '', 'ad' => '', 'mesaj' => $m];

        $unvan = mb_substr(trim($unvan), 0, 200);
        $telefon = mb_substr(trim($telefon), 0, 30);
        $sehir = mb_substr(trim($sehir), 0, 30);
        $ilce = mb_substr(trim($ilce), 0, 30);
        if ($unvan === '') {
            return $hata('Cari unvani zorunludur.');
        }

        $denendi = 0;
        while ($denendi < 3) {
            $denendi++;
            $yeniKod = cariyeni_sonraki_kod($dbh, $firma, $onek);
            try {
                // Sablon: calisan en son ANDL cari (tum alanlar = LOGO butunlugu)
                $ref = $dbh->query("SELECT TOP 1 * FROM {$firma}CLCARD WHERE CODE LIKE '{$onek}%' AND ACTIVE=0 ORDER BY LOGICALREF DESC")->fetch(PDO::FETCH_ASSOC);
                if (!$ref) {
                    $ref = $dbh->query("SELECT TOP 1 * FROM {$firma}CLCARD WHERE ACTIVE=0 ORDER BY LOGICALREF DESC")->fetch(PDO::FETCH_ASSOC);
                }
                if (!$ref) {
                    return $hata('Sablon cari bulunamadi.');
                }
                unset($ref['LOGICALREF']); // IDENTITY

                $ref['CODE'] = $yeniKod;
                $ref['DEFINITION_'] = $unvan;
                $ref['DEFINITION2'] = '';
                $ref['TELNRS1'] = $telefon;
                $ref['TELNRS2'] = '';
                $ref['CITY'] = $sehir;
                $ref['TOWN'] = $ilce;
                $ref['DISTRICT'] = '';
                $ref['ADDR1'] = '';
                $ref['ADDR2'] = '';
                $ref['EMAILADDR'] = '';
                $ref['TAXNR'] = '';
                $ref['TAXOFFICE'] = '';
                if (array_key_exists('TCKNO', $ref)) {
                    $ref['TCKNO'] = '';
                }
                $ref['SPECODE'] = '';
                $ref['CYPHCODE'] = '';
                $ref['GUID'] = strtoupper(guid());
                $ref['CAPIBLOCK_CREATEDBY'] = $kullanici;
                $ref['CAPIBLOCK_CREADEDDATE'] = date('Y-m-d H:i:s');
                $ref['CAPIBLOCK_CREATEDHOUR'] = (int) date('H');
                $ref['CAPIBLOCK_CREATEDMIN'] = (int) date('i');
                $ref['CAPIBLOCK_CREATEDSEC'] = (int) date('s');

                $kolonlar = array_keys($ref);
                $kolStr = '[' . implode('],[', $kolonlar) . ']';
                $phStr = ':' . implode(', :', $kolonlar);
                $stmt = $dbh->prepare("INSERT INTO {$firma}CLCARD ({$kolStr}) VALUES ({$phStr})");
                foreach ($ref as $k => $v) {
                    $stmt->bindValue(':' . $k, $v);
                }
                $stmt->execute();
                $cariid = (int) $dbh->lastInsertId();

                if (function_exists('logGenel')) {
                    @logGenel('cari_olustur', "Yeni cari: [{$yeniKod}] {$unvan}", $kullanici);
                }
                return ['ok' => true, 'cariid' => $cariid, 'kod' => $yeniKod, 'ad' => $unvan, 'mesaj' => 'Cari olusturuldu.'];
            } catch (PDOException $e) {
                $kod = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($kod, [2601, 2627], true) && $denendi < 3) {
                    continue; // kod cakismasi -> yeni kod ile tekrar
                }
                error_log('cari_olustur_andl INSERT: ' . $e->getMessage());
                return $hata('Cari olusturulamadi. Lutfen tekrar deneyin.');
            } catch (Throwable $e) {
                error_log('cari_olustur_andl: ' . $e->getMessage());
                return $hata('Cari olusturulamadi.');
            }
        }
        return $hata('Kod cakismasi nedeniyle olusturulamadi, tekrar deneyin.');
    }
}
