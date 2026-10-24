<?php

declare(strict_types=1);

/**
 * Kasa / banka fişleri — yalnız bağımsız mod (LM_FINANS_FIS, LM_FINANS_HAREKET, LM_CARI_HAREKET).
 *
 * Tür     hesap etkisi            karşı hesap      cari etkisi
 * 1 tahsilat   giriş                 —            alacak (müşteri borcu azalır)
 * 2 ödeme      çıkış                 —            borç (tedarikçiye borcumuz azalır)
 * 3 virman     çıkış                 giriş        —
 * 4 masraf     çıkış                 —            —
 * 5 açılış     giriş                 —            —
 * İptal: hareketler silinir, fiş DURUM 9 ile iz olarak kalır. Aynı istek kimliğiyle
 * ikinci gönderim (çift tıklama) yeni fiş açmaz.
 */

require_once __DIR__ . '/../belge/belge_lib.php';

if (!function_exists('lumen_finans_turleri')) {
    /** @return array<int, array{ad:string, seri:string, cari:bool, karsi:bool}> */
    function lumen_finans_turleri(): array
    {
        return [
            1 => ['ad' => 'Tahsilat', 'seri' => 'TAHSILAT', 'cari' => true, 'karsi' => false],
            2 => ['ad' => 'Ödeme', 'seri' => 'ODEME', 'cari' => true, 'karsi' => false],
            3 => ['ad' => 'Virman', 'seri' => 'VIRMAN', 'cari' => false, 'karsi' => true],
            4 => ['ad' => 'Masraf', 'seri' => 'MASRAF', 'cari' => false, 'karsi' => false],
            5 => ['ad' => 'Açılış', 'seri' => 'FINANS_ACILIS', 'cari' => false, 'karsi' => false],
        ];
    }
}

if (!function_exists('lumen_finans_fis_kaydet')) {
    /**
     * @param array{tur:int, hesap_id:int, karsi_hesap_id?:int, cari_id?:int, tutar:float|string, tarih?:string,
     *              aciklama?:string, istek_id?:string} $v
     * @return array{ok:bool, mesaj:string, id?:int, no?:string}
     */
    function lumen_finans_fis_kaydet(PDO $dbh, array $v, int $kullanici): array
    {
        $turler = lumen_finans_turleri();
        $tur = (int) ($v['tur'] ?? 0);
        if (!isset($turler[$tur])) {
            return ['ok' => false, 'mesaj' => 'Geçersiz fiş türü.'];
        }
        $t = $turler[$tur];
        $tutar = round(lumen_tutar_coz($v['tutar'] ?? 0), 2);
        if ($tutar <= 0) {
            return ['ok' => false, 'mesaj' => 'Geçerli ve sıfırdan büyük bir tutar girin.'];
        }
        $hesap = (int) ($v['hesap_id'] ?? 0);
        $karsi = (int) ($v['karsi_hesap_id'] ?? 0);
        $cari = (int) ($v['cari_id'] ?? 0);
        if ($t['cari'] && $cari <= 0) {
            return ['ok' => false, 'mesaj' => 'Cari seçilmedi.'];
        }
        if ($t['karsi'] && ($karsi <= 0 || $karsi === $hesap)) {
            return ['ok' => false, 'mesaj' => 'Virman için farklı bir karşı hesap seçin.'];
        }
        $tarih = trim((string) ($v['tarih'] ?? '')) !== '' ? (string) $v['tarih'] : date('Y-m-d');
        if (($th = lumen_tarih_hatasi($dbh, $tarih)) !== null) {
            return ['ok' => false, 'mesaj' => $th];
        }
        $aciklama = mb_substr(trim((string) ($v['aciklama'] ?? '')), 0, 400);
        $istek = preg_match('/^[a-f0-9]{32}$/', (string) ($v['istek_id'] ?? '')) === 1 ? (string) $v['istek_id'] : null;

        try {
            $dbh->beginTransaction();
            if ($istek !== null) {
                $q = $dbh->prepare('SELECT ID FROM dbo.LM_FINANS_FIS WITH (UPDLOCK, HOLDLOCK) WHERE ISTEK_ID = :i');
                $q->execute([':i' => $istek]);
                if (($var = $q->fetchColumn()) !== false) {
                    $dbh->rollBack();
                    return ['ok' => false, 'mesaj' => 'Bu fiş zaten kaydedildi (tekrar gönderim).', 'id' => (int) $var];
                }
            }
            $h = $dbh->prepare('SELECT ID, TUR, PARA_BIRIMI FROM dbo.LM_FINANS_HESAP WHERE ID = :h AND AKTIF = 1');
            $h->execute([':h' => $hesap]);
            $hs = $h->fetch(PDO::FETCH_ASSOC);
            if (!$hs) {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => 'Kasa/banka hesabı bulunamadı.'];
            }
            // Fiş tutarları TL'dir: dövizli hesaba TL tutar yazılmasın.
            if ($hs['PARA_BIRIMI'] !== 'TRY') {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => 'Dövizli hesaplarla işlem bu sürümde yapılamaz (yalnız TL hesaplar).'];
            }
            if ($t['karsi']) {
                $h->execute([':h' => $karsi]);
                $ks = $h->fetch(PDO::FETCH_ASSOC);
                if (!$ks || $ks['PARA_BIRIMI'] !== $hs['PARA_BIRIMI']) {
                    $dbh->rollBack();
                    return ['ok' => false, 'mesaj' => 'Karşı hesap bulunamadı ya da para birimi farklı.'];
                }
            }
            if ($t['cari']) {
                $c = $dbh->prepare('SELECT ID FROM dbo.LM_CARI WHERE ID = :c AND AKTIF = 1');
                $c->execute([':c' => $cari]);
                if (!$c->fetch()) {
                    $dbh->rollBack();
                    return ['ok' => false, 'mesaj' => 'Cari bulunamadı veya pasif.'];
                }
            }
            $no = lumen_belge_seri_no($dbh, $t['seri']);
            $fisId = lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_FINANS_FIS (TUR, NO, TARIH, HESAP_ID, KARSI_HESAP_ID, CARI_ID, TUTAR, ACIKLAMA, ISTEK_ID, OLUSTURAN_ID)
                VALUES (:tur, :no, :tarih, :h, :k, :c, :t, :a, :i, :o)',
                [':tur' => $tur, ':no' => $no, ':tarih' => $tarih, ':h' => $hesap, ':k' => $t['karsi'] ? $karsi : null,
                 ':c' => $t['cari'] ? $cari : null, ':t' => $tutar, ':a' => $aciklama !== '' ? $aciklama : null, ':i' => $istek,
                 ':o' => $kullanici ?: null], 'ID');
            $har = $dbh->prepare('INSERT INTO dbo.LM_FINANS_HAREKET (HESAP_ID, TARIH, FIS_ID, GIRIS, CIKIS, ACIKLAMA) VALUES (:h, :t, :f, :g, :c, :a)');
            $giris = in_array($tur, [1, 5], true);
            $har->execute([':h' => $hesap, ':t' => $tarih, ':f' => $fisId, ':g' => $giris ? $tutar : 0, ':c' => $giris ? 0 : $tutar,
                           ':a' => $aciklama !== '' ? $aciklama : $t['ad'] . ' ' . $no]);
            if ($t['karsi']) {
                $har->execute([':h' => $karsi, ':t' => $tarih, ':f' => $fisId, ':g' => $tutar, ':c' => 0, ':a' => $aciklama !== '' ? $aciklama : $t['ad'] . ' ' . $no]);
            }
            if ($t['cari']) {
                $dbh->prepare('INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, FINANS_FIS_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
                               VALUES (:c, :t, 2, :f, :no, :b, :a, :ack)')
                    ->execute([':c' => $cari, ':t' => $tarih, ':f' => $fisId, ':no' => $no, ':b' => $tur === 2 ? $tutar : 0,
                               ':a' => $tur === 1 ? $tutar : 0, ':ack' => $aciklama !== '' ? $aciklama : $t['ad'] . ' ' . $no]);
            }
            $dbh->commit();
            return ['ok' => true, 'mesaj' => $t['ad'] . ' ' . $no . ' kaydedildi (' . number_format($tutar, 2, ',', '.') . ' ₺).', 'id' => $fisId, 'no' => $no];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('lumen_finans_fis_kaydet: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'Fiş kaydedilemedi; hiçbir kayıt yapılmadı.'];
        }
    }
}

if (!function_exists('lumen_finans_fis_iptal')) {
    /** @return array{ok:bool, mesaj:string} */
    function lumen_finans_fis_iptal(PDO $dbh, int $fisId, int $kullanici, bool $yonetici = false): array
    {
        try {
            $dbh->beginTransaction();
            $st = $dbh->prepare('SELECT NO, DURUM, TUR, TARIH FROM dbo.LM_FINANS_FIS WITH (UPDLOCK, HOLDLOCK) WHERE ID = :id');
            $st->execute([':id' => $fisId]);
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if (!$f || (int) $f['DURUM'] === 9) {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => $f ? 'Fiş zaten iptal edilmiş.' : 'Fiş bulunamadı.'];
            }
            // Açılış fişi ekrandan yeniden kesilemez: iptali yalnız yöneticiye.
            if ((int) $f['TUR'] === 5 && !$yonetici) {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => 'Açılış fişini yalnız yönetici iptal edebilir.'];
            }
            if (($kilit = lumen_donem_kilidi($dbh)) !== null && substr((string) $f['TARIH'], 0, 10) <= $kilit) {
                $dbh->rollBack();
                return ['ok' => false, 'mesaj' => 'Fiş kilitli dönemde; iptal edilemez.'];
            }
            $dbh->prepare('DELETE FROM dbo.LM_FINANS_HAREKET WHERE FIS_ID = :id')->execute([':id' => $fisId]);
            $dbh->prepare('DELETE FROM dbo.LM_CARI_HAREKET WHERE FINANS_FIS_ID = :id')->execute([':id' => $fisId]);
            $dbh->prepare('UPDATE dbo.LM_FINANS_FIS SET DURUM = 9 WHERE ID = :id')->execute([':id' => $fisId]);
            $dbh->commit();
            return ['ok' => true, 'mesaj' => $f['NO'] . ' iptal edildi; kasa/banka ve cari etkisi geri alındı.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('lumen_finans_fis_iptal: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'İptal edilemedi; fiş olduğu gibi duruyor.'];
        }
    }
}
