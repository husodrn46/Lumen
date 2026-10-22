<?php

declare(strict_types=1);

/** Kasa ve banka hesapları, bakiyeler ve son fişler — yalnız bağımsız mod. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

$hesaplar = finans_hesaplar($dbh);
$turler = lumen_finans_turleri();
$secHesap = (int) ($_GET['hesap'] ?? 0);
$bas = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['bas'] ?? '')) ? (string) $_GET['bas'] : date('Y-m-01');
$bit = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['bit'] ?? '')) ? (string) $_GET['bit'] : date('Y-m-d');

$kosul = 'F.TARIH BETWEEN :bas AND :bit';
$p = [':bas' => $bas, ':bit' => $bit];
if ($secHesap > 0) {
    $kosul .= ' AND (F.HESAP_ID = :h1 OR F.KARSI_HESAP_ID = :h2)';
    $p += [':h1' => $secHesap, ':h2' => $secHesap];
}
$ozel = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'ISNULL(F.CARI_ID, 0)', 'finans_liste_gizli');
if ($ozel['sql'] !== '') {
    $kosul .= ' AND ' . $ozel['sql'];
    $p += $ozel['params'];
}
$st = $dbh->prepare("SELECT TOP 300 F.ID, F.TUR, F.NO, F.TARIH, F.TUTAR, F.DURUM, F.ACIKLAMA, H.AD AS HESAP, K.AD AS KARSI, C.KOD AS CARI_KOD, C.UNVAN
                     FROM dbo.LM_FINANS_FIS F
                     JOIN dbo.LM_FINANS_HESAP H ON H.ID = F.HESAP_ID
                     LEFT JOIN dbo.LM_FINANS_HESAP K ON K.ID = F.KARSI_HESAP_ID
                     LEFT JOIN dbo.LM_CARI C ON C.ID = F.CARI_ID
                     WHERE {$kosul} ORDER BY F.TARIH DESC, F.ID DESC");
$st->execute($p);
$fisler = $st->fetchAll(PDO::FETCH_ASSOC);
$toplam = array_sum(array_map(static fn(array $h): float => (float) $h['BAKIYE'], $hesaplar));

belge_sayfa_basla('Kasa ve Banka', 'fa-building-columns', '../index.php',
    '<a href="yeni.php?tur=1" class="btn btn-birincil"><i class="fa-solid fa-plus"></i><span class="metin">Yeni Fiş</span></a>');
?>
<style>
    .hesaplar { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .hesap { display: block; text-decoration: none; color: inherit; padding: 14px 16px; margin-bottom: 0; }
    .hesap.secili { outline: 2px solid var(--red); }
    .hesap .tutar { font-size: 20px; font-weight: 700; margin-top: 6px; }
    .eksi { color: var(--red); }
    .hizli { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
</style>
<div class="hizli">
    <?php foreach ([1 => 'fa-arrow-down', 2 => 'fa-arrow-up', 3 => 'fa-right-left', 4 => 'fa-receipt'] as $t => $ikon): ?>
        <a class="btn btn-ikincil" href="yeni.php?tur=<?= $t ?>"><i class="fa-solid <?= $ikon ?>"></i> <?= bh($turler[$t]['ad']) ?></a>
    <?php endforeach; ?>
</div>
<div class="hesaplar">
    <?php foreach ($hesaplar as $h): ?>
        <a class="kart hesap <?= (int) $h['ID'] === $secHesap ? 'secili' : '' ?>" href="?hesap=<?= (int) $h['ID'] ?>&bas=<?= bh($bas) ?>&bit=<?= bh($bit) ?>">
            <div class="soluk"><i class="fa-solid <?= (int) $h['TUR'] === 1 ? 'fa-cash-register' : 'fa-building-columns' ?>"></i>
                <?= bh($h['KOD']) ?> · <?= (int) $h['TUR'] === 1 ? 'Kasa' : 'Banka' ?></div>
            <div class="koyu"><?= bh($h['AD']) ?></div>
            <div class="tutar <?= (float) $h['BAKIYE'] < 0 ? 'eksi' : '' ?>" data-bakiye="<?= (int) $h['ID'] ?>"><?= btl($h['BAKIYE']) ?></div>
        </a>
    <?php endforeach; ?>
    <div class="kart hesap"><div class="soluk">Toplam</div><div class="koyu">Tüm hesaplar</div>
        <div class="tutar <?= $toplam < 0 ? 'eksi' : '' ?>"><?= btl($toplam) ?></div></div>
</div>

<form method="get" class="kart kart-ic izgara" style="align-items:end">
    <label class="alan">Hesap
        <select name="hesap"><option value="0">Tümü</option>
            <?php foreach ($hesaplar as $h): ?><option value="<?= (int) $h['ID'] ?>" <?= (int) $h['ID'] === $secHesap ? 'selected' : '' ?>><?= bh($h['KOD'] . ' ' . $h['AD']) ?></option><?php endforeach; ?>
        </select></label>
    <label class="alan">Başlangıç <input type="date" name="bas" value="<?= bh($bas) ?>"></label>
    <label class="alan">Bitiş <input type="date" name="bit" value="<?= bh($bit) ?>"></label>
    <div><button class="btn btn-birincil" type="submit"><i class="fa-solid fa-filter"></i> Listele</button></div>
</form>

<div class="kart tablo-sar">
    <table class="liste">
        <thead><tr><th>Fiş No</th><th>Tür</th><th>Tarih</th><th>Hesap</th><th>Cari / Açıklama</th><th class="sag">Tutar</th><th></th></tr></thead>
        <tbody>
        <?php if ($fisler === []): ?>
            <tr><td colspan="7" class="soluk" style="text-align:center;padding:28px">Bu aralıkta fiş yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($fisler as $f):
            $tur = (int) $f['TUR']; ?>
            <tr>
                <td class="koyu"><a href="goster.php?id=<?= (int) $f['ID'] ?>" style="color:inherit"><?= bh($f['NO']) ?></a></td>
                <td><span class="rozet <?= in_array($tur, [1, 5], true) ? 'satis' : 'alis' ?>"><?= bh($turler[$tur]['ad'] ?? 'Fiş') ?></span>
                    <?php if ((int) $f['DURUM'] === 9): ?><span class="rozet iptal">İptal</span><?php endif; ?></td>
                <td><?= bh(date('d.m.Y', strtotime((string) $f['TARIH']))) ?></td>
                <td><?= bh($f['HESAP']) ?><?= $f['KARSI'] ? ' → ' . bh($f['KARSI']) : '' ?></td>
                <td><?= $f['CARI_KOD'] ? bh(trim($f['CARI_KOD'] . ' ' . $f['UNVAN'])) : '<span class="soluk">' . bh($f['ACIKLAMA'] ?? '—') . '</span>' ?></td>
                <td class="sag koyu"><?= btl($f['TUTAR']) ?></td>
                <td class="sag"><a class="btn btn-ikincil" href="goster.php?id=<?= (int) $f['ID'] ?>"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php belge_sayfa_bitir();
