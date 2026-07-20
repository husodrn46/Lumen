<?php
declare(strict_types=1);

// Bu sayfa ayar/guvenlik.php altında "Saldırı Modu" sekmesine taşındı.
// Geriye dönük uyumluluk için buraya gelen istekleri yönlendiriyoruz.
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';

header('Location: guvenlik.php?tab=saldiri', true, 302);
exit;
