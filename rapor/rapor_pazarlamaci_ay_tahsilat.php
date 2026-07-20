<?php
declare(strict_types=1);

/**
 * AJAX endpoint: Belirli bir ayin tahsilat detaylari.
 * Tek kaynak: CLFLINE (cari hesap hareketleri), DATE_ bazli — yani o ay
 * fiilen yapilan tahsilatlar. Cek/senet icin de CLFLINE TRCODE=61/62
 * kullaniliyor; CSCARD SETDATE (tanzim tarihi) bazli sorgu artik kullanilmiyor
 * (cift sayim ve yil/ay sinirlarinda kayma sebebiyle).
 *
 * POST parametreleri:
 *   - month: 1-12
 *   - prefix: Cari kod on eki
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    http_response_code(403);
    echo '<p class="text-red-600 text-center p-4">Yetkiniz yok.</p>';
    exit;
}

$month = isset($_POST['month']) ? (int) $_POST['month'] : 0;
$cariPrefix = isset($_POST['prefix']) ? trim((string) $_POST['prefix']) : 'TMÇN';

if ($month < 1 || $month > 12) {
    echo '<p class="text-red-600 text-center p-4">Gecersiz ay.</p>';
    exit;
}

$monthNames = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
];

$decimals = (int) ($parakusurat ?? 2);

// TRCODE aciklamalari (sadece nakit/havale/POS icin; cek=61, senet=62 ayri tablolarda)
$trcodeNames = [
    1  => 'Nakit Tahsilat',
    4  => 'Alacak Dekontu',
    20 => 'Gelen Havale',
    70 => 'Kredi Karti / POS',
];

/* -----------------------------------------------------------------
 *  A) Nakit / Havale / POS tahsilatlar (CLFLINE, TRCODE 1/4/20/70)
 * ----------------------------------------------------------------- */
$nakitRows = [];
$sqlNakit = "
    SELECT
        CF.DATE_ AS TARIH,
        CL.CODE AS CARI_KODU,
        CL.DEFINITION_ AS CARI_UNVANI,
        CF.TRCODE,
        CF.AMOUNT AS TUTAR,
        CF.LINEEXP AS ACIKLAMA
    FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CF.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND CF.TRCODE IN (1, 4, 20, 70)
      AND CF.SIGN = 1
      AND MONTH(CF.DATE_) = :month
      AND YEAR(CF.DATE_) = 2026
      AND CF.CANCELLED = 0
    ORDER BY CF.DATE_ DESC
";

try {
    $stmt = $dbh->prepare($sqlNakit);
    $stmt->execute([':prefix' => $cariPrefix . '%', ':month' => $month]);
    $nakitRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $nakitRows = [];
}

/* -----------------------------------------------------------------
 *  B) Cek alimlari (CLFLINE TRCODE=61, alma tarihi bazli)
 * ----------------------------------------------------------------- */
$cekRows = [];
$sqlCek = "
    SELECT
        CF.DATE_ AS TARIH,
        CL.CODE AS CARI_KODU,
        CL.DEFINITION_ AS CARI_UNVANI,
        CF.AMOUNT AS TUTAR,
        CF.LINEEXP AS ACIKLAMA
    FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CF.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND CF.TRCODE = 61
      AND CF.SIGN = 1
      AND MONTH(CF.DATE_) = :month
      AND YEAR(CF.DATE_) = 2026
      AND CF.CANCELLED = 0
    ORDER BY CF.DATE_ DESC
";

try {
    $stmt = $dbh->prepare($sqlCek);
    $stmt->execute([':prefix' => $cariPrefix . '%', ':month' => $month]);
    $cekRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $cekRows = [];
}

/* -----------------------------------------------------------------
 *  C) Senet alimlari (CLFLINE TRCODE=62, alma tarihi bazli)
 * ----------------------------------------------------------------- */
$senetRows = [];
$sqlSenet = "
    SELECT
        CF.DATE_ AS TARIH,
        CL.CODE AS CARI_KODU,
        CL.DEFINITION_ AS CARI_UNVANI,
        CF.AMOUNT AS TUTAR,
        CF.LINEEXP AS ACIKLAMA
    FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CF.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND CF.TRCODE = 62
      AND CF.SIGN = 1
      AND MONTH(CF.DATE_) = :month
      AND YEAR(CF.DATE_) = 2026
      AND CF.CANCELLED = 0
    ORDER BY CF.DATE_ DESC
";

try {
    $stmt = $dbh->prepare($sqlSenet);
    $stmt->execute([':prefix' => $cariPrefix . '%', ':month' => $month]);
    $senetRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $senetRows = [];
}

// Toplamlar
$topNakit = 0.0; foreach ($nakitRows as $r) { $topNakit += (float) ($r['TUTAR'] ?? 0); }
$topCek = 0.0;   foreach ($cekRows as $r)   { $topCek += (float) ($r['TUTAR'] ?? 0); }
$topSenet = 0.0; foreach ($senetRows as $r)  { $topSenet += (float) ($r['TUTAR'] ?? 0); }
$topGenel = $topNakit + $topCek + $topSenet;

$hasData = !empty($nakitRows) || !empty($cekRows) || !empty($senetRows);

?>
<style>
  .tah-wrap {
    --sky: #0284c7;
    --sky-soft: #eff6ff;
    --emerald: #059669;
    --emerald-soft: #ecfdf5;
    --indigo: #4f46e5;
    --indigo-soft: #eef2ff;
    --purple: #7c3aed;
    --purple-soft: #f5f3ff;
    --text-1: #1f2937;
    --text-2: #6b7280;
    --text-3: #9ca3af;
    --border: #e5e7eb;
    font-family: 'Avenir Next', 'Montserrat', sans-serif;
  }
  .tah-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 14px; margin-bottom: 12px;
    background: var(--sky-soft);
    border: 1px solid rgba(2, 132, 199, 0.18);
    border-radius: 12px;
  }
  .tah-head .title { font-size: 14px; font-weight: 700; color: var(--sky); }
  .tah-head .sub   { font-size: 11.5px; color: var(--text-2); margin-left: 8px; font-weight: 500; }
  .tah-head .total { font-size: 12.5px; color: var(--sky); font-weight: 700; padding: 4px 12px; background: #fff; border-radius: 100px; border: 1px solid rgba(2, 132, 199, 0.22); }

  /* Ozet kartlar */
  .tah-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
  .tah-sum-card {
    position: relative;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 12px 14px 12px 16px;
    overflow: hidden;
  }
  .tah-sum-card::before {
    content: ''; position: absolute; top: 0; bottom: 0; left: 0; width: 3px;
  }
  .tah-sum-card.tone-emerald::before { background: var(--emerald); }
  .tah-sum-card.tone-indigo::before  { background: var(--indigo); }
  .tah-sum-card.tone-purple::before  { background: var(--purple); }
  .tah-sum-card .lbl {
    font-size: 9.5px; font-weight: 700;
    color: var(--text-3); text-transform: uppercase; letter-spacing: 0.4px;
    display: flex; align-items: center; gap: 6px;
  }
  .tah-sum-card .val { font-size: 15px; font-weight: 700; margin-top: 4px; }
  .tah-sum-card.tone-emerald .val { color: var(--emerald); }
  .tah-sum-card.tone-indigo .val  { color: var(--indigo); }
  .tah-sum-card.tone-purple .val  { color: var(--purple); }
  .tah-sum-card .cnt { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }

  /* Sekton basligi */
  .tah-section-head {
    display: flex; align-items: center; gap: 8px;
    font-size: 12.5px; font-weight: 700;
    margin: 14px 0 6px;
    padding: 6px 10px;
    border-radius: 8px;
  }
  .tah-section-head.tone-emerald { color: var(--emerald); background: var(--emerald-soft); }
  .tah-section-head.tone-indigo  { color: var(--indigo);  background: var(--indigo-soft); }
  .tah-section-head.tone-purple  { color: var(--purple);  background: var(--purple-soft); }

  /* Tablo */
  .gd-tah-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
  .gd-tah-table thead th {
    text-align: left; font-size: 10px; font-weight: 700;
    color: var(--text-2); text-transform: uppercase; letter-spacing: 0.4px;
    padding: 8px 10px; border-bottom: 1px solid var(--border); background: #fafafa;
  }
  .gd-tah-table tbody td { padding: 9px 10px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: var(--text-1); }
  .gd-tah-table .col-right { text-align: right; }
  .gd-tah-table .cust-name { font-weight: 600; color: var(--text-1); font-size: 12px; }
  .gd-tah-table .cust-code { font-size: 10px; color: var(--text-3); margin-top: 2px; }
  .gd-tah-table .mono { font-family: 'Courier New', monospace; font-size: 11px; color: var(--text-2); }
  .gd-tah-table .val { font-weight: 700; white-space: nowrap; }
  .gd-tah-table.tone-emerald tbody tr:hover { background: var(--emerald-soft); }
  .gd-tah-table.tone-emerald .val { color: var(--emerald); }
  .gd-tah-table.tone-emerald tfoot td { background: var(--emerald-soft); color: var(--emerald); border-top: 2px solid rgba(5, 150, 105, 0.22); font-weight: 700; padding: 10px; }
  .gd-tah-table.tone-indigo tbody tr:hover { background: var(--indigo-soft); }
  .gd-tah-table.tone-indigo .val { color: var(--indigo); }
  .gd-tah-table.tone-indigo tfoot td { background: var(--indigo-soft); color: var(--indigo); border-top: 2px solid rgba(79, 70, 229, 0.22); font-weight: 700; padding: 10px; }
  .gd-tah-table.tone-purple tbody tr:hover { background: var(--purple-soft); }
  .gd-tah-table.tone-purple .val { color: var(--purple); }
  .gd-tah-table.tone-purple tfoot td { background: var(--purple-soft); color: var(--purple); border-top: 2px solid rgba(124, 58, 237, 0.22); font-weight: 700; padding: 10px; }

  .tah-empty { text-align: center; padding: 28px 16px; color: var(--text-3); font-size: 12px; }
  .tah-empty i { font-size: 28px; display: block; margin-bottom: 8px; color: #d1d5db; }

  @media (max-width: 640px) {
    .tah-summary { grid-template-columns: 1fr; }
  }
</style>

<div class="tah-wrap">
  <div class="tah-head">
    <div>
      <span class="title"><?php echo htmlspecialchars($monthNames[$month] ?? '', ENT_QUOTES, 'UTF-8'); ?> 2026</span>
      <span class="sub">Tahsilat Detaylari</span>
    </div>
    <span class="total">Toplam: <?php echo number_format($topGenel, $decimals, ',', '.'); ?> &#8378;</span>
  </div>

  <!-- Ozet kartlar -->
  <div class="tah-summary">
    <div class="tah-sum-card tone-emerald">
      <div class="lbl"><i class="fa-solid fa-money-bill-wave"></i> Nakit/Havale/POS</div>
      <div class="val"><?php echo number_format($topNakit, $decimals, ',', '.'); ?> &#8378;</div>
      <div class="cnt"><?php echo count($nakitRows); ?> islem</div>
    </div>
    <div class="tah-sum-card tone-indigo">
      <div class="lbl"><i class="fa-solid fa-money-check"></i> Cek</div>
      <div class="val"><?php echo number_format($topCek, $decimals, ',', '.'); ?> &#8378;</div>
      <div class="cnt"><?php echo count($cekRows); ?> adet</div>
    </div>
    <div class="tah-sum-card tone-purple">
      <div class="lbl"><i class="fa-solid fa-file-invoice-dollar"></i> Senet</div>
      <div class="val"><?php echo number_format($topSenet, $decimals, ',', '.'); ?> &#8378;</div>
      <div class="cnt"><?php echo count($senetRows); ?> adet</div>
    </div>
  </div>

<?php if (!$hasData): ?>
  <div class="tah-empty">
    <i class="fa-solid fa-inbox"></i>
    Bu ayda tahsilat bulunamadi.
  </div>
<?php else: ?>

  <?php if (!empty($nakitRows)): ?>
  <div class="tah-section-head tone-emerald">
    <i class="fa-solid fa-money-bill-wave"></i> Nakit / Havale / POS
  </div>
  <div style="overflow-x:auto;">
    <table class="gd-tah-table tone-emerald">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Musteri</th>
          <th>Tur</th>
          <th class="col-right">Tutar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($nakitRows as $r):
            $tarih = $r['TARIH'] ?? '';
            if ($tarih !== '') { $tarih = (new DateTime($tarih))->format('d.m.Y'); }
            $trcode = (int) ($r['TRCODE'] ?? 0);
            $turAdi = $trcodeNames[$trcode] ?? 'Diger';
        ?>
        <tr>
          <td><?php echo htmlspecialchars($tarih, ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
            <div class="cust-name"><?php echo htmlspecialchars((string) ($r['CARI_UNVANI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="cust-code"><?php echo htmlspecialchars((string) ($r['CARI_KODU'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
          </td>
          <td><?php echo htmlspecialchars($turAdi, ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-right val"><?php echo number_format((float) ($r['TUTAR'] ?? 0), $decimals, ',', '.'); ?> &#8378;</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3">Nakit Toplam</td>
          <td class="col-right"><?php echo number_format($topNakit, $decimals, ',', '.'); ?> &#8378;</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($cekRows)): ?>
  <div class="tah-section-head tone-indigo">
    <i class="fa-solid fa-money-check"></i> Alinan Cekler
  </div>
  <div style="overflow-x:auto;">
    <table class="gd-tah-table tone-indigo">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Musteri</th>
          <th>Aciklama</th>
          <th class="col-right">Tutar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cekRows as $r):
            $tarih = $r['TARIH'] ?? '';
            if ($tarih !== '') { $tarih = (new DateTime($tarih))->format('d.m.Y'); }
        ?>
        <tr>
          <td><?php echo htmlspecialchars($tarih, ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
            <div class="cust-name"><?php echo htmlspecialchars((string) ($r['CARI_UNVANI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="cust-code"><?php echo htmlspecialchars((string) ($r['CARI_KODU'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
          </td>
          <td><?php echo htmlspecialchars((string) ($r['ACIKLAMA'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-right val"><?php echo number_format((float) ($r['TUTAR'] ?? 0), $decimals, ',', '.'); ?> &#8378;</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3">Cek Toplam</td>
          <td class="col-right"><?php echo number_format($topCek, $decimals, ',', '.'); ?> &#8378;</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($senetRows)): ?>
  <div class="tah-section-head tone-purple">
    <i class="fa-solid fa-file-invoice-dollar"></i> Alinan Senetler
  </div>
  <div style="overflow-x:auto;">
    <table class="gd-tah-table tone-purple">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Musteri</th>
          <th>Aciklama</th>
          <th class="col-right">Tutar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($senetRows as $r):
            $tarih = $r['TARIH'] ?? '';
            if ($tarih !== '') { $tarih = (new DateTime($tarih))->format('d.m.Y'); }
        ?>
        <tr>
          <td><?php echo htmlspecialchars($tarih, ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
            <div class="cust-name"><?php echo htmlspecialchars((string) ($r['CARI_UNVANI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="cust-code"><?php echo htmlspecialchars((string) ($r['CARI_KODU'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
          </td>
          <td><?php echo htmlspecialchars((string) ($r['ACIKLAMA'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="col-right val"><?php echo number_format((float) ($r['TUTAR'] ?? 0), $decimals, ',', '.'); ?> &#8378;</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3">Senet Toplam</td>
          <td class="col-right"><?php echo number_format($topSenet, $decimals, ',', '.'); ?> &#8378;</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>

<?php endif; ?>
</div>
