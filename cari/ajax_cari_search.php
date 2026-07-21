<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once __DIR__ . "/../ayr.php";                       // DB bağlantısı
header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------------
 *  Parametre ve joker (%) hazırlığı
 * ----------------------------------------------------- */
$raw = isset($_GET['term']) ? trim((string) $_GET['term']) : '';
$like = '%' . str_replace(['%', '_'], ['\%','\_'], $raw) . '%';

/* -------------------------------------------------------
 *  Sorgu – aktif cariler + arama filtresi
 * ----------------------------------------------------- */
$sql = "
  SELECT TOP 20
         LOGICALREF,
         CODE,
         DEFINITION_      AS UNVAN,
         DEFINITION2,
         ADDR1,
         ADDR2,
         TOWN,
         CITY,
         COUNTRY
  FROM   {$firma}CLCARD
  WHERE  ACTIVE = 0
    AND ( CODE          LIKE :q
       OR DEFINITION_   LIKE :q
       OR DEFINITION2   LIKE :q )
  ORDER BY CODE";

$stmt = $dbh->prepare($sql);
$stmt->execute([':q' => $like]);

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
