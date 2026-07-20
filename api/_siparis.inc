<?php
declare(strict_types=1);

/**
 * ============================================================================
 * Sipariş uçları (siparis_onizle / siparis_olustur) ortak yardımcıları
 * ----------------------------------------------------------------------------
 * LOGO sipariş yazma sözleşmesi mevcut akıştan birebir alınmıştır:
 *  - ORFICHE başlık: ai_beta_order_header()
 *  - ORFLINE satır:  hareketeklecoklu.php / hareketekle.php (KDV + birim çarpanı)
 *  - ORFICHE toplam: lg_fis.php
 *  - Genel iskonto (LINETYPE=2 satır + satır dağıtımı, 2 kademe): iskonto.php
 * KDV satıra yazılır (VAT/VATAMNT); birim çarpanı miktarı ana birime çevirir
 * (AMOUNT = miktar × çarpan), fiyat ana birim fiyatıdır.
 * ============================================================================
 */

/** Tek ürünün sipariş için gereken bilgileri (fiyat/vat/birim/uomref/stok). null = bulunamadı. */
function siparis_stok_getir(PDO $dbh, string $firma, string $firmadonemx, int $stokId): ?array
{
    $stmt = $dbh->prepare("
        SELECT TOP 1
            S.LOGICALREF AS id,
            S.CODE        AS code,
            S.NAME        AS name,
            S.VAT         AS vat,
            S.UNITSETREF  AS unitsetref,
            ISNULL(U.CODE, '')   AS unit_code,
            ISNULL(ST.ONHAND, 0) AS onhand,
            ISNULL(PL.PRICE, 0)  AS price,
            ISNULL(UA.UNITLINEREF, 0) AS uomref
        FROM {$firma}ITEMS S WITH(NOLOCK)
        OUTER APPLY (
            SELECT TOP 1 B.CODE FROM {$firma}UNITSETL B WITH(NOLOCK)
            WHERE B.UNITSETREF = S.UNITSETREF AND B.MAINUNIT = 1
        ) U
        OUTER APPLY (
            SELECT SUM(X.ONHAND) AS ONHAND FROM {$firmadonemx}STINVTOT X WITH(NOLOCK)
            WHERE X.STOCKREF = S.LOGICALREF AND X.INVENNO = -1
        ) ST
        OUTER APPLY (
            SELECT TOP 1 P.PRICE FROM {$firma}PRCLIST P WITH(NOLOCK)
            WHERE P.CARDREF = S.LOGICALREF AND P.PTYPE = 2 AND P.ACTIVE = 0
            ORDER BY P.LOGICALREF DESC
        ) PL
        OUTER APPLY (
            SELECT TOP 1 A.UNITLINEREF FROM {$firma}ITMUNITA A WITH(NOLOCK)
            WHERE A.ITEMREF = S.LOGICALREF
            ORDER BY A.LOGICALREF ASC
        ) UA
        WHERE S.LOGICALREF = :id AND S.ACTIVE = 0 AND S.CARDTYPE <> 22
    ");
    $stmt->execute([':id' => $stokId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return [
        'id'         => (int) $r['id'],
        'code'       => (string) $r['code'],
        'name'       => function_exists('trcevir') ? trcevir($r['name']) : (string) $r['name'],
        'vat'        => (float) $r['vat'],
        'unitsetref' => (int) $r['unitsetref'],
        'unit_code'  => (string) $r['unit_code'],
        'onhand'     => (float) $r['onhand'],
        'price'      => (float) $r['price'],
        'uomref'     => (int) $r['uomref'],
    ];
}

/** Ürünün birimleri: [{kod, carpan}] (ana birim çarpan 1, koli/paket > 1). */
function siparis_stok_birimleri(PDO $dbh, string $firma, int $stokId): array
{
    $stmt = $dbh->prepare("
        SELECT U.CODE AS kod, ISNULL(IA.CONVFACT2, 1) AS carpan
        FROM {$firma}ITEMS S WITH(NOLOCK)
        INNER JOIN {$firma}ITMUNITA IA WITH(NOLOCK) ON IA.ITEMREF = S.LOGICALREF
        LEFT JOIN {$firma}UNITSETL U WITH(NOLOCK) ON U.LOGICALREF = IA.UNITLINEREF
        WHERE S.LOGICALREF = :id
        ORDER BY IA.LINENR ASC, IA.LOGICALREF ASC
    ");
    $stmt->execute([':id' => $stokId]);
    $out = [];
    $gorulen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $kod = function_exists('trcevir') ? trcevir($r['kod']) : (string) $r['kod'];
        $kod = trim($kod);
        if ($kod === '' || isset($gorulen[$kod])) {
            continue;
        }
        $gorulen[$kod] = true;
        $out[] = ['kod' => $kod, 'carpan' => max(1.0, (float) $r['carpan'])];
    }
    return $out;
}

/** Virgüllü/noktalı sayıyı güvenle float'a çevir. */
function siparis_sayi(string|int|float|null $v): float
{
    return (float) str_replace(',', '.', (string) $v);
}

/**
 * Kalemleri doğrula. F3 kuralı: fiyatı olmayan/geçersiz kalem atlanır.
 * Girdi kalemi: { stok_id, miktar, fiyat? (override), kdv? (override; yoksa stok VAT),
 *                 birim_carpan? (varsayılan 1), label? }
 * Döner: ['ready' => [...], 'skipped' => [...]]
 *   ready öğesi: stok_id, kod, ad, miktar(girilen), birim_carpan, ana_miktar(AMOUNT),
 *                fiyat, kdv, kdv_tutar, toplam(brüt), unitsetref, uomref, onhand
 */
function siparis_kalemleri_hazirla(PDO $dbh, string $firma, string $firmadonemx, array $kalemler): array
{
    $ready = [];
    $skipped = [];
    foreach ($kalemler as $k) {
        if (!is_array($k)) {
            continue;
        }
        $stokId = (int) ($k['stok_id'] ?? 0);
        $miktar = siparis_sayi($k['miktar'] ?? 0);
        $carpan = max(1.0, min(100000.0, siparis_sayi($k['birim_carpan'] ?? 1)));
        $label  = trim((string) ($k['label'] ?? ''));

        if ($stokId <= 0 || $miktar <= 0 || $miktar > 1000000) {
            $skipped[] = ['etiket' => $label !== '' ? $label : ('#' . $stokId), 'sebep' => 'miktar veya ürün geçersiz'];
            continue;
        }
        $s = siparis_stok_getir($dbh, $firma, $firmadonemx, $stokId);
        if ($s === null) {
            $skipped[] = ['etiket' => $label !== '' ? $label : ('#' . $stokId), 'sebep' => 'ürün bulunamadı'];
            continue;
        }
        $fiyatRaw = $k['fiyat'] ?? null;
        $fiyat = ($fiyatRaw !== null && $fiyatRaw !== '')
            ? siparis_sayi($fiyatRaw)
            : (float) $s['price'];
        if ($fiyat <= 0 || $fiyat > 100000000) {
            $skipped[] = ['etiket' => $s['code'] . ' ' . $s['name'], 'sebep' => 'fiyat tanımlı değil veya geçersiz'];
            continue;
        }

        // KDV: istemci açıkça gönderdiyse onu, yoksa ürünün VAT'ı.
        $kdvRaw = $k['kdv'] ?? null;
        $kdv = ($kdvRaw !== null && $kdvRaw !== '')
            ? max(0.0, min(100.0, siparis_sayi($kdvRaw)))
            : (float) $s['vat'];

        $anaMiktar = $miktar * $carpan;          // AMOUNT (ana birim)
        $toplam    = round($anaMiktar * $fiyat, 2); // brüt (KDV/iskonto hariç)
        $kdvTutar  = round(($toplam / 100) * $kdv, 2);

        $ready[] = [
            'stok_id'      => $stokId,
            'kod'          => $s['code'],
            'ad'           => $s['name'],
            'miktar'       => $miktar,
            'birim_carpan' => $carpan,
            'ana_miktar'   => $anaMiktar,
            'fiyat'        => $fiyat,
            'kdv'          => $kdv,
            'kdv_tutar'    => $kdvTutar,
            'toplam'       => $toplam,
            'unitsetref'   => $s['unitsetref'],
            'uomref'       => $s['uomref'],
            'onhand'       => $s['onhand'],
        ];
    }

    return ['ready' => $ready, 'skipped' => $skipped];
}

/**
 * Tek satıra iskonto uygula (iskonto.php hesaplaIskontoluSatir birebir).
 * Birim bazında yuvarlayıp toplama döner; per-unit sapmayı önler.
 */
function siparis_iskontolu_satir(float $brutToplam, float $miktar, float $oran): array
{
    $oran = max(0.0, min(100.0, $oran));
    if ($brutToplam <= 0) {
        return ['net' => 0.0, 'iskonto' => 0.0];
    }
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

/**
 * Fiş toplamını (iskonto + KDV dahil) YAZMADAN hesapla — önizleme ve gösterim için.
 * İki kademe iskonto sıralı uygulanır (önce iskonto1, kalan üzerine iskonto2).
 */
function siparis_toplam_hesapla(array $ready, float $iskonto1, float $iskonto2): array
{
    $araToplam = 0.0;
    $iskontoToplam = 0.0;
    $netToplam = 0.0;
    $kdvToplam = 0.0;
    $satirlar = [];
    foreach ($ready as $r) {
        $brut = (float) $r['toplam'];
        $mik  = (float) $r['ana_miktar'];
        $h1 = siparis_iskontolu_satir($brut, $mik, $iskonto1);
        $h2 = siparis_iskontolu_satir($h1['net'], $mik, $iskonto2);
        $net = $h2['net'];
        $satirIsk = round($brut - $net, 2);
        $satirKdv = round(($net / 100) * (float) $r['kdv'], 2);

        $araToplam     += $brut;
        $iskontoToplam += $satirIsk;
        $netToplam     += $net;
        $kdvToplam     += $satirKdv;

        $satirlar[] = [
            'stok_id'      => $r['stok_id'],
            'kod'          => $r['kod'],
            'ad'           => $r['ad'],
            'miktar'       => $r['miktar'],
            'birim_carpan' => $r['birim_carpan'],
            'ana_miktar'   => $mik,
            'fiyat'        => $r['fiyat'],
            'kdv'          => $r['kdv'],
            'brut'         => $brut,
            'iskonto'      => $satirIsk,
            'net'          => $net,
            'kdv_tutar'    => $satirKdv,
        ];
    }
    return [
        'ara_toplam'     => round($araToplam, 2),
        'iskonto_toplam' => round($iskontoToplam, 2),
        'net_toplam'     => round($netToplam, 2),
        'kdv_toplam'     => round($kdvToplam, 2),
        'genel_toplam'   => round($netToplam + $kdvToplam, 2),
        'satirlar'       => $satirlar,
    ];
}

/**
 * Mevcut bir fişe TEK kademe genel iskonto uygula (iskonto.php isk1 mantığı birebir):
 *  - LINETYPE=2 iskonto satırı ekle (DISCPER=oran)
 *  - tüm LINETYPE=0 satırları (TOTAL - DISTDISC) üzerinden dağıt (DISTDISC/LINENET/VATMATRAH/VATAMNT)
 *  - LINETYPE=2 satırın TOTAL'ini bu kademede uygulanan toplam iskontoya ayarla
 * İki kademe için sırayla iki kez çağrılır. oran <= 0 ise hiçbir şey yapmaz.
 */
function siparis_iskonto_uygula(PDO $dbh, string $firmadonem, int $fisId, int $cariId, float $oran, int $kayitsaat): float
{
    $oran = max(0.0, min(100.0, $oran));
    if ($oran <= 0.0) {
        return 0.0;
    }

    $st = $dbh->prepare("SELECT MAX(LINENO_) AS SATIR FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :f");
    $st->execute([':f' => $fisId]);
    $sonsirano = (int) (($st->fetch(PDO::FETCH_ASSOC)['SATIR'] ?? 0)) + 1;

    // LINETYPE=2 genel iskonto satırı (iskonto.php INSERT birebir; GUID/RESERVE alanları yok).
    $ins = $dbh->prepare("INSERT INTO {$firmadonem}ORFLINE (    
		  [STOCKREF]
	      ,[ORDFICHEREF]
	      ,[CLIENTREF]
      ,[LINETYPE]
      ,[PREVLINEREF]
      ,[PREVLINENO]
      ,[DETLINE]
      ,[LINENO_]
      ,[TRCODE]
      ,[DATE_]
      ,[TIME_]
      ,[GLOBTRANS]
      ,[CALCTYPE]
      ,[CENTERREF]
      ,[ACCOUNTREF]
      ,[VATACCREF]
      ,[VATCENTERREF]
      ,[PRACCREF]
      ,[PRCENTERREF]
      ,[PRVATACCREF]
      ,[PRVATCENREF]
      ,[PROMREF]
      ,[SPECODE]
      ,[DELVRYCODE]
      ,[AMOUNT]
      ,[PRICE]
      ,[TOTAL]
      ,[SHIPPEDAMOUNT]
      ,[DISCPER]
      ,[DISTCOST]
      ,[DISTDISC]
      ,[DISTEXP]
      ,[DISTPROM]
      ,[VAT]
      ,[VATAMNT]
      ,[VATMATRAH]
      ,[LINEEXP]
      ,[UOMREF]
      ,[USREF]
      ,[UINFO1]
      ,[UINFO2]
      ,[UINFO3]
      ,[UINFO4]
      ,[UINFO5]
      ,[UINFO6]
      ,[UINFO7]
      ,[UINFO8]
      ,[VATINC]
      ,[CLOSED]
      ,[DORESERVE]
      ,[INUSE]
      ,[DUEDATE]
      ,[PRCURR]
      ,[PRPRICE]
      ,[REPORTRATE]
      ,[BILLEDITEM]
      ,[PAYDEFREF]
      ,[EXTENREF]
      ,[CPSTFLAG]
      ,[SOURCEINDEX]
      ,[SOURCECOSTGRP]
      ,[BRANCH]
      ,[DEPARTMENT]
      ,[LINENET]
      ,[SALESMANREF]
      ,[STATUS]
      ,[DREF]
      ,[TRGFLAG]
      ,[SITEID]
      ,[RECSTATUS]
	  ,[ORGLOGICREF]
      ,[FACTORYNR]
      ,[WFSTATUS]
      ,[NETDISCFLAG]
      ,[NETDISCPERC]
      ,[NETDISCAMNT]
      ,[CONDITIONREF]
      ,[DISTRESERVED]
      ,[ONVEHICLE]
      ,[CAMPAIGNREFS1]
      ,[CAMPAIGNREFS2]
      ,[CAMPAIGNREFS3]
      ,[CAMPAIGNREFS4]
      ,[CAMPAIGNREFS5]
      ,[POINTCAMPREF]
      ,[CAMPPOINT]
      ,[PROMCLASITEMREF]
      ,[REASONFORNOTSHP]
      ,[CMPGLINEREF]
      ,[PRRATE]
      ,[GROSSUINFO1]
      ,[GROSSUINFO2]
      ,[CANCELLED]
      ,[DEMPEGGEDAMNT]
      ,[TEXTINC]
      ,[OFFERREF]
      ,[ORDERPARAM]
      ,[ITEMASGREF]
      ,[EXIMAMOUNT]
      ,[OFFTRANSREF]
      ,[ORDEREDAMOUNT]
      ,[ORGLOGOID]
      ,[TRCURR]
      ,[TRRATE]
      ,[WITHPAYTRANS]
      ,[PROJECTREF]
      ,[POINTCAMPREFS1]
      ,[POINTCAMPREFS2]
      ,[POINTCAMPREFS3]
      ,[POINTCAMPREFS4]
      ,[CAMPPOINTS1]
      ,[CAMPPOINTS2]
      ,[CAMPPOINTS3]
      ,[CAMPPOINTS4]
      ,[CMPGLINEREFS1]
      ,[CMPGLINEREFS2]
      ,[CMPGLINEREFS3]
      ,[CMPGLINEREFS4]
      ,[PRCLISTREF]
      ,[AFFECTCOLLATRL]
      ,[FCTYP]
      ,[PURCHOFFNR]
      ,[DEMFICHEREF]
      ,[DEMTRANSREF]
      ,[ALTPROMFLAG]
      ,[VARIANTREF]
      ,[REFLVATACCREF]
      ,[REFLVATOTHACCREF]
      ,[PRIORITY]
      ,[AFFECTRISK]
      ,[BOMREF]
      ,[BOMREVREF]
      ,[ROUTINGREF]
      ,[OPERATIONREF]
      ,[WSREF]
      ,[ADDTAXRATE]
      ,[ADDTAXCONVFACT]
      ,[ADDTAXAMOUNT]
      ,[ADDTAXACCREF]
      ,[ADDTAXCENTERREF]
      ,[ADDTAXAMNTISUPD]
      ,[ADDTAXDISCAMOUNT]
      ,[EXADDTAXRATE]
      ,[EXADDTAXCONVF]
      ,[EXADDTAXAMNT]
      ,[EUVATSTATUS]
      ,[ADDTAXVATMATRAH]
      ,[CAMPPAYDEFREF]
      ,[RPRICE]
      ,[ORGDUEDATE]
      ,[ORGAMOUNT]
      ,[ORGPRICE]
      ,[SPECODE2]
      ,[CANDEDUCT]
      ,[UNDERDEDUCTLIMIT]
      ,[GLOBALID]
	      ,[DEDUCTIONPART1]
	      ,[DEDUCTIONPART2]
	      ,[PARENTLNREF]
	) VALUES (
	0,:stokhareket,:cari,2,0,0,0,:sonsirano,1,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,1,0,0,0,0,0,0,0,0,0,0,'','',0,0,0,0,:isk,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),0,0,1,0,0,0,0,0,0,0,0,0,0,1,0,0,0,1
	,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0
	)");
    $ins->execute([':stokhareket' => $fisId, ':cari' => $cariId, ':sonsirano' => $sonsirano, ':kayitsaat' => $kayitsaat, ':isk' => $oran]);

    // Ürün satırlarını dağıt.
    $sel = $dbh->prepare("SELECT LOGICALREF, TOTAL, VAT, DISTDISC, AMOUNT
                          FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :f AND LINETYPE = 0");
    $sel->execute([':f' => $fisId]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    $upd = $dbh->prepare("UPDATE {$firmadonem}ORFLINE
        SET VATAMNT = :va, DISTCOST = :dc, DISTDISC = :dd, VATMATRAH = :vm, LINENET = :ln
        WHERE LOGICALREF = :ref");

    $topisk = 0.0;
    foreach ($rows as $row) {
        $kalan = (float) $row['TOTAL'] - (float) $row['DISTDISC'];
        $mik = (float) ($row['AMOUNT'] ?? 0);
        $h = siparis_iskontolu_satir($kalan, $mik, $oran);
        $net = $h['net'];
        $kdv = (float) $row['VAT'];
        $kdvTut = round(($net / 100) * $kdv, 2);
        if ($kdvTut < 0) {
            $kdvTut = 0.0;
        }
        $satirIsk = round($h['iskonto'] + (float) $row['DISTDISC'], 2);
        $topisk += $h['iskonto'];
        $upd->execute([':va' => $kdvTut, ':dc' => $satirIsk, ':dd' => $satirIsk, ':vm' => $net, ':ln' => $net, ':ref' => (int) $row['LOGICALREF']]);
    }

    $updIsk = $dbh->prepare("UPDATE {$firmadonem}ORFLINE SET TOTAL = :t
                             WHERE ORDFICHEREF = :f AND LINETYPE = 2 AND LINENO_ = :l");
    $updIsk->execute([':t' => round($topisk, 2), ':f' => $fisId, ':l' => $sonsirano]);

    return round($topisk, 2);
}

/** Cari var/aktif mi — id + kod + ad döndür, yoksa null. */
function siparis_cari_getir(PDO $dbh, string $firma, int $cariId): ?array
{
    $stmt = $dbh->prepare("SELECT TOP 1 LOGICALREF, CODE, DEFINITION_
                           FROM {$firma}CLCARD WITH(NOLOCK)
                           WHERE LOGICALREF = :id AND ACTIVE = 0");
    $stmt->execute([':id' => $cariId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return [
        'id'  => (int) $r['LOGICALREF'],
        'kod' => (string) $r['CODE'],
        'ad'  => function_exists('trcevir') ? trcevir($r['DEFINITION_']) : (string) $r['DEFINITION_'],
    ];
}

/**
 * Fiş düzenlenebilir mi? Var olmalı ve iptal edilmemiş olmalı.
 * (Cariler birbirinin siparişini düzenleyebilir — kullanıcı bazlı kısıt YOK, kasıtlı.)
 * Döner: ['id', 'fisno', 'cari_id'] veya null.
 */
function siparis_fis_duzenlenebilir(PDO $dbh, string $firmadonem, int $fisId): ?array
{
    if ($fisId <= 0) {
        return null;
    }
    $st = $dbh->prepare("SELECT TOP 1 LOGICALREF, FICHENO, CLIENTREF
                         FROM {$firmadonem}ORFICHE WITH(NOLOCK)
                         WHERE LOGICALREF = :f AND (CANCELLED = 0 OR CANCELLED IS NULL)");
    $st->execute([':f' => $fisId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return [
        'id'      => (int) $r['LOGICALREF'],
        'fisno'   => (string) $r['FICHENO'],
        'cari_id' => (int) $r['CLIENTREF'],
    ];
}

/** Tek bir ORFLINE satırının bilgisini getir (sil/güncelle öncesi doğrulama). null = yok. */
function siparis_satir_getir(PDO $dbh, string $firma, string $firmadonem, int $satirId): ?array
{
    $st = $dbh->prepare("
        SELECT TOP 1 L.LOGICALREF, L.ORDFICHEREF, L.LINETYPE, L.STOCKREF, L.AMOUNT,
               L.PRICE, L.VAT, L.TOTAL, L.VATMATRAH, L.SHIPPEDAMOUNT, L.LINEEXP,
               ISNULL(I.CODE,'') AS CODE, ISNULL(I.NAME,'') AS NAME
        FROM {$firmadonem}ORFLINE L WITH(NOLOCK)
        LEFT JOIN {$firma}ITEMS I WITH(NOLOCK) ON I.LOGICALREF = L.STOCKREF
        WHERE L.LOGICALREF = :id");
    $st->execute([':id' => $satirId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return [
        'id'        => (int) $r['LOGICALREF'],
        'fis_id'    => (int) $r['ORDFICHEREF'],
        'linetype'  => (int) $r['LINETYPE'],
        'stok_id'   => (int) $r['STOCKREF'],
        'miktar'    => (float) $r['AMOUNT'],
        'fiyat'     => (float) $r['PRICE'],
        'kdv'       => (float) $r['VAT'],
        'toplam'    => (float) $r['TOTAL'],
        'vatmatrah' => (float) $r['VATMATRAH'],
        'sevk'      => (float) $r['SHIPPEDAMOUNT'],
        'aciklama'  => (string) $r['LINEEXP'],
        'kod'       => function_exists('trcevir') ? trcevir($r['CODE']) : (string) $r['CODE'],
        'ad'        => function_exists('trcevir') ? trcevir($r['NAME']) : (string) $r['NAME'],
    ];
}

/**
 * Fişin ORFICHE toplamlarını mevcut ORFLINE satırlarından yeniden hesaplar ve yazar.
 * siparis_olustur.php'deki toplam UPDATE sözleşmesiyle BİREBİR aynıdır; her satır
 * ekleme/silme/güncelleme işleminden sonra çağrılmalıdır (LOGO tutarlılığı).
 * Döner: ['brut','iskonto','kdv','net'].
 */
function siparis_fis_toplam_yenile(PDO $dbh, string $firmadonem, int $fisId): array
{
    $stmtSum = $dbh->prepare("
        SELECT SUM(VATAMNT) AS KDVTUTAR, SUM(TOTAL) AS TUTAR, SUM(DISTDISC) AS ISK
        FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :ref AND LINETYPE = 0
    ");
    $stmtSum->execute([':ref' => $fisId]);
    $t    = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];
    $kdvT = round((float) ($t['KDVTUTAR'] ?? 0), 2);
    $brut = round((float) ($t['TUTAR'] ?? 0), 2);
    $isk  = round((float) ($t['ISK'] ?? 0), 2);
    $net  = round($brut - $isk, 2);
    $netKdv = round($net + $kdvT, 2);

    $stmtUpd = $dbh->prepare("
        UPDATE {$firmadonem}ORFICHE
        SET ADDDISCOUNTS=:a, TOTALDISCOUNTS=:td, TOTALDISCOUNTED=:tded,
            TOTALVAT=:tv, GROSSTOTAL=:gt, NETTOTAL=:nt, REPORTNET=:rn, TRNET=:tn
        WHERE LOGICALREF=:ref
    ");
    $stmtUpd->execute([
        ':a' => $isk, ':td' => $isk, ':tded' => $brut, ':tv' => $kdvT,
        ':gt' => $brut, ':nt' => $netKdv, ':rn' => $netKdv, ':tn' => $netKdv,
        ':ref' => $fisId,
    ]);

    return ['brut' => $brut, 'iskonto' => $isk, 'kdv' => $kdvT, 'net' => $netKdv];
}

/** Fişin LINETYPE=0 satırlarını LINENO_ 1..N olarak yeniden sıralar; iskonto satırı (LINETYPE=2) en sona. */
function siparis_lineno_yenile(PDO $dbh, string $firmadonem, int $fisId): void
{
    $sel = $dbh->prepare("SELECT LOGICALREF FROM {$firmadonem}ORFLINE
                          WHERE ORDFICHEREF = :f AND LINETYPE = 0 ORDER BY LINENO_ ASC, LOGICALREF ASC");
    $sel->execute([':f' => $fisId]);
    $refler = $sel->fetchAll(PDO::FETCH_COLUMN);

    $upd = $dbh->prepare("UPDATE {$firmadonem}ORFLINE SET LINENO_ = :n WHERE LOGICALREF = :ref");
    $sira = 0;
    foreach ($refler as $ref) {
        $sira++;
        $upd->execute([':n' => $sira, ':ref' => (int) $ref]);
    }
    // İskonto satırları (varsa) en sona.
    $sira++;
    $updIsk = $dbh->prepare("UPDATE {$firmadonem}ORFLINE SET LINENO_ = :n
                            WHERE ORDFICHEREF = :f AND LINETYPE = 2");
    $updIsk->execute([':n' => $sira, ':f' => $fisId]);
}
