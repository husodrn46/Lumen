# Lumen SQL Server kabul paketi

Bu paket üretim bağlantısı kurmaz, `ayr.php`/`.env` okumaz ve mevcut DB'yi silmez. Hazırlanan SQL fixture henüz gerçek SQL Server'da çalıştırılmadı. Yerel PHP 8.1.34 ve eksik `pdo_sqlsrv` nedeniyle çıkış 2 ile durdu; bu test geçişi değildir.

## Tek onay/ortam listesi

1. **İzole test hostu:** yalnız Lumen testine ayrılmış, üretim ağı/verisi olmayan uygun host/VM. Forge ARM64 üzerinde emülasyonla SQL Server çalıştırmak desteklenen kabul sayılmaz; x86-64 Linux SQL Server ortamı tercih edilecekse host ayrıca seçilmeli. Yeni Docker/VM/global paket kurulumu bu görevde yapılmadı.
2. **Proje runtime'ı:** bu test ortamında PHP 8.2 veya 8.3, `pdo_sqlsrv` ve gerekli ODBC bağımlılığı; Composer proje seviyesinde. Runtime/bağımlılık indirme veya kurulumu ayrıca izin gerektirir. Forge'daki global PHP değiştirilmez.
3. **Boş test DB ve sınırlı hesap:** yönetici `LumenTest_Start_001` gibi boş DB hazırlasın. Hesap yalnız o DB'de tablo/trigger/index oluşturma, ilgili fixture tablosunu ALTER etme ve SELECT/INSERT/UPDATE çalıştırmaya yetkili olsun. CREATE DATABASE, sysadmin/sa veya üretim hesabı gerekmez. dbo şemasında fixture nesnelerini oluşturabilmeli. Test DROP/DELETE uygulamaz. Hesap/DSN/parola güvenli ortam değişkenlerinden sağlansın; dosyaya/repoya/mesaja yazılmasın.
4. **Gerçek LOGO kabulü için ayrı fixture:** kullanılan LOGO sürümünün tablo/trigger/kolon sözleşmesini temsil eden, müşteri/iOS/üretim verisi içermeyen sentetik cari, stok, birim ve izin verisi. Aşağıdaki küçük test DB bunu karşılamaz; ikinci kabul kapısıdır.

AKEL-SRV01 veya diğer uygulamanın test DB'si hedef olarak seçilmez. Test scripti uzaktan DSN kabul etmez. Seçilen host üzerinde projenin ayrı test kopyası ve SQL Server localhost kullanılır; mevcut guard kaldırılmaz.

## Çalıştırma

Ortam sorumlusu güvenli şekilde şu değişkenleri sağlar:

- `LUMEN_TEST_SQL_DSN`: `sqlsrv:Server=localhost,1433;Database=LumenTest_Start_001`.
- `LUMEN_TEST_SQL_USER`, `LUMEN_TEST_SQL_PASS`: sadece bu test DB hesabı.
- `LUMEN_TEST_SQL_WRITE=sentetik-test-onayli`: fixture yazma seçimi.

DSN yalnız localhost/127.0.0.1 + LumenTest_* ve opsiyonel boolean Encrypt/TrustServerCertificate/ConnectionPooling alanlarını kabul eder. DSN içinden ikinci Server, UID veya başka DB ekleme reddedilir. Test TLS ayarını otomatik değiştirmez. Bağlantı hatasında credential/driver trace yazdırılmaz.

```sh
php tests/sqlserver-preflight.php
php tests/sqlserver-regression.php
php tests/sqlserver-preflight.php
```

İlk preflight yalnız metadata okur: boş DB'de token şeması bulunmamasını bildirir; “API hazır” demez. Runner boş DB koşulunu kontrol eder, `tests/fixtures/sqlserver-startup.sql` içindeki sentetik şemayı yükler ve testleri çalıştırır. Son preflight token alanı ve force-logout sözleşmesini kontrol eder.

Runner aynı DB'de ikinci kez çalışmaz: mevcut tablo varsa durur. Tekrar kabul için yönetici yeni boş LumenTest_* DB hazırlar. Fixture/test verisi kanıt için bırakılır; otomatik temizlik/silme yapılmaz.

## Runner senaryoları ve başarı ölçütleri

- INSERT trigger başka kimlik ve result-set üretir; ortak helper yalnız kendi OUTPUT kimliğini döndürür.
- Satır ortası hatada gerçek rollback başlık ve satırı bırakmaz; IDENTITY boşluğu doğru fiş no üretimini bozmaz.
- 20 paralel iş sonunda tam 20 başlık/20 satır olur; JOB eşleşmeleri başka isteğin başlığına bağlanmaz; numaralar gerçek kimlikten gelir.
- Bilerek VARCHAR(64) legacy token fixture'ı hash şema kontrolünde reddedilir; kayıt değişmez. Sadece bu bilinen, indexsiz **sentetik** tabloda VARCHAR(128) ALTER denenir ve raw token korunur. Bu bölüm üretim migration betiği değildir.
- İki paralel legacy token doğrulaması aynı raw bearer'ı kabul eder; tek kayıt işaretli hash'e yükselir. Yeni token hash ile saklanır; DB hash replay, başka firma, pasif kullanıcı, force-logout ve logout reddi doğrulanır.
- Başarı çıkış 0 ve TAMAM; eksik ortam/bağlantı çıkış 2, assertion/SQL hatası başarısızdır. Gerçek SQL başarısı elde edilmeden tamamlandı kaydı yazılmaz.

## Üretim token geçiş planı — uygulanmadı

1. Yetkili yönetici dbo token/personel/yetki/force-logout şemasını, mevcut token alanı tipi/uzunluğu, index/constraint bağımlılıklarını ve firma/aktif/önceki dönem öneklerini salt okunur doğrular. API ön kontrolü `dbo.M_API_TOKEN.TOKEN` varchar/nvarchar ve en az 71 karakter (veya MAX) ister.
2. Gerekirse ayrı incelenmiş, veri koruyan schema migration hazırlanır; index/constraint/nullability/collation korunur. Fixture'ın yalın ALTER'ı üretime kopyalanmaz. API runtime otomatik ALTER yapmaz.
3. Tek API sürümüyle geçiş sağlanır; eski ve yeni kod aynı token DB'sinde paralel çalıştırılmaz. Standart istemciye verilen token hâlâ 64 hex; istemci hash üretmez.
4. Rollback gerekiyorsa yükseltilen hash'ten raw token geri üretilemez. Kontrollü token iptali ve tekrar giriş gerekir; kod rollback'i eski oturumları kendiliğinden geri getirmez.

## Testin kapsamadığı konular

Gerçek LOGO 95+ INSERT kolonu, üretimdeki trigger/NOCOUNT/rowCount/nextRowset, gerçek NUMERIC alan kapasitesi, kilit timeout/deadlock, LOGO ile eşzamanlı yazma, Apache/IIS header aktarımı, gerçek login/rate-limit/parola yükseltmesi ve masaüstü istemci davranışı ayrıca test edilmelidir. Satır düzenleme/sevk yarışı bu paketle çözülmüş sayılmaz. Bu temel runner idempotency kabulünü kapsamaz; aşağıdaki ayrı runner bunun için hazırlandı. `Idempotency-Key` göndermeyen eski istemcinin aynı oluşturma isteğini yeniden göndermesi tekrar INSERT denemesine yol açar. Anahtarlı sunucu davranışı yerel testlerle doğrulandı; gerçek SQL ve istemci kabulü henüz çalıştırılmadı.

## Ayrı idempotency kabul kapısı

[IDEMPOTENCY.md](IDEMPOTENCY.md) protokolü ve `php tests/sqlserver-idempotency.php` ayrı **boş** LumenTest_* DB ister. API hesabının ledger SELECT/INSERT/metadata ve `sys.sp_getapplock` çağrısı ayrıca doğrulanmalı; migration hazırlığı için DB'ye sınırlı CREATE TABLE/constraint yetkisi gerekir. Bu test 20 aynı-key eşzamanlı worker, lost response, rollback ve gerçek lock timeout sonrası replay içindir. Anahtarlı sunucu yaması hazırdır; gerçek SQL kabulü henüz yoktur.

## Ayrı test sunucusu olmadan GitHub adayı

[GITHUB-SQL-ACCEPTANCE.md](GITHUB-SQL-ACCEPTANCE.md): standart x86-64 GitHub Linux runner ve job sonunda silinen resmî SQL 2022 Developer service container ile iki yeni sentetik DB. Yerel workflow/CI guard hazırlığı tamamlandı; uzak run **henüz yapılmadı**. Mevcut localhost/LumenTest guard değiştirilmedi. Public repo/Actions etkinliği doğrulandı; hesabın güncel billing kısıtı API izinleriyle tam doğrulanamadı.
