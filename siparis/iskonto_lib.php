<?php

declare(strict_types=1);

/**
 * Fis iskonto yardimcilari.
 *
 * Amac: yeni urun eklendiginde fiste tanimli iskontolari (1. ve 2.) yeni urune
 * de otomatik uygulamak. Hesap, iskonto.php'deki "guncelleme" (isk1b / isk2b)
 * mantigiyla BIREBIR ayni sonucu verir:
 *   - 1. iskonto urun brutunden (TOTAL), 2. iskonto 1. iskonto sonrasi netten
 *     (kademeli), birim bazinda yuvarlama ile.
 */

if (!function_exists('hesaplaIskontoluSatir')) {
    /**
     * Brut satir toplamina yuzde iskonto uygular (birim bazinda yuvarlama).
     * iskonto.php icindeki ayni isimli fonksiyonun birebir kopyasidir.
     *
     * @return array{net: float, iskonto: float}
     */
    function hesaplaIskontoluSatir(float $brutToplam, float $miktar, float $oran): array
    {
        $oran = max(0.0, min(100.0, $oran));

        if ($brutToplam <= 0) {
            return ['net' => 0.0, 'iskonto' => 0.0];
        }

        // Oran %0 ise dogrudan dondur (per-unit yuvarlama sapmasini onlemek icin).
        if (abs($oran) < 0.00001) {
            return ['net' => round($brutToplam, 2), 'iskonto' => 0.0];
        }

        if ($miktar > 0) {
            $birimBrut = $brutToplam / $miktar;
            $birimNet = round($birimBrut * (1 - ($oran / 100)), 2);
            $net = round($birimNet * $miktar, 2);
        } else {
            $net = round($brutToplam * (1 - ($oran / 100)), 2);
        }

        $iskonto = round($brutToplam - $net, 2);
        if ($iskonto < 0) {
            $iskonto = 0.0;
        }

        return ['net' => $net, 'iskonto' => $iskonto];
    }
}

if (!function_exists('fis_iskonto_oranlari_uygula')) {
    /**
     * Fiste tanimli iskonto satirlarindaki (LINETYPE=2) oranlari, fisin TUM urun
     * satirlarina (LINETYPE=0) kademeli olarak yeniden uygular ve iskonto
     * satirlarinin TOTAL degerlerini gunceller. Boylece sonradan eklenen urun de
     * mevcut iskontoyla kapsanir; kullanicinin iskontoyu elle tekrar girmesi
     * gerekmez.
     *
     * Mevcut (zaten iskontolu) urunlerin sonucu degismez; yalnizca yeni urun(ler)
     * iskontolanmis olur. Fiste iskonto satiri yoksa hicbir sey yapmaz.
     *
     * @return bool Fiste iskonto bulunup uygulandiysa true, aksi halde false.
     */
    function fis_iskonto_oranlari_uygula(PDO $dbh, string $firmadonem, int $stokhareket): bool
    {
        // 1) Iskonto satirlari — LINENO_ sirasi = uygulama sirasi (1., 2., ...)
        $stIsk = $dbh->prepare(
            "SELECT LOGICALREF, DISCPER FROM {$firmadonem}ORFLINE
             WHERE ORDFICHEREF = :s AND LINETYPE = 2 ORDER BY LINENO_ ASC"
        );
        $stIsk->execute([':s' => $stokhareket]);
        $iskSatirlari = $stIsk->fetchAll(PDO::FETCH_ASSOC);
        $stIsk->closeCursor();

        if (empty($iskSatirlari)) {
            return false; // fiste iskonto yok -> dokunma
        }
        $oranlar = array_map(static fn($r) => (float) $r['DISCPER'], $iskSatirlari);

        // 2) Urun satirlari — TOTAL = brut tutar (iskonto.php'de de sabit kabul edilir)
        $stUrun = $dbh->prepare(
            "SELECT LOGICALREF, TOTAL, VAT, AMOUNT FROM {$firmadonem}ORFLINE
             WHERE ORDFICHEREF = :s AND LINETYPE = 0"
        );
        $stUrun->execute([':s' => $stokhareket]);
        $urunler = $stUrun->fetchAll(PDO::FETCH_ASSOC);
        $stUrun->closeCursor();

        if (empty($urunler)) {
            return false;
        }

        $stUrunUp = $dbh->prepare(
            "UPDATE {$firmadonem}ORFLINE
             SET VATAMNT = :v, DISTCOST = :dc, DISTDISC = :dd, VATMATRAH = :vm, LINENET = :ln
             WHERE LOGICALREF = :ref"
        );

        // Her iskonto seviyesinin tum fis genelindeki toplam iskonto tutari
        $seviyeTutar = array_fill(0, count($oranlar), 0.0);

        foreach ($urunler as $u) {
            $brut   = (float) $u['TOTAL'];
            $miktar = (float) ($u['AMOUNT'] ?? 0);
            $kdv    = (float) $u['VAT'];

            $net = $brut;
            $toplamIsk = 0.0;
            foreach ($oranlar as $i => $oran) {
                $h = hesaplaIskontoluSatir($net, $miktar, $oran);
                $seviyeTutar[$i] += $h['iskonto'];
                $toplamIsk += $h['iskonto'];
                $net = $h['net'];
            }
            $net = max(0.0, $net);

            $vatamnt = round(($net / 100) * $kdv, 2);
            if ($vatamnt < 0) {
                $vatamnt = 0.0;
            }
            $toplamIsk = round($toplamIsk, 2);

            $stUrunUp->execute([
                ':v'   => $vatamnt,
                ':dc'  => $toplamIsk,
                ':dd'  => $toplamIsk,
                ':vm'  => $net,
                ':ln'  => $net,
                ':ref' => (int) $u['LOGICALREF'],
            ]);
        }

        // 3) Iskonto satirlarinin TOTAL'ini guncelle (DISCPER degismez)
        $stIskUp = $dbh->prepare(
            "UPDATE {$firmadonem}ORFLINE SET TOTAL = :t WHERE LOGICALREF = :ref"
        );
        foreach ($iskSatirlari as $i => $isk) {
            $stIskUp->execute([':t' => round($seviyeTutar[$i], 2), ':ref' => (int) $isk['LOGICALREF']]);
        }

        return true;
    }
}
