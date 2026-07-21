<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");

header('Content-Type: application/json; charset=utf-8');

// Sadece POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Gecersiz metod']);
    exit;
}

// CSRF kontrol
if (!csrf_verify()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Gecersiz token']);
    exit;
}

// M2 yetki kontrolu (siparisler)
if (m_p_yetki($terminalkullanici, 'M2') != 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'Yetki yok']);
    exit;
}

$fisRef = isset($_POST['fis_ref']) ? (int)$_POST['fis_ref'] : 0;
$odeme  = trim((string)($_POST['odeme'] ?? ''));

$izinli = ['NAKIT', 'KART', 'HAVALE', 'KARMA', ''];
if (!in_array($odeme, $izinli, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Gecersiz odeme sekli']);
    exit;
}

if ($fisRef <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'Gecersiz fis']);
    exit;
}

try {
    // Siparisin gercekten magaza satis (cariid=$magaza_cari) oldugunu kontrol et
    $magaza_cari = (int) ($magaza_cari ?? 0);
    $check = $dbh->prepare("SELECT CLIENTREF FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :ref");
    $check->bindValue(':ref', $fisRef, PDO::PARAM_INT);
    $check->execute();
    $row = $check->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['ok' => false, 'msg' => 'Siparis bulunamadi']);
        exit;
    }

    if ($magaza_cari <= 0 || (int)$row['CLIENTREF'] !== $magaza_cari) {
        echo json_encode(['ok' => false, 'msg' => 'Sadece magaza satisi icin kullanilabilir']);
        exit;
    }

    // DOCODE guncelle
    $upd = $dbh->prepare("UPDATE {$firmadonem}ORFICHE SET DOCODE = :docode WHERE LOGICALREF = :ref");
    $upd->bindValue(':docode', $odeme, PDO::PARAM_STR);
    $upd->bindValue(':ref', $fisRef, PDO::PARAM_INT);
    $upd->execute();

    echo json_encode(['ok' => true, 'odeme' => $odeme]);

} catch (Throwable $e) {
    error_log("ajax_odeme_sekli hatasi: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => 'DB hatasi']);
}
