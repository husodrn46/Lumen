# Pozitif TLS: geçici test CA hazırlığı

Durum: yalnız yerel CI/test hazırlığı. Gerçek ODBC/PDO/SQL kabulü henüz çalışmadı. Önceki82 aktarım ve49favori SQL kabulü tamamlanmış geçmiş işlerdir; TLS adımı aynı fixture verilerini kullanır, yeni iş verisi/şema/grant eklemez.

## Yaklaşım ve belirsizlik

[Microsoft container TLS yapılandırması](https://learn.microsoft.com/en-us/sql/linux/containers/security?view=sql-server-ver17), sunucu sertifika/anahtar dosyaları ve mssql.conf TLS seçenekleriyle geçici SQL container'ını yapılandırmaya izin verir. Önce mevcut SQL kabul adımları tamamlanır; ardından sadece bu job'un container kimliği/digest/loopback portu doğrulanır, dosyalar kopyalanır ve container yeniden başlatılır.

[OpenSSL varsayılan CA yolları](https://docs.openssl.org/3.0/man3/SSL_CTX_load_verify_locations/) `SSL_CERT_FILE`/`SSL_CERT_DIR` süreç ortamıyla değiştirilebilir. ODBC18'in seçilmiş runner'da bu yolu kullanacağı **çıkarım ve bekleyen deneysel kabul**dür; Microsoft PHP DSN'sinde ayrı CAfile parametresi varsayılmadı. Test geçmezse system truststore kurma veya doğrulama gevşetme fallback'i yok; kapsam yeniden değerlendirilir.

## Geçici sınırlar

- `$RUNNER_TEMP/lumen-tls.XXXXXXXX` dizini private0700; rastgele RSA2048 CA/server anahtarları ve2günlük SHA256 sertifikalar. CAprivate anahtarları imzadan hemen sonra silinir. Server key sadece fixture ömründe kalır.
- IP SAN127.0.0.1 pozitif adı; localhost sertifikada yok ve hostname negatifidir. Geçici server config TLS1.2 + forceencryption1 kullanır; üretim dosyası/DSN değişmez.
- Kopyalanan server cert/key yalnız job SQL container'ında mssql kullanıcısına400; mssql.conf10001:0/600. Bu sadece sentetik server fixture ayarıdır.
- CAfile ve boş CAdir yalnız tek PHP child komutunun ortamında belirtilir. Runner/yerel OS truststore, GITHUB_ENV ve job genel güven ayarı değiştirilmez. `/etc/hosts` veya DNS kaydı eklenmez.
- EXIT cleanup host private dosyaları ve container key dizinini siler. Job yaşam döngüsü container'ı kapatıp kaldırır. Ani runner kaybında trap garantisi yok; kalıcı volume/artifact/cache olmaması ikinci sınırdır.
- Genel runtime, credential, migration, yeniDB/grant, üretim pilot veya transfer komutu oluşturulmaz. Mevcut geçici transfer hesabı kullanılır.

## Üç sert kapı

1. Doğru test CA +IP SAN: native, değiştirilmemiş `LumenConnectionFactory::connect` ile Encrypt=yes/TrustServerCertificate=no bağlantı ve sınırlı hesap metadata/DBadı kabulü. Restart hazırlığı için sınırlı retry; doğrulama seçenekleri hiç değiştirilmez.
2. Yanlış CA, doğru endpoint/hesap: nativePDO sertifika hatası vermeli ve fabrika güvenli ret üretmeli.
3. Doğru CA, yanlış hostname: aynı loopback servisine localhost üzerinden nativePDO sertifika/hostname hatası vermeli ve fabrika ret üretmeli. DNS/port/credential gibi sıradan hatalar negatif kabul yerine geçmez.

Her mod ayrı process: önceki bağlantı havuzu/CAcache sonucu diğer modu yanlış pozitif yapamaz. Pozitif kapı geçmeden negatifler koşmaz. Doğru CA dosyası yanlış CA testinde sistem deposuna kurulu değildir. IP SAN desteği, ODBCenvironment yolu, gerçek server restart ve hostname hata sınıflaması CI'da doğrulanacaktır; şimdiden geçti sayılmaz.

## Yerel kontroller

`python3 tests/tls-fixture-regression.py`: gerçek yerel OpenSSL ile CA zinciri/IP doğrulama, yanlış CA/hostname reddi, leafCAfalse/serverAuth, anahtar eşleşmesi, kısa ömür, izinler, CAkey silme, nonempty/symlink ret; CLI/PHPShell CIguard ve config sahipliği/sözdizimi. Fixturelar TemporaryDirectory kapanırken silinir; özel anahtarlar depoya yazılmaz.

`python3 tests/ci-workflow-regression.py`: TLSstep yalnız mevcut services ID, önceki SQLstep sonrası, process-only trust/cleanup ve system CA kurulumu bulunmaması. `SKIP_RECTOR=1 bash scripts/validate.sh` TLSfixture testsuite'ini de çalıştırır.

## Gerekli izin

Yeni kesin yerel commit'i aynı inceleme dalına göndermek ve mevcut manuel Lint/izole SQL workflow'unu bir kez çalıştırmak ayrı izin gerektirir. Bu koşu yalnız job SQL container'ında geçici sertifika/config değişikliği+restart, childprocess CA yolları ve sonunda cleanup içerir. Üretim/kalıcı güven kurulumu, main/beta/PR/merge/deploy ve gelecekteki yayın bu iznin kapsamı değildir.

Başarılı geçici kabul bile gerçek hedef ortamın sertifika zinciri/hostname/yenileme kabulü değildir. Üretim kullanımından önce o hedef ayrıca doğrulanır.
