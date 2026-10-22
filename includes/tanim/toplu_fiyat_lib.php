<?php

declare(strict_types=1);

/**
 * Toplu fiyat güncelleme (yalnız bağımsız mod, tanimlar/toplu_fiyat.php): seçilen ürünlerin
 * satış ya da alış fiyatı yüzde ya da sabit tutar kadar değiştirilir, isteğe bağlı yuvarlanır.
 * Eski fiyat silinmez: seçilen tarihten geçerli yeni fiyat satırı eklenir (aynı gün başlayan
 * satır varsa güncellenir). Yalnız ana birimdeki TL fiyatı olan aktif ürünler etkilenir.
 */

if (!function_exists('lumen_toplu_fiyat_coz')) {
    /**
     * Form değerlerini doğrular.
     * @return array{ok:bool, mesaj?:string, f?:array{tur:int, grup:string, kod:string, yontem:string, deger:float, yuvarla:float, tarih:string}}
     */
    function lumen_toplu_fiyat_coz(PDO $dbh, array $v): array
    {
        $tur = (int) ($v['tur'] ?? 1);
        $yontem = (string) ($v['yontem'] ?? 'yuzde');
        $deger = lumen_tutar_coz((string) ($v['deger'] ?? '0'));
        $yuvarla = (float) str_replace(',', '.', (string) ($v['yuvarla'] ?? '0'));
        $tarih = (string) ($v['tarih'] ?? date('Y-m-d'));
        if (!in_array($tur, [1, 2], true) || !in_array($yontem, ['yuzde', 'tutar'], true)) {
            return ['ok' => false, 'mesaj' => 'Geçersiz seçim.'];
        }
        if ($deger == 0.0 || ($yontem === 'yuzde' && ($deger <= -100 || $deger > 1000)) || abs($deger) > 1e9) {
            return ['ok' => false, 'mesaj' => $yontem === 'yuzde' ? 'Yüzde -100 ile 1000 arasında ve sıfırdan farklı olmalı.' : 'Tutar sıfırdan farklı olmalı.'];
        }
        if (!in_array($yuvarla, [0.0, 0.01, 0.05, 0.1, 0.5, 1.0, 5.0, 10.0], true)) {
            return ['ok' => false, 'mesaj' => 'Geçersiz yuvarlama.'];
        }
        if (($th = lumen_tarih_hatasi($dbh, $tarih)) !== null) {
            return ['ok' => false, 'mesaj' => $th];
        }
        return ['ok' => true, 'f' => ['tur' => $tur, 'grup' => mb_substr(trim((string) ($v['grup'] ?? '')), 0, 40),
            'kod' => mb_strtoupper(mb_substr(trim((string) ($v['kod'] ?? '')), 0, 40), 'UTF-8'),
            'yontem' => $yontem, 'deger' => $deger, 'yuvarla' => $yuvarla, 'tarih' => $tarih]];
    }
}

if (!function_exists('lumen_toplu_fiyat_hesapla')) {
    /**
     * Etkilenecek ürünler ve yeni fiyatları.
     * @return list<array{stok_id:int, birim_id:int, kod:string, ad:string, eski:float, yeni:float}>
     */
    function lumen_toplu_fiyat_hesapla(PDO $dbh, array $f): array
    {
        $kosul = ['S.AKTIF = 1'];
        $p = [':tur' => $f['tur'], ':tarih' => $f['tarih']];
        if ($f['grup'] !== '') {
            $kosul[] = 'S.GRUP = :grup';
            $p[':grup'] = $f['grup'];
        }
        if ($f['kod'] !== '') {
            // LIKE özel karakterleri kaçırılır: kod başlangıcı birebir aranır
            $kosul[] = "S.KOD LIKE :kod ESCAPE '!'";
            $p[':kod'] = str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $f['kod']) . '%';
        }
        $st = $dbh->prepare("SELECT S.ID, S.ANA_BIRIM_ID, S.KOD, S.AD, F.FIYAT
            FROM dbo.LM_STOK S
            CROSS APPLY (SELECT TOP 1 FIYAT FROM dbo.LM_FIYAT X WHERE X.STOK_ID = S.ID AND X.TUR = :tur AND X.BIRIM_ID = S.ANA_BIRIM_ID
                         AND X.PARA_BIRIMI = 'TRY' AND X.BASLANGIC <= :tarih ORDER BY X.BASLANGIC DESC, X.ID DESC) F
            WHERE " . implode(' AND ', $kosul) . ' ORDER BY S.KOD');
        $st->execute($p);
        $sonuc = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $eski = (float) $r['FIYAT'];
            $yeni = $f['yontem'] === 'yuzde' ? $eski * (1 + $f['deger'] / 100) : $eski + $f['deger'];
            if ($f['yuvarla'] > 0) {
                $yeni = round($yeni / $f['yuvarla']) * $f['yuvarla'];
            }
            $yeni = round(max(0.0, $yeni), 4);
            if (abs($yeni - $eski) < 0.00005) {
                continue;
            }
            $sonuc[] = ['stok_id' => (int) $r['ID'], 'birim_id' => (int) $r['ANA_BIRIM_ID'], 'kod' => (string) $r['KOD'],
                        'ad' => (string) $r['AD'], 'eski' => $eski, 'yeni' => $yeni];
        }
        return $sonuc;
    }
}

if (!function_exists('lumen_toplu_fiyat_uygula')) {
    /** @return array{ok:bool, mesaj:string, sayi?:int} */
    function lumen_toplu_fiyat_uygula(PDO $dbh, array $f): array
    {
        $liste = lumen_toplu_fiyat_hesapla($dbh, $f);
        if ($liste === []) {
            return ['ok' => false, 'mesaj' => 'Değişecek fiyat yok.'];
        }
        try {
            $dbh->beginTransaction();
            // Hesaplanan fiyatlar geçici tabloya 500'erli yazılır, sonra iki toplu komutla uygulanır
            // (20.000 üründe satır satır yazmak dakikalar sürüyordu).
            $dbh->exec('CREATE TABLE #TF (STOK_ID INT NOT NULL PRIMARY KEY, BIRIM_ID INT NOT NULL, FIYAT DECIMAL(19,4) NOT NULL)');
            foreach (array_chunk($liste, 500) as $parca) {
                $yer = [];
                $p = [];
                foreach ($parca as $i => $r) {
                    $yer[] = "(:s{$i}, :b{$i}, :f{$i})";
                    $p[":s{$i}"] = $r['stok_id'];
                    $p[":b{$i}"] = $r['birim_id'];
                    $p[":f{$i}"] = $r['yeni'];
                }
                $dbh->prepare('INSERT INTO #TF (STOK_ID, BIRIM_ID, FIYAT) VALUES ' . implode(', ', $yer))->execute($p);
            }
            $p = [':t' => $f['tur'], ':tarih' => $f['tarih']];
            $dbh->prepare("UPDATE X SET FIYAT = T.FIYAT FROM dbo.LM_FIYAT X JOIN #TF T ON T.STOK_ID = X.STOK_ID AND T.BIRIM_ID = X.BIRIM_ID
                           WHERE X.TUR = :t AND X.PARA_BIRIMI = 'TRY' AND X.BASLANGIC = :tarih")->execute($p);
            $dbh->prepare("INSERT INTO dbo.LM_FIYAT (STOK_ID, TUR, BIRIM_ID, FIYAT, PARA_BIRIMI, BASLANGIC)
                           SELECT T.STOK_ID, :t, T.BIRIM_ID, T.FIYAT, 'TRY', :tarih FROM #TF T
                           WHERE NOT EXISTS (SELECT 1 FROM dbo.LM_FIYAT X WHERE X.STOK_ID = T.STOK_ID AND X.BIRIM_ID = T.BIRIM_ID
                                             AND X.TUR = :t2 AND X.PARA_BIRIMI = 'TRY' AND X.BASLANGIC = :tarih2)")
                ->execute($p + [':t2' => $f['tur'], ':tarih2' => $f['tarih']]);
            $dbh->exec('DROP TABLE #TF');
            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            try {
                $dbh->exec("IF OBJECT_ID('tempdb..#TF') IS NOT NULL DROP TABLE #TF");
            } catch (Throwable) {
            }
            error_log('lumen_toplu_fiyat_uygula: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'Fiyatlar güncellenemedi; hiçbir fiyat değişmedi.'];
        }
        return ['ok' => true, 'mesaj' => count($liste) . ' ürünün ' . ($f['tur'] === 1 ? 'satış' : 'alış') . ' fiyatı '
            . date('d.m.Y', (int) strtotime($f['tarih'])) . ' tarihinden geçerli olarak güncellendi.', 'sayi' => count($liste)];
    }
}
