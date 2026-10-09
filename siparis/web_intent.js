// Prevent accidental double submit; native POST keeps the same key/body on network retry.
// pageshow resets a button restored from browser back/forward cache.
(() => {
  const reset = () => document.querySelectorAll('form').forEach(form => {
    if (!form.querySelector('[name="web_intent_key"]')) return;
    form.dataset.sending = '';
    form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = false; });
  });
  document.addEventListener('submit', event => {
    const form = event.target;
    if (!form.querySelector('[name="web_intent_key"]')) return;
    if (form.dataset.sending === '1') { event.preventDefault(); return; }
    form.dataset.sending = '1';
    form.querySelectorAll('[type="submit"]').forEach(button => { button.disabled = true; });
  });
  window.addEventListener('pageshow', reset);
})();
