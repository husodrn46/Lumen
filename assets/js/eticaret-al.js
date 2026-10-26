/* Bir açık kullanıcı eyleminden sonra sayfa sayfa alım. Anahtar/cursor tarayıcıya gelmez. */
(() => {
    'use strict';
    const form = document.getElementById('al-form');
    if (!form) return;
    const mesaj = document.getElementById('al-hata');
    const bekleme = document.getElementById('al-bekleme');
    const devam = document.getElementById('al-devam');
    const durdur = document.getElementById('al-durdur');
    let kapandi = false;
    window.addEventListener('pagehide', () => { kapandi = true; });
    form.addEventListener('submit', () => { kapandi = true; });
    const dur = (hata = '') => {
        bekleme.textContent = '';
        if (hata) { mesaj.textContent = hata; mesaj.hidden = false; }
        devam.hidden = false;
        devam.value = 'tekrar';
        devam.textContent = 'Yeniden dene';
    };
    async function adim() {
        if (kapandi) return;
        bekleme.textContent = 'Sipariş sayfası alınıyor…';
        const veri = new FormData(form);
        veri.set('islem', 'adim');
        try {
            const r = await fetch(window.location.href, {method: 'POST', body: veri, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            const j = await r.json();
            if (!r.ok) { dur(j.hata || 'Alım durdu. Sayfayı yenileyip yeniden dene.'); return; }
            ['yeni', 'mukerrer', 'incele', 'sayfa'].forEach(k => { document.getElementById('al-' + k).textContent = j[k]; });
            mesaj.hidden = !j.hata;
            mesaj.textContent = j.hata || '';
            if (j.durum !== 'suruyor') {
                document.getElementById('al-durum').textContent = j.durum === 'tamam' ? 'Alım tamamlandı' : 'Alım durduruldu';
                bekleme.textContent = 'Alınan kayıtları sipariş kutusunda inceleyebilirsin.';
                durdur.hidden = true;
                return;
            }
            if (j.manuel) { document.getElementById('al-durum').textContent = 'Alım bekliyor'; dur(); return; }
            const saniye = Math.max(5, Number(j.bekle) || 5);
            bekleme.textContent = 'Sonraki sayfa için ' + saniye + ' saniye bekleniyor.';
            window.setTimeout(adim, saniye * 1000);
        } catch (_) { dur('Bağlantı kesildi. Kaydedilen sayfalar korunuyor; alımı yeniden sürdürebilirsin.'); }
    }
    adim();
})();
