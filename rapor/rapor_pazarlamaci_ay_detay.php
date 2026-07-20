<?php
declare(strict_types=1);

/**
 * AJAX endpoint: Belirli bir ayin satis faturalarini dondurur.
 * Parametreler (POST):
 *   - month: 1-12 arasi ay numarasi
 *   - prefix: Cari kod on eki (ornek: TMÇN)
 *
 * 2026 yili sabittir, satis fisleri (TRCODE 7/8) listelenir.
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");

// Yetki kontrolu
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

/* -----------------------------------------------------------------
 *  Satis faturalari – 2026, secilen ay, TRCODE 7/8
 *  STFICHE: Fatura basliklari, STLINE: Fatura satirlari
 *  Her fatura icin: tarih, fis no, musteri, toplam tutar
 * ----------------------------------------------------------------- */
$sql = "
    SELECT
        F.LOGICALREF AS FICHREF,
        F.FICHENO AS FIS_NO,
        F.DATE_ AS TARIH,
        CL.CODE AS CARI_KODU,
        CL.DEFINITION_ AS CARI_UNVANI,
        ISNULL(F.NETTOTAL, 0) AS NET_TUTAR,
        F.GROSSTOTAL AS BRUT_TUTAR,
        (SELECT COUNT(*) FROM {$firmadonem}STLINE SL2
         WHERE SL2.STFICHEREF = F.LOGICALREF AND SL2.LINETYPE = 0) AS KALEM_SAYISI
    FROM {$firmadonem}STFICHE F WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON F.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND F.TRCODE IN (7, 8)
      AND MONTH(F.DATE_) = :month
      AND YEAR(F.DATE_) = 2026
      AND F.CANCELLED = 0
    ORDER BY F.DATE_ DESC, F.FICHENO DESC
";

$rows = [];
try {
    $stmt = $dbh->prepare($sql);
    $stmt->execute([
        ':prefix' => $cariPrefix . '%',
        ':month'  => $month,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    echo '<p class="text-red-600 text-center p-4">Sorgu hatasi: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

$toplamNet = 0.0;
foreach ($rows as $r) {
    $toplamNet += (float) ($r['NET_TUTAR'] ?? 0);
}

?>
<style>
  .ay-detay-wrap {
    --emerald: #059669;
    --emerald-soft: #ecfdf5;
    --text-1: #1f2937;
    --text-2: #6b7280;
    --text-3: #9ca3af;
    --border: #e5e7eb;
    font-family: 'Avenir Next', 'Montserrat', sans-serif;
  }
  .ay-detay-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 12px; margin-bottom: 12px;
    background: var(--emerald-soft);
    border: 1px solid rgba(5, 150, 105, 0.18);
    border-radius: 12px;
  }
  .ay-detay-head .title { font-size: 14px; font-weight: 700; color: var(--emerald); }
  .ay-detay-head .sub   { font-size: 11.5px; color: var(--text-2); margin-left: 8px; font-weight: 500; }
  .ay-detay-head .count { font-size: 11.5px; color: var(--emerald); font-weight: 700; padding: 4px 10px; background: #fff; border-radius: 100px; border: 1px solid rgba(5, 150, 105, 0.2); }

  .gd-table-inner { width: 100%; border-collapse: collapse; font-size: 13px; font-family: 'Avenir Next', 'Montserrat', sans-serif; }
  .gd-table-inner thead th {
    text-align: left; font-size: 10.5px; font-weight: 700;
    color: var(--text-2); text-transform: uppercase; letter-spacing: 0.4px;
    padding: 10px 12px; border-bottom: 1px solid var(--border);
    background: #fafafa;
  }
  .gd-table-inner tbody td {
    padding: 10px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: middle; color: var(--text-1);
  }
  .gd-table-inner tbody tr:hover { background: var(--emerald-soft); }
  .gd-table-inner .col-right { text-align: right; }
  .gd-table-inner .mono { font-family: 'Courier New', monospace; font-size: 11.5px; color: var(--text-2); }
  .gd-table-inner .cust-name { font-weight: 600; color: var(--text-1); }
  .gd-table-inner .cust-code { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }
  .gd-table-inner .val-money { font-weight: 700; color: var(--emerald); white-space: nowrap; }
  .gd-table-inner tfoot td {
    padding: 12px; background: var(--emerald-soft);
    font-weight: 700; color: var(--emerald);
    border-top: 2px solid rgba(5, 150, 105, 0.25);
  }
  .ay-detay-empty {
    text-align: center; padding: 32px 16px; color: var(--text-3);
    font-size: 12.5px;
  }
  .ay-detay-empty i { font-size: 28px; display: block; margin-bottom: 8px; color: #d1d5db; }
</style>

<div class="ay-detay-wrap">
  <div class="ay-detay-head">
    <div>
      <span class="title"><?php echo htmlspecialchars($monthNames[$month] ?? '', ENT_QUOTES, 'UTF-8'); ?> 2026</span>
      <span class="sub">Toptan Satis Faturalari</span>
    </div>
    <span class="count"><?php echo count($rows); ?> fatura</span>
  </div>

<?php if (empty($rows)): ?>
  <div class="ay-detay-empty">
    <i class="fa-solid fa-inbox"></i>
    Bu ayda satis faturasi bulunamadi.
  </div>
<?php else: ?>
  <div style="overflow-x:auto;">
    <table class="gd-table-inner">
      <thead>
        <tr>
          <th>Tarih</th>
          <th>Fis No</th>
          <th>Musteri</th>
          <th class="col-right">Kalem</th>
          <th class="col-right">Net Tutar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $idx => $r):
            $tarih = $r['TARIH'] ?? '';
            if ($tarih !== '') {
                $dt = new DateTime($tarih);
                $tarih = $dt->format('d.m.Y');
            }
            $net = (float) ($r['NET_TUTAR'] ?? 0);
        ?>
        <tr>
          <td><?php echo htmlspecialchars($tarih, ENT_QUOTES, 'UTF-8'); ?></td>
          <td class="mono"><?php echo htmlspecialchars((string) ($r['FIS_NO'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
          <td>
            <div class="cust-name"><?php echo htmlspecialchars((string) ($r['CARI_UNVANI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="cust-code"><?php echo htmlspecialchars((string) ($r['CARI_KODU'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
          </td>
          <td class="col-right"><?php echo (int) ($r['KALEM_SAYISI'] ?? 0); ?></td>
          <td class="col-right val-money">
            <?php echo number_format($net, $decimals, ',', '.'); ?> &#8378;
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4">TOPLAM</td>
          <td class="col-right">
            <?php echo number_format($toplamNet, $decimals, ',', '.'); ?> &#8378;
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
<?php endif; ?>
</div>
