<?php

declare(strict_types=1);

/**
 * Çek/senet işlemleri — bağımsız mod (LM_CEK, LM_CEK_HAREKET, LM_CARI_HAREKET).
 *
 * cek_lib.php'deki *_core işlevleri bağımsız modda buraya yönlenir; dönüş biçimi ve
 * M_CEK_LOG kaydı aynıdır, böylece paneller, geri alma ve listeler değişmeden çalışır.
 * M_CEK_LOG eşlemesi: CSCARD_REF → LM_CEK.ID, CSTRANS_REF → LM_CEK_HAREKET.ID,
 * CSROLL_REF → bordronun ilk hareket ID'si, CLFLINE_REF → LM_CARI_HAREKET.ID.
 * Durumlar: LM_CEK.DURUM 1 portföyde, 2 ciro edildi / kendi çekimiz verildi.
 */

require_once __DIR__ . '/../includes/belge/belge_lib.php';

if (!function_exists('cek_lumen_kalemler')) {
    /** Giriş/kendi çek kalemlerini doğrular. @return array{0: ?array, 1: ?array} [kalemler, hata] */
    function cek_lumen_kalemler(array $ham, bool $sahipli): array
    {
        $out = [];
        foreach ($ham as $x) {
            $t = round((float) ($x['tutar'] ?? 0), 2);
            $v = trim((string) ($x['vade'] ?? ''));
            if ($t <= 0) {
                return [null, ['ok' => false, 'kod' => 422, 'mesaj' => 'Her çekin tutarı sıfırdan büyük olmalı.']];
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return [null, ['ok' => false, 'kod' => 422, 'mesaj' => 'Her çek için geçerli bir vade tarihi girin.']];
            }
            $out[] = ['tutar' => $t, 'vade' => $v, 'cekno' => mb_substr(trim((string) ($x['cekno'] ?? '')), 0, 40),
                      'banka' => $sahipli ? mb_substr(trim((string) ($x['banka'] ?? '')), 0, 80) : '',
                      'sahibi' => $sahipli ? mb_substr(trim((string) ($x['sahibi'] ?? '')), 0, 200) : ''];
        }
        if (count($out) < 1 || count($out) > 50) {
            return [null, ['ok' => false, 'kod' => 422, 'mesaj' => 'En az 1, en fazla 50 çek girilebilir.']];
        }
        return [$out, null];
    }
}

if (!function_exists('cek_lumen_portfoy_base')) {
    /** Serideki en büyük portföy numarası (resmi: R+7 hane, gayri resmi: 8 hane). */
    function cek_lumen_portfoy_base(PDO $dbh, bool $resmi): int
    {
        return (int) $dbh->query($resmi
            ? "SELECT MAX(CAST(SUBSTRING(PORTFOY_NO, 2, 30) AS BIGINT)) FROM dbo.LM_CEK WHERE PORTFOY_NO LIKE 'R%'
                 AND LEN(PORTFOY_NO) > 1 AND SUBSTRING(PORTFOY_NO, 2, 30) NOT LIKE '%[^0-9]%'"
            : "SELECT MAX(CAST(PORTFOY_NO AS BIGINT)) FROM dbo.LM_CEK WHERE PORTFOY_NO NOT LIKE '%[^0-9]%' AND PORTFOY_NO <> ''")->fetchColumn();
    }
}

if (!function_exists('cek_lumen_log')) {
    function cek_lumen_log(PDO $dbh, array $v): int
    {
        return lumen_insert_kimlik($dbh, "INSERT INTO M_CEK_LOG
            (REQUEST_ID, FIRMADONEM, DURUM, TUR, CSCARD_REF, CSCARD_PORTFOYNO, CSROLL_REF, CSROLL_ROLLNO, CSTRANS_REF, CLFLINE_REF, CLFLINE_TRANNO,
             CLIENTREF, TUTAR, DOCCNT, KALEMLER, CEKNO, BANKNAME, VADE, SAHIBI, ACIKLAMA, KULLANICI, IP)
            VALUES (:req, :fd, 'AKTIF', :tur, :cc, :pno, :cr, :roll, :ct, :cl, :tno, :cari, :tut, :adet, :kalemler, :cekno, :banka, :vade, :sahibi, :exp, :kul, :ip)",
            $v, 'ID');
    }
}

if (!function_exists('cek_giris_lumen')) {
    function cek_giris_lumen(PDO $dbh, string $fd, int $cariRef, array $cek, int $personel, string $requestId, array $opt = []): array
    {
        $ham = (isset($cek['kalemler']) && is_array($cek['kalemler']) && $cek['kalemler'] !== []) ? $cek['kalemler']
            : [['tutar' => $cek['tutar'] ?? 0, 'cekno' => $cek['cekno'] ?? '', 'banka' => $cek['banka'] ?? '', 'vade' => $cek['vade'] ?? '', 'sahibi' => $cek['sahibi'] ?? '']];
        [$kalemler, $hata] = cek_lumen_kalemler($ham, true);
        if ($hata !== null) {
            return $hata;
        }
        if ($cariRef <= 0) {
            return ['ok' => false, 'kod' => 400, 'mesaj' => 'Cari seçilmedi.'];
        }
        $resmi = !empty($cek['resmi']);
        $aciklama = mb_substr(trim((string) ($cek['aciklama'] ?? '')), 0, 240);
        $toplam = round(array_sum(array_column($kalemler, 'tutar')), 2);
        $adet = count($kalemler);
        cek_log_tablo_olustur($dbh);
        try {
            $dbh->beginTransaction();
            cek_numara_kilidi($dbh);
            if (($onceki = cek_istek_tekrari($dbh, $requestId)) !== null) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 409, 'tekrar' => true, 'log_id' => $onceki, 'mesaj' => 'Bu işlem zaten kaydedildi (tekrar gönderim). Sayfayı yenileyin.'];
            }
            $c = $dbh->prepare('SELECT UNVAN FROM dbo.LM_CARI WHERE ID = :c AND AKTIF = 1');
            $c->execute([':c' => $cariRef]);
            $cariAd = $c->fetchColumn();
            if ($cariAd === false) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 404, 'mesaj' => 'Cari bulunamadı/pasif.'];
            }
            $bordro = lumen_belge_seri_no($dbh, 'CEK_BORDRO');
            $base = cek_lumen_portfoy_base($dbh, $resmi);
            $cekEkle = $dbh->prepare('INSERT INTO dbo.LM_CEK (TUR, PORTFOY_NO, SERI_NO, BANKA, BORCLU, VADE, TUTAR, DURUM, ALINAN_CARI_ID)
                                      OUTPUT INSERTED.ID VALUES (1, :pno, :seri, :banka, :borclu, :vade, :tutar, 1, :cari)');
            $harEkle = $dbh->prepare('INSERT INTO dbo.LM_CEK_HAREKET (CEK_ID, TARIH, BORDRO_NO, ISLEM, CARI_ID, ACIKLAMA, OLUSTURAN_ID)
                                      OUTPUT INSERTED.ID VALUES (:cek, CAST(GETDATE() AS DATE), :bordro, 1, :cari, :ack, :kul)');
            $kayitlar = [];
            foreach ($kalemler as $i => $k) {
                $pno = cek_portfoy_bicim($resmi, $base + $i + 1);
                $sahibi = $k['sahibi'] !== '' ? $k['sahibi'] : mb_substr((string) $cariAd, 0, 200);
                $cekEkle->execute([':pno' => $pno, ':seri' => $k['cekno'], ':banka' => $k['banka'], ':borclu' => $sahibi,
                                   ':vade' => $k['vade'], ':tutar' => $k['tutar'], ':cari' => $cariRef]);
                $cekId = (int) $cekEkle->fetchColumn();
                $cekEkle->closeCursor();
                $harEkle->execute([':cek' => $cekId, ':bordro' => $bordro, ':cari' => $cariRef, ':ack' => $aciklama ?: null, ':kul' => $personel ?: null]);
                $harId = (int) $harEkle->fetchColumn();
                $harEkle->closeCursor();
                $kayitlar[] = ['cscard_ref' => $cekId, 'cstrans_ref' => $harId, 'portfoyno' => $pno, 'cekno' => $k['cekno'],
                               'tutar' => $k['tutar'], 'vade' => $k['vade'], 'banka' => $k['banka'], 'sahibi' => $sahibi];
            }
            $ilk = $kayitlar[0];
            $clRef = lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, VADE, KAYNAK, CEK_HAREKET_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
                VALUES (:c, CAST(GETDATE() AS DATE), :v, 3, :h, :no, 0, :t, :a)',
                [':c' => $cariRef, ':v' => $ilk['vade'], ':h' => $ilk['cstrans_ref'], ':no' => $bordro, ':t' => $toplam,
                 ':a' => $aciklama !== '' ? $aciklama : 'Çek girişi ' . $bordro], 'ID');
            $logId = cek_lumen_log($dbh, [':req' => $requestId, ':fd' => $fd, ':tur' => 'giris', ':cc' => $ilk['cscard_ref'], ':pno' => $ilk['portfoyno'],
                ':cr' => $ilk['cstrans_ref'], ':roll' => $bordro, ':ct' => $ilk['cstrans_ref'], ':cl' => $clRef, ':tno' => $bordro,
                ':cari' => $cariRef, ':tut' => $toplam, ':adet' => $adet, ':kalemler' => json_encode($kayitlar, JSON_UNESCAPED_UNICODE),
                ':cekno' => $ilk['cekno'], ':banka' => $ilk['banka'], ':vade' => $ilk['vade'], ':sahibi' => $ilk['sahibi'], ':exp' => $aciklama,
                ':kul' => $personel, ':ip' => (string) ($opt['ip'] ?? '')]);
            $dbh->commit();
            $mesaj = $adet === 1
                ? number_format($toplam, 2, ',', '.') . ' TL çek portföye alındı (portföy no ' . $ilk['portfoyno'] . ').'
                : $adet . ' çek portföye alındı — toplam ' . number_format($toplam, 2, ',', '.') . ' TL (bordro no ' . $bordro . ').';
            return ['ok' => true, 'mesaj' => $mesaj, 'cscard_ref' => $ilk['cscard_ref'], 'csroll_ref' => $ilk['cstrans_ref'],
                    'cstrans_ref' => $ilk['cstrans_ref'], 'clfline_ref' => $clRef, 'portfoyno' => $ilk['portfoyno'], 'rollno' => $bordro,
                    'adet' => $adet, 'tutar' => $toplam, 'kayitlar' => $kayitlar, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('cek_giris_lumen: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Çek girişi yapılamadı, hiçbir kayıt oluşmadı.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_lumen_log_kalemleri')) {
    /** @return array<int, array{cc:int, ct:int}> */
    function cek_lumen_log_kalemleri(array $log): array
    {
        $out = [];
        $j = json_decode((string) ($log['KALEMLER'] ?? ''), true);
        foreach (is_array($j) ? $j : [] as $k) {
            $out[] = ['cc' => (int) ($k['cscard_ref'] ?? 0), 'ct' => (int) ($k['cstrans_ref'] ?? 0)];
        }
        return $out ?: [['cc' => (int) $log['CSCARD_REF'], 'ct' => (int) $log['CSTRANS_REF']]];
    }
}

if (!function_exists('cek_geri_al_lumen')) {
    /** Giriş, ciro ve kendi çek geri alması ($tur: giris | ciro | kendi_cek). */
    function cek_geri_al_lumen(PDO $dbh, string $fd, int $logId, int $personel, string $not, string $tur): array
    {
        $adlar = ['giris' => 'çek', 'ciro' => 'ciro', 'kendi_cek' => 'işlem'];
        try {
            $dbh->beginTransaction();
            [$log, $hata] = cek_log_kilitle($dbh, $fd, $logId, $tur, $adlar[$tur]);
            if ($hata !== null) {
                $dbh->rollBack();
                return $hata;
            }
            $kalemler = cek_lumen_log_kalemleri($log);
            $durumSt = $dbh->prepare('SELECT DURUM, VERILEN_CARI_ID FROM dbo.LM_CEK WITH (UPDLOCK) WHERE ID = :id');
            $sonraSt = $dbh->prepare('SELECT COUNT(*) FROM dbo.LM_CEK_HAREKET WHERE CEK_ID = :id AND ID > :h AND GERI_ALINDI = 0');
            foreach ($kalemler as $k) {
                $durumSt->execute([':id' => $k['cc']]);
                $d = $durumSt->fetch(PDO::FETCH_ASSOC);
                $beklenen = $tur === 'giris' ? 1 : 2;
                $sonraSt->execute([':id' => $k['cc'], ':h' => $k['ct']]);
                if (!$d || (int) $d['DURUM'] !== $beklenen || (int) $sonraSt->fetchColumn() > 0) {
                    $dbh->rollBack();
                    return ['ok' => false, 'kod' => 409, 'mesaj' => $tur === 'giris'
                        ? 'Bordrodaki bir çek portföyden çıkmış (ciro/tahsil edilmiş); geri alınamaz. Önce o işlemi geri alın.'
                        : 'Bordrodaki bir çekin durumu sonradan değişmiş; geri alınamaz.'];
                }
            }
            $dbh->prepare('DELETE FROM dbo.LM_CARI_HAREKET WHERE ID = :id AND KAYNAK = 3')->execute([':id' => (int) $log['CLFLINE_REF']]);
            $harSil = $dbh->prepare('DELETE FROM dbo.LM_CEK_HAREKET WHERE ID = :id AND CEK_ID = :cek');
            foreach ($kalemler as $k) {
                $harSil->execute([':id' => $k['ct'], ':cek' => $k['cc']]);
            }
            if ($tur === 'ciro') {
                $geri = $dbh->prepare('UPDATE dbo.LM_CEK SET DURUM = 1, VERILEN_CARI_ID = NULL WHERE ID = :id AND DURUM = 2');
                foreach ($kalemler as $k) {
                    $geri->execute([':id' => $k['cc']]);
                }
            } else {
                // Giriş: portföyden çıkar; kendi çek: düzenlenen çek silinir (hareketi yukarıda silindi).
                $sil = $dbh->prepare('DELETE FROM dbo.LM_CEK WHERE ID = :id AND NOT EXISTS (SELECT 1 FROM dbo.LM_CEK_HAREKET H WHERE H.CEK_ID = :id2)');
                foreach ($kalemler as $k) {
                    $sil->execute([':id' => $k['cc'], ':id2' => $k['cc']]);
                }
            }
            cek_log_geri_alindi_isaretle($dbh, $logId, $personel, $not);
            $dbh->commit();
            return ['ok' => true, 'mesaj' => match ($tur) {
                'giris' => 'Çek girişi geri alındı; portföyden çıkarıldı ve cari bakiye eski haline döndü.',
                'ciro' => 'Ciro geri alındı; çekler portföye döndü ve cari bakiye eski haline geldi.',
                default => 'Kendi çek verme geri alındı; çekler silindi ve cari bakiye eski haline geldi.',
            }];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('cek_geri_al_lumen: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Geri alma başarısız; kayıt olduğu gibi duruyor.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_ciro_lumen')) {
    function cek_ciro_lumen(PDO $dbh, string $fd, int $hedefCariRef, array $cekRefleri, string $aciklama, int $personel, string $requestId, array $opt = []): array
    {
        $aciklama = mb_substr(trim($aciklama), 0, 240);
        $cekRefleri = array_values(array_unique(array_filter(array_map('intval', $cekRefleri), static fn($x) => $x > 0)));
        if ($hedefCariRef <= 0) {
            return ['ok' => false, 'kod' => 400, 'mesaj' => 'Hedef cari (tedarikçi) seçilmedi.'];
        }
        if (!$cekRefleri || count($cekRefleri) > 100) {
            return ['ok' => false, 'kod' => 422, 'mesaj' => $cekRefleri ? 'En fazla 100 çek ciro edilebilir.' : 'En az bir çek seçin.'];
        }
        cek_log_tablo_olustur($dbh);
        try {
            $dbh->beginTransaction();
            cek_numara_kilidi($dbh);
            if (($onceki = cek_istek_tekrari($dbh, $requestId)) !== null) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 409, 'tekrar' => true, 'log_id' => $onceki, 'mesaj' => 'Bu işlem zaten kaydedildi (tekrar gönderim). Sayfayı yenileyin.'];
            }
            $c = $dbh->prepare('SELECT UNVAN FROM dbo.LM_CARI WHERE ID = :c AND AKTIF = 1');
            $c->execute([':c' => $hedefCariRef]);
            $hedefAd = $c->fetchColumn();
            if ($hedefAd === false) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 404, 'mesaj' => 'Hedef cari bulunamadı/pasif.'];
            }
            $bordro = lumen_belge_seri_no($dbh, 'CEK_BORDRO');
            $cekSt = $dbh->prepare('SELECT ID, PORTFOY_NO, SERI_NO, TUTAR, VADE FROM dbo.LM_CEK WITH (UPDLOCK) WHERE ID = :id AND TUR = 1 AND DURUM = 1');
            $harEkle = $dbh->prepare('INSERT INTO dbo.LM_CEK_HAREKET (CEK_ID, TARIH, BORDRO_NO, ISLEM, ONCEKI_DURUM, CARI_ID, ACIKLAMA, OLUSTURAN_ID)
                                      OUTPUT INSERTED.ID VALUES (:cek, CAST(GETDATE() AS DATE), :bordro, 2, 1, :cari, :ack, :kul)');
            $durum = $dbh->prepare('UPDATE dbo.LM_CEK SET DURUM = 2, VERILEN_CARI_ID = :cari WHERE ID = :id AND DURUM = 1');
            $kayitlar = [];
            foreach ($cekRefleri as $ref) {
                $cekSt->execute([':id' => $ref]);
                $k = $cekSt->fetch(PDO::FETCH_ASSOC);
                $cekSt->closeCursor();
                if (!$k) {
                    $dbh->rollBack();
                    return ['ok' => false, 'kod' => 409, 'mesaj' => 'Seçilen çeklerden biri portföyde değil (başka işlemde kullanılmış olabilir).'];
                }
                $harEkle->execute([':cek' => $ref, ':bordro' => $bordro, ':cari' => $hedefCariRef, ':ack' => $aciklama ?: null, ':kul' => $personel ?: null]);
                $harId = (int) $harEkle->fetchColumn();
                $harEkle->closeCursor();
                $durum->execute([':cari' => $hedefCariRef, ':id' => $ref]);
                $kayitlar[] = ['cscard_ref' => $ref, 'cstrans_ref' => $harId, 'portfoyno' => (string) $k['PORTFOY_NO'],
                               'cekno' => (string) $k['SERI_NO'], 'tutar' => (float) $k['TUTAR'], 'vade' => (string) $k['VADE']];
            }
            $toplam = round(array_sum(array_column($kayitlar, 'tutar')), 2);
            $ilk = $kayitlar[0];
            // Ciro: tedarikçiye borcumuz azalır → cariye BORÇ.
            $clRef = lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, CEK_HAREKET_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
                VALUES (:c, CAST(GETDATE() AS DATE), 3, :h, :no, :t, 0, :a)',
                [':c' => $hedefCariRef, ':h' => $ilk['cstrans_ref'], ':no' => $bordro, ':t' => $toplam, ':a' => $aciklama !== '' ? $aciklama : 'Çek cirosu ' . $bordro], 'ID');
            $logId = cek_lumen_log($dbh, [':req' => $requestId, ':fd' => $fd, ':tur' => 'ciro', ':cc' => $ilk['cscard_ref'], ':pno' => $ilk['portfoyno'],
                ':cr' => $ilk['cstrans_ref'], ':roll' => $bordro, ':ct' => $ilk['cstrans_ref'], ':cl' => $clRef, ':tno' => $bordro,
                ':cari' => $hedefCariRef, ':tut' => $toplam, ':adet' => count($kayitlar), ':kalemler' => json_encode($kayitlar, JSON_UNESCAPED_UNICODE),
                ':cekno' => $ilk['cekno'], ':banka' => null, ':vade' => $ilk['vade'], ':sahibi' => mb_substr((string) $hedefAd, 0, 200), ':exp' => $aciklama,
                ':kul' => $personel, ':ip' => (string) ($opt['ip'] ?? '')]);
            $dbh->commit();
            return ['ok' => true, 'mesaj' => count($kayitlar) . ' çek ' . mb_substr((string) $hedefAd, 0, 40) . '\'e ciro edildi — toplam '
                    . number_format($toplam, 2, ',', '.') . ' TL (bordro ' . $bordro . ').',
                    'csroll_ref' => $ilk['cstrans_ref'], 'clfline_ref' => $clRef, 'rollno' => $bordro, 'adet' => count($kayitlar),
                    'tutar' => $toplam, 'kayitlar' => $kayitlar, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('cek_ciro_lumen: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Ciro yapılamadı, hiçbir kayıt oluşmadı.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_kendi_bankalar_lumen')) {
    function cek_kendi_bankalar_lumen(PDO $dbh): array
    {
        return array_map(static fn(array $r): array => ['ref' => (int) $r['ID'], 'kod' => (string) $r['KOD'], 'ad' => (string) $r['AD']],
            $dbh->query('SELECT ID, KOD, ISNULL(BANKA_ADI, AD) AS AD FROM dbo.LM_FINANS_HESAP WHERE TUR = 2 AND AKTIF = 1 ORDER BY KOD')->fetchAll(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('cek_kendi_lumen')) {
    function cek_kendi_lumen(PDO $dbh, string $fd, int $hedefCariRef, int $bankaRef, array $kalemler, string $aciklama, int $personel, string $requestId, array $opt = []): array
    {
        [$kl, $hata] = cek_lumen_kalemler($kalemler, false);
        if ($hata !== null) {
            return $hata;
        }
        if ($hedefCariRef <= 0) {
            return ['ok' => false, 'kod' => 400, 'mesaj' => 'Hedef cari seçilmedi.'];
        }
        if ($bankaRef <= 0) {
            return ['ok' => false, 'kod' => 400, 'mesaj' => 'Banka seçilmedi.'];
        }
        $aciklama = mb_substr(trim($aciklama), 0, 240);
        $toplam = round(array_sum(array_column($kl, 'tutar')), 2);
        cek_log_tablo_olustur($dbh);
        try {
            $dbh->beginTransaction();
            cek_numara_kilidi($dbh);
            if (($onceki = cek_istek_tekrari($dbh, $requestId)) !== null) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 409, 'tekrar' => true, 'log_id' => $onceki, 'mesaj' => 'Bu işlem zaten kaydedildi (tekrar gönderim). Sayfayı yenileyin.'];
            }
            $c = $dbh->prepare('SELECT UNVAN FROM dbo.LM_CARI WHERE ID = :c AND AKTIF = 1');
            $c->execute([':c' => $hedefCariRef]);
            $hedefAd = $c->fetchColumn();
            $b = $dbh->prepare('SELECT ISNULL(BANKA_ADI, AD) FROM dbo.LM_FINANS_HESAP WHERE ID = :b AND TUR = 2 AND AKTIF = 1');
            $b->execute([':b' => $bankaRef]);
            $bankaAd = $b->fetchColumn();
            if ($hedefAd === false || $bankaAd === false) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 404, 'mesaj' => $hedefAd === false ? 'Hedef cari bulunamadı/pasif.' : 'Banka hesabı bulunamadı.'];
            }
            $bordro = lumen_belge_seri_no($dbh, 'CEK_BORDRO');
            // Kendi çeklerimiz ayrı seri: 'K' + 7 hane
            $base = (int) $dbh->query("SELECT MAX(CAST(SUBSTRING(PORTFOY_NO, 2, 30) AS BIGINT)) FROM dbo.LM_CEK WHERE TUR = 2 AND PORTFOY_NO LIKE 'K%'
                                       AND SUBSTRING(PORTFOY_NO, 2, 30) NOT LIKE '%[^0-9]%'")->fetchColumn();
            $cekEkle = $dbh->prepare('INSERT INTO dbo.LM_CEK (TUR, PORTFOY_NO, SERI_NO, BANKA, BORCLU, VADE, TUTAR, DURUM, VERILEN_CARI_ID, HESAP_ID)
                                      OUTPUT INSERTED.ID VALUES (2, :pno, :seri, :banka, :borclu, :vade, :tutar, 2, :cari, :hesap)');
            $harEkle = $dbh->prepare('INSERT INTO dbo.LM_CEK_HAREKET (CEK_ID, TARIH, BORDRO_NO, ISLEM, CARI_ID, HESAP_ID, ACIKLAMA, OLUSTURAN_ID)
                                      OUTPUT INSERTED.ID VALUES (:cek, CAST(GETDATE() AS DATE), :bordro, 2, :cari, :hesap, :ack, :kul)');
            $kayitlar = [];
            foreach ($kl as $i => $k) {
                $pno = 'K' . str_pad((string) ($base + $i + 1), 7, '0', STR_PAD_LEFT);
                $cekEkle->execute([':pno' => $pno, ':seri' => $k['cekno'], ':banka' => mb_substr((string) $bankaAd, 0, 80),
                                   ':borclu' => null, ':vade' => $k['vade'], ':tutar' => $k['tutar'], ':cari' => $hedefCariRef, ':hesap' => $bankaRef]);
                $cekId = (int) $cekEkle->fetchColumn();
                $cekEkle->closeCursor();
                $harEkle->execute([':cek' => $cekId, ':bordro' => $bordro, ':cari' => $hedefCariRef, ':hesap' => $bankaRef,
                                   ':ack' => $aciklama ?: null, ':kul' => $personel ?: null]);
                $harId = (int) $harEkle->fetchColumn();
                $harEkle->closeCursor();
                $kayitlar[] = ['cscard_ref' => $cekId, 'cstrans_ref' => $harId, 'portfoyno' => $pno, 'cekno' => $k['cekno'],
                               'tutar' => $k['tutar'], 'vade' => $k['vade']];
            }
            $ilk = $kayitlar[0];
            $clRef = lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, VADE, KAYNAK, CEK_HAREKET_ID, BELGE_NO, BORC, ALACAK, ACIKLAMA)
                VALUES (:c, CAST(GETDATE() AS DATE), :v, 3, :h, :no, :t, 0, :a)',
                [':c' => $hedefCariRef, ':v' => $ilk['vade'], ':h' => $ilk['cstrans_ref'], ':no' => $bordro, ':t' => $toplam,
                 ':a' => $aciklama !== '' ? $aciklama : 'Kendi çekimiz ' . $bordro], 'ID');
            $logId = cek_lumen_log($dbh, [':req' => $requestId, ':fd' => $fd, ':tur' => 'kendi_cek', ':cc' => $ilk['cscard_ref'], ':pno' => $ilk['portfoyno'],
                ':cr' => $ilk['cstrans_ref'], ':roll' => $bordro, ':ct' => $ilk['cstrans_ref'], ':cl' => $clRef, ':tno' => $bordro,
                ':cari' => $hedefCariRef, ':tut' => $toplam, ':adet' => count($kayitlar), ':kalemler' => json_encode($kayitlar, JSON_UNESCAPED_UNICODE),
                ':cekno' => $ilk['cekno'], ':banka' => mb_substr((string) $bankaAd, 0, 120), ':vade' => $ilk['vade'], ':sahibi' => mb_substr((string) $hedefAd, 0, 200),
                ':exp' => $aciklama, ':kul' => $personel, ':ip' => (string) ($opt['ip'] ?? '')]);
            $dbh->commit();
            return ['ok' => true, 'mesaj' => count($kayitlar) . ' kendi çekimiz ' . mb_substr((string) $hedefAd, 0, 40) . '\'e verildi — toplam '
                    . number_format($toplam, 2, ',', '.') . ' TL (bordro ' . $bordro . ').',
                    'csroll_ref' => $ilk['cstrans_ref'], 'clfline_ref' => $clRef, 'rollno' => $bordro, 'adet' => count($kayitlar),
                    'tutar' => $toplam, 'kayitlar' => $kayitlar, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('cek_kendi_lumen: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Kendi çek verilemedi, hiçbir kayıt oluşmadı.', 'hata' => $e->getMessage()];
        }
    }
}
