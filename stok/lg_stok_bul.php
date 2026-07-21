<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");
include_once(__DIR__ . "/urun_gorsel_helper.php");

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['barkod', 'fisid']);

// Session bazlı parametre sistemi
$a_barkod = getPageParamString('barkod');
$fisid = getPageParamInt('fisid');
$lgStokBulReturnUrl = 'lg_stok_bul.php?fisid=' . rawurlencode((string) $fisid) . '&barkod=' . rawurlencode($a_barkod);

// Döviz kontrolü
$dovizAktif = false;
$dovizKuru = 1;
$dovizSembol = '₺';
$dovizTipi = 'TL';

if ($dovizlicalis == '1' && $fisid > 0) {
  $dovizTipi = $_SESSION['doviz'] ?? '0';
  if ($dovizTipi != '0' && $dovizTipi != 'TL') {
    $dovizKuru = dovizkuru_bul($fisid);
    if ($dovizKuru > 0) {
      $dovizAktif = true;
      $dovizSembol = dovizsembol_bul($dovizTipi);
      if (empty($dovizSembol)) {
        $dovizSembol = '$';
      }
    }
  }
}

if ($a_barkod === '' || $a_barkod === '0' || $fisid <= 0) {
  if ($fisid > 0) {
    // siparis/lg_fis.php için session'a kaydet ve temiz URL'ye yönlendir
    setPageParam('stokhareket', $fisid, 'lg_fis');
    echo '<script>if (window.toast) { toast("Lütfen bir ürün kodu veya adı girin.", "warning"); } else { alert("Lütfen bir ürün kodu veya adı girin."); } window.location="../siparis/lg_fis.php";</script>';
  } else {
    die('<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Hata</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-red-100 flex items-center justify-center min-h-screen"><div class="p-6 bg-white rounded-lg shadow text-center"><h2 class="text-xl font-bold text-red-700 mb-1">Hata!</h2><p class="text-gray-600 text-sm">Geçersiz veya eksik parametre. Lütfen işlemi tekrar deneyin.</p><a href="../index.php" class="mt-3 inline-block px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 text-sm">Ana Sayfaya Dön</a></div></body></html>');
  }
  exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stok Arama Sonuclari</title>
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
    }
    * { box-sizing: border-box; margin: 0; }
    body {
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      background: var(--bg);
      color: var(--text-1);
      min-height: 100vh;
      letter-spacing: 0.1px;
    }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }

    /* ═══════ HEADER ═══════ */
    .top-header {
      position: sticky;
      top: 0;
      z-index: 40;
      height: 64px;
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border-bottom: 1px solid rgba(248, 113, 113, 0.18);
      box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
    }
    .header-inner {
      max-width: 1100px;
      margin: 0 auto;
      height: 100%;
      display: flex;
      align-items: center;
      gap: 14px;
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
      font-size: 18px;
      font-weight: 700;
      color: var(--text-1);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .header-title i { color: var(--red,#ef4444); font-size: 16px; }

    /* ═══════ BULK PANEL ═══════ */
    .bulk-panel {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 14px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      padding: 12px 14px;
      margin-bottom: 16px;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
      will-change: transform, opacity;
    }
    .bulk-row { display: flex; align-items: center; gap: 10px; }
    .bulk-label {
      font-size: 12px; font-weight: 600; color: var(--text-2);
      text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
    }
    .bulk-input-wrap { position: relative; flex: 1 1 auto; min-width: 0; }
    .bulk-input-wrap i {
      position: absolute; top: 50%; left: 12px;
      transform: translateY(-50%); color: var(--text-3);
      font-size: 13px; pointer-events: none;
    }
    .bulk-input {
      width: 100%; padding: 10px 12px 10px 34px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px;
      color: var(--text-1); background: #fff;
      border: 1px solid var(--border); border-radius: 10px;
      outline: none; transition: all 0.2s ease;
    }
    .bulk-input:focus {
      border-color: rgba(239, 68, 68, 0.5);
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }
    .bulk-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 6px;
      padding: 10px 16px; background: #64748b; color: #fff;
      border: none; border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
      cursor: pointer; transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08); white-space: nowrap;
    }
    .bulk-btn:hover { background: #475569; transform: translateY(-1px); }
    .bulk-btn:active { transform: translateY(0); }

    /* ═══════ STOK LIST ═══════ */
    #stok-listesi { display: flex; flex-direction: column; gap: 10px; }
    .stok-card {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 14px;
      box-shadow: 0 4px 14px rgba(0,0,0,0.04);
      padding: 12px 14px;
      display: grid;
      grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr) minmax(0, 1.4fr);
      align-items: center;
      gap: 14px;
      will-change: transform, opacity;
      animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
      transition: box-shadow 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
    }
    .stok-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 24px rgba(239, 68, 68, 0.08);
      border-color: rgba(239, 68, 68, 0.32);
    }
    .stok-card.has-color-glow {
      position: relative;
      border-color: var(--product-glow, rgba(248, 113, 113, 0.42));
      box-shadow:
        0 0 0 1px var(--product-glow, rgba(248, 113, 113, 0.35)),
        0 8px 24px var(--product-glow-soft, rgba(248, 113, 113, 0.14));
    }
    .stok-card.has-color-glow::before {
      content: "";
      position: absolute;
      inset: 0 auto 0 0;
      width: 5px;
      border-radius: 14px 0 0 14px;
      background: var(--product-glow, var(--red,#ef4444));
      box-shadow: 0 0 18px var(--product-glow, var(--red,#ef4444));
      pointer-events: none;
    }

    /* Block 1 */
    .stok-info { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .stok-img {
      width: 52px; height: 52px; flex-shrink: 0;
      border-radius: 10px; object-fit: contain;
      border: 1px solid var(--border); background: #f3f4f6;
      transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
    }
    .stok-img.is-clickable {
      cursor: zoom-in;
    }
    .stok-img.is-clickable:hover {
      transform: scale(1.05);
      border-color: rgba(239, 68, 68, 0.4);
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.14);
    }
    .stok-name-wrap { min-width: 0; flex: 1 1 auto; }
    .stok-name {
      display: block; font-weight: 600; font-size: 13.5px;
      color: var(--red); text-decoration: none; line-height: 1.35;
      transition: color 0.15s ease; word-break: break-word;
    }
    .stok-name:hover { color: #b91c1c; text-decoration: underline; }
    .stok-code {
      display: block; margin-top: 3px; font-size: 11.5px;
      color: var(--text-3); font-weight: 500;
    }

    /* Block 2 */
    .stok-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 12px; }
    .stok-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 10px; border-radius: 100px;
      font-weight: 600; font-size: 11.5px;
      border: 1px solid var(--border); background: #fff;
      transition: all 0.15s ease;
    }
    .stok-chip i { font-size: 11px; }
    .stok-chip.chip-indigo {
      color: var(--indigo); background: var(--indigo-soft);
      border-color: rgba(79, 70, 229, 0.2); cursor: pointer;
    }
    .stok-chip.chip-indigo:hover { background: var(--indigo); color: #fff; }
    .stok-chip.chip-indigo:hover i { color: #fff; }
    .stok-chip.chip-emerald {
      color: var(--emerald); background: var(--emerald-soft);
      border-color: rgba(5, 150, 105, 0.2);
    }

    /* Block 3 */
    .stok-actions { display: flex; align-items: center; gap: 8px; }
    .price-field { flex: 1 1 0; min-width: 0; }
    .price-input-wrap { position: relative; }
    .price-input-wrap i.currency {
      position: absolute; top: 50%; left: 10px;
      transform: translateY(-50%); color: var(--emerald);
      font-size: 12px; pointer-events: none;
    }
    .price-input {
      width: 100%; padding: 9px 10px 9px 28px;
      text-align: center;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
      color: var(--emerald); background: #fff;
      border: 1px solid var(--border); border-radius: 9px;
      outline: none; transition: all 0.2s ease;
    }
    .price-input:focus {
      border-color: rgba(239, 68, 68, 0.5);
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }
    .price-input[readonly] { background: #f9fafb; color: var(--text-2); cursor: default; }

    .doviz-row {
      margin-top: 4px; text-align: center;
      font-size: 11px; font-weight: 600; color: #2563eb;
    }
    .doviz-row i { margin-right: 3px; font-size: 10px; }

    .qty-field { flex: 1 1 0; min-width: 0; }
    .qty-input {
      width: 100%; padding: 9px 10px;
      text-align: center;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13px; font-weight: 600;
      color: var(--text-1); background: #fff;
      border: 1px solid var(--border); border-radius: 9px;
      outline: none; transition: all 0.2s ease;
    }
    .qty-input::placeholder { color: var(--text-3); font-weight: 500; }
    .qty-input:focus {
      border-color: rgba(239, 68, 68, 0.5);
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }
    /* ═══════ ADET / KOLI BIRIM GECISI ═══════ */
    .unit-toggle { display: inline-flex; background: #f3f4f6; border-radius: 8px; padding: 3px; gap: 2px; margin-bottom: 5px; }
    .unit-toggle .ut-btn { border: none; background: transparent; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 11px; font-weight: 600; color: var(--text-2); padding: 4px 11px; border-radius: 6px; cursor: pointer; transition: all 0.12s ease; }
    .unit-toggle .ut-btn.on { background: var(--red); color: #fff; }
    .qty-input.mode-koli { border-color: #f0b429; background: #fffdf6; }
    .koli-hint { font-size: 10.5px; color: var(--text-2); margin-top: 5px; line-height: 1.35; }
    .koli-hint .kref { color: var(--text-3); }
    .koli-hint .kcalc b { color: var(--emerald); font-weight: 600; }

    .add-btn {
      flex-shrink: 0; width: 38px; height: 38px;
      border: none; border-radius: 10px;
      background: var(--emerald); color: #fff;
      cursor: pointer; transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 14px;
    }
    .add-btn:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 4px 10px rgba(5, 150, 105, 0.3); }
    .add-btn:active { transform: translateY(0); }

    /* ═══════ BULK SUBMIT ═══════ */
    .bulk-submit-wrap { margin-top: 20px; display: flex; justify-content: center; }
    .bulk-submit-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 13px 28px; background: var(--red,#ef4444); color: #fff;
      border: none; border-radius: 12px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px; font-weight: 600;
      cursor: pointer; transition: all 0.2s ease;
      box-shadow: 0 4px 14px rgba(239, 68, 68, 0.2);
    }
    .bulk-submit-btn:hover { background: var(--red); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3); }
    .bulk-submit-btn:active { transform: translateY(0); }

    /* ═══════ MODAL ═══════ */
    .akl-modal {
      position: fixed; inset: 0;
      background: rgba(15, 23, 42, 0.42);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      z-index: 60;
      display: none;
      align-items: flex-start; justify-content: center;
      padding: 60px 16px 16px;
    }
    .akl-modal.is-open { display: flex; animation: fadeIn 0.2s ease both; }
    .akl-modal-dialog {
      background: #fff; border: 1px solid var(--border);
      border-radius: 16px;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
      width: 100%; max-width: 760px; max-height: 80vh;
      display: flex; flex-direction: column;
      animation: modalIn 0.28s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .akl-modal-head {
      display: flex; align-items: center; justify-content: space-between;
      gap: 10px; padding: 14px 18px;
      border-bottom: 1px solid var(--border);
    }
    .akl-modal-head h3 {
      font-size: 14px; font-weight: 700; color: var(--text-1);
      display: inline-flex; align-items: center; gap: 8px;
    }
    .akl-modal-head h3 i { color: var(--red); font-size: 13px; }
    .modal-close {
      background: transparent; border: none;
      font-size: 18px; line-height: 1;
      color: var(--text-2); cursor: pointer;
      width: 32px; height: 32px; border-radius: 8px;
      transition: all 0.15s ease;
    }
    .modal-close:hover { background: rgba(0,0,0,0.05); color: var(--red); }
    .akl-modal-body {
      padding: 16px 18px; overflow-y: auto;
      font-size: 13px; color: var(--text-1);
    }
    .akl-modal-foot {
      padding: 12px 18px; border-top: 1px solid var(--border);
      background: #fafafa;
      display: flex; justify-content: flex-end;
      border-radius: 0 0 16px 16px;
    }
    .btn-modal-close {
      padding: 9px 20px; background: #64748b; color: #fff;
      border: none; border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif; font-weight: 600; font-size: 13px;
      cursor: pointer; transition: all 0.15s ease;
    }
    .btn-modal-close:hover { background: #475569; }

    .image-modal {
      align-items: center;
      padding: 18px;
    }
    .image-modal-dialog {
      position: relative;
      width: min(92vw, 720px);
      max-height: 86vh;
      background: #fff;
      border: 1px solid rgba(229, 231, 235, 0.9);
      border-radius: 16px;
      box-shadow: 0 28px 70px rgba(15, 23, 42, 0.26);
      padding: 18px;
      animation: modalIn 0.28s cubic-bezier(0.22, 1, 0.36, 1) both;
    }
    .image-modal-close {
      position: absolute;
      top: 10px;
      right: 10px;
      width: 34px;
      height: 34px;
      border: none;
      border-radius: 10px;
      background: rgba(15, 23, 42, 0.82);
      color: #fff;
      cursor: pointer;
      font-size: 20px;
      line-height: 1;
    }
    .image-modal-close:hover { background: var(--red,#6F1022); }
    .image-modal-img {
      display: block;
      width: 100%;
      max-height: calc(86vh - 36px);
      object-fit: contain;
      border-radius: 12px;
      background: #f8fafc;
    }

    /* ═══════ ANIMATIONS ═══════ */
    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes modalIn {
      from { opacity: 0; transform: translate3d(0, 14px, 0) scale(0.97); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    /* ═══════ RESPONSIVE ═══════ */
    @media (max-width: 900px) {
      .stok-card {
        grid-template-columns: 1fr;
        gap: 12px;
        padding: 14px;
      }
      .stok-meta { border-top: 1px dashed var(--border); padding-top: 10px; }
    }
    @media (max-width: 767px) {
      .top-header { height: 50px; }
      .header-inner { padding: 0 12px; gap: 10px; }
      .header-title { font-size: 15px; }
      .header-back { width: 30px; height: 30px; border-radius: 8px; }
      .header-divider { height: 18px; }

      main { padding: 14px 12px 40px !important; }

      .bulk-panel { padding: 10px 12px; }
      .bulk-label { display: none; }
      .bulk-input { font-size: 16px; padding: 10px 12px 10px 32px; } /* iOS zoom engeli */
      .bulk-btn { padding: 10px 14px; font-size: 12px; }

      .stok-card { padding: 12px; gap: 10px; }
      .stok-card:hover { transform: none; }
      .stok-img { width: 46px; height: 46px; }
      .stok-name { font-size: 13px; }
      .stok-actions { gap: 6px; }
      .price-input, .qty-input { font-size: 16px; } /* iOS zoom engeli */
      .price-input { padding: 10px 10px 10px 28px; }
      .qty-input { padding: 10px; }
      .add-btn { width: 40px; height: 40px; }
      .add-btn:hover { transform: none; }

      .bulk-submit-btn { padding: 12px 22px; font-size: 13px; }
      .bulk-submit-btn:hover { transform: none; }

      .akl-modal { padding: 40px 12px 12px; }
    }
    @media (max-width: 480px) {
      .stok-actions { gap: 6px; }
      .price-input { padding: 10px 6px 10px 24px; font-size: 14px; }
      .price-input-wrap i.currency { left: 8px; font-size: 11px; }
      .qty-input { padding: 10px 6px; font-size: 14px; }
      .add-btn { width: 38px; height: 38px; font-size: 13px; }
      .stok-card { padding: 10px; }
    }

    /* ═══════ SONUC YOK / ONERILER ═══════ */
    .empty-state { text-align: center; padding: 40px 20px; animation: cardIn 0.4s ease both; }
    .empty-ico { width: 72px; height: 72px; margin: 0 auto 16px; border-radius: 18px; background: var(--red-soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 30px; }
    .empty-state h3 { font-size: 16px; font-weight: 700; color: var(--text-1); line-height: 1.4; }
    .empty-state h3 b { color: var(--red); word-break: break-word; }
    .empty-sub { font-size: 13px; color: var(--text-2); margin-top: 6px; margin-bottom: 18px; }
    .suggest-list { display: flex; flex-direction: column; gap: 8px; max-width: 520px; margin: 0 auto; }
    .suggest-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: #fff; border: 1px solid var(--border); border-radius: 12px; text-decoration: none; color: inherit; transition: all 0.18s ease; text-align: left; }
    .suggest-item:hover { border-color: rgba(239,68,68,0.4); box-shadow: 0 6px 16px rgba(239,68,68,0.08); transform: translateY(-1px); }
    .suggest-code { font-weight: 700; font-size: 13px; color: var(--red); font-family: ui-monospace, monospace; flex-shrink: 0; }
    .suggest-name { flex: 1; min-width: 0; font-size: 13px; color: var(--text-1); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .suggest-stock { font-size: 11.5px; color: var(--text-2); flex-shrink: 0; white-space: nowrap; }
    .suggest-stock i { color: var(--text-3); margin-right: 3px; }
    @media (max-width: 480px) {
      .suggest-item { flex-wrap: wrap; gap: 6px 10px; }
      .suggest-name { white-space: normal; }
    }
  </style>
</head>
<body>

  <!-- Header -->
  <header class="top-header">
    <div class="header-inner">
      <a href="../siparis/lg_fis.php?stokhareket=<?php echo $fisid; ?>" class="header-back" title="Fise Geri Don">
        <i class="fa fa-arrow-left"></i>
      </a>
      <div class="header-divider"></div>
      <span class="header-title">
        <i class="fa-solid fa-magnifying-glass"></i>Stok Arama Sonuclari
      </span>
    </div>
  </header>

  <main style="max-width:1100px;margin:0 auto;padding:18px 24px 60px;">

    <!-- Toplu miktar cubugu -->
    <div class="bulk-panel">
      <div class="bulk-row">
        <label for="coklumiktar" class="bulk-label">Toplu Miktar</label>
        <div class="bulk-input-wrap">
          <i class="fa-solid fa-box"></i>
          <input type="number" step="any" name="coklumiktar" id="coklumiktar"
                 class="bulk-input"
                 placeholder="Tum urunlere uygulanacak miktar...">
        </div>
        <button type="button" onclick="coklumiktargir()" id="coklumiktarb" class="bulk-btn">
          <i class="fa-solid fa-check"></i> Uygula
        </button>
      </div>
    </div>

    <form name="frm" id="frm" method="POST" action="../siparis/lg_fis.php?stokhareket=<?php echo $fisid; ?>" onsubmit="return bak()" autocomplete="off">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="stokhareket" value="<?php echo $fisid; ?>">
      <input type="hidden" name="coklusecim" value="evet">
      <input type="hidden" name="tekli_ekle_stok_id" id="tekli_ekle_stok_id" value="">

      <div id="stok-listesi">
        <?php
        $is_hizli_miktar_processed = false;
$stmt_barcode = $dbh->prepare("SELECT TOP 1 B.ITEMREF FROM {$firma}UNITBARCODE B INNER JOIN {$firma}ITEMS S ON B.ITEMREF=S.LOGICALREF WHERE B.BARCODE = :barkod AND S.ACTIVE=0 ");
$stmt_barcode->execute([':barkod' => $a_barkod]);
$barcode_match = $stmt_barcode->fetch(PDO::FETCH_ASSOC);
if ($barcode_match) {
  $stokid_match = intcevir($barcode_match['ITEMREF']);
  echo '<script>window.location="lg_stok_ekle.php?fisid=' . $fisid . '&stok=' . $stokid_match . '&return_to=' . rawurlencode($lgStokBulReturnUrl) . '";</script>';
  exit;
}
$where_clause = "";
$order_clause = "URUN.CODE";
$params = [];
$a_barkod_normalized = "";
$normalizedCodeSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
  URUN.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')";
$normalizedNameSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
  URUN.NAME, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')";
if (str_contains($a_barkod, ',')) {
  $codes = array_filter(array_map('trim', explode(',', $a_barkod)));
  if ($codes !== []) {
    $like_conditions = array_map(fn($code): string => "URUN.CODE LIKE ?", $codes);
    $params = array_map(fn($code): string => $code . '%', $codes);
    $where_clause = " (" . implode(' OR ', $like_conditions) . ") ";
  } else {
    $where_clause = " 1 = 0 ";
  }
} else {
  // Türkçe karakterleri ASCII'ye çevir (mağaza -> magaza)
  $a_barkod_normalized = turkce($a_barkod);
  if (preg_match('/^\d+$/', $a_barkod_normalized) === 1) {
    $where_clause = " (
      URUN.CODE = ?
      OR URUN.CODE LIKE ?
      OR URUN.CODE LIKE ?
      OR URUN.CODE LIKE ?
    ) ";
    $order_clause = "CASE
      WHEN URUN.CODE = ? THEN 0
      WHEN URUN.CODE LIKE ? THEN 1
      WHEN URUN.CODE LIKE ? THEN 2
      WHEN URUN.CODE LIKE ? THEN 3
      ELSE 9
    END, URUN.CODE";
    $numericCodeParams = [
      $a_barkod_normalized,
      $a_barkod_normalized . "%",
      "AKL" . $a_barkod_normalized . "%",
      "AKL-" . $a_barkod_normalized . "%",
    ];
    $params = array_merge($numericCodeParams, $numericCodeParams);
  } else {
    $where_clause = " (
      {$normalizedCodeSql} LIKE ?
      OR {$normalizedNameSql} LIKE ?
    ) ";
    $order_clause = "CASE
      WHEN {$normalizedCodeSql} LIKE ? THEN 0
      WHEN {$normalizedCodeSql} LIKE ? THEN 1
      ELSE 2
    END, URUN.CODE";
    $params = [
      "%" . $a_barkod_normalized . "%",
      "%" . $a_barkod_normalized . "%",
      $a_barkod_normalized . "%",
      "%" . $a_barkod_normalized . "%",
    ];
  }
}
$xi = 0;
// N+1 ÇÖZÜMÜ: Fiyat grup sorgusu ana sorguya dahil edildi
$fiyatGrupKolonu = "";
$fiyatGrupJoin = "";
if (isset($fiyatgruplu) && $fiyatgruplu == 1 && isset($_SESSION['fiyatgrup'])) {
    $odemekodu = (int)$_SESSION['fiyatgrup'];
    // LEFT JOIN ile fiyat grup sorgusunu ana sorguya dahil et
    $fiyatGrupKolonu = ", ISNULL(FG.PRICE, 0) AS 'GRUP_FIYAT'";
    $fiyatGrupJoin = "
        LEFT JOIN (
            SELECT CARDREF, PRICE,
                ROW_NUMBER() OVER (PARTITION BY CARDREF ORDER BY ENDDATE DESC, LOGICALREF DESC) AS RN
            FROM {$firma}PRCLIST
            WHERE PTYPE = 2 AND PAYPLANREF = {$odemekodu} AND ACTIVE = 0
        ) FG ON FG.CARDREF = URUN.LOGICALREF AND FG.RN = 1";
}
$sql_final = "SELECT TOP " . (isset($stoklistesayisi) ? (int) $stoklistesayisi : 50) . "
                            URUN.CODE AS 'URUN_KODU', URUN.NAME AS 'URUN_ADI', URUN.LOGICALREF AS 'URUN_ID',
                            BIRIM.CODE AS 'BIRIM', BIRIM.UNITSETREF AS 'BIRIMID',
                            ISNULL((SELECT SUM(ONHAND) FROM {$firmadonemx}STINVTOT WHERE INVENNO = -1 AND STOCKREF = URUN.LOGICALREF), 0) AS 'MIKTAR',
                            ISNULL((SELECT PRICE FROM {$firma}PRCLIST PL1 WHERE PL1.PTYPE = 2 AND PL1.CARDREF = URUN.LOGICALREF AND PL1.LOGICALREF = (SELECT MAX(LOGICALREF) FROM {$firma}PRCLIST PL2 WHERE PL2.CARDREF = PL1.CARDREF AND PL2.PTYPE = 2)), 0) AS 'S_FIYAT',
                            ISNULL((SELECT TOP 1 (A.CONVFACT2 / NULLIF(A.CONVFACT1, 0)) FROM {$firma}ITMUNITA A JOIN {$firma}UNITSETL U ON U.LOGICALREF = A.UNITLINEREF AND U.CODE = 'KOLI' WHERE A.ITEMREF = URUN.LOGICALREF AND A.CONVFACT2 > 1), 0) AS 'KOLI_ADET'
                            {$fiyatGrupKolonu}
                        FROM {$firma}ITEMS URUN
                        LEFT JOIN {$firma}UNITSETL BIRIM ON URUN.UNITSETREF = BIRIM.UNITSETREF AND BIRIM.MAINUNIT = 1
                        {$fiyatGrupJoin}
                        WHERE URUN.CARDTYPE <> '22' AND URUN.ACTIVE = 0 AND " . $where_clause . " ORDER BY {$order_clause} ";
$stmt_list = $dbh->prepare($sql_final);
$stmt_list->execute($params);
while ($row = $stmt_list->fetch(PDO::FETCH_ASSOC)) {
  $xi++;
  $stokidx = intcevir($row['URUN_ID']);
  $fiyati = (float) $row['S_FIYAT'];

  // Koli boyutu (ITMUNITA.CONVFACT2): 1 koli = kaç adet. 0 ise LOGO'da tanımsız → sadece adet.
  $koliAdet = (float) ($row['KOLI_ADET'] ?? 0);
  $koliVar = $koliAdet > 1;
  $koliGoster = $koliVar
    ? (fmod($koliAdet, 1.0) === 0.0 ? (string) (int) $koliAdet : rtrim(rtrim(number_format($koliAdet, 3, '.', ''), '0'), '.'))
    : '';

  // N+1 ÇÖZÜMÜ: Fiyat artık ana sorgudan geliyor (ayrı sorgu yok)
  if (isset($fiyatgruplu) && $fiyatgruplu == 1 && isset($_SESSION['fiyatgrup'])) {
    // GRUP_FIYAT ana sorgudan geldi, ayrı sorgu gerekmiyor
    $grupFiyat = isset($row['GRUP_FIYAT']) ? (float)$row['GRUP_FIYAT'] : 0;
    if ($grupFiyat > 0) {
        $fiyati = $grupFiyat;
    }
  }

  $fiyatdegistirme = (m_p_yetki($terminalkullanici, 'SP1') == 1 || $yetkidurum == 0) ? "" : "readonly";
  $miktarGoster = (m_p_yetki($terminalkullanici, 'ST1') == 1 || $yetkidurum == 0);
  $fiyatGoster  = (m_p_yetki($terminalkullanici, 'ST2') == 1 || $yetkidurum == 0);
  $cardDelay = min(($xi - 1) * 30, 240);
  $stokGorsel = akl_stok_gorsel_bul((string) $row['URUN_KODU'], (string) $row['URUN_ADI']);
  $resim_src = $stokGorsel['src'];
  $resimGoster = $stokGorsel['found'] || (isset($resimlistoklistesi) && $resimlistoklistesi == 1);
  $urunRenkHex = function_exists('akl_stok_gorsel_renk_hex') ? akl_stok_gorsel_renk_hex((string) ($stokGorsel['renk'] ?? '')) : '';
  $cardClass = 'stok-card' . ($urunRenkHex !== '' ? ' has-color-glow' : '');
  $cardStyle = 'animation-delay: ' . $cardDelay . 'ms;';
  if ($urunRenkHex !== '') {
    $cardStyle .= ' --product-glow: ' . $urunRenkHex . '; --product-glow-soft: ' . $urunRenkHex . '24;';
  }
  ?>
              <div class="<?php echo $cardClass; ?>" style="<?php echo htmlspecialchars($cardStyle, ENT_QUOTES); ?>">

      <!-- Urun Bilgisi -->
      <div class="stok-info">
        <?php if ($resimGoster): ?>
          <img class="stok-img <?php echo $stokGorsel['found'] ? 'is-clickable' : ''; ?>"
               src="<?php echo htmlspecialchars($resim_src, ENT_QUOTES); ?>"
               alt="<?php echo htmlspecialchars((string) $row['URUN_ADI']); ?>"
               data-full-src="<?php echo htmlspecialchars($resim_src, ENT_QUOTES); ?>"
               data-full-alt="<?php echo htmlspecialchars((string) $row['URUN_ADI'], ENT_QUOTES); ?>"
               tabindex="<?php echo $stokGorsel['found'] ? '0' : '-1'; ?>"
               loading="lazy"
               onerror="this.src='tm/rs/urunyok.jpg';" />
        <?php endif; ?>
        <div class="stok-name-wrap">
          <a href="lg_stok_ekle.php?fisid=<?php echo $fisid; ?>&stok=<?php echo $stokidx; ?>&return_to=<?php echo rawurlencode($lgStokBulReturnUrl); ?>" class="stok-name">
            <?php echo htmlspecialchars((string) $row['URUN_ADI']); ?>
          </a>
          <span class="stok-code"><i class="fa-solid fa-barcode" style="margin-right:4px;color:#9ca3af;"></i><?php echo htmlspecialchars((string) $row['URUN_KODU']); ?></span>
        </div>
      </div>

      <!-- Stok / Bekleyen -->
      <div class="stok-meta">
        <?php if ($miktarGoster): ?>
        <button type="button"
          data-id="<?php echo $fisid . ',' . $stokidx . ',' . htmlspecialchars((string) $row['BIRIM'], ENT_QUOTES); ?>"
          class="userinfo stok-chip chip-indigo"
          title="Stok Detaylari">
          <i class="fa-solid fa-warehouse"></i>
          <span><?php echo kusuratsifir($row['MIKTAR']) . ' ' . htmlspecialchars((string) $row['BIRIM']); ?></span>
        </button>
        <?php endif; ?>
        <?php if (m_p_yetki($terminalkullanici, 'SP4') == 1 || $yetkidurum == 0): ?>
        <div class="stok-chip chip-emerald" title="Bekleyen Siparis">
          <i class="fa-solid fa-dolly"></i>
          <span><?php echo kusuratadet(function_exists('bekleyen_siparis') ? bekleyen_siparis($stokidx) : 0); ?></span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Fiyat / Miktar / Ekle -->
      <div class="stok-actions">
        <?php if ($fiyatGoster): ?>
        <div class="price-field">
          <div class="price-input-wrap">
            <i class="fa-solid fa-turkish-lira-sign currency"></i>
            <input type="text" name="cstok_fiyati[]" value="<?php echo kusuratpara($fiyati); ?>" <?php echo $fiyatdegistirme; ?>
                   class="price-input" aria-label="Birim fiyat">
          </div>
          <?php if ($dovizAktif):
            $fiyatiDoviz = $fiyati / $dovizKuru;
            $dovizIcon = 'fa-dollar-sign';
            if ($dovizSembol == '€') { $dovizIcon = 'fa-euro-sign'; }
            elseif ($dovizSembol == '£') { $dovizIcon = 'fa-sterling-sign'; }
          ?>
          <div class="doviz-row">
            <i class="fa-solid <?php echo $dovizIcon; ?>"></i>
            <span><?php echo kusuratpara($fiyatiDoviz); ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <input type="hidden" name="cstok_fiyati[]" value="<?php echo kusuratpara($fiyati); ?>">
        <?php endif; ?>

        <div class="qty-field"<?php echo $koliVar ? ' data-koli-field data-mode="adet"' : ''; ?>>
          <?php if ($koliVar): ?>
          <div class="unit-toggle" role="group" aria-label="Birim seçimi">
            <button type="button" class="ut-btn on" data-mode="adet">Adet</button>
            <button type="button" class="ut-btn" data-mode="koli">Koli</button>
          </div>
          <input type="number" step="any" class="qty-input qty-koli" data-koli="<?php echo $koliGoster; ?>"
                 placeholder="Miktar" inputmode="decimal" aria-label="Miktar"
                 oninput="kbSync(this.closest('.qty-field'))">
          <input type="hidden" name="cstok_miktari[]" class="qty-real" value="">
          <div class="koli-hint"><span class="kref">1 koli = <?php echo $koliGoster; ?> adet</span><span class="kcalc"></span></div>
          <?php else: ?>
          <input type="number" step="any" name="cstok_miktari[]" placeholder="Miktar"
                 class="qty-input qty-plain" inputmode="decimal" aria-label="Miktar">
          <?php endif; ?>
        </div>

        <button type="submit" class="btn-tekli-ekle add-btn"
                title="Bu Urunu Fise Ekle" data-stokid="<?php echo $stokidx; ?>">
          <i class="fa-solid fa-plus"></i>
        </button>
      </div>

      <input type="hidden" name="cstok_id[]" value="<?php echo $stokidx; ?>">
      <input type="hidden" name="cstok_birim[]" value="<?php echo $row['BIRIMID']; ?>">
    </div>
  <?php
            }
// while
        ?>
      </div>

      <?php
      // ════════ SONUÇ YOKSA "BUNU MU DEMEK İSTEDİNİZ?" ÖNERİLERİ ════════
      if ($xi == 0) {
          $oneriler = [];
          try {
              if ($a_barkod_normalized !== '' && preg_match('/^\d+$/', $a_barkod_normalized) === 1) {
                  // Sayısal arama: prefix'i gevşet (son haneyi at), sayısal yakınlığa göre sırala
                  $hedefSayi = (int) $a_barkod_normalized;
                  $prefix = strlen($a_barkod_normalized) > 1 ? substr($a_barkod_normalized, 0, -1) : $a_barkod_normalized;
                  $stmtOneri = $dbh->prepare("SELECT TOP 40 URUN.CODE AS K, URUN.NAME AS A,
                      ISNULL((SELECT SUM(ONHAND) FROM {$firmadonemx}STINVTOT WHERE INVENNO = -1 AND STOCKREF = URUN.LOGICALREF), 0) AS M
                      FROM {$firma}ITEMS URUN
                      WHERE URUN.CARDTYPE <> '22' AND URUN.ACTIVE = 0
                        AND (URUN.CODE LIKE ? OR URUN.CODE LIKE ? OR URUN.CODE LIKE ?)");
                  $stmtOneri->execute([$prefix . '%', 'AKL' . $prefix . '%', 'AKL-' . $prefix . '%']);
                  $adaylar = $stmtOneri->fetchAll(PDO::FETCH_ASSOC);
                  foreach ($adaylar as &$aday) {
                      $aday['_d'] = (preg_match('/\d+/', (string) $aday['K'], $mm) === 1) ? abs((int) $mm[0] - $hedefSayi) : PHP_INT_MAX;
                  }
                  unset($aday);
                  usort($adaylar, fn($a, $b): int => $a['_d'] <=> $b['_d']);
                  $oneriler = array_slice($adaylar, 0, 6);
              } elseif ($a_barkod_normalized !== '' && strlen($a_barkod_normalized) >= 3) {
                  // Metin arama: ilk 3 karakter prefix + Levenshtein yakınlığı (harf karışıklığı/typo)
                  $on = substr($a_barkod_normalized, 0, 3);
                  $stmtOneri = $dbh->prepare("SELECT TOP 60 URUN.CODE AS K, URUN.NAME AS A,
                      ISNULL((SELECT SUM(ONHAND) FROM {$firmadonemx}STINVTOT WHERE INVENNO = -1 AND STOCKREF = URUN.LOGICALREF), 0) AS M
                      FROM {$firma}ITEMS URUN
                      WHERE URUN.CARDTYPE <> '22' AND URUN.ACTIVE = 0
                        AND ({$normalizedNameSql} LIKE ? OR {$normalizedCodeSql} LIKE ?)");
                  $stmtOneri->execute([$on . '%', $on . '%']);
                  $adaylar = $stmtOneri->fetchAll(PDO::FETCH_ASSOC);
                  $hedefMetin = strtolower($a_barkod_normalized);
                  foreach ($adaylar as &$aday) {
                      $aday['_d'] = levenshtein($hedefMetin, strtolower(turkce((string) $aday['A'])));
                  }
                  unset($aday);
                  usort($adaylar, fn($a, $b): int => $a['_d'] <=> $b['_d']);
                  $oneriler = array_slice($adaylar, 0, 6);
              }
          } catch (Throwable $e) {
              error_log("stok oneri hatasi: " . $e->getMessage());
          }
          ?>
          <div class="empty-state">
            <div class="empty-ico"><i class="fa-solid fa-magnifying-glass-minus"></i></div>
            <h3><b><?php echo htmlspecialchars(trim($a_barkod)); ?></b> için sonuç bulunamadı</h3>
            <?php if ($oneriler !== []): ?>
              <p class="empty-sub">Bunu mu aratmak istediniz?</p>
              <div class="suggest-list">
                <?php foreach ($oneriler as $o): ?>
                  <a class="suggest-item" href="lg_stok_bul.php?fisid=<?php echo $fisid; ?>&amp;barkod=<?php echo rawurlencode((string) $o['K']); ?>">
                    <span class="suggest-code"><?php echo htmlspecialchars((string) $o['K']); ?></span>
                    <span class="suggest-name"><?php echo htmlspecialchars((string) $o['A']); ?></span>
                    <span class="suggest-stock"><i class="fa-solid fa-warehouse"></i><?php echo kusuratsifir($o['M']); ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="empty-sub">Farklı bir kod veya ürün adıyla tekrar deneyin.</p>
            <?php endif; ?>
          </div>
          <?php
      }
      ?>

      <?php if ($xi > 0): ?>
      <div class="bulk-submit-wrap">
        <button class="bulk-submit-btn" type="submit">
          <i class="fa-solid fa-cart-plus"></i> Miktari Girilenleri Toplu Ekle
        </button>
      </div>
      <?php endif; ?>
    </form>
  </main>

  <!-- Modal -->
  <div id="empModal" class="akl-modal" role="dialog" aria-modal="true" aria-labelledby="empModalTitle">
    <div class="akl-modal-dialog">
      <div class="akl-modal-head">
        <h3 id="empModalTitle"><i class="fa-solid fa-chart-line"></i> Stok Satis Detayi</h3>
        <button id="empModalClose" type="button" class="modal-close" aria-label="Kapat">&times;</button>
      </div>
      <div id="empModalBody" class="akl-modal-body">
        <!-- AJAX icerik -->
      </div>
      <div class="akl-modal-foot">
        <button id="empModalCloseBtn" type="button" class="btn-modal-close">Kapat</button>
      </div>
    </div>
  </div>

  <div id="imageModal" class="akl-modal image-modal" role="dialog" aria-modal="true" aria-label="Urun gorseli">
    <div class="image-modal-dialog">
      <button id="imageModalClose" type="button" class="image-modal-close" aria-label="Kapat">&times;</button>
      <img id="imageModalImg" class="image-modal-img" src="" alt="">
    </div>
  </div>

  <!-- JS -->
  <script src="/tm/js/jquery-3.7.1.min.js"></script>
  <script>
    // Koli modunda: girilen koli sayisi x koli-faktoru = gizli cstok_miktari (adet olarak gonderilir)
    function kbSync(field) {
      if (!field) return;
      var ui = field.querySelector('.qty-koli');
      var real = field.querySelector('.qty-real');
      if (!ui || !real) return;
      var calc = field.querySelector('.kcalc');
      var koli = parseFloat(ui.getAttribute('data-koli')) || 0;
      var mode = field.getAttribute('data-mode') || 'adet';
      var n = parseFloat((ui.value || '').replace(',', '.')) || 0;
      if (mode === 'koli' && koli > 0) {
        var adet = Math.round(n * koli);
        real.value = n > 0 ? adet : '';
        if (calc) calc.innerHTML = n > 0 ? ' &middot; <b>= ' + adet.toLocaleString('tr-TR') + ' adet</b>' : '';
      } else {
        real.value = ui.value;
        if (calc) calc.innerHTML = '';
      }
    }

    function coklumiktargir() {
      const miktar = $('#coklumiktar').val();
      if (miktar === '') return;
      // Duz (koli olmayan) miktar alanlari
      $('input.qty-plain[name="cstok_miktari[]"]').val(miktar);
      // Koli kartlari: adet moduna al, gorunur degeri yaz, gizli alani senkronla
      document.querySelectorAll('#stok-listesi .qty-field[data-koli-field]').forEach(function (field) {
        field.setAttribute('data-mode', 'adet');
        field.querySelectorAll('.ut-btn').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-mode') === 'adet'); });
        var ui = field.querySelector('.qty-koli');
        if (ui) { ui.classList.remove('mode-koli'); ui.placeholder = 'Miktar'; ui.value = miktar; }
        kbSync(field);
      });
    }
    function bak() { return true; }

    // Animation render bug fix: force reflow
    window.addEventListener('load', function(){
      requestAnimationFrame(function(){
        document.querySelectorAll('.stok-card, .bulk-panel').forEach(function(el){ void el.offsetHeight; });
      });
    });

    $(function () {
      $('#coklumiktar').focus();

      // Tekli ekleme
      $('#stok-listesi').on('click', '.btn-tekli-ekle', function () {
        $('#tekli_ekle_stok_id').val($(this).data('stokid'));
      });

      // Adet / Koli birim gecisi
      $('#stok-listesi').on('click', '.ut-btn', function () {
        var btn = this, field = btn.closest('.qty-field');
        if (!field) return;
        var koli = btn.getAttribute('data-mode') === 'koli';
        field.setAttribute('data-mode', koli ? 'koli' : 'adet');
        field.querySelectorAll('.ut-btn').forEach(function (b) { b.classList.toggle('on', b === btn); });
        var ui = field.querySelector('.qty-koli');
        ui.classList.toggle('mode-koli', koli);
        ui.placeholder = koli ? 'Kaç koli?' : 'Miktar';
        kbSync(field);
        ui.focus();
      });

      // Modal helpers
      const openModal  = () => $('#empModal').addClass('is-open');
      const closeModal = () => $('#empModal').removeClass('is-open');

      // Stok detay modali
      $('#stok-listesi').on('click', '.userinfo', function (e) {
        e.preventDefault();
        const userid = $(this).data('id');
        $('#empModalBody').html('<p style="text-align:center;padding:24px;color:#6b7280;"><i class="fa-solid fa-circle-notch fa-spin" style="margin-right:8px;"></i> Yukleniyor...</p>');
        openModal();
        $.post('lg_stoksonalisx.php', { userid })
          .done(res => $('#empModalBody').html(res))
          .fail(() => $('#empModalBody').html('<p style="text-align:center;padding:24px;color:var(--red,#6F1022);">Hata olustu.</p>'));
      });

      // Modal kapatma
      $('#empModalClose, #empModalCloseBtn').on('click', closeModal);
      $('#empModal').on('click', function (e) {
        if (e.target === this) closeModal();
      });

      const openImageModal = function (img) {
        const src = img.getAttribute('data-full-src') || img.getAttribute('src');
        const alt = img.getAttribute('data-full-alt') || img.getAttribute('alt') || '';
        $('#imageModalImg').attr({ src, alt });
        $('#imageModal').addClass('is-open');
      };
      const closeImageModal = function () {
        $('#imageModal').removeClass('is-open');
        $('#imageModalImg').attr({ src: '', alt: '' });
      };

      $('#stok-listesi').on('click', '.stok-img.is-clickable', function () {
        openImageModal(this);
      });
      $('#stok-listesi').on('keydown', '.stok-img.is-clickable', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openImageModal(this);
        }
      });
      $('#imageModalClose').on('click', closeImageModal);
      $('#imageModal').on('click', function (e) {
        if (e.target === this) closeImageModal();
      });

      $(document).on('keyup', function (e) {
        if (e.key === 'Escape' && $('#empModal').hasClass('is-open')) closeModal();
        if (e.key === 'Escape' && $('#imageModal').hasClass('is-open')) closeImageModal();
      });
    });
  </script>
    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
</body>
</html>
