<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once __DIR__ . "/../ayr.php";                       // DB bağlantısı
header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------------
 *  Parametre ve joker (%) hazırlığı
 * ----------------------------------------------------- */
$raw = isset($_GET['term']) ? trim((string) $_GET['term']) : '';
$like = '%' . lumen_arama_terimi($raw) . '%';

/* -------------------------------------------------------
 *  Sorgu – aktif cariler + arama filtresi
 * ----------------------------------------------------- */
// Özel cari kısıtı: gizlenen cariler aramada da çıkmaz (eskiden uygulanmıyordu).
$ozel = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'C.ID', 'cari_ara_gizli');
$sql = "
  SELECT TOP 20
         C.ID       AS LOGICALREF,
         C.KOD      AS CODE,
         C.UNVAN,
         C.UNVAN2   AS DEFINITION2,
         C.ADRES    AS ADDR1,
         C.ADRES2   AS ADDR2,
         C.ILCE     AS TOWN,
         C.IL       AS CITY,
         C.ULKE     AS COUNTRY
  FROM   " . lumen_tablo('cari') . " C
  WHERE  C.AKTIF = 1
    AND ( " . lumen_arama_sql('C.KOD') . " LIKE :q1
       OR " . lumen_arama_sql('C.UNVAN') . " LIKE :q2
       OR " . lumen_arama_sql('C.UNVAN2') . " LIKE :q3 )" . ($ozel['sql'] !== '' ? "\n    AND {$ozel['sql']}" : '') . "
  ORDER BY C.KOD";

$stmt = $dbh->prepare($sql);
$stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like] + $ozel['params']);

/* -------------------------------------------------------
 *  JSON çıktısı
 * ----------------------------------------------------- */
$out = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $out[] = [
       'id'   => $r['LOGICALREF'],
       'text' => $r['CODE'].' - '.trcevir($r['UNVAN']),
       'addr' => trcevir(trim($r['ADDR1'].' '.$r['ADDR2'])),
       'city' => trcevir($r['CITY']),
       'town' => trcevir($r['TOWN']),
       'ctry' => trcevir($r['COUNTRY']),
       'extra'=> trcevir($r['DEFINITION2'])
    ];
}
// XSS koruması: JSON_HEX_TAG script injection'ı önler
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
