<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");

// JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['id'])) {
    echo json_encode(['success' => false, 'message' => 'Gecersiz istek']);
    exit;
}

// Stok yonetimi yetkisi (M15): urun adi + koli olculeri guncelleniyor.
if ((int) m_p_yetki($terminalkullanici, 'M15') !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bu islem icin yetkiniz yok']);
    exit;
}

// CSRF: istemci token'i JSON govdesinde gonderir.
if (!csrf_verify((string) ($input['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Gecersiz guvenlik dogrulamasi. Sayfayi yenileyin.']);
    exit;
}

$id = (int)$input['id'];
$name = trim($input['name'] ?? '');
$width = (float)($input['width'] ?? 0);
$length = (float)($input['length'] ?? 0);
$height = (float)($input['height'] ?? 0);
$weight = (float)($input['weight'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Gecersiz urun ID']);
    exit;
}

if ($name === '') {
    echo json_encode(['success' => false, 'message' => 'Urun adi bos olamaz']);
    exit;
}

try {
    $dbh->beginTransaction();

    // 1. Urun adini guncelle (ITEMS tablosu)
    $stmtName = $dbh->prepare("UPDATE {$firma}ITEMS SET NAME = :name WHERE LOGICALREF = :id");
    $stmtName->execute([':name' => $name, ':id' => $id]);

    // 2. ITMUNITA kaydinin var olup olmadigini kontrol et
    $stmtCheck = $dbh->prepare("SELECT LOGICALREF, UNITLINEREF FROM {$firma}ITMUNITA WHERE ITEMREF = :id AND LINENR = 1");
    $stmtCheck->execute([':id' => $id]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Guncelle
        $stmtUpdate = $dbh->prepare("
            UPDATE {$firma}ITMUNITA
            SET WIDTH = :width,
                LENGTH = :length,
                HEIGHT = :height,
                WEIGHT = :weight,
                WIDTHREF = CASE WHEN :width2 > 0 THEN 2 ELSE 0 END,
                LENGTHREF = CASE WHEN :length2 > 0 THEN 2 ELSE 0 END,
                HEIGHTREF = CASE WHEN :height2 > 0 THEN 2 ELSE 0 END,
                WEIGHTREF = CASE WHEN :weight2 > 0 THEN 18 ELSE 0 END
            WHERE ITEMREF = :id AND LINENR = 1
        ");
        $stmtUpdate->execute([
            ':width' => $width,
            ':length' => $length,
            ':height' => $height,
            ':weight' => $weight,
            ':width2' => $width,
            ':length2' => $length,
            ':height2' => $height,
            ':weight2' => $weight,
            ':id' => $id
        ]);
    } else {
        // ITMUNITA kaydi yoksa, UNITSETREF ve UNITLINEREF bilgilerini al
        $stmtItem = $dbh->prepare("SELECT UNITSETREF FROM {$firma}ITEMS WHERE LOGICALREF = :id");
        $stmtItem->execute([':id' => $id]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

        if ($item && $item['UNITSETREF'] > 0) {
            // Birim seti satir referansini bul
            $stmtUnitLine = $dbh->prepare("SELECT LOGICALREF FROM {$firma}UNITSETL WHERE UNITSETREF = :ref AND LINENR = 1");
            $stmtUnitLine->execute([':ref' => $item['UNITSETREF']]);
            $unitLine = $stmtUnitLine->fetch(PDO::FETCH_ASSOC);

            if ($unitLine) {
                // Yeni ITMUNITA kaydi olustur
                $stmtInsert = $dbh->prepare("
                    INSERT INTO {$firma}ITMUNITA
                    (ITEMREF, LINENR, UNITLINEREF, WIDTH, LENGTH, HEIGHT, WEIGHT,
                     WIDTHREF, LENGTHREF, HEIGHTREF, WEIGHTREF,
                     MTRLCLAS, PURCHCLAS, SALESCLAS, CONVFACT1, CONVFACT2, RECSTATUS)
                    VALUES
                    (:itemref, 1, :unitlineref, :width, :length, :height, :weight,
                     CASE WHEN :width2 > 0 THEN 2 ELSE 0 END,
                     CASE WHEN :length2 > 0 THEN 2 ELSE 0 END,
                     CASE WHEN :height2 > 0 THEN 2 ELSE 0 END,
                     CASE WHEN :weight2 > 0 THEN 18 ELSE 0 END,
                     1, 1, 1, 1, 1, 2)
                ");
                $stmtInsert->execute([
                    ':itemref' => $id,
                    ':unitlineref' => $unitLine['LOGICALREF'],
                    ':width' => $width,
                    ':length' => $length,
                    ':height' => $height,
                    ':weight' => $weight,
                    ':width2' => $width,
                    ':length2' => $length,
                    ':height2' => $height,
                    ':weight2' => $weight
                ]);
            }
        }
    }

    $dbh->commit();
    echo json_encode(['success' => true, 'message' => 'Basariyla kaydedildi']);

} catch (PDOException $e) {
    $dbh->rollBack();
    error_log("Koli olcu kaydetme hatasi: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Veritabani hatasi: ' . $e->getMessage()]);
}
