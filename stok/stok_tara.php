<?php
declare(strict_types=1);

// Gerekli yapılandırma ve loglama dosyalarını yükle.
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../log_ip.php");

// Güvenli oturum ve yetki kontrolünü zorunlu kıl.
require_once __DIR__ . '/../kontrol.php';

// YETKI KONTROLÜ: M7 (Stok Ara) yetkisi kontrolü
if (m_p_yetki($terminalkullanici, 'M7') != 1 && (int)$yetkidurum !== 0) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// (Opsiyonel) link paramı – sadece uygulama içi göreli yollar kabul edilir.
function getSafeReturnLink(string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return '../index.php';
    }

    // javascript:, data:, http:, https: gibi şemaları ve // ile başlayanları engelle
    if (preg_match('/^[a-z][a-z0-9+\-.]*:/i', $candidate) || substr($candidate, 0, 2) === '//') {
        return '../index.php';
    }

    // Dışarı çıkış ve kontrol karakterleri engeli
    if (strpos($candidate, '..') !== false || preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
        return '../index.php';
    }

    // Uygulama içi basit path + query karakterleri
    if (!preg_match('/^[A-Za-z0-9_\/\-.?=&%#]+$/', $candidate)) {
        return '../index.php';
    }

    $normalized = ltrim($candidate, '/');
    return $normalized !== '' ? $normalized : '../index.php';
}

$rawLink = isset($_GET['link']) ? (string) $_GET['link'] : '';
$link = getSafeReturnLink($rawLink);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Stok Goruntuleme</title>
  <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
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
    }
    * { box-sizing: border-box; margin: 0; }
    body {
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      background: var(--bg);
      color: var(--text-1);
      min-height: 100vh;
    }

    /* ═══════ HEADER ═══════ */
    .top-header {
      position: sticky; top: 0; z-index: 40;
      height: 64px;
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border-bottom: 1px solid rgba(248, 113, 113, 0.18);
      box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
    }
    .header-inner {
      max-width: 1100px; margin: 0 auto;
      height: 100%; display: flex; align-items: center; gap: 14px;
      padding: 0 24px;
    }
    .header-back {
      display: inline-flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 10px;
      color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
    }
    .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
    .header-divider { width: 1px; height: 24px; background: var(--border); }
    .header-title {
      font-size: 18px; font-weight: 700; color: var(--text-1);
      display: inline-flex; align-items: center; gap: 8px;
    }
    .header-title i { color: var(--red,#ef4444); font-size: 16px; }

    /* ═══════ SEARCH PANEL ═══════ */
    .search-panel {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      padding: 14px;
      margin-bottom: 16px;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
      will-change: transform, opacity;
    }
    .search-wrap { position: relative; }
    .search-wrap i.fa-search {
      position: absolute;
      top: 50%; left: 14px;
      transform: translateY(-50%);
      color: var(--text-3);
      font-size: 14px;
      pointer-events: none;
    }
    .search-input {
      width: 100%;
      padding: 13px 14px 13px 40px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 14px;
      color: var(--text-1);
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 10px;
      outline: none;
      transition: all 0.2s ease;
    }
    .search-input:focus {
      border-color: rgba(239, 68, 68, 0.5);
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }

    /* ═══════ LIST PANEL ═══════ */
    .list-panel {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      overflow: hidden;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.08s both;
      will-change: transform, opacity;
    }
    .list-head {
      display: grid;
      grid-template-columns: 140px 1fr 120px 90px;
      gap: 12px;
      padding: 10px 16px;
      background: linear-gradient(180deg, #fff, #fffafa);
      border-bottom: 1px solid var(--border);
      font-size: 10px;
      font-weight: 700;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .list-head .col-right { text-align: right; }
    .list-body { list-style: none; padding: 0; margin: 0; }
    .list-body li {
      border-bottom: 1px solid #f3f4f6;
      transition: background 0.15s ease;
    }
    .list-body li:last-child { border-bottom: none; }
    .list-body li:hover { background: #fafafa; }

    .list-empty {
      padding: 40px 20px !important;
      text-align: center;
      color: var(--text-3);
      font-size: 13px;
    }
    .list-empty i {
      display: block;
      font-size: 32px;
      margin-bottom: 10px;
      color: var(--text-3);
    }

    /* Server-tarafi HTML icin: AJAX donuslu satirlara bu class eklenir ise */
    .list-row {
      display: grid;
      grid-template-columns: 140px 1fr 120px 90px;
      gap: 12px;
      padding: 11px 16px;
      align-items: center;
      font-size: 13px;
    }
    .list-row .kod { font-family: 'JetBrains Mono', monospace; color: var(--red); font-weight: 600; }
    .list-row .ad { color: var(--text-1); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .list-row .bk { color: var(--text-3); font-size: 11px; }
    .list-row .stk { text-align: right; font-weight: 700; color: var(--emerald); }

    /* Pager / load more */
    .load-more-wrap {
      padding: 14px;
      text-align: center;
    }
    .btn-load-more {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 22px;
      background: var(--red,#ef4444);
      color: #fff;
      border: none;
      border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 4px 14px rgba(239, 68, 68, 0.22);
    }
    .btn-load-more:hover { background: var(--red); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3); }
    .btn-load-more:disabled { opacity: 0.6; cursor: wait; transform: none; }

    .load-error {
      padding: 12px 16px;
      background: var(--red-soft);
      color: var(--red);
      font-size: 12px;
      font-weight: 500;
      text-align: center;
      border-top: 1px solid rgba(239, 68, 68, 0.15);
    }

    .result-total {
      padding: 12px 16px;
      text-align: center;
      color: var(--text-3);
      font-size: 11px;
      font-weight: 500;
      background: #fafafa;
      border-top: 1px solid var(--border);
    }

    /* Skeleton loading */
    .sk-row {
      display: grid;
      grid-template-columns: 140px 1fr 120px 90px;
      gap: 12px;
      padding: 11px 16px;
      border-bottom: 1px solid #f3f4f6;
    }
    .sk-row .bar {
      height: 11px;
      background: linear-gradient(90deg, #f3f4f6 0%, #e5e7eb 50%, #f3f4f6 100%);
      background-size: 200% 100%;
      border-radius: 4px;
      animation: shimmer 1.4s infinite linear;
    }
    .sk-row .bar.short { width: 65%; }
    .sk-row .bar.right { margin-left: auto; width: 55%; }
    @keyframes shimmer {
      0% { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }

    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    /* ═══════ MOBILE ═══════ */
    @media (max-width: 767px) {
      .top-header { height: 50px; }
      .header-inner { padding: 0 12px; gap: 10px; }
      .header-title { font-size: 15px; }
      .header-back { width: 30px; height: 30px; border-radius: 8px; }

      main { padding: 14px 12px 40px !important; }

      .search-panel { padding: 12px; margin-bottom: 12px; }
      .search-input { font-size: 16px; padding: 12px 14px 12px 38px; } /* iOS zoom engeli */

      .list-head { display: none; }
      .list-row, .sk-row {
        grid-template-columns: 1fr auto;
        gap: 4px 12px;
        padding: 12px 14px;
      }
      .list-row .kod { font-size: 11px; grid-column: 1; }
      .list-row .stk { grid-column: 2; grid-row: 1 / 3; align-self: center; }
      .list-row .ad { grid-column: 1; font-size: 13px; }
      .list-row .bk { grid-column: 1; font-size: 10px; }
      .sk-row .bar { height: 10px; }
    }
  </style>
</head>
<body>

  <header class="top-header">
    <div class="header-inner">
      <a href="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>" class="header-back" title="Geri Don">
        <i class="fa fa-arrow-left"></i>
      </a>
      <div class="header-divider"></div>
      <span class="header-title">
        <i class="fa-solid fa-magnifying-glass"></i>Stok Goruntuleme
      </span>
    </div>
  </header>

  <main style="max-width:1100px;margin:0 auto;padding:18px 24px 60px;">

    <!-- Arama -->
    <div class="search-panel">
      <div class="search-wrap">
        <i class="fa-solid fa-search"></i>
        <input
          type="text"
          id="arama-input"
          class="search-input"
          placeholder="Urun adi, kodu veya barkod yazin..."
          autofocus
          autocomplete="off" />
      </div>
    </div>

    <!-- Liste Paneli -->
    <div class="list-panel">
      <div class="list-head">
        <div>Kod</div>
        <div>Urun Adi</div>
        <div>Barkod</div>
        <div class="col-right">Stok</div>
      </div>

      <ul id="liste-icerik" class="list-body">
        <li class="list-empty">
          <i class="fa-solid fa-magnifying-glass"></i>
          Aramaya baslamak icin yukariya yazin
        </li>
      </ul>
    </div>
  </main>

  <script src="/tm/js/jquery-3.7.1.min.js"></script>
  <script>
    $(function() {
      var gecikme = null;
      var $input = $('#arama-input');
      var $liste = $('#liste-icerik');
      var currentPage = 1;
      var totalResults = 0;
      var isLoading = false;
      var activeRequest = null;
      var lastQuery = '';

      function renderBosMesaj(mesaj, iconClass) {
        $('#load-more-error').remove();
        var icon = iconClass || 'fa-magnifying-glass';
        $liste.html(
          '<li class="list-empty"><i class="fa-solid ' + icon + '"></i>' + mesaj + '</li>'
        );
      }

      function showLoadMoreError(mesaj) {
        $('#load-more-error').remove();
        $liste.append(
          '<li id="load-more-error" class="load-error">' + mesaj + '</li>'
        );
      }

      function renderLoader(append) {
        if (append) {
          var $btn = $('#btn-load-more');
          if ($btn.length) {
            $btn.data('original-text', $btn.text());
            $btn.prop('disabled', true).html('<i class="fa-solid fa-circle-notch fa-spin"></i> Yukleniyor...');
          }
          return;
        }
        var skeletonRow = function() {
          return '<li><div class="sk-row">'
            + '<div class="bar short"></div>'
            + '<div class="bar"></div>'
            + '<div class="bar short"></div>'
            + '<div class="bar right"></div>'
            + '</div></li>';
        };
        $liste.html(skeletonRow() + skeletonRow() + skeletonRow() + skeletonRow());
      }

      function appendPager(data) {
        // Eski pager'i kaldir
        $('#btn-load-more').closest('li').remove();
        $('.result-total').closest('li').remove();

        if (data.has_more) {
          $liste.append(
            '<li><div class="load-more-wrap">' +
            '<button id="btn-load-more" class="btn-load-more" type="button">' +
            '<i class="fa-solid fa-plus"></i> Daha Fazla Goster ' +
            '<span style="opacity:0.8;font-weight:500;">(' + data.showing + ' / ' + data.total + ')</span>' +
            '</button></div></li>'
          );
        } else if (data.total > 0) {
          $liste.append(
            '<li class="result-total">Toplam ' + data.total + ' sonuc gosteriliyor</li>'
          );
        }
      }

      function performSearch(page, append) {
        if (isLoading) return;

        var q = $input.val().trim();
        if (!append) { // yeni arama
          currentPage = 1;
          totalResults = 0;
        }

        if (q === '') {
          lastQuery = '';
          renderBosMesaj('Aramaya baslamak icin yukariya yazin');
          return;
        }
        if (q.length < 3) {
          lastQuery = q;
          renderBosMesaj('Lutfen en az 3 karakter girin', 'fa-keyboard');
          return;
        }

        // Aynı sorguda tekrar arama yapılırsa (örn. Enter), sayfa 1'de listeyi sıfırla
        renderLoader(append === true);

        // Önceki istek varsa iptal et
        if (activeRequest && activeRequest.readyState !== 4) {
          activeRequest.abort();
        }

        isLoading = true;
        lastQuery = q;

        activeRequest = $.ajax({
          url: 'urun_arama_ajax.php',
          type: 'POST',
          dataType: 'json',
          cache: false,
          data: {
            barkod: q,
            page: page || 1
          }
        }).done(function(data) {
          if (data && data.error) {
            if (append) {
              var $btn = $('#btn-load-more');
              var originalText = $btn.data('original-text') || 'Daha Fazla Göster';
              $btn.prop('disabled', false).text(originalText);
              showLoadMoreError(data.error);
            } else {
              renderBosMesaj(data.error);
            }
            return;
          }

          if (!data || !data.html || data.html.trim() === '') {
            if (!append) {
              renderBosMesaj('Sonuc bulunamadi', 'fa-circle-xmark');
            } else {
              // append modunda boş geldiyse pager'ı kaldır, mesaj verme
              $('#btn-load-more').closest('li').remove();
            }
            return;
          }

          totalResults = data.total || 0;
          currentPage = page || 1;

          if (append) {
            // önce eski pager'ı kaldır, sonra yeni satırları ekle
            $('#btn-load-more').closest('li').remove();
            $('#load-more-error').remove();
            $liste.append(data.html);
          } else {
            $liste.html(data.html);
          }

          appendPager(data);

        }).fail(function(xhr, status, err) {
          if (status === 'abort') return; // iptal normal
          console.error('AJAX Hata:', status, err, xhr && xhr.responseText ? xhr.responseText.slice(0, 500) : '');

          // Session timeout veya sunucu custom error sayfası yakalanırsa daha anlaşılır mesaj ver.
          var responseText = xhr && typeof xhr.responseText === 'string' ? xhr.responseText : '';
          var sessionRedirect = responseText.indexOf('giris.php') !== -1 || responseText.indexOf('oturum_gerekli') !== -1;
          var serverHtmlError = responseText.indexOf('<html') !== -1 || responseText.indexOf('500') !== -1;

          var failMessage = 'Sunucu hatasi olustu. Lutfen tekrar deneyin.';
          if (status === 'parsererror') {
            failMessage = sessionRedirect
              ? 'Oturum suresi dolmus olabilir. Sayfayi yenileyip tekrar giris yapin.'
              : (serverHtmlError ? 'Sunucu hata sayfasi dondu. Sistem yoneticinize bildirin.' : 'Veri formati hatali.');
          }

          if (append) {
            var $btn = $('#btn-load-more');
            var originalText = $btn.data('original-text') || 'Daha Fazla Goster';
            $btn.prop('disabled', false).html(originalText);
            showLoadMoreError('Yeni kayitlar yuklenemedi. ' + failMessage);
            return;
          }

          renderBosMesaj(failMessage, 'fa-triangle-exclamation');
        }).always(function() {
          isLoading = false;
        });
      }

      // Debounce ile arama
      $input.on('keyup', function() {
        clearTimeout(gecikme);
        gecikme = setTimeout(function() {
          performSearch(1, false);
        }, 300);
      });

      // Enter'a bastığında gecikmeyi bekleme
      $input.on('keydown', function(e) {
        if (e.key === 'Enter') {
          clearTimeout(gecikme);
          performSearch(1, false);
        }
      });

      // "Daha Fazla Göster"
      $(document).on('click', '#btn-load-more', function() {
        if (isLoading) return;
        performSearch(currentPage + 1, true);
      });
    });
  </script>
</body>
</html>
