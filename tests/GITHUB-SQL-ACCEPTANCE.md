# GitHub Actions — geçici SQL kabul kapısı

Bu paket yerel dalda hazırlandı; workflow GitHub'a gönderilmedi ve çalıştırılmadı. Gerçek SQL, Composer install/audit ve PDF/XLSX kabulü henüz bu paketle geçmedi.

## Güncel kullanılabilirlik kontrolü — 9 Ekim 2026

Salt okunur GitHub API kontrolleri Lumen'in public, archived/disabled olmayan, main dalı bulunan repo olduğunu; Actions `enabled=true`, `allowed_actions=all` olduğunu doğruladı. Mevcut oturum `husodrn46`, repo izinlerinde admin/push görünür; bu durum push yetkisinin bu görevde kullanıldığı anlamına gelmez.

[Son görünen Lint işi](https://github.com/husodrn46/Lumen/actions/runs/30529063409) 30 Temmuz 2026'da başarılı. [Araştırma tabanı main işi](https://github.com/husodrn46/Lumen/actions/runs/30519026985) da başarılı. [29 Temmuz başarısız iş](https://github.com/husodrn46/Lumen/actions/runs/30436999112) runner üzerinde başladı; PHP 8.2 bağımlılık kurulumunda durdu, PHP 8.3 işi başarılıydı. Bunlar **tarihsel** sonuçlar; yeni SQL workflow'unun bugünkü sonucu değildir. Güncel main ile yerel taban `636bae488cc092cb92350acc5a394857bbee832f` karşılaştırması identical geldi.

Hesabın güncel billing/usage API'si mevcut izinle 404/scope engeli verdi. Token kapsamı yenilenmedi veya ödeme/limit ayarı değiştirilmedi. Bu yüzden eski hesap limit sorununun bugün kesin çözüldüğü söylenemez. Yeni run başlatılmadan hesabın güncel scheduling durumunu tam doğrulamak mümkün olmadı; run tetiklenmedi.

[GitHub billing belgesi](https://docs.github.com/en/actions/concepts/billing-and-usage), public repolarda standart GitHub-hosted runner kullanımını ücretsiz kapsamda gösterir. Bu workflow standart `ubuntu-22.04` kullanır; larger/self-hosted runner, ücretli image, cache veya upload-artifact yoktur. Public statüsü/runner sınıfı değişirse maliyet yeniden değerlendirilmelidir. Hesap erişim/faturalama kısıtı yine işi başlatmayı engelleyebilir; workflow bu kısıtı atlatmaz.

## Hazırlanan workflow

[sqlserver-acceptance.yml](../.github/workflows/sqlserver-acceptance.yml):

- Manuel `workflow_dispatch` ve mevcut Lint üzerinden aynı ref içindeki reusable `workflow_call` girişi. Gerçek SQL job ayrıca `github.event_name=workflow_dispatch` ister; push/PR/call ile otomatik SQL başlamaz. Onay checkbox'ı varsayılan false; yalnız true ve `husodrn46/Lumen` repo bağlamında job başlar. Checkbox false/iş skipped sonucu SQL kabulü değildir.
- `contents: read`, checkout `persist-credentials: false`; repo içeriği okunur. Push/PR/comment/release/deploy yetkisi yoktur. Actions/setup-php varsayılan kısa ömürlü GitHub token'ı kullanabilir; özel PAT veya production secret istenmez.
- Tek Ubuntu 22.04 **x86-64** job; 20 dakika sınırı ve aynı workflow için seri çalıştırma. GitHub'ın [Linux service container](https://docs.github.com/en/actions/tutorials/use-containerized-services/use-docker-service-containers) özelliği kullanılır.
- Resmî `mcr.microsoft.com/mssql/server:2022-latest` image'i immutable digest'e sabitlendi: `sha256:4402d880dd4c34bfa7d8705e56a86cd6c88da80a1f6bbbe741f999e76264a090`. 9 Ekim MCR manifest/config okuması `linux/amd64`, build 26 Ağustos 2026 gösterdi. Image yerelde pull edilmedi/çalıştırılmadı. Digest güvenlik güncellemesi gerektiğinde bilinçli yenilenmelidir.
- SQL container localhost 1433'e bağlanır, 4 GB/2 CPU sınırı vardır. TCP health ardından PDO ile bounded SQL/login readiness kontrolü yapılır. Host/DB parametresi kullanıcıdan alınmaz.
- PHP 8.3, sabit `pdo_sqlsrv-5.13.3`; Microsoft ODBC18 ve unixODBC development bağımlılığı yalnız geçici runner'a kurulur. PHP driver sürümü, ODBC18 kaydı ve SQL 16.x Developer edition gerçek run sırasında ayrıca doğrulanır. Microsoft [driver matrisi](https://learn.microsoft.com/en-us/sql/connect/php/microsoft-php-drivers-for-sql-server-support-matrix?view=sql-server-ver17) PHP 8.3/Ubuntu 22.04/SQL 2022 ile 5.13 sürümünü destekler.
- SQL Developer `MSSQL_PID=Developer`, `ACCEPT_EULA=Y`. [Microsoft edition belgesi](https://learn.microsoft.com/en-us/sql/sql-server/editions-and-components-of-sql-server-2022?view=sql-server-ver16) Developer'ı geliştirme/test kullanımıyla sınırlar. Bu, ücretsiz Lumen'in üretim SQL lisans kararını değiştirmez. Workflow'u çalıştırma onayı geçici test ortamındaki Microsoft SQL/ODBC EULA kabulünü de kapsamalıdır.

## Kimlik bilgisi ve veri sınırı

Container bootstrap hesabı için GitHub run ID/attempt'ten üretilen **herkese açık, yalnız o geçici instance'a ait** parola kullanılır. Production secret değildir; bilindiği için SQL portu yalnız loopback'tedir. Mevcut bir SQL hesabı/parolası veya repo Secret kopyalanmaz.

`tests/ci/sqlserver-prepare.php`, yalnız gerçek GitHub-hosted Linux/X64, bu repo, workflow_dispatch ve açık hazırlık flag'iyle çalışır. Localhost master üzerinde yalnız iki fixed DB'nin **henüz bulunmadığını** doğrular; varsa durur. SQL 2022 Developer sürümünü de doğrular. Geçici sınırlı test login'i için `random_bytes` ile ayrı parola üretir, log mask uygular, yalnız sonraki step'lere runner'ın `GITHUB_ENV` dosyasıyla taşır. Credential/API trace yazdırılmaz.

Sadece `LumenTest_Start_CI` ve `LumenTest_Idem_CI` oluşturulur. Fixture hesabı yalnız bu DB'lerde DDL/data read-write/VIEW DEFINITION alır; sysadmin/dbcreator/CREATE DATABASE yetkisi almaz. Fixture account'unun DDL yetkisi bu boş sentetik DB'leri hazırlamak içindir; production runtime least-privilege kabulünün yerine geçmez. Hesap ve DB'ler job container'ıyla biter; harici volume, cache, credential/data artifact veya otomatik DROP yoktur.

Mevcut test runner'ların **localhost + LumenTest_*** DSN guard'ı korunur. CI'da self-signed container için `Encrypt=yes;TrustServerCertificate=yes` açıkça seçilmiştir; bu sadece geçici loopback testinde kullanılır ve production TLS önerisi değildir. Bootstrap master bağlantısı yalnız ayrı CI hazırlayıcıda bulunur; genel test helper master'ı kabul etmez.

## Run sırasında zorunlu kapılar

1. Strict Composer validate; **tüm lock** için audit (dev dahil); üretim install `--no-scripts --no-plugins`; gerçek platform gereksinimi; PDF/XLSX smoke. Lock otomatik güncellenmez. Yeni advisory veya platform hatasında durur.
2. Mevcut lint/güvenlik/helper/API/web yerel paketleri.
3. İki boş DB ve test hesabı hazırlığı; eski DB veya yanlış CI bağlamı reddi.
4. Start DB'de preflight → gerçek SQL identity/trigger/20 farklı paralel yazı/rollback/token lifecycle → preflight.
5. Ayrı Idem DB'de gerçek 20 aynı-key yarış, aynı receipt replay, farklı-key ikinci fiş, rollback, lost response ve 5 saniyelik lock timeout sonrası replay.

Bir adım başarısız olursa job başarısızdır; `continue-on-error` veya test yerine başarılı skip yoktur. SQL testi dışında başarısız Composer gate de kabulü engeller. Job service'leri sona erince platform tarafından kaldırılır.

Bu küçük SQL fixture gerçek LOGO'nun 95+ kolon/trigger sözleşmesi veya gerçek login/session ile web endpoint entegrasyonu değildir. CI SQL job'u geçtiğinde yalnız **sentetik veriyle gerçek SQL Server helper/transaction kabulü** geçmiştir; üretim LOGO/web uçtan uca, satır düzenleme/sevk yarışı ve tüm web güvenliği tamamlandı denmez.

## Yerelde çalıştırılan kontroller

```sh
php tests/ci-prepare-regression.php
python3 tests/ci-workflow-regression.py
SKIP_RECTOR=1 bash scripts/validate.sh
```

28 CI hazırlık assertion'ı (PDO test double), 32 YAML/workflow güvenlik assertion'ı (yerelde mevcut PyYAML) ve Bash run bloklarının `bash -n` kontrolü geçti. Bunlar GitHub schema validator, Docker health/startup veya SQL kurulumu testi değildir. Hazırlayıcı Forge'da normal çağrıldığında exit 2 ile güvenli durdu; hiçbir SQL bağlantısı/DB yazısı yapılmadı. Validate 261 PHP dosyasında lint temiz, güvenlik/PHP paketleri başarılı; Composer yokluğu nedeniyle Composer bölümü atlandı, Rector açıkça atlandı. Önceki HTTP/JS paketleri bu CI-only değişiklikten sonra yeniden çalıştırılmadı.

## Gerekli sonraki yayın adımı — yapılmadı

Yerel API/web/helper/fixture yamalarıyla birlikte yeni workflow ve CI hazırlayıcılar onaylanan review dalına commit/push ile ulaşmalıdır. Yalnız workflow'u göndermek, branch'te bulunmayan helper/test dosyaları nedeniyle çalışmaz.

Repo'da **mevcut Lint workflow'u aktif** (id `320199424`, `.github/workflows/lint.yml`). Yerel Lint'e yalnız açık manuel onay halinde aynı-ref SQL reusable job çağrısı eklendi. Bu sayede onaydan sonra önce review dalı push edilir, kayıtlı Lint o branch ref'inde onay input'uyla manuel çalıştırılır; SQL job gerçek kabulü vermeden uygulama yamalarını main'e merge etme zorunluluğu yoktur. Yeni bağımsız SQL workflow'u ancak daha sonra main'e merge edilince Actions ekranında ayrı manuel giriş olarak keşfedilir. [GitHub manuel/ref kılavuzu](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow).

Onay sonrasına ait örnek (bu görevde çalıştırılmadı):

```sh
gh workflow run lint.yml --ref codex/lumen-startup-safety -f confirm_synthetic_only=true
```

Repo/account scheduling veya setup sorunu varsa ilk job gerçek engeli gösterecek. Remote SQL sonucu iş loglarıyla doğrulanmalıdır; statik/local sonuçlar yerine kullanılamaz.

Bu aşamada commit/push/PR/merge veya remote run tetikleme yapılmadı. Üretim kurulumu/secret/veri, Forge global paket veya Docker başlatma, başka AKEL görevi ve tarayıcı/Apple session'ı kullanılmadı.
