# Favori aktarımı SQL adaptörü: bekleyen kabul

Durum: yerel hazırlık; gerçek SQL çalıştırılmadı. `LumenFavoriteTransfer` bir iç adaptördür; uç nokta, üretim aktarım komutu veya pilot bağlantısı yoktur. Çağıran taraf sunucu kapsamını ve yetkili kullanıcıyı doğrulamalı, ayrı Lumen bağlantı fabrikasını ve `PDO::ERRMODE_EXCEPTION` kullanmalıdır. Silent/warning modu reddedilir; exception chain ve driver metni dışarı taşınmaz.

Mevcut eşleme ve favoriler aynı transaction içinde `UPDLOCK,HOLDLOCK` ile okunur. Kapsamlı ledger anahtarı da kilitlenir; personel sırası stabildir. Mevcut değerin üzerine yazma yoktur. Ledger sonucu ve yeni favoriler birlikte commit olur. Başarılı intent tekrarı tarihi sonucu döndürür; bugünkü hedef değerini doğrulamaz. Aynı anahtar farklı içerikle reddedilir. Genel hata sonrası niyet/anahtar değiştirilmeden uzlaştırma veya tekrar gerekir; güvenli sayısal 1205/1222 sınıfları hariç driver ayrıntısı saklanmaz.

Microsoft'un [table hint sözleşmesi](https://learn.microsoft.com/en-us/sql/t-sql/queries/hints-transact-sql-table?view=sql-server-ver17) HOLDLOCK'u SERIALIZABLE ile eşdeğer tanımlar. Bu tasarımın motor kabulü aşağıdaki testlerde beklemektedir; yerel PDO double gerçek kilit veya grant davranışını doğrulamaz.

`database/lumen/migrations/002_favorite_transfer.sql` ayrı veritabanı şema taslağıdır. İnceleme session-context kapısı ve 001 tablosu ön koşulu vardır. Runtime DDL değildir; hiç uygulanmadı. Ledger PK firma/bağlantı/istek anahtarı içerir. Yalnız fingerprint ve sayaç tutar; tercih veya kişi listesi tutmaz. Ledger'ın saklama/temizleme politikası bu pakette uygulanmaz; temizlenirse aynı anahtarın tarihi replay garantisi kaybolur. Gerçek geçiş önce bu politikayı ayrıca belirlemelidir.

## Mevcut runner ile tek bütün kabul planı

1. Aynı inceleme dalındaki Lint iş akışı yalnız manuel `confirm_synthetic_only=true` ile mevcut SQL workflow'unu çağırır. Hiçbir yeni otomatik push/PR tetikleyicisi yoktur.
2. Mevcut digest-pinned SQL2022Developer, localhost portu, üç yeni sentetik DB ve maskeli geçici hesap düzeni korunur. Yeni DB/kalıcı servis yok.
3. Eski PHP/HTTP/JS, kimlik/token, sipariş idempotency ve favori49 SQL kabulü önce koşar. `LumenTest_Fav_CI` üzerinde 001 şeması ve önceki fixture hazırlanmış olur.
4. Yeni `tests/sqlserver-favorite-transfer.php` yalnız aynı GitHub-hosted Linux/X64 repository/event/run kapısı ve **tam sabit sentetik DSN** ile başlayabilir. Yerel çağrı check0'da, PDO/şema işlemi öncesi reddedilir. Fixture hesabı 002 taslağını yalnız bu geçici DB'de uygular.
5. Yeni geçici `_transfer` hesabına links SELECT, favorites SELECT/INSERT, ledger SELECT/INSERT verilir; mevcut uygulama `_fav` hesabına ledger yetkisi verilmez. DDL/update/delete/eşleme/crossDB reddi test edilir. Operator bu şema dışında kalıcı yetki almaz.
6. Ayrı PHP process/PDO bağlantıları: 10 aynı anahtar → bir ilk işlem + 9 replay; 10 farklı anahtar aynı hedef → bir ekleme + 9 stale reddi. Sentetik deadlock/locktimeout retry yalnız1205/1222 için ve en fazla5 denemedir; başka driver hatası başarıya çevrilmez.
7. Gerçek CHECK ihlali ikinci satırda → ilk satır+ledger rollback; hata sonrası tekrar; sonradan değişmiş hedef/eşleme; aynı/boş/ham farklı değer; lost-response replay; kapsam ayrımı; parent row-lock + worker LOCK_TIMEOUT300 → gerçek1222, kapalı işlem ve güvenli retry.
8. Tüm işler sert kapıdır; secrets, DB artifact/cache veya deploy yok. Runner20dakika sınırı ve geçici service yaşam döngüsü korunur.

Üretim fabrikasında TLS doğrulaması gevşetilmedi. Fixture self-signed bağlantıda TrustServerCertificate=yes yalnız mevcut geçici CI DSN'sindedir. Pozitif sertifika/hostname TLS, gerçek LOGO uyumluluğu, süreç/servis çökmesi ve güç kaybı sonrası dayanıklılık bu planla geçti sayılmaz. Lost-response kabulü commit sonrası yanıtın kullanılmaması ve aynı isteğin tekrarından ibarettir.

## Yerel tekrar

```sh
php tests/favorite-transfer-regression.php
PYTHONDONTWRITEBYTECODE=1 python3 tests/ci-workflow-regression.py
SKIP_RECTOR=1 bash scripts/validate.sh
```

`php tests/sqlserver-favorite-transfer.php` yerelde koşulmaz; sadece guard reddi kontrol edilir. Yayın ve manuel çalıştırma bu hazırlığın dışında ayrıca onay gerektirir. Bu onay üretim migration/pilot açılışı değildir.
