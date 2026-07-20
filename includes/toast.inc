<!-- Toast Container - Sağ üst köşede sabit konumlu -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:9999;" id="toastContainer">
   <div id="globalToast" class="toast align-items-center border-0" role="status" aria-live="polite" aria-atomic="true" data-bs-delay="3000">
      <div class="d-flex">
         <div class="toast-body d-flex align-items-center gap-2">
            <i id="toastIcon" class="fas"></i>
            <span id="toastMessage"></span>
         </div>
         <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Kapat"></button>
      </div>
   </div>
</div>

<script>
/**
 * Global Toast Fonksiyonu
 * Kullanım: showToast('Mesaj', 'success'); // success, danger, warning, info
 */
function showToast(message, type = 'success', duration = 3000) {
   const toastEl = document.getElementById('globalToast');
   const messageEl = document.getElementById('toastMessage');
   const iconEl = document.getElementById('toastIcon');

   if (!toastEl || !messageEl) {
      alert(String(message).replace(/<[^>]+>/g, ''));
      return;
   }

   // Önceki stilleri temizle
   toastEl.classList.remove('text-bg-success', 'text-bg-danger', 'text-bg-warning', 'text-bg-info', 'text-bg-primary');
   iconEl.classList.remove('fa-check-circle', 'fa-times-circle', 'fa-exclamation-triangle', 'fa-info-circle');

   // Tipe göre stil ve ikon ayarla
   const styles = {
      success: { bg: 'text-bg-success', icon: 'fa-check-circle' },
      danger: { bg: 'text-bg-danger', icon: 'fa-times-circle' },
      error: { bg: 'text-bg-danger', icon: 'fa-times-circle' },
      warning: { bg: 'text-bg-warning', icon: 'fa-exclamation-triangle' },
      info: { bg: 'text-bg-info', icon: 'fa-info-circle' },
      primary: { bg: 'text-bg-primary', icon: 'fa-info-circle' }
   };

   const style = styles[type] || styles.success;
   toastEl.classList.add(style.bg);
   iconEl.classList.add(style.icon);

   // Mesajı ayarla
   messageEl.innerHTML = message;

   // Süreyi ayarla
   toastEl.setAttribute('data-bs-delay', duration);

   // Toast'u göster
   if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
      const toast = bootstrap.Toast.getOrCreateInstance(toastEl);
      toast.show();
   } else {
      alert(String(message).replace(/<[^>]+>/g, ''));
   }
}

// URL'den toast mesajı kontrolü (sayfa yönlendirmelerinde kullanılır)
document.addEventListener('DOMContentLoaded', function() {
   const urlParams = new URLSearchParams(window.location.search);
   const toastMsg = urlParams.get('toast_msg');
   const toastType = urlParams.get('toast_type') || 'success';

   if (toastMsg) {
      showToast(decodeURIComponent(toastMsg), toastType);
      // URL'den parametreleri temizle (opsiyonel)
      // window.history.replaceState({}, document.title, window.location.pathname);
   }
});

// PHP session'dan toast mesajı kontrolü
<?php if (isset($_SESSION['toast_message'])): ?>
   showToast('<?= addslashes($_SESSION['toast_message']) ?>', '<?= $_SESSION['toast_type'] ?? 'success' ?>');
   <?php unset($_SESSION['toast_message'], $_SESSION['toast_type']); ?>
<?php endif; ?>
</script>
