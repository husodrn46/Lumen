<?php
declare(strict_types=1);

// Bu sayfanın işlevi loglar.php'ye "Cihazlar" sekmesi olarak taşındı.
// Eski bağlantılar kırılmasın diye burada kalıcı olarak yönlendiriyoruz.
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';

header('Location: ' . APP_ROOT_URL . '/loglar.php?tab=cihaz');
exit;
