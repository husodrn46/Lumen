# Lumen güvenlik regresyonları

Üretim yapılandırması, müşteri verisi veya `ayr.php` DB bağlantısı kullanılmaz.

## Her geliştirmede

```sh
php tests/run.php
php tests/permission-regression.php
php tests/api-regression.php
php tests/idempotency-regression.php
bash scripts/security-regression.sh
```

`run.php` gerçek ortak yardımcıları PDO test double ile sınar. `api-regression.php` endpoint gövdesini ayrı CLI process'te çalıştırır; yalnız bootstrap include'ları çıkarır, oturum/gövde/izin sonucu ve PDO sentetik olarak sağlanır. İş kuralları ve SQL parametre/transaction akışı gerçek dosyadan gelir. Üretimde `api_json` exit yaptığı için testin ApiResponse istisnası hata catch bloklarını bypass eder; bu yalnız yanıt kontrolü shim'idir. Bu yöntem HTTP sunucusu veya SQL Server motorunu doğrulamaz. İzin SQL'inin iç anlamını taklit ederek güvenlik garantisi vermez.

`permission-regression.php`, uygulama bootstrap'ını açmadan ayr.php içindeki gerçek izin/cache fonksiyonlarını yükler; stale cookie-session izni, revocation ve mevcut admin bypass kuralını sentetik PDO ile sınar. Bu PHP suite'leri bağımlılık kurmadan çalışır. Projenin desteklediği runtime PHP 8.2/8.3'tür; daha eski PHP'de başarılı sonuç o runtime'ın ürün için desteklendiği anlamına gelmez. CI her iki sürümde bu suite'leri çalıştıracak şekilde düzenlenmiştir; yerel rapor gelecekteki CI'yi geçmiş saymaz.

## Geçici localhost HTTP kontrolü

`python3 tests/http-regression.py` standart Python ve mevcut PHP ile, geçici boş document root içinde sadece `127.0.0.1` üzerinde fixture başlatır ve sonunda kapatır. `ayr.php`, `.env` ve üretim bağlantısı yüklenmez. Gerçek `api_body`, bearer, token yardımcıları ve logout endpoint gövdesi HTTP üzerinden sınanır; PDO sentetiktir. Bu, Apache/IIS proxy/header ayarları, gerçek login akışı, gerçek DB veya masaüstü istemci testi değildir. Sandbox loopback bind'i engellerse sadece bu geçici test için ayrıca yürütme izni gerekebilir.

## Gerçek SQL Server kabul kapısı (isteğe bağlı)

`php tests/sqlserver-regression.php` yalnız PHP 8.2+, `pdo_sqlsrv`, localhost SQL Server ve **boş** `LumenTest_*` DB ile çalışır. Eksik önkoşul çıkış kodu 2 verir; bu başarı veya test geçişi değildir. Test mevcut veritabanı şemasını silmez; başlangıçta tablo varsa reddeder. Yeni sentetik tabloları bırakır, otomatik DROP uygulamaz. Test hesabı/DB hazırlanması mevcut işin dışında ayrı onay gerektiren erişimdir.

Gerekli ortam değişkenleri (örneklerde parola bulunmaz):

- `LUMEN_TEST_SQL_DSN`: `sqlsrv:Server=localhost,1433;Database=LumenTest_Start_001` biçimi. TLS seçeneklerini ortam sorumlusu belirler; test bunları değiştirmez.
- `LUMEN_TEST_SQL_USER`, `LUMEN_TEST_SQL_PASS`: sadece bu boş test DB için hesap.
- `LUMEN_TEST_SQL_WRITE=sentetik-test-onayli`: sentetik DB'ye yazmanın açık seçimi.

Senaryolar: INSERT trigger'ının başka ID/result-set üretmesi; OUTPUT INTO ile gerçek kimlik; işlem ortasında hata ve rollback; IDENTITY boşluğu; 20 paralel başlık/satırın birbirine karışmaması; gerçek ID tabanlı fiş no; token hash replay, firma, pasif kullanıcı, force-logout ve çıkış. Gerçek LOGO'nun bütün kolonları/trigger'ları/iş kuralları için ayrıca sürüm uyumluluk testi gerekir. Bu fixture o testin yerine geçmez. SQL preflight/kurulum betiklerinin gerçek boş ve mevcut şemada çalışması da ayrı kabul kapısıdır.

Kurulum/izin listesi, fixture ve token geçiş planı: [SQLSERVER-ACCEPTANCE.md](SQLSERVER-ACCEPTANCE.md). Salt okunur test DB kontrolü: `php tests/sqlserver-preflight.php`.

## Geçiş notları

- Güncel `M_API_TOKEN.TOKEN` sözleşmesi VARCHAR(128); yeni işaretli hash 71 karakterdir. Eski kurulumda alan daha kısa ise veri koruyan schema upgrade gerekir; runtime otomatik ALTER yapmaz. `M_OTURUM_KAPAT` eksikse API oturum hizmeti 503 üretir ve erişim vermez. Kurulum artık gerekli tabloyu doğrular.
- Legacy düz bearer kayıtları başarılı doğrulamada işaretli hash'e yükseltilir. Çıkış `/api/logout.php` POST ile yalnız kullanılan tokenı iptal eder. Tokenlar hâlâ 30 günlük mutlak ömür kullanır.
- Fiyat, KDV/iskonto değiştirme hakkının yeni anlamı veya kullanıcı yönetiminin çok firma kapsamı bu paket içinde icat edilmedi. Rapor M17, fiyat listesi M7 ve bekleyen ürünler M8 mevcut menü izinleriyle korunur.
- TL web fiş açma eski GET akışını korur; tam POST/CSRF dönüşümü henüz yapılmadı. Döviz POST formuna CSRF eklendi. Web satır hesaplama/iskonto kuralları API ile bütünüyle ortaklaştırılmadı.

- API kimlikleri pozitif SQL INT veya sadece rakam içeren metin olmalı; dizi/bool/kesirli sayı veya karışık metin cast edilmez. API firma numarası ve aktif/önceki dönem tablo/view önekleri aynı firmayı göstermeli; uyumsuz eski config 503 ile kapatılır.
- Token doğrulamasındaki son-kullanım güncellemesi aktiflik/firma/rol/force-logout durumunu tekrar kontrol eder. Tek SQL yazım anından sonra başlayan bir çıkışın yürüyen iş isteğini iptal edeceği garantisi yoktur. Cookie-session yetki cache'i yalnız doğrulanan kullanıcı için API isteğinde temizlenir; web'in mevcut cache politikası değişmez.

## Sipariş tekrar gönderim protokolü

Keyed sipariş oluşturma için [IDEMPOTENCY.md](IDEMPOTENCY.md). Eski header'sız istemci hâlâ çalışır ama duplicate koruması yoktur. Yerel migration herhangi bir gerçek DB'ye uygulanmadı. Ayrı boş DB gerçek race/timeout kabulü: `php tests/sqlserver-idempotency.php`.

## Web sipariş başlığı

[WEB-IDEMPOTENCY.md](WEB-IDEMPOTENCY.md), mevcut PHP web akışının anahtar, CSRF, POST/303 ve yeniden deneme sözleşmesini açıklar. `php tests/web-intent-regression.php`, `node tests/web-intent-js.test.cjs` ve `python3 tests/web-regression.py` yerel/sentetik kontrollerdir; gerçek SQL/LOGO kabulünün yerine geçmez.

## Geçici GitHub SQL kabulü

[GITHUB-SQL-ACCEPTANCE.md](GITHUB-SQL-ACCEPTANCE.md), manuel ve secrets-free `sqlserver-acceptance.yml` paketini, CI-only boş DB hazırlığını ve henüz çalıştırılmayan gerçek SQL kabulünü açıklar. `php tests/ci-prepare-regression.php` PDO double; `python3 tests/ci-workflow-regression.py` (PyYAML) yalnız statik YAML/Bash kontrolüdür. GitHub SQL workflow'u yerel dalda hazırlandı; push veya remote run yapılmadı.

## Local separate favorite store pilot

See [LUMEN-FAVORITES-PILOT.md](LUMEN-FAVORITES-PILOT.md). New feature tests run locally; real new schema acceptance is pending.

## Yerel favori aktarımı güvenlik modeli

`tools/favorites_dry_run.md` yerel JSON ön kontrolü ve test-only SQLite `:memory:` transaction/idempotency modelinin kullanımını ve sınırlarını açıklar. `python3 tests/favorites-dry-run-test.py` ve `python3 tests/favorites-transfer-simulation-test.py` standart yerel validate kapısına eklendi. Yeni model bir üretim aktarım uygulaması değildir; gerçek SQL Server veya TLS kabulünün yerini almaz.

Gerçek SQL aktarım adaptörü hazırlığı ve bekleyen CI planı: [LUMEN-FAVORITE-TRANSFER-ACCEPTANCE.md](LUMEN-FAVORITE-TRANSFER-ACCEPTANCE.md). `php tests/favorite-transfer-regression.php` yerel PDO double; `php tests/sqlserver-favorite-transfer.php` yalnız sabit geçici GitHub CI fixture kapısından yürür. Yeni adaptör gerçek kullanıcı akışına bağlanmadı.

Pozitif TLS için process-only test CA hazırlığı: [LUMEN-POSITIVE-TLS-ACCEPTANCE.md](LUMEN-POSITIVE-TLS-ACCEPTANCE.md). Yerel OpenSSL kontrolleri gerçek SQL/ODBC TLS geçişi değildir; sistem truststore kurulumu yoktur.
