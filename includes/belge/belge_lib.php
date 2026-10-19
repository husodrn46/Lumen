<?php

declare(strict_types=1);

/**
 * Lumen belge motoru (yalnız bağımsız mod): fatura, iade, irsaliye, üretimden giriş, sayım.
 *
 * Bir belge onaylandığında tek transaction'da:
 *   LM_BELGE + LM_BELGE_SATIR
 *   LM_STOK_HAREKET  — stok etkisi (ana birimde; satış/iade yönüne göre giriş/çıkış)
 *   LM_CARI_HAREKET  — fatura/iade cari etkisi (KDV dahil net tutar)
 *   kaynak sipariş satırının teslim miktarı (siparişten aktarımda) ve kapanışı
 * İptal bu etkilerin hepsini geri alır, belge DURUM 9 olarak kalır (iz).
 * Numara LM_SERI sayacından, satır kilidiyle (MAX+1 yarışı yok).
 *
 * LOGO modunda kullanılmaz: orada fatura/irsaliye LOGO'da kesilir.
 */

require_once __DIR__ . '/../veri/veri.php';

if (!function_exists('lumen_belge_turleri')) {
    /**
     * Belge türleri. stok: +1 giriş, -1 çıkış, 0 yok; cari: +1 borç, -1 alacak, 0 yok.
     * @return array<string, array{tur:int, yon:int, seri:string, ad:string, stok:int, cari:int, cari_zorunlu:bool}>
     */
    function lumen_belge_turleri(): array
    {
        return [
            'satis_fatura' => ['tur' => 3, 'yon' => 1, 'seri' => 'SATIS_FATURA', 'ad' => 'Satış Faturası', 'stok' => -1, 'cari' => 1, 'cari_zorunlu' => true],
            'alis_fatura' => ['tur' => 3, 'yon' => 2, 'seri' => 'ALIS_FATURA', 'ad' => 'Alış Faturası', 'stok' => 1, 'cari' => -1, 'cari_zorunlu' => true],
            'satis_iade' => ['tur' => 4, 'yon' => 1, 'seri' => 'SATIS_IADE', 'ad' => 'Satış İade Faturası', 'stok' => 1, 'cari' => -1, 'cari_zorunlu' => true],
            'alis_iade' => ['tur' => 4, 'yon' => 2, 'seri' => 'ALIS_IADE', 'ad' => 'Alış İade Faturası', 'stok' => -1, 'cari' => 1, 'cari_zorunlu' => true],
            'satis_irsaliye' => ['tur' => 2, 'yon' => 1, 'seri' => 'SATIS_IRSALIYE', 'ad' => 'Satış İrsaliyesi', 'stok' => -1, 'cari' => 0, 'cari_zorunlu' => true],
            'alis_irsaliye' => ['tur' => 2, 'yon' => 2, 'seri' => 'ALIS_IRSALIYE', 'ad' => 'Alış İrsaliyesi', 'stok' => 1, 'cari' => 0, 'cari_zorunlu' => true],
            'uretim' => ['tur' => 5, 'yon' => 2, 'seri' => 'URETIM', 'ad' => 'Üretimden Giriş', 'stok' => 1, 'cari' => 0, 'cari_zorunlu' => false],
            'sayim_fazla' => ['tur' => 6, 'yon' => 2, 'seri' => 'SAYIM', 'ad' => 'Sayım Fazlası', 'stok' => 1, 'cari' => 0, 'cari_zorunlu' => false],
            'sayim_eksik' => ['tur' => 6, 'yon' => 1, 'seri' => 'SAYIM', 'ad' => 'Sayım Eksiği', 'stok' => -1, 'cari' => 0, 'cari_zorunlu' => false],
        ];
    }
}

if (!function_exists('lumen_belge_tur_kodu')) {
    /** LM_BELGE (TUR, YON) → tür kodu ('satis_fatura' …); bilinmiyorsa null. */
    function lumen_belge_tur_kodu(int $tur, int $yon): ?string
    {
        foreach (lumen_belge_turleri() as $kod => $t) {
            if ($t['tur'] === $tur && $t['yon'] === $yon) {
                return $kod;
            }
        }
        return null;
    }
}

if (!function_exists('lumen_belge_satir_hesapla')) {
    /**
     * Satır tutarları (TL, 2 hane): tutar = miktar × fiyat; iskonto = tutar × oran;
     * net = tutar − iskonto; KDV = net × KDV oranı.
     * @return array{tutar:float, iskonto:float, net:float, kdv:float}
     */
    function lumen_belge_satir_hesapla(float $miktar, float $fiyat, float $iskontoOrani, float $kdvOrani): array
    {
        $tutar = round($miktar * $fiyat, 2);
        $iskonto = round($tutar * $iskontoOrani / 100, 2);
        $net = round($tutar - $iskonto, 2);
        $kdv = round($net * $kdvOrani / 100, 2);
        return ['tutar' => $tutar, 'iskonto' => $iskonto, 'net' => $net, 'kdv' => $kdv];
    }
}

if (!function_exists('lumen_belge_seri_no')) {
    /** Sayaçtan sıradaki belge numarası (ör. FTR000031). Transaction içinde çağrılmalıdır. */
    function lumen_belge_seri_no(PDO $dbh, string $seri): string
    {
        $st = $dbh->prepare("UPDATE dbo.LM_SERI SET SON_NO = SON_NO + 1
                             OUTPUT INSERTED.ONEK, INSERTED.SON_NO, INSERTED.HANE WHERE TUR = :t");
        $st->execute([':t' => $seri]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        $st->closeCursor();
        if (!$r) {
            throw new RuntimeException("Seri tanımlı değil: {$seri}");
        }
        return (string) $r['ONEK'] . str_pad((string) $r['SON_NO'], (int) $r['HANE'], '0', STR_PAD_LEFT);
    }
}

if (!function_exists('lumen_belge_kaydet')) {
    /**
     * Belgeyi kesip etkilerini yazar.
     *
     * $baslik: cari_id, tarih (Y-m-d), vade (Y-m-d|null), depo_id (boşsa merkez), aciklama, dokuman_no
     * $satirlar: [['stok_id', 'miktar', 'birim_id'?, 'fiyat', 'iskonto' (%), 'kdv' (%), 'aciklama'?, 'kaynak_satir_id'?], …]
     *            kaynak_satir_id: siparişten aktarımda sipariş satırı (teslim miktarı güncellenir)
     *
     * @return array{ok:bool, mesaj:string, id?:int, no?:string, net?:float}
     */
    function lumen_belge_kaydet(PDO $dbh, string $turKodu, array $baslik, array $satirlar, int $kullanici): array
    {
        $turler = lumen_belge_turleri();
        if (!isset($turler[$turKodu])) {
            return ['ok' => false, 'mesaj' => 'Geçersiz belge türü.'];
        }
        $t = $turler[$turKodu];
        $cariId = (int) ($baslik['cari_id'] ?? 0);
        if ($t['cari_zorunlu'] && $cariId <= 0) {
            return ['ok' => false, 'mesaj' => 'Cari seçilmedi.'];
        }
        $tarih = (string) ($baslik['tarih'] ?? date('Y-m-d'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) !== 1) {
            return ['ok' => false, 'mesaj' => 'Tarih geçersiz.'];
        }
        $vade = isset($baslik['vade']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $baslik['vade']) === 1 ? (string) $baslik['vade'] : null;
        $satirlar = array_values(array_filter($satirlar, static fn($s): bool => is_array($s) && (float) ($s['miktar'] ?? 0) > 0));
        if ($satirlar === []) {
            return ['ok' => false, 'mesaj' => 'En az bir satır girin (miktarı 0\'dan büyük).'];
        }

        try {
            $dbh->beginTransaction();

            if ($cariId > 0) {
                $c = $dbh->prepare('SELECT ID FROM dbo.LM_CARI WITH (UPDLOCK) WHERE ID = :c AND AKTIF = 1');
                $c->execute([':c' => $cariId]);
                if (!$c->fetchColumn()) {
                    $dbh->rollBack();
                    return ['ok' => false, 'mesaj' => 'Cari bulunamadı veya pasif.'];
                }
            }
            $depoId = (int) ($baslik['depo_id'] ?? 0);
            if ($depoId <= 0) {
                $depoId = (int) $dbh->query('SELECT TOP 1 ID FROM dbo.LM_DEPO WHERE AKTIF = 1 ORDER BY NO')->fetchColumn();
            }
            if ($depoId <= 0) {
                throw new RuntimeException('Depo tanımlı değil.');
            }

            // Satırları doğrula ve hesapla
            $hazir = [];
            $toplam = ['brut' => 0.0, 'iskonto' => 0.0, 'kdv' => 0.0, 'net' => 0.0];
            $stokSt = $dbh->prepare('SELECT S.ID, S.ANA_BIRIM_ID, S.STOK_TAKIPLI, S.AD FROM dbo.LM_STOK S WHERE S.ID = :s AND S.AKTIF = 1');
            $birimSt = $dbh->prepare('SELECT CARPAN FROM dbo.LM_STOK_BIRIM WHERE STOK_ID = :s AND BIRIM_ID = :b');
            $kaynakSt = $dbh->prepare('SELECT S.ID, S.MIKTAR, S.TESLIM_MIKTAR, S.STOK_ID, S.BIRIM_ID, B.CARI_ID, B.TUR, B.YON, B.DURUM
                                       FROM dbo.LM_BELGE_SATIR S WITH (UPDLOCK) JOIN dbo.LM_BELGE B ON B.ID = S.BELGE_ID WHERE S.ID = :k');
            foreach ($satirlar as $i => $s) {
                $stokSt->execute([':s' => (int) ($s['stok_id'] ?? 0)]);
                $stok = $stokSt->fetch(PDO::FETCH_ASSOC);
                if (!$stok) {
                    $dbh->rollBack();
                    return ['ok' => false, 'mesaj' => 'Satır ' . ($i + 1) . ': ürün bulunamadı veya pasif.'];
                }
                $birimId = (int) ($s['birim_id'] ?? 0) ?: (int) $stok['ANA_BIRIM_ID'];
                $birimSt->execute([':s' => (int) $stok['ID'], ':b' => $birimId]);
                $carpan = (float) $birimSt->fetchColumn();
                if ($carpan <= 0) {
                    $dbh->rollBack();
                    return ['ok' => false, 'mesaj' => 'Satır ' . ($i + 1) . ': birim bu ürün için tanımlı değil.'];
                }
                $miktar = round((float) $s['miktar'], 6);
                $fiyat = max(0.0, (float) ($s['fiyat'] ?? 0));
                $isk = min(100.0, max(0.0, (float) ($s['iskonto'] ?? 0)));
                $kdvOran = min(100.0, max(0.0, (float) ($s['kdv'] ?? 0)));
                $kaynak = (int) ($s['kaynak_satir_id'] ?? 0);
                if ($kaynak > 0) {
                    $kaynakSt->execute([':k' => $kaynak]);
                    $k = $kaynakSt->fetch(PDO::FETCH_ASSOC);
                    if (!$k || (int) $k['TUR'] !== 1 || (int) $k['DURUM'] === 9 || (int) $k['YON'] !== $t['yon']
                        || (int) $k['STOK_ID'] !== (int) $stok['ID'] || ($cariId > 0 && (int) $k['CARI_ID'] !== $cariId)) {
                        $dbh->rollBack();
                        return ['ok' => false, 'mesaj' => 'Satır ' . ($i + 1) . ': kaynak sipariş satırı uygun değil.'];
                    }
                    $kalan = (float) $k['MIKTAR'] - (float) $k['TESLIM_MIKTAR'];
                    if ($miktar > $kalan + 0.000001) {
                        $dbh->rollBack();
                        return ['ok' => false, 'mesaj' => 'Satır ' . ($i + 1) . ': siparişte kalan miktardan (' . rtrim(rtrim(number_format($kalan, 3, ',', '.'), '0'), ',') . ') fazla.'];
                    }
                }
                $h = lumen_belge_satir_hesapla($miktar, $fiyat, $isk, $kdvOran);
                $hazir[] = ['stok' => $stok, 'birim' => $birimId, 'carpan' => $carpan, 'miktar' => $miktar, 'fiyat' => $fiyat,
                            'isk' => $isk, 'kdv_oran' => $kdvOran, 'h' => $h, 'kaynak' => $kaynak,
                            'aciklama' => mb_substr(trim((string) ($s['aciklama'] ?? '')), 0, 250)];
                $toplam['brut'] += $h['tutar'];
                $toplam['iskonto'] += $h['iskonto'];
                $toplam['kdv'] += $h['kdv'];
                $toplam['net'] += $h['net'];
            }
            $genelToplam = round($toplam['net'] + $toplam['kdv'], 2);

            $no = lumen_belge_seri_no($dbh, $t['seri']);
            $belgeId = lumen_insert_kimlik($dbh,
                'INSERT INTO dbo.LM_BELGE (TUR, YON, NO, TARIH, VADE, CARI_ID, DEPO_ID, PERSONEL_ID, BRUT, ISKONTO, KDV, NET,
                                            ACIKLAMA, DOKUMAN_NO, DURUM, KAYNAK_BELGE_ID, OLUSTURAN_ID, GUID)
                 VALUES (:tur, :yon, :no, :tarih, :vade, :cari, :depo, :per, :brut, :isk, :kdv, :net, :ack, :dok, 1, :kaynak, :olu, NEWID())',
                [':tur' => $t['tur'], ':yon' => $t['yon'], ':no' => $no, ':tarih' => $tarih, ':vade' => $vade,
                 ':cari' => $cariId > 0 ? $cariId : null, ':depo' => $depoId, ':per' => $kullanici ?: null,
                 ':brut' => round($toplam['brut'], 2), ':isk' => round($toplam['iskonto'], 2), ':kdv' => round($toplam['kdv'], 2),
                 ':net' => $genelToplam, ':ack' => mb_substr(trim((string) ($baslik['aciklama'] ?? '')), 0, 500) ?: null,
                 ':dok' => mb_substr(trim((string) ($baslik['dokuman_no'] ?? '')), 0, 40) ?: null,
                 ':kaynak' => ((int) ($baslik['kaynak_belge_id'] ?? 0)) ?: null, ':olu' => $kullanici ?: null], 'ID');

            $satirEkle = $dbh->prepare('INSERT INTO dbo.LM_BELGE_SATIR (BELGE_ID, SIRA, SATIR_TURU, STOK_ID, BIRIM_ID, CARPAN, MIKTAR, FIYAT,
                    ISKONTO_ORANI, TUTAR, ISKONTO_TUTAR, KDV_ORANI, KDV_MATRAH, KDV_TUTAR, NET, VADE, KAYNAK_SATIR_ID, ACIKLAMA)
                OUTPUT INSERTED.ID VALUES (:b, :sira, 0, :stok, :birim, :carpan, :miktar, :fiyat, :isk, :tutar, :iskt, :ko, :km, :kt, :net, :vade, :kay, :ack)');
            $stokHar = $dbh->prepare('INSERT INTO dbo.LM_STOK_HAREKET (STOK_ID, DEPO_ID, TARIH, BELGE_ID, SATIR_ID, GIRIS, CIKIS, BIRIM_MALIYET)
                                      VALUES (:s, :d, :t, :b, :sat, :g, :c, :m)');
            $teslim = $dbh->prepare('UPDATE dbo.LM_BELGE_SATIR SET TESLIM_MIKTAR = TESLIM_MIKTAR + :m,
                                      KAPALI = CASE WHEN TESLIM_MIKTAR + :m2 >= MIKTAR - 0.000001 THEN 1 ELSE KAPALI END WHERE ID = :k');
            foreach ($hazir as $sira => $r) {
                $satirEkle->execute([':b' => $belgeId, ':sira' => $sira + 1, ':stok' => (int) $r['stok']['ID'], ':birim' => $r['birim'],
                    ':carpan' => $r['carpan'], ':miktar' => $r['miktar'], ':fiyat' => $r['fiyat'], ':isk' => $r['isk'],
                    ':tutar' => $r['h']['tutar'], ':iskt' => $r['h']['iskonto'], ':ko' => $r['kdv_oran'], ':km' => $r['h']['net'],
                    ':kt' => $r['h']['kdv'], ':net' => $r['h']['net'], ':vade' => $vade, ':kay' => $r['kaynak'] ?: null,
                    ':ack' => $r['aciklama'] !== '' ? $r['aciklama'] : null]);
                $satirId = (int) $satirEkle->fetchColumn();
                $satirEkle->closeCursor();
                if ($t['stok'] !== 0 && (int) $r['stok']['STOK_TAKIPLI'] === 1) {
                    $anaMiktar = round($r['miktar'] * $r['carpan'], 6);
                    $stokHar->execute([':s' => (int) $r['stok']['ID'], ':d' => $depoId, ':t' => $tarih, ':b' => $belgeId, ':sat' => $satirId,
                        ':g' => $t['stok'] > 0 ? $anaMiktar : 0, ':c' => $t['stok'] < 0 ? $anaMiktar : 0,
                        // Birim maliyet yalnız girişte (alış/üretim/iade girişi): satış fiyatı maliyet değildir.
                        ':m' => ($t['stok'] > 0 && $anaMiktar > 0) ? round($r['h']['net'] / $anaMiktar, 6) : null]);
                }
                if ($r['kaynak'] > 0) {
                    $teslim->execute([':m' => $r['miktar'], ':m2' => $r['miktar'], ':k' => $r['kaynak']]);
                }
            }

            if ($t['cari'] !== 0 && $cariId > 0 && $genelToplam > 0) {
                $dbh->prepare('INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, VADE, KAYNAK, BELGE_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
                               VALUES (:c, :t, :v, 1, :b, :no, :borc, :alacak, :ack)')
                    ->execute([':c' => $cariId, ':t' => $tarih, ':v' => $vade, ':b' => $belgeId, ':no' => $no,
                               ':borc' => $t['cari'] > 0 ? $genelToplam : 0, ':alacak' => $t['cari'] < 0 ? $genelToplam : 0,
                               ':ack' => $t['ad'] . ' ' . $no]);
            }

            $dbh->commit();
            return ['ok' => true, 'mesaj' => $t['ad'] . ' ' . $no . ' kesildi.', 'id' => $belgeId, 'no' => $no, 'net' => $genelToplam];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('lumen_belge_kaydet: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'Belge kaydedilemedi; hiçbir kayıt yapılmadı.'];
        }
    }
}

if (!function_exists('lumen_belge_iptal')) {
    /**
     * Belgeyi iptal eder: stok ve cari hareketleri silinir, kaynak sipariş satırlarının teslim
     * miktarı geri alınır; belge DURUM 9 ile iz olarak kalır.
     * @return array{ok:bool, mesaj:string}
     */
    function lumen_belge_iptal(PDO $dbh, int $belgeId, int $kullanici): array
    {
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT ID, TUR, DURUM, NO FROM dbo.LM_BELGE WITH (UPDLOCK, HOLDLOCK) WHERE ID = :id');
            $st->execute([':id' => $belgeId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b || (int) $b['TUR'] === 1) {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => 'Belge bulunamadı.'];
            }
            if ((int) $b['DURUM'] === 9) {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => 'Belge zaten iptal edilmiş.'];
            }
            // Bu belgeden türeyen geçerli bir belge varsa (ör. irsaliyeden fatura) önce o iptal edilmeli.
            $tur = $dbh->prepare('SELECT TOP 1 NO FROM dbo.LM_BELGE WHERE KAYNAK_BELGE_ID = :id AND DURUM <> 9');
            $tur->execute([':id' => $belgeId]);
            if (($bagli = $tur->fetchColumn()) !== false) {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => "Bu belgeden kesilmiş {$bagli} önce iptal edilmeli."];
            }
            $dbh->prepare('UPDATE K SET TESLIM_MIKTAR = CASE WHEN K.TESLIM_MIKTAR - S.MIKTAR < 0 THEN 0 ELSE K.TESLIM_MIKTAR - S.MIKTAR END, KAPALI = 0
                           FROM dbo.LM_BELGE_SATIR K JOIN dbo.LM_BELGE_SATIR S ON S.KAYNAK_SATIR_ID = K.ID WHERE S.BELGE_ID = :id')
                ->execute([':id' => $belgeId]);
            $dbh->prepare('DELETE FROM dbo.LM_STOK_HAREKET WHERE BELGE_ID = :id')->execute([':id' => $belgeId]);
            $dbh->prepare('DELETE FROM dbo.LM_CARI_HAREKET WHERE BELGE_ID = :id')->execute([':id' => $belgeId]);
            $dbh->prepare('UPDATE dbo.LM_BELGE SET DURUM = 9, GUNCELLEYEN_ID = :k, GUNCELLEME = SYSDATETIME() WHERE ID = :id')
                ->execute([':k' => $kullanici ?: null, ':id' => $belgeId]);
            $dbh->commit();
            return ['ok' => true, 'mesaj' => $b['NO'] . ' iptal edildi; stok ve cari etkisi geri alındı.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('lumen_belge_iptal: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'İptal edilemedi; belge olduğu gibi duruyor.'];
        }
    }
}

if (!function_exists('lumen_siparis_kalan_satirlar')) {
    /**
     * Siparişin faturalanmamış/sevk edilmemiş satırları (kalan > 0) — siparişten belge ekranı için.
     * @return array<int, array<string, mixed>>
     */
    function lumen_siparis_kalan_satirlar(PDO $dbh, int $siparisId): array
    {
        $st = $dbh->prepare("SELECT S.ID, S.SIRA, S.STOK_ID, S.BIRIM_ID, ST.KOD, ST.AD, BR.KOD AS BIRIM, S.MIKTAR, S.TESLIM_MIKTAR,
                    S.MIKTAR - S.TESLIM_MIKTAR AS KALAN, S.FIYAT, S.KDV_ORANI,
                    -- Satır + dağıtılmış iskonto (iskonto satırları dahil) → etkin iskonto oranı
                    CASE WHEN S.TUTAR > 0 THEN ROUND((S.TUTAR - S.NET) * 100.0 / S.TUTAR, 4) ELSE 0 END AS ISKONTO
             FROM dbo.LM_BELGE_SATIR S
             JOIN dbo.LM_BELGE B ON B.ID = S.BELGE_ID AND B.TUR = 1 AND B.DURUM <> 9
             JOIN dbo.LM_STOK ST ON ST.ID = S.STOK_ID
             LEFT JOIN dbo.LM_BIRIM BR ON BR.ID = S.BIRIM_ID
             WHERE S.BELGE_ID = :s AND S.SATIR_TURU = 0 AND S.IPTAL = 0 AND S.MIKTAR - S.TESLIM_MIKTAR > 0.000001
             ORDER BY S.SIRA");
        $st->execute([':s' => $siparisId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
