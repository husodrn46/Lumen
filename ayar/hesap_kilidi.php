<?php
declare(strict_types=1);

// Bu sayfa ayar/guvenlik.php altında "Hesap Kilidi" sekmesine taşındı.
// Geriye dönük uyumluluk için buraya gelen istekleri yönlendiriyoruz.
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';

header('Location: guvenlik.php?tab=kilit', true, 302);
exit;
