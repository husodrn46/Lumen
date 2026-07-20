<?php
/**
 * cek_kirpma.php — Cek gorseli elle kirpma katmani (Cropper.js).
 * Kullanim: sayfanin </body> oncesine include_once(__DIR__ . '/cek_kirpma.php');
 * JS:  cekKirpAc(file, function(blob){ ... blob'u yukle ... });
 * Resim ise kirpma modali acar; PDF/diger dosyalar dokunulmadan callback'e gecer.
 */
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<div id="ckModal" class="ck-overlay" aria-hidden="true">
    <div class="ck-dialog">
        <div class="ck-head">
            <span><i class="fa-solid fa-crop-simple"></i> Çeki kırpın</span>
            <button type="button" class="ck-x" onclick="cekKirpKapat()" aria-label="Kapat">&times;</button>
        </div>
        <div class="ck-stage"><img id="ckImg" alt="kirpilacak cek"></div>
        <div class="ck-hint">Çerçeveyi çekin sınırına getirin; gerekirse döndürün.</div>
        <div class="ck-foot">
            <button type="button" class="ck-btn ck-ikincil" onclick="cekKirpDondur(-90)" title="Sola döndür"><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" class="ck-btn ck-ikincil" onclick="cekKirpDondur(90)" title="Sağa döndür"><i class="fa-solid fa-rotate-right"></i></button>
            <button type="button" class="ck-btn ck-iptal" onclick="cekKirpKapat()">İptal</button>
            <button type="button" class="ck-btn ck-onay" id="ckOnayBtn" onclick="cekKirpOnayla()"><i class="fa-solid fa-check"></i> Kullan</button>
        </div>
    </div>
</div>
<style>
    .ck-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.7); z-index:100000; align-items:center; justify-content:center; padding:12px; }
    .ck-overlay.acik { display:flex; }
    .ck-dialog { background:#fff; border-radius:16px; width:100%; max-width:560px; max-height:92vh; display:flex; flex-direction:column; overflow:hidden; font-family:'Avenir Next','Montserrat',sans-serif; box-shadow:0 24px 60px rgba(0,0,0,0.4); }
    .ck-head { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #e5e7eb; }
    .ck-head span { font-size:15px; font-weight:700; color:#1f2937; display:flex; align-items:center; gap:8px; }
    .ck-head span i { color:#6F1022; }
    .ck-x { background:none; border:none; font-size:24px; line-height:1; color:#6b7280; cursor:pointer; }
    .ck-stage { flex:1; min-height:240px; max-height:60vh; background:#0f172a; overflow:hidden; }
    .ck-stage img { display:block; max-width:100%; }
    .ck-hint { font-size:12px; color:#6b7280; padding:8px 18px 0; text-align:center; }
    .ck-foot { display:flex; gap:8px; padding:13px 18px; border-top:1px solid #e5e7eb; background:#fafafa; align-items:center; }
    .ck-btn { padding:11px 14px; border-radius:10px; font-family:inherit; font-size:13.5px; font-weight:600; cursor:pointer; border:1px solid transparent; display:inline-flex; align-items:center; gap:6px; }
    .ck-ikincil { background:#fff; color:#374151; border-color:#e5e7eb; }
    .ck-iptal { background:#fff; color:#6b7280; border-color:#e5e7eb; margin-left:auto; }
    .ck-onay { background:#6F1022; color:#fff; }
    .ck-btn:disabled { opacity:.6; cursor:not-allowed; }
</style>
<script>
(function(){
    var cropper = null, cbFn = null, objUrl = null;
    function temizle(){
        if (cropper) { cropper.destroy(); cropper = null; }
        if (objUrl) { URL.revokeObjectURL(objUrl); objUrl = null; }
        cbFn = null;
    }
    window.cekKirpAc = function(file, onComplete){
        if (!file) { return; }
        // Resim degilse (PDF vb.) kirpma yok, dosyayi oldugu gibi gec
        if (!/^image\//.test(file.type)) { onComplete(file); return; }
        if (typeof Cropper === 'undefined') { onComplete(file); return; } // kutuphane yuklenmediyse orijinali yukle
        cbFn = onComplete;
        var img = document.getElementById('ckImg');
        if (objUrl) { URL.revokeObjectURL(objUrl); }
        objUrl = URL.createObjectURL(file);
        img.src = objUrl;
        document.getElementById('ckModal').classList.add('acik');
        if (cropper) { cropper.destroy(); }
        cropper = new Cropper(img, {
            viewMode: 1, autoCropArea: 0.92, background: false,
            movable: false, zoomable: true, rotatable: true, responsive: true
        });
    };
    window.cekKirpKapat = function(){
        document.getElementById('ckModal').classList.remove('acik');
        temizle();
    };
    window.cekKirpDondur = function(deg){ if (cropper) { cropper.rotate(deg); } };
    window.cekKirpOnayla = function(){
        if (!cropper || !cbFn) { return; }
        var btn = document.getElementById('ckOnayBtn');
        btn.disabled = true;
        var canvas = cropper.getCroppedCanvas({ maxWidth: 2200, maxHeight: 2200, imageSmoothingQuality: 'high' });
        if (!canvas) { btn.disabled = false; return; }
        var cb = cbFn;
        canvas.toBlob(function(blob){
            btn.disabled = false;
            window.cekKirpKapat();
            if (blob && cb) { cb(blob); }
        }, 'image/jpeg', 0.9);
    };
})();
</script>
