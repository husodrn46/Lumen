# Yapay zekâ ajanları için

Lumen: LOGO Tiger için PHP ön yüzü ve (beta) kendi veritabanıyla çalışan ön muhasebe.
Arayüz ve belgeler Türkçe. Ayrıntı: `README.md`, `docs/mimari/bagimsiz-lumen.md`.

## Bir sürümü denemeniz (kabul testi) istendiyse

**`docs/test/KABUL_TESTI.md` rehberini izleyin.** Kısaca:

```bash
git checkout <sürüm etiketi>          # ör. en yeni Pre-release
bash tests/kabul/calistir.sh          # Docker gerekir; rapor: kabul-raporu.md
```

Docker yoksa GitHub üzerinde: `gh workflow run kabul.yml -f ref=<etiket>`.
Gerçek bir kurulumu dışarıdan denetlemek için: `php tests/kabul/sunucu_denetimi.php <adres>`.
Yalnız demo verisiyle çalışın; kod değiştirmeyin; sonucu rapor olarak verin.

## Kod üzerinde çalışıyorsanız

- Testler uygulama konteynerinde çalışır (`bash demo/kur.sh`, sonra
  `docker compose -f demo/docker-compose.yml exec -T app php tests/...`): `tests/birim/calistir.php`,
  `tests/duman/tara.php`, `tests/akis/akislar.php`, bağımsız mod için `bash demo/kur.sh --bagimsiz` ve
  `tests/akis/bagimsiz.php`. Statik denetimler: `bash scripts/validate.sh`, `bash scripts/security-regression.sh`.
- Yeni PHP dosyalarında `declare(strict_types=1);`, sorgularda prepared statement, çıktıda kaçış
  (`htmlspecialchars`), formlarda CSRF (`csrf_field()` / `csrf_verify()`), yetki `m_p_yetki()`.
- Sürüm yöntemi: `main` kararlı sürümdür (GitHub "Latest"); yeni özellikler önce beta ön sürümü
  (Pre-release) olur. Güvenlik yamaları önce kararlıya, sonra betaya gider.
