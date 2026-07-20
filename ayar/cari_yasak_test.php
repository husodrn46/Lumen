<?php
declare(strict_types=1);

// Bu sayfa artik ozel_cari_kisitlari.php icindeki "Test" sekmesine tasindi.
// Eski linkler kirilmasin diye burasi yonlendirme yapar.

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

$params = ['sekme' => 'test'];

// Eski parametreleri test sekmesinin yeni isimlerine tasima.
if (isset($_GET['personel_id'])) {
    $params['t_personel_id'] = (int) $_GET['personel_id'];
}
if (isset($_GET['cariref'])) {
    $params['t_cariref'] = (int) $_GET['cariref'];
}
if (isset($_GET['q'])) {
    $q = trim((string) $_GET['q']);
    if ($q !== '') {
        $params['t_q'] = $q;
    }
}

header('Location: ozel_cari_kisitlari.php?' . http_build_query($params), true, 301);
exit;
