<?php

declare(strict_types=1);

/**
 * Ürün (stok) kartı ekleme ve düzenleme — yalnız bağımsız mod (kart/stok_duzenle.php).
 * Kod ve ana birim kart açıldıktan sonra değişmez (belgeler ana birimle tutulur); diğer
 * birimlerin çarpanı değişebilir (belge satırı kendi çarpanını saklar). Fiyat değişince
 * eski fiyat silinmez: bugünden geçerli yeni fiyat satırı eklenir (geçmiş korunur).
 */

if (!function_exists('lumen_stok_kart_oku')) {
    /** @return array{kart:array<string,mixed>, birimler:list<array>, barkodlar:list<array>, satis:?float, alis:?float, hareketli:bool}|null */
    function lumen_stok_kart_oku(PDO $dbh, int $id): ?array
    {
        $q = $dbh->prepare('SELECT S.*, B.KOD AS ANA_BIRIM FROM dbo.LM_STOK S JOIN dbo.LM_BIRIM B ON B.ID = S.ANA_BIRIM_ID WHERE S.ID = :id');
        $q->execute([':id' => $id]);
        $kart = $q->fetch(PDO::FETCH_ASSOC);
        if (!$kart) {
            return null;
        }
        $sorgu = static function (string $sql) use ($dbh, $id): array {
            $s = $dbh->prepare($sql);
            $s->execute([':id' => $id]);
            return $s->fetchAll(PDO::FETCH_ASSOC);
        };
        $birimler = $sorgu('SELECT SB.BIRIM_ID, B.KOD, SB.CARPAN, CASE WHEN EXISTS (SELECT 1 FROM dbo.LM_BELGE_SATIR BS
                                WHERE BS.STOK_ID = SB.STOK_ID AND BS.BIRIM_ID = SB.BIRIM_ID) THEN 1 ELSE 0 END AS KULLANILDI
                            FROM dbo.LM_STOK_BIRIM SB JOIN dbo.LM_BIRIM B ON B.ID = SB.BIRIM_ID
                            WHERE SB.STOK_ID = :id AND SB.BIRIM_ID <> ' . (int) $kart['ANA_BIRIM_ID'] . ' ORDER BY SB.CARPAN');
        $barkodlar = $sorgu('SELECT BARKOD, BIRIM_ID FROM dbo.LM_BARKOD WHERE STOK_ID = :id ORDER BY ID');
        $fiyat = static function (int $tur) use ($dbh, $id, $kart): ?float {
            $f = $dbh->prepare("SELECT TOP 1 FIYAT FROM dbo.LM_FIYAT WHERE STOK_ID = :id AND TUR = :t AND BIRIM_ID = :b AND PARA_BIRIMI = 'TRY'
                                AND BASLANGIC <= :bugun ORDER BY BASLANGIC DESC, ID DESC");
            $f->execute([':id' => $id, ':t' => $tur, ':b' => (int) $kart['ANA_BIRIM_ID'], ':bugun' => date('Y-m-d')]);
            $v = $f->fetchColumn();
            return $v === false ? null : (float) $v;
        };
        $h = $dbh->prepare('SELECT CASE WHEN EXISTS (SELECT 1 FROM dbo.LM_STOK_HAREKET WHERE STOK_ID = :a)
                                 OR EXISTS (SELECT 1 FROM dbo.LM_BELGE_SATIR WHERE STOK_ID = :b) THEN 1 ELSE 0 END');
        $h->execute([':a' => $id, ':b' => $id]);
        return ['kart' => $kart, 'birimler' => $birimler, 'barkodlar' => $barkodlar, 'satis' => $fiyat(1), 'alis' => $fiyat(2),
                'hareketli' => (int) $h->fetchColumn() === 1];
    }
}

if (!function_exists('lumen_stok_kart_kaydet')) {
    /**
     * @param array<string,mixed> $v  kod (yalnız yeni), ad, grup, ozel_kod, ana_birim_id (yalnız yeni), kdv, hizmet, aktif,
     *        birim_id[] + carpan[] (ek birimler), barkod[] + barkod_birim[], satis, alis
     * @param array{fiyat:bool, alis:bool} $izin  satış fiyatı / alış fiyatı yazma izni
     * @return array{ok:bool, mesaj:string, id?:int}
     */
    function lumen_stok_kart_kaydet(PDO $dbh, int $id, array $v, array $izin): array
    {
        $mevcut = $id > 0 ? lumen_stok_kart_oku($dbh, $id) : null;
        if ($id > 0 && $mevcut === null) {
            return ['ok' => false, 'mesaj' => 'Ürün bulunamadı.'];
        }
        $ad = trim((string) ($v['ad'] ?? ''));
        if ($ad === '' || mb_strlen($ad) > 200) {
            return ['ok' => false, 'mesaj' => 'Ürün adı boş ya da 200 karakterden uzun.'];
        }
        $kdv = lumen_tutar_coz((string) ($v['kdv'] ?? '20'));
        if ($kdv < 0 || $kdv > 100) {
            return ['ok' => false, 'mesaj' => 'KDV oranı 0 ile 100 arasında olmalı.'];
        }
        $grup = mb_substr(trim((string) ($v['grup'] ?? '')), 0, 40) ?: null;
        $ozel = mb_substr(trim((string) ($v['ozel_kod'] ?? '')), 0, 40) ?: null;
        $hizmet = !empty($v['hizmet']);
        $aktif = $id === 0 || !empty($v['aktif']);
        $birimVar = static function (int $b) use ($dbh): bool {
            $q = $dbh->prepare('SELECT 1 FROM dbo.LM_BIRIM WHERE ID = :b');
            $q->execute([':b' => $b]);
            return $q->fetchColumn() !== false;
        };

        if ($mevcut === null) {
            $kod = mb_strtoupper(trim((string) ($v['kod'] ?? '')), 'UTF-8');
            if ($kod === '' || mb_strlen($kod) > 40 || preg_match('/[\x00-\x1F]/u', $kod) === 1) {
                return ['ok' => false, 'mesaj' => 'Ürün kodu boş ya da 40 karakterden uzun.'];
            }
            $q = $dbh->prepare('SELECT 1 FROM dbo.LM_STOK WHERE KOD = :k');
            $q->execute([':k' => $kod]);
            if ($q->fetchColumn() !== false) {
                return ['ok' => false, 'mesaj' => "'{$kod}' kodlu bir ürün zaten var."];
            }
            $ana = (int) ($v['ana_birim_id'] ?? 0);
            if (!$birimVar($ana)) {
                return ['ok' => false, 'mesaj' => 'Ana birim seçilmedi.'];
            }
        } else {
            $ana = (int) $mevcut['kart']['ANA_BIRIM_ID'];
            // Hareket görmüş ürün hizmete/stoklu karta çevrilemez (eldeki miktar anlamsızlaşır).
            if ($mevcut['hareketli'] && $hizmet !== ((int) $mevcut['kart']['STOK_TAKIPLI'] === 0)) {
                return ['ok' => false, 'mesaj' => 'Hareket görmüş ürünün stok takibi değiştirilemez.'];
            }
        }

        // Ek birimler: birim → çarpan (ana birim cinsinden)
        $ekler = [];
        $bIdler = (array) ($v['birim_id'] ?? []);
        $carpanlar = (array) ($v['carpan'] ?? []);
        foreach ($bIdler as $i => $b) {
            $b = (int) $b;
            $c = trim((string) ($carpanlar[$i] ?? ''));
            if ($b <= 0 && $c === '') {
                continue;
            }
            $cs = lumen_tutar_coz($c);
            if ($b <= 0 || !$birimVar($b) || $b === $ana) {
                return ['ok' => false, 'mesaj' => 'Ek birimlerde geçersiz ya da ana birimle aynı birim var.'];
            }
            if (isset($ekler[$b])) {
                return ['ok' => false, 'mesaj' => 'Aynı birim iki kez yazılmış.'];
            }
            if (!($cs > 0) || $cs > 1000000) {
                return ['ok' => false, 'mesaj' => 'Birim çarpanı sıfırdan büyük olmalı (ör. 1 KOLİ = 24 ADET ise 24).'];
            }
            $ekler[$b] = round($cs, 6);
        }
        if ($mevcut !== null) {
            foreach ($mevcut['birimler'] as $mb) {
                if ((int) $mb['KULLANILDI'] === 1 && !isset($ekler[(int) $mb['BIRIM_ID']])) {
                    return ['ok' => false, 'mesaj' => "{$mb['KOD']} birimi belgelerde kullanıldığı için kaldırılamaz."];
                }
            }
        }

        // Barkodlar (her biri bir birime ait; sistem genelinde tek)
        $barkodlar = [];
        $bkBirim = (array) ($v['barkod_birim'] ?? []);
        foreach ((array) ($v['barkod'] ?? []) as $i => $bk) {
            $bk = trim((string) $bk);
            if ($bk === '') {
                continue;
            }
            if (mb_strlen($bk) > 60 || preg_match('/[\x00-\x1F]/u', $bk) === 1) {
                return ['ok' => false, 'mesaj' => 'Barkod 60 karakterden uzun olamaz.'];
            }
            $bb = (int) ($bkBirim[$i] ?? $ana) ?: $ana;
            if ($bb !== $ana && !isset($ekler[$bb])) {
                return ['ok' => false, 'mesaj' => "'{$bk}' barkodunun birimi bu üründe tanımlı değil."];
            }
            if (isset($barkodlar[$bk])) {
                return ['ok' => false, 'mesaj' => "'{$bk}' barkodu iki kez yazılmış."];
            }
            $q = $dbh->prepare('SELECT S.KOD FROM dbo.LM_BARKOD B JOIN dbo.LM_STOK S ON S.ID = B.STOK_ID WHERE B.BARKOD = :b AND B.STOK_ID <> :s');
            $q->execute([':b' => $bk, ':s' => $id]);
            if (($baska = $q->fetchColumn()) !== false) {
                return ['ok' => false, 'mesaj' => "'{$bk}' barkodu {$baska} ürününde kayıtlı."];
            }
            $barkodlar[$bk] = $bb;
        }

        $fiyatlar = [];
        foreach ([1 => ['satis', 'fiyat'], 2 => ['alis', 'alis']] as $tur => [$alan, $izinAdi]) {
            if (empty($izin[$izinAdi]) || !array_key_exists($alan, $v)) {
                continue;
            }
            $s = trim((string) $v[$alan]);
            if ($s === '') {
                continue;   // boş bırakılan fiyat değişmez
            }
            $f = lumen_tutar_coz($s);
            if ($f < 0 || $f > 1e12) {
                return ['ok' => false, 'mesaj' => 'Fiyat sıfır ya da pozitif olmalı.'];
            }
            $once = $mevcut[$alan] ?? null;
            if ($once === null || abs($once - $f) >= 0.00005) {
                $fiyatlar[$tur] = round($f, 4);
            }
        }

        try {
            $dbh->beginTransaction();
            if ($mevcut === null) {
                $id = lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_STOK (KOD, AD, GRUP, OZEL_KOD, ANA_BIRIM_ID, KDV_ORANI, STOK_TAKIPLI)
                    VALUES (:k, :a, :g, :o, :b, :kdv, :t)', [':k' => $kod, ':a' => $ad, ':g' => $grup, ':o' => $ozel, ':b' => $ana,
                    ':kdv' => $kdv, ':t' => $hizmet ? 0 : 1], 'ID');
                $dbh->prepare('INSERT INTO dbo.LM_STOK_BIRIM (STOK_ID, BIRIM_ID, CARPAN) VALUES (:s, :b, 1)')->execute([':s' => $id, ':b' => $ana]);
            } else {
                $dbh->prepare('UPDATE dbo.LM_STOK SET AD = :a, GRUP = :g, OZEL_KOD = :o, KDV_ORANI = :kdv, STOK_TAKIPLI = :t, AKTIF = :ak,
                    GUNCELLEME = SYSDATETIME() WHERE ID = :id')->execute([':a' => $ad, ':g' => $grup, ':o' => $ozel, ':kdv' => $kdv,
                    ':t' => $hizmet ? 0 : 1, ':ak' => $aktif ? 1 : 0, ':id' => $id]);
                // Kaldırılan ek birimler: o birimin barkodu ve fiyatı da gider (belgede kullanılmadığı yukarıda denetlendi)
                foreach ($mevcut['birimler'] as $mb) {
                    $b = (int) $mb['BIRIM_ID'];
                    if (!isset($ekler[$b])) {
                        foreach (['LM_BARKOD', 'LM_FIYAT', 'LM_STOK_BIRIM'] as $t) {
                            $dbh->prepare("DELETE FROM dbo.{$t} WHERE STOK_ID = :s AND BIRIM_ID = :b")->execute([':s' => $id, ':b' => $b]);
                        }
                    }
                }
            }
            $guncelleBirim = $dbh->prepare('UPDATE dbo.LM_STOK_BIRIM SET CARPAN = :c WHERE STOK_ID = :s AND BIRIM_ID = :b');
            $ekleBirim = $dbh->prepare('INSERT INTO dbo.LM_STOK_BIRIM (STOK_ID, BIRIM_ID, CARPAN) VALUES (:s, :b, :c)');
            foreach ($ekler as $b => $c) {
                $guncelleBirim->execute([':c' => $c, ':s' => $id, ':b' => $b]);
                if ($guncelleBirim->rowCount() === 0) {
                    $ekleBirim->execute([':s' => $id, ':b' => $b, ':c' => $c]);
                }
            }
            $dbh->prepare('DELETE FROM dbo.LM_BARKOD WHERE STOK_ID = :s')->execute([':s' => $id]);
            $ekleBarkod = $dbh->prepare('INSERT INTO dbo.LM_BARKOD (STOK_ID, BIRIM_ID, BARKOD) VALUES (:s, :b, :k)');
            foreach ($barkodlar as $bk => $bb) {
                $ekleBarkod->execute([':s' => $id, ':b' => $bb, ':k' => (string) $bk]);
            }
            // Fiyat: bugün başlayan satır varsa güncellenir, yoksa yenisi eklenir (önceki fiyatlar geçmişte kalır)
            foreach ($fiyatlar as $tur => $f) {
                $u = $dbh->prepare("UPDATE dbo.LM_FIYAT SET FIYAT = :f WHERE STOK_ID = :s AND TUR = :t AND BIRIM_ID = :b
                                    AND PARA_BIRIMI = 'TRY' AND BASLANGIC = :bugun");
                $u->execute([':f' => $f, ':s' => $id, ':t' => $tur, ':b' => $ana, ':bugun' => date('Y-m-d')]);
                if ($u->rowCount() === 0) {
                    $dbh->prepare("INSERT INTO dbo.LM_FIYAT (STOK_ID, TUR, BIRIM_ID, FIYAT, PARA_BIRIMI, BASLANGIC)
                                   VALUES (:s, :t, :b, :f, 'TRY', :bugun)")->execute([':s' => $id, ':t' => $tur, ':b' => $ana, ':f' => $f, ':bugun' => date('Y-m-d')]);
                }
            }
            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('lumen_stok_kart_kaydet: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'Ürün kaydedilemedi.'];
        }
        return ['ok' => true, 'mesaj' => $mevcut === null ? 'Ürün eklendi.' : 'Ürün kartı güncellendi.', 'id' => $id];
    }
}
