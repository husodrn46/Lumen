<?php
declare(strict_types=1);

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

if (!function_exists('ozel_cari_test_rozet')) {
    /**
     * Test sekmesi icin Evet/Hayir rozeti uretir.
     */
    function ozel_cari_test_rozet(bool $durum, string $trueText = 'Evet', string $falseText = 'Hayir'): string
    {
        $base = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ';
        if ($durum) {
            return '<span class="' . $base . 'bg-emerald-100 text-emerald-700">' . htmlspecialchars($trueText, ENT_QUOTES, 'UTF-8') . '</span>';
        }

        return '<span class="' . $base . 'bg-red-100 text-red-700">' . htmlspecialchars($falseText, ENT_QUOTES, 'UTF-8') . '</span>';
    }
}

if (!function_exists('ozel_cari_test_aciklama')) {
    /**
     * Test sonucunu insanlarin okuyabilecegi bir nota cevirir.
     */
    function ozel_cari_test_aciklama(
        bool $sayfaErisimi,
        bool $globalKural,
        bool $kullaniciKurali,
        bool $gorunur,
        bool $bypass,
        string $modulAdi
    ): string {
        if (!$sayfaErisimi) {
            return $modulAdi . ' sayfasina giris yetkisi yok.';
        }
        if ($kullaniciKurali) {
            return 'Kullaniciya ozel yasak kaydi var.';
        }
        if ($globalKural && !$gorunur) {
            return 'Tum Kullanicilar kuralina takiliyor.';
        }
        if ($globalKural && $gorunur && $bypass) {
            return 'Global kural var ama kullanici bypass hakkina sahip.';
        }
        if ($gorunur) {
            return 'Bu modulde gorunebilir.';
        }

        return 'Kisit nedeniyle gorunemiyor.';
    }
}

$initError = null;

try {
    m_p_ozel_cari_tablosu_olustur($dbh);
} catch (Throwable $e) {
    $initError = $e->getMessage();
}

$kisitKodlari = m_p_ozel_cari_kisit_yetki_kodlari();
$globalPersonelId = m_p_ozel_cari_global_personel_id();

$personelStmt = $dbh->prepare("
    SELECT
        S.LOGICALREF,
        S.CODE,
        S.DEFINITION_,
        S.ACTIVE,
        ISNULL(Y.YETKI, 1) AS YETKI_TURU
    FROM LG_SLSMAN S
    LEFT JOIN M_P_YETKI Y ON Y.PERSONEL = S.LOGICALREF
    WHERE S.FIRMNR = :firma
      AND ISNULL(Y.YETKI, 1) IN (0, 1)
    ORDER BY S.ACTIVE ASC, S.CODE ASC, S.DEFINITION_ ASC
");
$personelStmt->execute([':firma' => (int) $firmano]);
$personelRows = $personelStmt->fetchAll(PDO::FETCH_ASSOC);

$personelSecenekleri = [[
    'LOGICALREF' => $globalPersonelId,
    'CODE' => '*',
    'DEFINITION_' => 'Tum Kullanicilar',
    'ACTIVE' => 0,
    'YETKI_TURU' => -1,
]];

foreach ($personelRows as $row) {
    $personelSecenekleri[] = $row;
}

$varsayilanPersonelId = isset($personelSecenekleri[1]['LOGICALREF'])
    ? (int) $personelSecenekleri[1]['LOGICALREF']
    : $globalPersonelId;

$personelMap = [];
foreach ($personelSecenekleri as $row) {
    $personelMap[(int) $row['LOGICALREF']] = $row;
}

$selectedPersonelId = isset($_REQUEST['personel_id'])
    ? m_p_ozel_cari_kisit_personel_normalize($_REQUEST['personel_id'])
    : $varsayilanPersonelId;
if (!isset($personelMap[$selectedPersonelId])) {
    $selectedPersonelId = $varsayilanPersonelId;
}

$selectedPersonel = $personelMap[$selectedPersonelId] ?? $personelSecenekleri[0];
$selectedPersonelLabel = ((int) $selectedPersonel['LOGICALREF'] === $globalPersonelId)
    ? 'Tum Kullanicilar'
    : trim((string) (($selectedPersonel['CODE'] ?? '') . ' - ' . ($selectedPersonel['DEFINITION_'] ?? '')));

$buildPageUrl = static function (int $personelId, ?string $status = null, string $search = ''): string {
    $params = ['personel_id' => $personelId];
    if ($search !== '') {
        $params['q'] = $search;
    }
    if ($status !== null && $status !== '') {
        $params['durum'] = $status;
    }

    return 'ozel_cari_kisitlari.php?' . http_build_query($params);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ayar_require_csrf();

    if ($initError !== null) {
        header('Location: ' . $buildPageUrl($selectedPersonelId, 'tablo_hata'), true, 303);
        exit;
    }

    $action = trim((string) $_POST['action']);
    $cariRef = isset($_POST['cariref']) ? (int) $_POST['cariref'] : 0;
    $postedPersonelId = isset($_POST['personel_id'])
        ? m_p_ozel_cari_kisit_personel_normalize($_POST['personel_id'])
        : $selectedPersonelId;
    $postedSearch = trim((string) ($_POST['q'] ?? ''));

    if (!isset($personelMap[$postedPersonelId])) {
        header('Location: ' . $buildPageUrl($selectedPersonelId, 'gecersiz_kullanici', $postedSearch), true, 303);
        exit;
    }

    if ($cariRef <= 0) {
        header('Location: ' . $buildPageUrl($postedPersonelId, 'gecersiz_cari', $postedSearch), true, 303);
        exit;
    }

    if ($action === 'add') {
        $stmtCari = $dbh->prepare("
            SELECT TOP 1 LOGICALREF
            FROM {$firma}CLCARD WITH(NOLOCK)
            WHERE LOGICALREF = :cariref AND ACTIVE = 0
        ");
        $stmtCari->execute([':cariref' => $cariRef]);
        $exists = (bool) $stmtCari->fetchColumn();

        if (!$exists) {
            header('Location: ' . $buildPageUrl($postedPersonelId, 'cari_bulunamadi', $postedSearch), true, 303);
            exit;
        }

        foreach ($kisitKodlari as $yetkiKodu) {
            $stmtExists = $dbh->prepare("
                SELECT COUNT(*)
                FROM " . m_p_ozel_cari_tablo_adi() . "
                WHERE PERSONEL_ID = :personel_id
                  AND CARIREF = :cariref
                  AND YETKI_KODU = :yetki_kodu
            ");
            $stmtExists->execute([
                ':personel_id' => $postedPersonelId,
                ':cariref' => $cariRef,
                ':yetki_kodu' => $yetkiKodu,
            ]);
            $alreadyAdded = (int) $stmtExists->fetchColumn() > 0;

            if ($alreadyAdded) {
                continue;
            }

            $stmtInsert = $dbh->prepare("
                INSERT INTO " . m_p_ozel_cari_tablo_adi() . " (PERSONEL_ID, CARIREF, YETKI_KODU, OLUSTURAN)
                VALUES (:personel_id, :cariref, :yetki_kodu, :olusturan)
            ");
            $stmtInsert->execute([
                ':personel_id' => $postedPersonelId,
                ':cariref' => $cariRef,
                ':yetki_kodu' => $yetkiKodu,
                ':olusturan' => (int) $terminalkullanici,
            ]);
        }

        m_p_ozel_cari_kisit_cache_temizle();
        header('Location: ' . $buildPageUrl($postedPersonelId, 'eklendi', $postedSearch), true, 303);
        exit;
    }

    if ($action === 'remove') {
        $stmtDelete = $dbh->prepare("
            DELETE FROM " . m_p_ozel_cari_tablo_adi() . "
            WHERE PERSONEL_ID = :personel_id AND CARIREF = :cariref
        ");
        $stmtDelete->execute([
            ':personel_id' => $postedPersonelId,
            ':cariref' => $cariRef,
        ]);

        m_p_ozel_cari_kisit_cache_temizle();
        header('Location: ' . $buildPageUrl($postedPersonelId, 'silindi', $postedSearch), true, 303);
        exit;
    }

    header('Location: ' . $buildPageUrl($postedPersonelId, 'gecersiz_islem', $postedSearch), true, 303);
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100, 'UTF-8') : substr($search, 0, 100);
$hiddenCaris = [];
$globalHiddenCaris = [];
$searchResults = [];
$globalRuleCount = 0;

if ($initError === null && m_p_ozel_cari_tablosu_var_mi($dbh)) {
    $hiddenStmt = $dbh->prepare("
        SELECT
            K.PERSONEL_ID,
            K.CARIREF,
            K.YETKI_KODU,
            K.OLUSTURMA_TARIHI,
            C.CODE,
            C.DEFINITION_,
            C.CITY,
            S.CODE AS EKLEYEN_KOD,
            S.DEFINITION_ AS EKLEYEN_ADI
        FROM " . m_p_ozel_cari_tablo_adi() . " K
        LEFT JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = K.CARIREF
        LEFT JOIN LG_SLSMAN S WITH(NOLOCK) ON S.LOGICALREF = K.OLUSTURAN
        WHERE K.PERSONEL_ID = :personel_id
        ORDER BY ISNULL(C.CODE, ''), ISNULL(C.DEFINITION_, ''), ISNULL(K.YETKI_KODU, '')
    ");
    $hiddenStmt->execute([':personel_id' => $selectedPersonelId]);
    $hiddenRows = $hiddenStmt->fetchAll(PDO::FETCH_ASSOC);
    $hiddenCarisMap = [];

    foreach ($hiddenRows as $row) {
        $cariRef = (int) ($row['CARIREF'] ?? 0);
        if ($cariRef <= 0) {
            continue;
        }

        if (!isset($hiddenCarisMap[$cariRef])) {
            $hiddenCarisMap[$cariRef] = $row;
            $hiddenCarisMap[$cariRef]['YETKI_KODLARI'] = [];
        }

        $yetkiKodu = strtoupper(trim((string) ($row['YETKI_KODU'] ?? '')));
        if ($yetkiKodu !== '' && !in_array($yetkiKodu, $hiddenCarisMap[$cariRef]['YETKI_KODLARI'], true)) {
            $hiddenCarisMap[$cariRef]['YETKI_KODLARI'][] = $yetkiKodu;
        }
    }

    $hiddenCaris = array_values($hiddenCarisMap);

    if ($selectedPersonelId !== $globalPersonelId) {
        $globalRuleStmt = $dbh->query("SELECT COUNT(DISTINCT CARIREF) FROM " . m_p_ozel_cari_tablo_adi() . " WHERE PERSONEL_ID = {$globalPersonelId}");
        $globalRuleCount = (int) $globalRuleStmt->fetchColumn();

        if ($globalRuleCount > 0) {
            $globalHiddenStmt = $dbh->prepare("
                SELECT
                    K.PERSONEL_ID,
                    K.CARIREF,
                    K.YETKI_KODU,
                    K.OLUSTURMA_TARIHI,
                    C.CODE,
                    C.DEFINITION_,
                    C.CITY,
                    S.CODE AS EKLEYEN_KOD,
                    S.DEFINITION_ AS EKLEYEN_ADI
                FROM " . m_p_ozel_cari_tablo_adi() . " K
                LEFT JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = K.CARIREF
                LEFT JOIN LG_SLSMAN S WITH(NOLOCK) ON S.LOGICALREF = K.OLUSTURAN
                WHERE K.PERSONEL_ID = :personel_id
                ORDER BY ISNULL(C.CODE, ''), ISNULL(C.DEFINITION_, ''), ISNULL(K.YETKI_KODU, '')
            ");
            $globalHiddenStmt->execute([':personel_id' => $globalPersonelId]);
            $globalRows = $globalHiddenStmt->fetchAll(PDO::FETCH_ASSOC);
            $globalHiddenMap = [];

            foreach ($globalRows as $row) {
                $cariRef = (int) ($row['CARIREF'] ?? 0);
                if ($cariRef <= 0) {
                    continue;
                }

                if (!isset($globalHiddenMap[$cariRef])) {
                    $globalHiddenMap[$cariRef] = $row;
                    $globalHiddenMap[$cariRef]['YETKI_KODLARI'] = [];
                }

                $yetkiKodu = strtoupper(trim((string) ($row['YETKI_KODU'] ?? '')));
                if ($yetkiKodu !== '' && !in_array($yetkiKodu, $globalHiddenMap[$cariRef]['YETKI_KODLARI'], true)) {
                    $globalHiddenMap[$cariRef]['YETKI_KODLARI'][] = $yetkiKodu;
                }
            }

            $globalHiddenCaris = array_values($globalHiddenMap);
        }
    }

    if ($search !== '') {
        $normalizedSearch = turkce($search);
        $like = '%' . $normalizedSearch . '%';

        $searchStmt = $dbh->prepare("
            SELECT TOP 40
                C.LOGICALREF,
                C.CODE,
                C.DEFINITION_,
                C.CITY,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM " . m_p_ozel_cari_tablo_adi() . " K
                    WHERE K.CARIREF = C.LOGICALREF
                      AND K.PERSONEL_ID = :personel_id
                ) THEN 1 ELSE 0 END AS LISTEDE
                ,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM " . m_p_ozel_cari_tablo_adi() . " K
                    WHERE K.CARIREF = C.LOGICALREF
                      AND K.PERSONEL_ID = :global_personel_id
                ) THEN 1 ELSE 0 END AS GLOBAL_LISTEDE
            FROM {$firma}CLCARD C WITH(NOLOCK)
            WHERE C.ACTIVE = 0
              AND (
                   REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                     C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                     COLLATE Turkish_CI_AS LIKE :p1
                OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                     C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                     COLLATE Turkish_CI_AS LIKE :p2
                OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                     C.CITY, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                     COLLATE Turkish_CI_AS LIKE :p3
              )
            ORDER BY LISTEDE DESC, C.CODE ASC, C.DEFINITION_ ASC
        ");
        $searchStmt->execute([
            ':personel_id' => $selectedPersonelId,
            ':global_personel_id' => $globalPersonelId,
            ':p1' => $like,
            ':p2' => $like,
            ':p3' => $like,
        ]);
        $searchResults = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$statusText = match ($_GET['durum'] ?? '') {
    'eklendi' => ['type' => 'success', 'text' => 'Cari secilen kullanici icin yasak listesine eklendi.'],
    'silindi' => ['type' => 'success', 'text' => 'Cari secilen kullanicinin yasak listesinden cikarildi.'],
    'gecersiz_cari' => ['type' => 'error', 'text' => 'Gecerli bir cari secilmedi.'],
    'cari_bulunamadi' => ['type' => 'error', 'text' => 'Cari bulunamadi veya pasif durumda.'],
    'gecersiz_kullanici' => ['type' => 'error', 'text' => 'Gecerli bir kullanici secilmedi.'],
    'gecersiz_islem' => ['type' => 'error', 'text' => 'Gecersiz islem istegi.'],
    'tablo_hata' => ['type' => 'error', 'text' => 'Ozel cari tablosu hazirlanamadi.'],
    default => null,
};

$totalHidden = count($hiddenCaris);
$isGlobalView = ($selectedPersonelId === $globalPersonelId);

// ---------------------------------------------------------------------------
// TEST SEKMESI: kullanici + cari secip hangi yasak kuralinin devreye girdigini
// gosteren teshis araci. Liste gorunumuyle catismamasi icin "t_" onekli
// parametreler ve "sekme=test" anahtari kullanilir.
// ---------------------------------------------------------------------------
$aktifSekme = (($_GET['sekme'] ?? '') === 'test') ? 'test' : 'kurallar';

$testPersonelId = isset($_GET['t_personel_id'])
    ? m_p_ozel_cari_kisit_personel_normalize($_GET['t_personel_id'])
    : ((int) ($terminalkullanici ?? 0));
if (!isset($personelMap[$testPersonelId])) {
    $testPersonelId = $varsayilanPersonelId;
}

$testSelectedCariRef = isset($_GET['t_cariref']) ? (int) $_GET['t_cariref'] : 0;
$testSearch = trim((string) ($_GET['t_q'] ?? ''));
$testSearch = function_exists('mb_substr') ? mb_substr($testSearch, 0, 100, 'UTF-8') : substr($testSearch, 0, 100);

$testSelectedPersonel = $personelMap[$testPersonelId] ?? null;
$testIsGlobalSelected = $testPersonelId === $globalPersonelId;
$testSelectedPersonelLabel = $testSelectedPersonel
    ? ($testIsGlobalSelected
        ? 'Tum Kullanicilar (Global)'
        : trim((string) (($testSelectedPersonel['CODE'] ?? '') . ' - ' . ($testSelectedPersonel['DEFINITION_'] ?? ''))))
    : 'Kullanici bulunamadi';

$testSearchResults = [];
$testSelectedCari = null;
$testSonucu = null;
$testCopyText = '';

if ($aktifSekme === 'test' && $testSearch !== '') {
    $normalizedTestSearch = turkce($testSearch);
    $testLike = '%' . $normalizedTestSearch . '%';

    $testSearchStmt = $dbh->prepare("
        SELECT TOP 40
            C.LOGICALREF,
            C.CODE,
            C.DEFINITION_,
            C.CITY
        FROM {$firma}CLCARD C WITH(NOLOCK)
        WHERE C.ACTIVE = 0
          AND (
               REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p1
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p2
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                 C.CITY, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
                 COLLATE Turkish_CI_AS LIKE :p3
          )
        ORDER BY C.CODE ASC, C.DEFINITION_ ASC
    ");
    $testSearchStmt->execute([
        ':p1' => $testLike,
        ':p2' => $testLike,
        ':p3' => $testLike,
    ]);
    $testSearchResults = $testSearchStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($aktifSekme === 'test' && $testSelectedCariRef > 0) {
    $testCariStmt = $dbh->prepare("
        SELECT TOP 1
            C.LOGICALREF,
            C.CODE,
            C.DEFINITION_,
            C.CITY,
            C.ACTIVE
        FROM {$firma}CLCARD C WITH(NOLOCK)
        WHERE C.LOGICALREF = :cariref
    ");
    $testCariStmt->execute([':cariref' => $testSelectedCariRef]);
    $testSelectedCari = $testCariStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($testSelectedCari !== null && $testSelectedPersonel !== null && !$testIsGlobalSelected) {
        $isAdmin = (int) m_p_yetki($testPersonelId, 'YETKI') === 0;
        $bakiyeSayfaErisimi = m_p_bakiye_erisim_var_mi($testPersonelId);
        $siparisSayfaErisimi = (int) m_p_yetki($testPersonelId, 'M2') === 1;
        $m19Yetkisi = (int) m_p_yetki($testPersonelId, 'M19') === 1;
        $tamBypass = m_p_ozel_cari_tam_bypass_var_mi($testPersonelId);

        $globalM4 = m_p_ozel_cari_ref_kisitli_mi($dbh, $testSelectedCariRef, 'M4', $globalPersonelId, false);
        $globalM19 = m_p_ozel_cari_ref_kisitli_mi($dbh, $testSelectedCariRef, 'M19', $globalPersonelId, false);
        $userM4 = m_p_ozel_cari_kullaniciya_ozel_yasak_var_mi($dbh, $testPersonelId, $testSelectedCariRef, 'M4');
        $userM19 = m_p_ozel_cari_kullaniciya_ozel_yasak_var_mi($dbh, $testPersonelId, $testSelectedCariRef, 'M19');

        $bypassM4 = m_p_ozel_cari_kisit_yetki_bypass_var_mi($testPersonelId, 'M4');
        $bypassM19 = m_p_ozel_cari_kisit_yetki_bypass_var_mi($testPersonelId, 'M19');

        $bakiyeGorunur = m_p_cariid_goruntulebilir_mi($dbh, $firma, $testPersonelId, $testSelectedCariRef, 'M4');
        $siparisGorunur = m_p_cariid_goruntulebilir_mi($dbh, $firma, $testPersonelId, $testSelectedCariRef, 'M19');

        // SQL filtre testi
        $sqlFiltreM4 = m_p_ozel_cari_sql_filtresi($testPersonelId, 'C.LOGICALREF', 'tf_m4', 'M4');
        $sqlFiltreM19 = m_p_ozel_cari_sql_filtresi($testPersonelId, 'C.LOGICALREF', 'tf_m19', 'M19');

        // CR1/CR4 yetki kontrolleri
        $cr1Yetkisi = (int) m_p_yetki($testPersonelId, 'CR1') === 1;
        $cr4Yetkisi = (int) m_p_yetki($testPersonelId, 'CR4') === 1;

        // Kisitli cari sayilari
        $kisitliReflerM4 = m_p_ozel_cari_kisitli_refler_getir($dbh, 'M4', $testPersonelId, true);
        $kisitliReflerM19 = m_p_ozel_cari_kisitli_refler_getir($dbh, 'M19', $testPersonelId, true);

        $testSonucu = [
            'is_admin' => $isAdmin,
            'tam_bypass' => $tamBypass,
            'bakiye_sayfa_erisimi' => $bakiyeSayfaErisimi,
            'siparis_sayfa_erisimi' => $siparisSayfaErisimi,
            'm19_yetkisi' => $m19Yetkisi,
            'bypass_m4' => $bypassM4,
            'bypass_m19' => $bypassM19,
            'global_m4' => $globalM4,
            'global_m19' => $globalM19,
            'user_m4' => $userM4,
            'user_m19' => $userM19,
            'bakiye_gorunur' => $bakiyeGorunur,
            'siparis_gorunur' => $siparisGorunur,
            'bakiye_not' => ozel_cari_test_aciklama($bakiyeSayfaErisimi, $globalM4, $userM4, $bakiyeGorunur, $bypassM4, 'Bakiye'),
            'siparis_not' => ozel_cari_test_aciklama($siparisSayfaErisimi, $globalM19, $userM19, $siparisGorunur, $bypassM19, 'Siparis'),
            'sql_m4_aktif' => $sqlFiltreM4['sql'] !== '',
            'sql_m4_kisitli_adet' => count($kisitliReflerM4),
            'sql_m19_aktif' => $sqlFiltreM19['sql'] !== '',
            'sql_m19_kisitli_adet' => count($kisitliReflerM19),
            'cr1_yetkisi' => $cr1Yetkisi,
            'cr4_yetkisi' => $cr4Yetkisi,
        ];

        $copyLines = [
            'Kullanici: ' . $testSelectedPersonelLabel,
            'Cari: ' . (string) ($testSelectedCari['CODE'] ?? '') . ' - ' . (string) ($testSelectedCari['DEFINITION_'] ?? ''),
            'Admin: ' . ($isAdmin ? 'Evet' : 'Hayir'),
            'Tam Bypass: ' . ($tamBypass ? 'Evet' : 'Hayir'),
            '',
            '--- Bakiye Modulu (M4) ---',
            'Bakiye Gorunur: ' . ($bakiyeGorunur ? 'Evet' : 'Hayir'),
            'Sayfa Erisimi (M4/M20): ' . ($bakiyeSayfaErisimi ? 'Evet' : 'Hayir'),
            'Global Kural: ' . ($globalM4 ? 'Evet' : 'Hayir'),
            'Kullanici Ozel Kural: ' . ($userM4 ? 'Evet' : 'Hayir'),
            'Bypass: ' . ($bypassM4 ? 'Evet' : 'Hayir'),
            'SQL Filtre Aktif: ' . ($sqlFiltreM4['sql'] !== '' ? 'Evet (' . count($kisitliReflerM4) . ' cari)' : 'Hayir'),
            'Not: ' . $testSonucu['bakiye_not'],
            '',
            '--- Siparis Modulu (M19) ---',
            'Siparis Gorunur: ' . ($siparisGorunur ? 'Evet' : 'Hayir'),
            'Sayfa Erisimi (M2): ' . ($siparisSayfaErisimi ? 'Evet' : 'Hayir'),
            'M19 Yetkisi: ' . ($m19Yetkisi ? 'Evet' : 'Hayir'),
            'Global Kural: ' . ($globalM19 ? 'Evet' : 'Hayir'),
            'Kullanici Ozel Kural: ' . ($userM19 ? 'Evet' : 'Hayir'),
            'Bypass: ' . ($bypassM19 ? 'Evet' : 'Hayir'),
            'SQL Filtre Aktif: ' . ($sqlFiltreM19['sql'] !== '' ? 'Evet (' . count($kisitliReflerM19) . ' cari)' : 'Hayir'),
            'Not: ' . $testSonucu['siparis_not'],
            '',
            '--- Ek Yetkiler ---',
            'CR1 (Bakiye Goruntuleme): ' . ($cr1Yetkisi ? 'Evet' : 'Hayir'),
            'CR4 (Ozel Islem): ' . ($cr4Yetkisi ? 'Evet' : 'Hayir'),
        ];
        $testCopyText = implode(PHP_EOL, $copyLines);
    } elseif ($testSelectedCari !== null && $testIsGlobalSelected) {
        $globalM4 = m_p_ozel_cari_ref_kisitli_mi($dbh, $testSelectedCariRef, 'M4', $globalPersonelId, false);
        $globalM19 = m_p_ozel_cari_ref_kisitli_mi($dbh, $testSelectedCariRef, 'M19', $globalPersonelId, false);
        $kisitliReflerM4Global = m_p_ozel_cari_kisitli_refler_getir($dbh, 'M4', $globalPersonelId, false);
        $kisitliReflerM19Global = m_p_ozel_cari_kisitli_refler_getir($dbh, 'M19', $globalPersonelId, false);

        $testSonucu = [
            'is_admin' => false,
            'tam_bypass' => false,
            'bakiye_sayfa_erisimi' => true,
            'siparis_sayfa_erisimi' => true,
            'm19_yetkisi' => false,
            'bypass_m4' => false,
            'bypass_m19' => false,
            'global_m4' => $globalM4,
            'global_m19' => $globalM19,
            'user_m4' => false,
            'user_m19' => false,
            'bakiye_gorunur' => !$globalM4,
            'siparis_gorunur' => !$globalM19,
            'bakiye_not' => $globalM4 ? 'Bu cari global M4 kuralinda: tum kullanicilar icin bakiye gizli.' : 'Global M4 kurali yok, bakiye gorunur.',
            'siparis_not' => $globalM19 ? 'Bu cari global M19 kuralinda: tum kullanicilar icin siparis gizli.' : 'Global M19 kurali yok, siparis gorunur.',
            'sql_m4_aktif' => false,
            'sql_m4_kisitli_adet' => count($kisitliReflerM4Global),
            'sql_m19_aktif' => false,
            'sql_m19_kisitli_adet' => count($kisitliReflerM19Global),
            'cr1_yetkisi' => false,
            'cr4_yetkisi' => false,
        ];

        $copyLines = [
            'Kullanici: Tum Kullanicilar (Global)',
            'Cari: ' . (string) ($testSelectedCari['CODE'] ?? '') . ' - ' . (string) ($testSelectedCari['DEFINITION_'] ?? ''),
            '',
            '--- Global Kurallar ---',
            'M4 Global Yasak: ' . ($globalM4 ? 'Evet' : 'Hayir'),
            'M19 Global Yasak: ' . ($globalM19 ? 'Evet' : 'Hayir'),
            'Toplam M4 Kisitli Cari: ' . count($kisitliReflerM4Global),
            'Toplam M19 Kisitli Cari: ' . count($kisitliReflerM19Global),
        ];
        $testCopyText = implode(PHP_EOL, $copyLines);
    }
}

// Modul bazli etkilenen sayfa listesi (test sekmesi)
$testModulHaritasi = [
    'M4' => [
        'baslik' => 'Bakiye Modulu',
        'sayfalar' => [
            ['ad' => 'lg_bakiye.php', 'tur' => 'SQL Filtre', 'aciklama' => 'Bakiye arama listesinde gizlenir'],
            ['ad' => 'lg_bakiyex.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Detay sayfasinda erisim engellenir'],
            ['ad' => 'lg_hareket.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Hareket gecmisi engellenir'],
            ['ad' => 'lg_nakit.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Nakit islem sayfasinda engellenir'],
            ['ad' => 'lg_nakit_2025.php', 'tur' => 'Tek Kayit', 'aciklama' => '2025 nakit sayfasinda engellenir'],
        ],
    ],
    'M19' => [
        'baslik' => 'Siparis / Cari Modulu',
        'sayfalar' => [
            ['ad' => 'cari.php', 'tur' => 'SQL Filtre', 'aciklama' => 'Cari listesinde gizlenir'],
            ['ad' => 'lg_essiparis.php', 'tur' => 'SQL Filtre', 'aciklama' => 'Siparis listesinde gizlenir'],
            ['ad' => 'lg_tumsiparisler.php', 'tur' => 'SQL Filtre', 'aciklama' => 'Tum siparisler listesinde gizlenir'],
            ['ad' => 'lg_fis.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Siparis fisi acilirken engellenir'],
            ['ad' => 'lg_siparis.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Siparis detayinda engellenir'],
            ['ad' => 'fisekle.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Fis ekleme/duzenleme engellenir'],
            ['ad' => 'fisyaz.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Fatura yazdirmada engellenir'],
            ['ad' => 'barkodluyaz.php', 'tur' => 'Tek Kayit', 'aciklama' => 'Barkodlu yazdirmada engellenir'],
        ],
    ],
];

$testErisimVar = $testSonucu !== null && ($testSonucu['bakiye_gorunur'] || $testSonucu['siparis_gorunur']);

// Sekme baglantilari icin yardimci: liste/test ortak personel & arama korunur.
$buildTabUrl = static function (string $sekme) use ($selectedPersonelId, $search, $testPersonelId, $testSearch, $testSelectedCariRef): string {
    if ($sekme === 'test') {
        $params = ['sekme' => 'test', 't_personel_id' => $testPersonelId];
        if ($testSearch !== '') {
            $params['t_q'] = $testSearch;
        }
        if ($testSelectedCariRef > 0) {
            $params['t_cariref'] = $testSelectedCariRef;
        }
    } else {
        $params = ['personel_id' => $selectedPersonelId];
        if ($search !== '') {
            $params['q'] = $search;
        }
    }

    return 'ozel_cari_kisitlari.php?' . http_build_query($params);
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ozel Cari Kisitlari</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        @keyframes cardIn {
            0% { opacity: 0; transform: translateY(8px); }
            100% { opacity: 1; transform: translateY(0); }
        }

        /* Sticky header — INDIGO tone */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(99, 102, 241, 0.18);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.05);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: var(--indigo-soft); color: var(--indigo); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--indigo-soft); color: var(--indigo);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .header-titles { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1 1 auto; }
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
        }
        .header-subtitle {
            font-size: 11.5px; color: var(--text-2);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .header-actions {
            display: inline-flex; align-items: center; gap: 8px;
            flex-shrink: 0;
        }
        .header-link {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 14px;
            background: var(--indigo-soft);
            color: var(--indigo);
            border: 1px solid rgba(99,102,241,0.22);
            border-radius: 10px;
            font-size: 12.5px; font-weight: 700;
            text-decoration: none;
            transition: all 0.18s ease;
        }
        .header-link:hover { background: rgba(99,102,241,0.15); }

        main {
            max-width: 1200px; margin: 0 auto;
            padding: 20px 24px 60px;
        }

        /* Glass card */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(99, 102, 241, 0.16);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: opacity, transform;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* Alert */
        .alert-bar {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 13px; font-weight: 500;
            margin-bottom: 14px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .alert-bar.ok { background: var(--emerald-soft); color: var(--emerald); border: 1px solid rgba(5,150,105,0.25); }
        .alert-bar.err { background: var(--red-soft); color: var(--red); border: 1px solid rgba(239,68,68,0.25); }
        .alert-bar i { font-size: 14px; flex-shrink: 0; }

        /* Filter panel */
        .filter-panel {
            padding: 16px;
            margin-bottom: 14px;
            animation-delay: 0.05s;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1.2fr 1.6fr auto;
            gap: 12px; align-items: end;
        }
        .field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .field label {
            font-size: 10.5px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .field select,
        .field input[type="text"] {
            width: 100%;
            padding: 11px 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 16px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            transition: all 0.18s ease;
            min-height: 44px;
        }
        @media (min-width: 640px) {
            .field select,
            .field input[type="text"] { font-size: 13px; }
        }
        .field select:focus,
        .field input[type="text"]:focus {
            border-color: rgba(99,102,241,0.5);
            box-shadow: 0 0 0 3px rgba(99,102,241,0.12);
        }
        .search-wrap { position: relative; }
        .search-wrap i.fa-search {
            position: absolute; top: 50%; left: 14px;
            transform: translateY(-50%);
            color: var(--text-3); font-size: 13px;
            pointer-events: none;
        }
        .search-wrap input { padding-left: 38px !important; }

        .btn-filter {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 11px 22px;
            background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
            color: #fff;
            border: 1px solid var(--indigo);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px; font-weight: 700;
            cursor: pointer; transition: all 0.18s ease;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
            height: 44px;
            min-width: 44px;
        }
        .btn-filter:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(79,70,229,0.3); filter: brightness(1.03); }

        /* Stat mini-cards */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }
        .stat-card {
            padding: 14px 16px;
            background: #fff;
            border-radius: 12px;
            border: 1px solid var(--border);
            display: flex; align-items: center; gap: 12px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: opacity, transform;
        }
        .stat-card.ind { border-color: rgba(79,70,229,0.22); background: linear-gradient(135deg, var(--indigo-soft) 0%, #fff 60%); animation-delay: 0.08s; }
        .stat-card.am { border-color: rgba(217,119,6,0.22); background: linear-gradient(135deg, var(--amber-soft) 0%, #fff 60%); animation-delay: 0.14s; }
        .stat-card.em { border-color: rgba(5,150,105,0.22); background: linear-gradient(135deg, var(--emerald-soft) 0%, #fff 60%); animation-delay: 0.20s; }
        .stat-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0;
        }
        .stat-card.ind .stat-icon { background: rgba(79,70,229,0.12); color: var(--indigo); }
        .stat-card.am .stat-icon { background: rgba(217,119,6,0.12); color: var(--amber); }
        .stat-card.em .stat-icon { background: rgba(5,150,105,0.12); color: var(--emerald); }
        .stat-body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .stat-val { font-size: 20px; font-weight: 700; color: var(--text-1); line-height: 1; }
        .stat-label { font-size: 11px; color: var(--text-2); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }

        /* Section wrap */
        .section-card {
            margin-bottom: 14px;
            overflow: hidden;
        }
        .section-head {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            background: linear-gradient(180deg, #fff, #f8faff);
        }
        .section-head .title-wrap { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .section-head h2 {
            font-size: 14px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .section-head h2 i { color: var(--indigo); font-size: 13px; }
        .section-head .subt { font-size: 11.5px; color: var(--text-2); }

        .badge-count {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            background: var(--indigo-soft); color: var(--indigo);
            border: 1px solid rgba(99,102,241,0.22);
            border-radius: 100px;
            font-size: 12px; font-weight: 700;
        }
        .badge-count.am { background: var(--amber-soft); color: var(--amber); border-color: rgba(217,119,6,0.22); }

        /* Table */
        .table-wrap { overflow-x: auto; }
        .gd-table { width: 100%; border-collapse: collapse; }
        .gd-table thead { background: linear-gradient(180deg, #fff, #f8faff); }
        .gd-table thead th {
            padding: 11px 14px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .gd-table thead th.right { text-align: right; }
        .gd-table tbody td {
            padding: 11px 14px;
            font-size: 13px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--indigo-soft); }
        .gd-table tbody tr:last-child td { border-bottom: none; }

        .cari-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .cari-code {
            font-size: 11px; color: var(--text-2);
            font-family: 'JetBrains Mono', ui-monospace, monospace;
            font-weight: 600;
        }
        .cari-name {
            font-weight: 600; color: var(--text-1);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 360px;
        }
        .muted { color: var(--text-2); font-size: 12px; }

        .chip-pill {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 100px;
            font-size: 10.5px; font-weight: 700;
            letter-spacing: 0.3px;
            margin-right: 4px; margin-bottom: 2px;
        }
        .chip-pill.ind { background: var(--indigo-soft); color: var(--indigo); border: 1px solid rgba(99,102,241,0.22); }
        .chip-pill.am { background: var(--amber-soft); color: var(--amber); border: 1px solid rgba(217,119,6,0.22); }
        .chip-pill.slate { background: #f3f4f6; color: var(--text-2); border: 1px solid var(--border); }

        .btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 44px; min-height: 44px;
            padding: 0 12px;
            border-radius: 9px;
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-2);
            text-decoration: none; cursor: pointer;
            transition: all 0.15s ease;
            font-size: 12.5px; font-weight: 600;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            gap: 7px;
        }
        .btn-icon:hover { border-color: var(--indigo); color: var(--indigo); background: var(--indigo-soft); }
        .btn-icon.danger:hover { border-color: var(--red); color: var(--red); background: var(--red-soft); }
        .btn-icon.em:hover { border-color: var(--emerald); color: var(--emerald); background: var(--emerald-soft); }

        .row-actions { display: inline-flex; align-items: center; gap: 6px; justify-content: flex-end; }

        /* Empty state */
        .empty-state {
            padding: 50px 20px;
            text-align: center;
        }
        .empty-state i { font-size: 38px; color: var(--text-3); margin-bottom: 10px; }
        .empty-state p { color: var(--text-1); font-weight: 600; margin-bottom: 4px; }
        .empty-state span { color: var(--text-2); font-size: 12.5px; }

        /* Add-rule hint box */
        .hint-box {
            margin: 14px;
            padding: 14px 16px;
            border-radius: 10px;
            border: 1px dashed rgba(99,102,241,0.3);
            background: linear-gradient(135deg, var(--indigo-soft), #fff 80%);
            font-size: 13px; color: var(--text-2);
            display: flex; align-items: center; gap: 10px;
        }
        .hint-box i { color: var(--indigo); font-size: 15px; flex-shrink: 0; }

        /* Global notice */
        .notice-am {
            padding: 13px 16px;
            border-radius: 12px;
            background: var(--amber-soft);
            border: 1px solid rgba(217,119,6,0.25);
            color: var(--amber);
            font-size: 12.5px; font-weight: 500;
            margin-bottom: 14px;
            display: flex; align-items: flex-start; gap: 10px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.1s both;
        }
        .notice-am i { font-size: 14px; margin-top: 2px; flex-shrink: 0; }

        /* Responsive */
        @media (max-width: 820px) {
            .header-inner { padding: 12px 14px; }
            main { padding: 16px 14px 50px; }
            .filter-grid { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: 1fr; }
            .section-head { padding: 12px 14px; }
            .header-subtitle { display: none; }
        }
        @media (max-width: 560px) {
            .header-title { font-size: 14.5px; }
            .cari-name { max-width: 200px; }
            .gd-table thead th,
            .gd-table tbody td { padding: 10px 10px; font-size: 12.5px; }
            .header-link span { display: none; }
        }

        /* RAF reflow guard */
        @media (prefers-reduced-motion: reduce) {
            .glass-card, .stat-card, .alert-bar, .notice-am { animation: none !important; }
        }

        /* ---- Tab switcher (Kurallar / Test) ---- */
        .tab-bar {
            display: inline-flex; gap: 4px;
            padding: 4px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 14px;
        }
        .tab-link {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px;
            border-radius: 9px;
            font-size: 13px; font-weight: 700;
            color: var(--text-2); text-decoration: none;
            transition: all 0.16s ease;
            min-height: 40px;
        }
        .tab-link:hover { color: var(--indigo); background: var(--indigo-soft); }
        .tab-link.active {
            color: #fff;
            background: linear-gradient(180deg, #6366f1 0%, #4f46e5 100%);
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);
        }
        .tab-link.active:hover { color: #fff; }

        /* ---- Test result hero ---- */
        .test-hero {
            display: flex; align-items: center; gap: 16px;
            padding: 18px;
            border-radius: 14px;
        }
        .test-hero.ok { background: linear-gradient(135deg, var(--emerald-soft), #fff 70%); border: 1px solid rgba(5,150,105,0.28); }
        .test-hero.bad { background: linear-gradient(135deg, var(--red-soft), #fff 70%); border: 1px solid rgba(111,16,34,0.28); }
        .test-hero-icon {
            width: 60px; height: 60px; flex: 0 0 60px;
            border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.7rem; color: #fff;
        }
        .test-hero-icon.ok { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 20px rgba(16,185,129,0.32); }
        .test-hero-icon.bad { background: linear-gradient(135deg, var(--red,#ef4444), var(--red,#6F1022)); box-shadow: 0 8px 20px rgba(239,68,68,0.32); }
        .test-hero-eyebrow { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700; }
        .test-hero-eyebrow.ok { color: var(--emerald); }
        .test-hero-eyebrow.bad { color: var(--red); }
        .test-hero-title { font-size: 20px; font-weight: 700; margin-top: 2px; }
        .test-hero-title.ok { color: #065f46; }
        .test-hero-title.bad { color: #991b1b; }

        .test-chip {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 100px;
            font-size: 11px; font-weight: 700;
        }
        .test-chip.slate { background: #f3f4f6; color: var(--text-2); }
        .test-chip.am { background: var(--amber-soft); color: var(--amber); }
        .test-chip.sky { background: var(--sky-soft); color: var(--sky); }
        .test-chip.em { background: var(--emerald-soft); color: var(--emerald); }
        .test-chip.red { background: var(--red-soft); color: var(--red); }

        .test-pill-grid { display: grid; gap: 10px; margin-top: 14px; }
        @media (min-width: 640px) { .test-pill-grid { grid-template-columns: 1fr 1fr; } }
        .test-pill {
            display: flex; align-items: flex-start; gap: 8px;
            padding: 12px 14px; border-radius: 12px;
            font-size: 13px; line-height: 1.45;
        }
        .test-pill.ok { background: var(--emerald-soft); color: #065f46; border: 1px solid rgba(5,150,105,0.22); }
        .test-pill.bad { background: var(--red-soft); color: #991b1b; border: 1px solid rgba(111,16,34,0.22); }
        .test-pill i { margin-top: 2px; }

        .test-note-box {
            margin-top: 14px;
            padding: 12px 14px;
            background: #f8faff;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 13px;
        }
        .test-note-box .lbl { font-size: 10.5px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .test-note-grid { display: grid; gap: 6px; }
        @media (min-width: 640px) { .test-note-grid { grid-template-columns: 1fr 1fr; } }

        .group-row td {
            background: var(--indigo-soft);
            font-weight: 700; color: var(--text-1);
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em;
        }
        .mono { font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: 12px; color: var(--text-2); }

        /* Test cari search list */
        .test-cari-row {
            display: grid; grid-template-columns: auto 1fr auto;
            align-items: center; gap: 12px;
            padding: 12px 14px;
            border-bottom: 1px solid #f3f4f6;
            transition: background 0.15s ease;
        }
        .test-cari-row:last-child { border-bottom: 0; }
        .test-cari-row:hover { background: var(--indigo-soft); }
        .test-cari-row.selected { background: var(--amber-soft); }
        .test-cari-row .code { font-family: 'JetBrains Mono', ui-monospace, monospace; font-weight: 700; color: var(--indigo); font-size: 12px; }
        .test-cari-row .name { font-weight: 600; color: var(--text-1); }
        .test-cari-row .city { color: var(--text-3); font-size: 11px; }
        @media (max-width: 560px) {
            .test-hero { flex-direction: column; align-items: flex-start; gap: 12px; }
            .test-cari-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Geri">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <div class="header-icon">
                <i class="fa-solid fa-user-lock"></i>
            </div>
            <div class="header-titles">
                <div class="header-title">Ozel Cari Kisitlari</div>
                <div class="header-subtitle">
                    <?php if ($aktifSekme === 'test'): ?>
                        Yetki kurallarini sinayin &middot; Hedef: <?php echo htmlspecialchars($testSelectedPersonelLabel, ENT_QUOTES, 'UTF-8'); ?>
                    <?php else: ?>
                        Kullanici bazli cari erisim kontrolu &middot; Hedef: <?php echo htmlspecialchars($selectedPersonelLabel, ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <main>
        <?php if ($statusText !== null): ?>
            <div class="alert-bar <?php echo $statusText['type'] === 'success' ? 'ok' : 'err'; ?>">
                <i class="fa-solid <?php echo $statusText['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?php echo htmlspecialchars($statusText['text'], ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($initError !== null): ?>
            <div class="alert-bar err">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span><strong>Tablo hazirlanamadi:</strong> <?php echo htmlspecialchars($initError, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <!-- Tab switcher: Kurallar / Test -->
        <nav class="tab-bar" aria-label="Sekmeler">
            <a href="<?php echo htmlspecialchars($buildTabUrl('kurallar'), ENT_QUOTES, 'UTF-8'); ?>"
               class="tab-link <?php echo $aktifSekme === 'kurallar' ? 'active' : ''; ?>">
                <i class="fa-solid fa-user-lock"></i> Kurallar
            </a>
            <a href="<?php echo htmlspecialchars($buildTabUrl('test'), ENT_QUOTES, 'UTF-8'); ?>"
               class="tab-link <?php echo $aktifSekme === 'test' ? 'active' : ''; ?>">
                <i class="fa-solid fa-flask-vial"></i> Test
            </a>
        </nav>

        <?php if ($aktifSekme === 'kurallar'): ?>
        <!-- Filter Panel -->
        <section class="glass-card filter-panel">
            <form method="GET" class="filter-grid">
                <div class="field">
                    <label for="personel_id"><i class="fa-solid fa-user-shield"></i> Hedef Kullanici</label>
                    <select id="personel_id" name="personel_id" onchange="this.form.submit()">
                        <?php foreach ($personelSecenekleri as $personel): ?>
                            <?php
                            $personelId = (int) $personel['LOGICALREF'];
                            $isGlobal = $personelId === $globalPersonelId;
                            $label = $isGlobal
                                ? 'Tum Kullanicilar (Global)'
                                : trim((string) (($personel['CODE'] ?? '') . ' - ' . ($personel['DEFINITION_'] ?? '')));
                            ?>
                            <option value="<?php echo $personelId; ?>" <?php echo $personelId === $selectedPersonelId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="q"><i class="fa-solid fa-magnifying-glass"></i> Cari Ara (yeni kural eklemek icin)</label>
                    <div class="search-wrap">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" id="q" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cari kodu, unvan veya sehir...">
                    </div>
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fa-solid fa-filter"></i>
                    Listele
                </button>
            </form>
        </section>

        <!-- Stat mini cards -->
        <div class="stat-grid">
            <div class="stat-card ind">
                <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
                <div class="stat-body">
                    <div class="stat-val"><?php echo $isGlobalView ? $totalHidden : $globalRuleCount; ?></div>
                    <div class="stat-label">Global Kural Sayisi</div>
                </div>
            </div>
            <div class="stat-card am">
                <div class="stat-icon"><i class="fa-solid fa-user-lock"></i></div>
                <div class="stat-body">
                    <div class="stat-val"><?php echo $totalHidden; ?></div>
                    <div class="stat-label"><?php echo $isGlobalView ? 'Global Kisit' : 'Bu Kullanicidaki Kisit'; ?></div>
                </div>
            </div>
            <div class="stat-card em">
                <div class="stat-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="stat-body">
                    <div class="stat-val"><?php echo count($kisitKodlari); ?></div>
                    <div class="stat-label">Yetki Kodu Kapsami</div>
                </div>
            </div>
        </div>

        <?php if (!$isGlobalView && $globalRuleCount > 0): ?>
            <div class="notice-am">
                <i class="fa-solid fa-circle-info"></i>
                <span>
                    <strong>Tum Kullanicilar</strong> altinda ayrica <?php echo $globalRuleCount; ?> ortak kural var. Bu kullanicida gorunmese de nedeni global liste olabilir. Yonetmek icin ustteki secimden <strong>Tum Kullanicilar</strong> sec.
                </span>
            </div>
        <?php endif; ?>

        <!-- ADD RULE: search results -->
        <section class="glass-card section-card" style="animation-delay: 0.1s;">
            <div class="section-head">
                <div class="title-wrap">
                    <h2><i class="fa-solid fa-plus-circle"></i> Yeni Kural Ekle</h2>
                    <div class="subt">Eklenen cari secili kullanici icin <strong>M4</strong> bakiye ve <strong>M19</strong> siparis tarafinda gizlenir.</div>
                </div>
                <div class="badge-count">
                    <i class="fa-solid fa-bullseye"></i>
                    <?php echo htmlspecialchars($selectedPersonelLabel, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>

            <?php if ($search !== ''): ?>
                <div class="table-wrap">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Cari</th>
                                <th>Sehir</th>
                                <th class="right">Islem</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($searchResults === []): ?>
                                <tr>
                                    <td colspan="3">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            <p>Sonuc bulunamadi.</p>
                                            <span>Farkli bir anahtar kelime dene.</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($searchResults as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="cari-cell">
                                                <span class="cari-code"><?php echo htmlspecialchars((string) $row['CODE'], ENT_QUOTES, 'UTF-8'); ?></span>
                                                <span class="cari-name"><?php echo htmlspecialchars((string) $row['DEFINITION_'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </div>
                                        </td>
                                        <td class="muted"><?php echo htmlspecialchars((string) ($row['CITY'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="right">
                                            <div class="row-actions">
                                                <?php if ((int) ($row['GLOBAL_LISTEDE'] ?? 0) === 1 && !$isGlobalView): ?>
                                                    <span class="chip-pill am" title="Tum Kullanicilar icin zaten gizli"><i class="fa-solid fa-globe" style="margin-right:4px;"></i>Global</span>
                                                <?php endif; ?>
                                                <?php if ((int) ($row['LISTEDE'] ?? 0) === 1): ?>
                                                    <span class="chip-pill am"><i class="fa-solid fa-check" style="margin-right:4px;"></i>Listede</span>
                                                <?php else: ?>
                                                    <form method="POST" style="display:inline;">
                                                        <?php echo csrf_field(); ?>
                                                        <input type="hidden" name="action" value="add">
                                                        <input type="hidden" name="personel_id" value="<?php echo $selectedPersonelId; ?>">
                                                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="cariref" value="<?php echo (int) $row['LOGICALREF']; ?>">
                                                        <button type="submit" class="btn-icon em">
                                                            <i class="fa-solid fa-plus"></i>
                                                            Ekle
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="hint-box">
                    <i class="fa-solid fa-lightbulb"></i>
                    <span>Kullaniciyi sec ve cari kodu, unvanin ya da sehir adini arat. Sonuclardan tek tuslama ile yasak listesine ekleyebilirsin.</span>
                </div>
            <?php endif; ?>
        </section>

        <!-- GLOBAL RULES (when viewing specific user) -->
        <?php if (!$isGlobalView && $globalHiddenCaris !== []): ?>
            <section class="glass-card section-card" style="animation-delay: 0.15s;">
                <div class="section-head">
                    <div class="title-wrap">
                        <h2 style="color: var(--amber);"><i class="fa-solid fa-globe" style="color: var(--amber);"></i> Tum Kullanicilar Kurallari</h2>
                        <div class="subt">Asagidaki cariler ortak kural nedeniyle bu kullaniciyi da etkiler.</div>
                    </div>
                    <a href="<?php echo htmlspecialchars($buildPageUrl($globalPersonelId, null, $search), ENT_QUOTES, 'UTF-8'); ?>" class="btn-icon" style="border-color: rgba(217,119,6,0.3); color: var(--amber);">
                        <i class="fa-solid fa-layer-group"></i>
                        Global Kayitlari Ac
                    </a>
                </div>
                <div class="table-wrap">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Cari</th>
                                <th>Sehir</th>
                                <th>Kisitlar</th>
                                <th>Ekleyen</th>
                                <th>Tarih</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($globalHiddenCaris as $row): ?>
                                <tr>
                                    <td>
                                        <div class="cari-cell">
                                            <span class="cari-code"><?php echo htmlspecialchars((string) ($row['CODE'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="cari-name"><?php echo htmlspecialchars((string) ($row['DEFINITION_'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </td>
                                    <td class="muted"><?php echo htmlspecialchars((string) ($row['CITY'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php foreach (($row['YETKI_KODLARI'] ?? []) as $yetkiKodu): ?>
                                            <span class="chip-pill am"><?php echo htmlspecialchars((string) $yetkiKodu, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="muted">
                                        <?php
                                        $ekleyen = trim((string) (($row['EKLEYEN_KOD'] ?? '') . ' ' . ($row['EKLEYEN_ADI'] ?? '')));
                                        echo htmlspecialchars($ekleyen !== '' ? $ekleyen : '-', ENT_QUOTES, 'UTF-8');
                                        ?>
                                    </td>
                                    <td class="muted"><?php echo htmlspecialchars((string) ($row['OLUSTURMA_TARIHI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- USER'S RESTRICTED LIST -->
        <section class="glass-card section-card" style="animation-delay: 0.2s;">
            <div class="section-head">
                <div class="title-wrap">
                    <h2><i class="fa-solid fa-ban"></i> Yasak Listesi</h2>
                    <div class="subt">Bu cariler yalnizca secili kullanici icin gizlenir. Satir hem <strong>M4</strong> hem <strong>M19</strong> kapsamini birlikte tasir.</div>
                </div>
                <div class="badge-count">
                    <i class="fa-solid fa-database"></i>
                    Toplam <?php echo $totalHidden; ?> kayit
                </div>
            </div>
            <div class="table-wrap">
                <table class="gd-table">
                    <thead>
                        <tr>
                            <th>Cari</th>
                            <th>Sehir</th>
                            <th>Kisitlar</th>
                            <th>Ekleyen</th>
                            <th>Tarih</th>
                            <th class="right">Islem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($hiddenCaris === []): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fa-solid fa-inbox"></i>
                                        <p>Henuz kural yok</p>
                                        <span>Secili kullanici icin yasak cari eklenmedi.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($hiddenCaris as $row): ?>
                                <tr>
                                    <td>
                                        <div class="cari-cell">
                                            <span class="cari-code"><?php echo htmlspecialchars((string) ($row['CODE'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                            <span class="cari-name"><?php echo htmlspecialchars((string) ($row['DEFINITION_'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                    </td>
                                    <td class="muted"><?php echo htmlspecialchars((string) ($row['CITY'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <?php foreach (($row['YETKI_KODLARI'] ?? []) as $yetkiKodu): ?>
                                            <span class="chip-pill ind"><?php echo htmlspecialchars((string) $yetkiKodu, ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="muted">
                                        <?php
                                        $ekleyen = trim((string) (($row['EKLEYEN_KOD'] ?? '') . ' ' . ($row['EKLEYEN_ADI'] ?? '')));
                                        echo htmlspecialchars($ekleyen !== '' ? $ekleyen : '-', ENT_QUOTES, 'UTF-8');
                                        ?>
                                    </td>
                                    <td class="muted"><?php echo htmlspecialchars((string) ($row['OLUSTURMA_TARIHI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="right">
                                        <div class="row-actions">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Bu cari secili kullanicinin yasak listesinden cikarilsin mi?');">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="personel_id" value="<?php echo $selectedPersonelId; ?>">
                                                <input type="hidden" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                                                <input type="hidden" name="cariref" value="<?php echo (int) $row['CARIREF']; ?>">
                                                <button type="submit" class="btn-icon danger">
                                                    <i class="fa-solid fa-trash"></i>
                                                    Cikar
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; // /kurallar sekmesi ?>

        <?php if ($aktifSekme === 'test'): ?>
        <!-- ===================== TEST SEKMESI ===================== -->

        <!-- Test parametreleri -->
        <section class="glass-card filter-panel">
            <form method="GET" class="filter-grid">
                <input type="hidden" name="sekme" value="test">
                <div class="field">
                    <label for="t_personel_id"><i class="fa-solid fa-user-shield"></i> Personel</label>
                    <select id="t_personel_id" name="t_personel_id">
                        <?php foreach ($personelSecenekleri as $personel): ?>
                            <?php
                            $personelId = (int) $personel['LOGICALREF'];
                            $isGlobal = $personelId === $globalPersonelId;
                            $label = $isGlobal
                                ? 'Tum Kullanicilar (Global)'
                                : trim((string) (($personel['CODE'] ?? '') . ' - ' . ($personel['DEFINITION_'] ?? '')));
                            if (!$isGlobal) {
                                if ((int) ($personel['ACTIVE'] ?? 0) !== 0) { $label .= ' (Pasif)'; }
                                if ((int) ($personel['YETKI_TURU'] ?? 1) === 0) { $label .= ' [Yonetici]'; }
                            }
                            ?>
                            <option value="<?php echo $personelId; ?>" <?php echo $personelId === $testPersonelId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="t_q"><i class="fa-solid fa-magnifying-glass"></i> Cari Ara (test edilecek)</label>
                    <div class="search-wrap">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" id="t_q" name="t_q" value="<?php echo htmlspecialchars($testSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Cari kodu, unvan veya sehir...">
                    </div>
                </div>
                <button type="submit" class="btn-filter">
                    <i class="fa-solid fa-flask-vial"></i>
                    Test Et
                </button>
                <?php if ($testSelectedCariRef > 0): ?>
                    <input type="hidden" name="t_cariref" value="<?php echo $testSelectedCariRef; ?>">
                <?php endif; ?>
            </form>
        </section>

        <!-- Bilgi notu -->
        <div class="notice-am" style="background: var(--sky-soft); border-color: rgba(2,132,199,0.25); color: var(--sky);">
            <i class="fa-solid fa-circle-info"></i>
            <span>Personel ve cari secerek, seciminizin <strong>bakiye (M4)</strong> ve <strong>siparis (M19)</strong> modullerinde nasil davrandigini gorebilirsiniz. Bu sekme yalnizca teshis amaclidir, hicbir kayit degistirmez.</span>
        </div>

        <?php if ($testSelectedCari !== null && $testSonucu !== null): ?>
            <!-- Sonuc ozeti -->
            <section class="glass-card section-card" style="padding: 18px;">
                <div class="test-hero <?php echo $testErisimVar ? 'ok' : 'bad'; ?>">
                    <div class="test-hero-icon <?php echo $testErisimVar ? 'ok' : 'bad'; ?>">
                        <i class="fa-solid <?php echo $testErisimVar ? 'fa-circle-check' : 'fa-ban'; ?>"></i>
                    </div>
                    <div style="flex: 1 1 auto; min-width: 0;">
                        <div class="test-hero-eyebrow <?php echo $testErisimVar ? 'ok' : 'bad'; ?>">Erisim Sonucu</div>
                        <div class="test-hero-title <?php echo $testErisimVar ? 'ok' : 'bad'; ?>">Erisim: <?php echo $testErisimVar ? 'EVET' : 'HAYIR'; ?></div>
                        <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:10px;">
                            <span class="test-chip slate"><i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($testSelectedPersonelLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="test-chip am"><i class="fa-solid fa-address-book"></i> <?php echo htmlspecialchars((string)($testSelectedCari['CODE'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if (!$testIsGlobalSelected): ?>
                                <?php if ($testSonucu['is_admin']): ?>
                                    <span class="test-chip sky"><i class="fa-solid fa-shield-halved"></i> Yonetici</span>
                                <?php endif; ?>
                                <?php if ($testSonucu['tam_bypass']): ?>
                                    <span class="test-chip am"><i class="fa-solid fa-key"></i> Tam Bypass</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ((int) ($testSelectedCari['ACTIVE'] ?? 0) !== 0): ?>
                                <span class="test-chip am"><i class="fa-solid fa-circle-pause"></i> Pasif Cari</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($testCopyText !== ''): ?>
                        <button type="button" id="copy-test-result" class="btn-icon" style="align-self:flex-start; white-space:nowrap;" aria-label="Sonucu kopyala">
                            <i class="fa-solid fa-copy"></i>
                            <span>Kopyala</span>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="test-note-box">
                    <div class="lbl">Aciklama</div>
                    <div class="test-note-grid">
                        <div><strong>Bakiye (M4):</strong> <?php echo htmlspecialchars($testSonucu['bakiye_not'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div><strong>Siparis (M19):</strong> <?php echo htmlspecialchars($testSonucu['siparis_not'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>

                <div class="test-pill-grid">
                    <div class="test-pill <?php echo $testSonucu['bakiye_gorunur'] ? 'ok' : 'bad'; ?>">
                        <i class="fa-solid <?php echo $testSonucu['bakiye_gorunur'] ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                        <div><strong>Bakiye:</strong> <?php echo htmlspecialchars($testSonucu['bakiye_not'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="test-pill <?php echo $testSonucu['siparis_gorunur'] ? 'ok' : 'bad'; ?>">
                        <i class="fa-solid <?php echo $testSonucu['siparis_gorunur'] ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                        <div><strong>Siparis:</strong> <?php echo htmlspecialchars($testSonucu['siparis_not'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
            </section>

            <!-- Detayli kontroller -->
            <section class="glass-card section-card">
                <div class="section-head">
                    <div class="title-wrap">
                        <h2><i class="fa-solid fa-list-check"></i> Detayli Kontroller</h2>
                        <div class="subt">Her kontrolun sonucu ayrintili olarak.</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <?php if (!$testIsGlobalSelected): ?>
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Kontrol</th>
                                <th>Aciklama</th>
                                <th style="width: 110px;">Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="group-row"><td colspan="3">Genel</td></tr>
                            <tr>
                                <td>Yonetici Yetkisi</td>
                                <td class="muted">YETKI = 0 ise tum kisitlar bypass.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['is_admin']); ?></td>
                            </tr>
                            <tr>
                                <td>Tam Bypass</td>
                                <td class="muted">Ozel cari kisiti tamamen devre disi.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['tam_bypass']); ?></td>
                            </tr>

                            <tr class="group-row"><td colspan="3">Bakiye Modulu (M4)</td></tr>
                            <tr>
                                <td>Bakiye Gorunur</td>
                                <td class="muted">Bu cari bakiye listesinde gorunur mu?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['bakiye_gorunur']); ?></td>
                            </tr>
                            <tr>
                                <td>Sayfa Erisimi (M4/M20)</td>
                                <td class="muted">Bakiye sayfasina girebilir mi?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['bakiye_sayfa_erisimi']); ?></td>
                            </tr>
                            <tr>
                                <td>Global M4 Yasak</td>
                                <td class="muted">Tum kullanicilar icin yasak kaydi var mi?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['global_m4']); ?></td>
                            </tr>
                            <tr>
                                <td>Kullaniciya Ozel M4 Yasak</td>
                                <td class="muted">Bu kullanici icin ozel yasak.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['user_m4']); ?></td>
                            </tr>
                            <tr>
                                <td>M4 Bypass Yetkisi</td>
                                <td class="muted">Global M4 kurallarini gecebilir mi?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['bypass_m4']); ?></td>
                            </tr>
                            <tr>
                                <td>SQL Filtresi Aktif</td>
                                <td class="muted">Listelerde otomatik filtre uygulanir.</td>
                                <td>
                                    <?php if ($testSonucu['sql_m4_aktif']): ?>
                                        <span class="test-chip em">Evet (<?php echo (int) $testSonucu['sql_m4_kisitli_adet']; ?>)</span>
                                    <?php else: ?>
                                        <span class="test-chip red">Hayir</span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr class="group-row"><td colspan="3">Siparis Modulu (M19)</td></tr>
                            <tr>
                                <td>Siparis Gorunur</td>
                                <td class="muted">Cari/siparis listesinde gorunur mu?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['siparis_gorunur']); ?></td>
                            </tr>
                            <tr>
                                <td>Sayfa Erisimi (M2)</td>
                                <td class="muted">Siparis sayfasina girebilir mi?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['siparis_sayfa_erisimi']); ?></td>
                            </tr>
                            <tr>
                                <td>M19 Yetkisi</td>
                                <td class="muted">Kullanicinin M19 yetki bayragi.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['m19_yetkisi']); ?></td>
                            </tr>
                            <tr>
                                <td>Global M19 Yasak</td>
                                <td class="muted">Tum kullanicilar icin yasak kaydi var mi?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['global_m19']); ?></td>
                            </tr>
                            <tr>
                                <td>Kullaniciya Ozel M19 Yasak</td>
                                <td class="muted">Bu kullanici icin ozel yasak.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['user_m19']); ?></td>
                            </tr>
                            <tr>
                                <td>M19 Bypass Yetkisi</td>
                                <td class="muted">Global M19 kurallarini gecebilir mi?</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['bypass_m19']); ?></td>
                            </tr>
                            <tr>
                                <td>SQL Filtresi Aktif</td>
                                <td class="muted">Listelerde otomatik filtre uygulanir.</td>
                                <td>
                                    <?php if ($testSonucu['sql_m19_aktif']): ?>
                                        <span class="test-chip em">Evet (<?php echo (int) $testSonucu['sql_m19_kisitli_adet']; ?>)</span>
                                    <?php else: ?>
                                        <span class="test-chip red">Hayir</span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <tr class="group-row"><td colspan="3">Ek Yetkiler</td></tr>
                            <tr>
                                <td>CR1 (Bakiye Goruntuleme)</td>
                                <td class="muted">Bakiye detay goruntuleme yetkisi.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['cr1_yetkisi']); ?></td>
                            </tr>
                            <tr>
                                <td>CR4 (Ozel Islem)</td>
                                <td class="muted">Ozel islem yetkisi.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['cr4_yetkisi']); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Global Kural</th>
                                <th>Aciklama</th>
                                <th style="width: 110px;">Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>M4 Global Yasak</td>
                                <td class="muted">Bu cari tum kullanicilar icin bakiyede gizli.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['global_m4']); ?></td>
                            </tr>
                            <tr>
                                <td>M19 Global Yasak</td>
                                <td class="muted">Bu cari tum kullanicilar icin siparisde gizli.</td>
                                <td><?php echo ozel_cari_test_rozet($testSonucu['global_m19']); ?></td>
                            </tr>
                            <tr>
                                <td>Toplam M4 Kisitli Cari</td>
                                <td class="muted">Tum global M4 yasakli cari sayisi.</td>
                                <td><span class="test-chip sky"><?php echo (int) $testSonucu['sql_m4_kisitli_adet']; ?></span></td>
                            </tr>
                            <tr>
                                <td>Toplam M19 Kisitli Cari</td>
                                <td class="muted">Tum global M19 yasakli cari sayisi.</td>
                                <td><span class="test-chip sky"><?php echo (int) $testSonucu['sql_m19_kisitli_adet']; ?></span></td>
                            </tr>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Etkilenen sayfalar -->
            <section class="glass-card section-card">
                <div class="section-head">
                    <div class="title-wrap">
                        <h2><i class="fa-solid fa-map"></i> Etkilenen Sayfalar</h2>
                        <div class="subt">Bu kullanici, bu cari icin hangi sayfalari gorebilir?</div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Sayfa</th>
                                <th>Aciklama</th>
                                <th style="width: 130px;">Durum</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($testModulHaritasi as $kod => $modul): ?>
                                <?php $modulGorunur = ($kod === 'M4') ? $testSonucu['bakiye_gorunur'] : $testSonucu['siparis_gorunur']; ?>
                                <tr class="group-row">
                                    <td colspan="3">
                                        <span style="display:inline-flex; align-items:center; gap:8px;">
                                            <span class="test-chip <?php echo $modulGorunur ? 'em' : 'red'; ?>"><?php echo htmlspecialchars($kod, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <?php echo htmlspecialchars($modul['baslik'], ENT_QUOTES, 'UTF-8'); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php foreach ($modul['sayfalar'] as $sayfa): ?>
                                    <tr>
                                        <td><span class="mono"><?php echo htmlspecialchars($sayfa['ad'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td class="muted"><?php echo htmlspecialchars($sayfa['aciklama'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php if ($modulGorunur): ?>
                                                <span class="test-chip em"><i class="fa-solid fa-circle-check"></i> Gorebilir</span>
                                            <?php else: ?>
                                                <span class="test-chip red"><i class="fa-solid fa-circle-xmark"></i> Goremez</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <!-- Cari arama sonuclari -->
        <section class="glass-card section-card">
            <div class="section-head">
                <div class="title-wrap">
                    <h2><i class="fa-solid fa-magnifying-glass"></i> Cari Sonuclari</h2>
                    <div class="subt">Test etmek istedigin cariyi sec.</div>
                </div>
                <div class="badge-count">
                    <i class="fa-solid fa-user"></i>
                    <?php echo htmlspecialchars($testSelectedPersonelLabel, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>

            <?php if ($testSearch === ''): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-keyboard"></i>
                    <p>Once bir arama yap</p>
                    <span>Ustte bir cari arat, sonra listeden secip test et. Sistem neden gorunup gorunmedigini aciklayacak.</span>
                </div>
            <?php elseif ($testSearchResults === []): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <p>"<?php echo htmlspecialchars($testSearch, ENT_QUOTES, 'UTF-8'); ?>" icin sonuc bulunamadi.</p>
                    <span>Farkli bir anahtar kelime dene.</span>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach ($testSearchResults as $row): ?>
                        <?php $isSelectedRow = $testSelectedCariRef === (int) $row['LOGICALREF']; ?>
                        <div class="test-cari-row <?php echo $isSelectedRow ? 'selected' : ''; ?>">
                            <div class="code"><?php echo htmlspecialchars((string) $row['CODE'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div style="min-width:0;">
                                <div class="name"><?php echo htmlspecialchars((string) $row['DEFINITION_'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php if (!empty($row['CITY'])): ?>
                                    <div class="city"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars((string) $row['CITY'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <?php if ($isSelectedRow): ?>
                                    <span class="test-chip am"><i class="fa-solid fa-check"></i> Secili</span>
                                <?php else: ?>
                                    <a href="?<?php echo htmlspecialchars(http_build_query(['sekme' => 'test', 't_personel_id' => $testPersonelId, 't_q' => $testSearch, 't_cariref' => (int) $row['LOGICALREF']]), ENT_QUOTES, 'UTF-8'); ?>"
                                       class="btn-icon em">
                                        <i class="fa-solid fa-vial"></i> Test Et
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php endif; // /test sekmesi ?>
    </main>

    <?php if ($aktifSekme === 'test' && $testCopyText !== ''): ?>
        <textarea id="copy-test-result-text" style="position:absolute; left:-9999px; top:-9999px;" aria-hidden="true"><?php echo htmlspecialchars($testCopyText, ENT_QUOTES, 'UTF-8'); ?></textarea>
        <script>
            (function () {
                const button = document.getElementById('copy-test-result');
                const textarea = document.getElementById('copy-test-result-text');
                if (!button || !textarea) { return; }

                const originalHtml = button.innerHTML;

                async function copyText() {
                    const text = textarea.value || '';
                    if (!text) { return false; }

                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(text);
                        return true;
                    }

                    textarea.style.position = 'fixed';
                    textarea.style.left = '0';
                    textarea.style.top = '0';
                    textarea.style.opacity = '0';
                    textarea.focus();
                    textarea.select();
                    const copied = document.execCommand('copy');
                    textarea.style.position = 'absolute';
                    textarea.style.left = '-9999px';
                    textarea.style.top = '-9999px';
                    textarea.style.opacity = '';
                    return copied;
                }

                button.addEventListener('click', async function () {
                    try {
                        const copied = await copyText();
                        if (!copied) { throw new Error('copy-failed'); }
                        button.innerHTML = '<i class="fa-solid fa-check"></i><span>Kopyalandi</span>';
                        setTimeout(function () { button.innerHTML = originalHtml; }, 1800);
                    } catch (error) {
                        button.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>Hata</span>';
                        setTimeout(function () { button.innerHTML = originalHtml; }, 2200);
                    }
                });
            }());
        </script>
    <?php endif; ?>

    <script>
        // RAF reflow fix for cardIn animations
        requestAnimationFrame(function () {
            document.querySelectorAll('.glass-card, .stat-card, .alert-bar, .notice-am').forEach(function (el) {
                void el.offsetWidth;
            });
        });
    </script>
</body>
</html>
