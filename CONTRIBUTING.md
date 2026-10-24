# Katkı rehberi

Lumen'de değişiklikler doğrudan `main` dalına gönderilmez. Görevin türüne göre
`duzeltme/`, `guvenlik/`, `gelistirme/`, `tasarim/`, `test/` veya `ci/` önekli kısa bir dal
açın; pull request kontrolleri geçtikten sonra squash merge kullanın.

Katkı gönderirken [davranış kurallarına](CODE_OF_CONDUCT.md) uyun. Güvenlik
açıklarını herkese açık issue yerine [güvenlik politikasındaki](SECURITY.md)
özel kanaldan bildirin.

## Yerel kontroller

```bash
composer install
php tests/birim/calistir.php
bash scripts/security-regression.sh
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
```

SQL Server kullanan uçtan uca akışlar için sentetik demo ortamını kullanın:

```bash
bash demo/kur.sh
docker compose -f demo/docker-compose.yml exec -T app php tests/akis/akislar.php
```

Canlı firma verisini test amacıyla kullanmayın. `.env`, `_baglanti_.inc`, yedekler,
ekler ve erişim anahtarları depoya eklenmemelidir.

## Güvenlik beklentileri

Yeni veya değişen her uçta kimlik doğrulama, en az yetki, özel cari kısıtı, CSRF
ve girdi doğrulaması kontrol edilir. İstemciye ham istisna, SQL, dosya yolu ya da bağlantı
bilgisi döndürülmez; `app_public_error_message()` ile referans kodu üretilir. Güvenlik
düzeltmesine, mümkünse açığı doğrudan deneyen bir akış testi eklenir.

## Sürüm

Kullanıcıya dönük değişiklikler `CHANGELOG.md` dosyasına eklenir. Kararlı sürüm
etiketi, yalnız `main` kontrolleri başarılı olduktan sonra `vX.Y.Z` biçiminde oluşturulur.
