<?php

declare(strict_types=1);

/**
 * Belge satırı için ürün arama (JSON): kod/ad/barkod; fiyat belge yönüne göre
 * (satış belgelerinde satış, alış belgelerinde alış fiyatı), KDV, birimler, eldeki miktar.
 * GET q, yon (1 satış, 2 alış).
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';

header('Content-Type: application/json; charset=utf-8');
if (!lumen_bagimsiz_mi()) {   // belge modülü yalnız bağımsız modda
    http_response_code(501);
    echo json_encode([]);
    exit;
}
// Fiyatlar yalnız fatura yetkilisine; depo personeli (M15) üretim/sayım için ürün arar.
$fiyatGorur = (int) m_p_yetki($terminalkullanici, 'M31') === 1;
$miktarGorur = (int) m_p_yetki($terminalkullanici, 'ST1') === 1 || (int) $yetkidurum === 0;
if (!$fiyatGorur && (int) m_p_yetki($terminalkullanici, 'M15') !== 1) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}
$q = trim((string) ($_GET['q'] ?? ''));
$yon = (int) ($_GET['yon'] ?? 1) === 2 ? 2 : 1;
if ($q === '') {
    echo json_encode([]);
    exit;
}
$like = '%' . lumen_arama_terimi($q) . '%';
$st = $dbh->prepare("
    SELECT TOP 15 S.ID, S.KOD, S.AD, S.KDV_ORANI, S.ANA_BIRIM_ID,
           FY.FIYAT, FY.PARA_BIRIMI AS FIYAT_PB,
           ISNULL((SELECT SUM(M.MIKTAR) FROM dbo.LMV_STOK_MIKTAR M WHERE M.STOK_ID = S.ID), 0) AS ELDEKI
    FROM dbo.LM_STOK S
    OUTER APPLY (SELECT TOP 1 F.FIYAT, F.PARA_BIRIMI FROM dbo.LM_FIYAT F WHERE F.STOK_ID = S.ID AND F.TUR = :tur
                 AND F.BIRIM_ID = S.ANA_BIRIM_ID AND F.BASLANGIC <= :bugun ORDER BY F.BASLANGIC DESC, F.ID DESC) FY
    WHERE S.AKTIF = 1 AND (" . lumen_arama_sql('S.KOD') . " LIKE :q1 OR " . lumen_arama_sql('S.AD') . " LIKE :q2
          OR EXISTS (SELECT 1 FROM dbo.LM_BARKOD BK WHERE BK.STOK_ID = S.ID AND BK.BARKOD = :barkod))
    ORDER BY S.KOD");
$st->execute([':tur' => $yon === 1 ? 1 : 2, ':bugun' => date('Y-m-d'), ':q1' => $like, ':q2' => $like, ':barkod' => $q]);
$urunler = $st->fetchAll(PDO::FETCH_ASSOC);
$birimSt = $dbh->prepare('SELECT SB.BIRIM_ID, B.KOD, SB.CARPAN FROM dbo.LM_STOK_BIRIM SB JOIN dbo.LM_BIRIM B ON B.ID = SB.BIRIM_ID
                          WHERE SB.STOK_ID = :s ORDER BY CASE WHEN SB.BIRIM_ID = :ana THEN 0 ELSE 1 END, SB.BIRIM_ID');
// Barkodla bulunduysa barkodun birimi (ör. koli barkodu → KOLI) satırda seçili gelir
$barkodSt = $dbh->prepare('SELECT BIRIM_ID FROM dbo.LM_BARKOD WHERE STOK_ID = :s AND BARKOD = :b');
$cikti = [];
foreach ($urunler as $u) {
    $barkodSt->execute([':s' => (int) $u['ID'], ':b' => $q]);
    $barkodBirim = $barkodSt->fetchColumn();
    $birimSt->execute([':s' => (int) $u['ID'], ':ana' => (int) $u['ANA_BIRIM_ID']]);
    $cikti[] = [
        'id' => (int) $u['ID'], 'kod' => (string) $u['KOD'], 'ad' => (string) $u['AD'],
        'kdv' => (float) $u['KDV_ORANI'], 'fiyat' => $fiyatGorur ? (float) ($u['FIYAT'] ?? 0) : 0.0, 'pb' => (string) ($u['FIYAT_PB'] ?? 'TRY'), 'eldeki' => $miktarGorur ? (float) $u['ELDEKI'] : null,
        'barkod_birim' => $barkodBirim !== false ? (int) $barkodBirim : null,
        'birimler' => array_map(static fn(array $b): array => ['id' => (int) $b['BIRIM_ID'], 'kod' => (string) $b['KOD'],
            'carpan' => (float) $b['CARPAN']], $birimSt->fetchAll(PDO::FETCH_ASSOC)),
    ];
}
echo json_encode($cikti, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
