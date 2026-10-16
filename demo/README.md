# Lumen demo ortamı

LOGO lisansı ve gerçek veri olmadan Lumen'i çalıştırmak, geliştirmek ve test etmek için
Docker tabanlı ortam. Tüm veriler **uydurmadır**.

```bash
bash demo/kur.sh          # kur / sıfırla (veritabanı her seferinde yeniden oluşturulur)
bash demo/kur.sh --kapat  # konteynerleri durdur
bash demo/kur.sh --sil    # konteynerleri ve demo veritabanını tamamen sil
```

Açılış: <http://localhost:8088/giris.php> — port `LUMEN_DEMO_PORT` ile değiştirilebilir.

| Kullanıcı | Parola | Rol |
|---|---|---|
| `YONETICI` | `demo1234` | Yönetici (tüm yetkiler) |
| `SATIS1` | `demo1234` | Personel: sipariş, cari, stok, çek |
| `DEPO1` | `demo1234` | Personel: yalnız stok |

Gereksinim: Docker ve `docker compose` (Docker Desktop, Colima veya OrbStack). Microsoft'un
SQL Server ve ODBC sürücüsü yalnız amd64 için dağıtıldığından konteynerler amd64 çalışır;
Apple Silicon'da Rosetta/QEMU ile (Colima: `colima start --vm-type vz --vz-rosetta`).

## Neler var

| Bileşen | Açıklama |
|---|---|
| `docker-compose.yml` | SQL Server 2022 (Turkish_CI_AS) · PHP 8.2-fpm + pdo_sqlsrv · nginx |
| `docker/nginx.conf` | README'deki nginx güvenlik kurallarının birebir aynısı (demo bunları da sınar) |
| `sql/01_logo_sema.sql` | **Üretilmiş** asgari LOGO şeması — yalnız Lumen'in kullandığı tablo/kolonlar |
| `sql/02_demo_veri.sql` | Sentetik veri: 25 ürün, 15 cari, 30 sipariş, cari hareketleri, çekler, kurlar |
| `araclar/sql_uygula.php` | `sql/` betiklerini **kurulum sihirbazının fonksiyonuyla** uygular |
| `araclar/kolon_cikar.py` | Kaynak koddaki SQL'den LOGO tablo/kolon listesini çıkarır |
| `araclar/sema_uret.py` | Bu listeden `sql/01_logo_sema.sql`'i üretir |
| `araclar/tekrar_parametre.py` | Bir sorguda tekrar eden `:parametre` adlarını bulur (pdo_sqlsrv desteklemez) |

Uygulama `sql/` klasöründeki Lumen şemalarını kurulum sihirbazıyla **aynı kodla**
(`includes/kurulum_sema.php`) kurar; yani demo her kurulduğunda sihirbazın temiz bir
veritabanında çalıştığı da doğrulanmış olur.

## Testler

```bash
# Birim testleri (veritabanı gerektirmez; PhpSpreadsheet testi için konteynerde çalıştırın)
docker compose -f demo/docker-compose.yml exec app php tests/birim/calistir.php

# Duman testi: tüm sayfalar × 3 kullanıcı + oturumsuz erişim + yetki sızıntısı
docker compose -f demo/docker-compose.yml exec app php tests/duman/tara.php
LUMEN_TEST_SADECE=cari/ docker compose -f demo/docker-compose.yml exec -e LUMEN_TEST_SADECE app php tests/duman/tara.php
```

Duman testi her isteği sınar: HTTP 500, gövdede PHP hata metni, istek sırasında
`logs/` altındaki PHP/uygulama hata loglarına yazılan satırlar; oturumsuz erişimin
girişe yönlenmesi; personelin yönetici sayfalarına erişememesi. GitHub Actions'taki
`demo-duman-testi` işi aynı adımları her push'ta çalıştırır.

## Şemayı güncellemek

Kod yeni bir LOGO kolonuna başvurduğunda duman testi "Invalid column name" ile düşer:

```bash
python3 demo/araclar/sema_uret.py   # kaynak koddan şemayı yeniden üret
bash demo/kur.sh                    # veritabanını yeniden kur
```

Kolon kodda takma adsız kullanılıyorsa (`SELECT X FROM ...`) `sema_uret.py` içindeki
`EK_KOLONLAR`'a ekleyin; tip yanlış tahmin edildiyse `METIN` / `TARIH` / `ONDALIK`
desenlerini düzenleyin.
