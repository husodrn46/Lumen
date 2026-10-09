<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/_idempotency.inc';

function web_siparis_tamsayi(mixed $value): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[0-9]{1,10}\z/', (string)$value)
        || (float)$value > 2147483647) { http_response_code(400); exit('Geçersiz sipariş parametresi.'); }
    return (int)$value;
}
function web_siparis_kur(mixed $value): float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) { http_response_code(400); exit('Geçersiz kur.'); }
    $value = str_replace(',', '.', (string)$value);
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0) { http_response_code(400); exit('Geçersiz kur.'); }
    return (float)$value;
}
function web_siparis_kapsam_dogrula(mixed $firmaNo, string $firma, string $donem): void
{
    $f = (is_int($firmaNo) || is_string($firmaNo)) ? str_pad((string)(int)$firmaNo, 3, '0', STR_PAD_LEFT) : '';
    if ((!is_int($firmaNo) && !is_string($firmaNo)) || !preg_match('/\\A[0-9]{1,3}\\z/', (string)$firmaNo)
        || (int)$firmaNo < 1 || (int)$firmaNo > 999 || $firma !== 'LG_' . $f . '_'
        || !preg_match('/\\ALG_' . $f . '_[0-9]{2}_\\z/', $donem) || str_ends_with($donem, '_00_')) {
        throw new RuntimeException('Web sipariş firma/dönem kapsamı doğrulanamadı.');
    }
}
/** Session retains form keys and immutable attempted payload; SQL receipt is authoritative. */
function web_siparis_anahtari(array &$session, string $scope, array $context, ?string $posted = null): string
{
    $bucket = hash('sha256', $scope . siparis_idempotency_parmakizi($context));
    $session['web_siparis_intents'] ??= [];
    if ($posted !== null) {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $posted)
            || !isset($session['web_siparis_intents'][$posted])
            || $session['web_siparis_intents'][$posted]['bucket'] !== $bucket) {
            throw new SiparisIdempotencyCakisma('Sipariş formu oturumu/kapsamı değişti. Siparişleri kontrol edin.');
        }
        return $posted;
    }
    foreach ($session['web_siparis_intents'] as $key => $intent) {
        if ($intent['bucket'] === $bucket && !$intent['complete']) { return $key; }
    }
    // Bounded session state; evicted forms fail closed, never silently get a new POST key.
    if (count($session['web_siparis_intents']) >= 64) {
        foreach ($session['web_siparis_intents'] as $key => $intent) {
            if ($intent['complete']) { unset($session['web_siparis_intents'][$key]); break; }
        }
        if (count($session['web_siparis_intents']) >= 64) {
            throw new RuntimeException('Çok sayıda sonuçlanmamış sipariş formu var. Siparişleri kontrol edin.');
        }
    }
    $key = bin2hex(random_bytes(32));
    $session['web_siparis_intents'][$key] = ['bucket'=>$bucket, 'complete'=>false, 'payload'=>null];
    return $key;
}
function web_siparis_dondur(array &$session, string $key, array $payload): string
{
    $hash = siparis_idempotency_parmakizi($payload);
    $old = $session['web_siparis_intents'][$key]['payload'] ?? null;
    if ($old !== null && siparis_idempotency_parmakizi($old) !== $hash) {
        throw new SiparisIdempotencyCakisma('Gönderilmiş siparişin içeriği değiştirilemez. Önce aynı içerikle sonucu alın.');
    }
    $session['web_siparis_intents'][$key]['payload'] = $payload;
    return $hash;
}
function web_siparis_tamam(array &$session, string $key): void
{
    $session['web_siparis_intents'][$key]['complete'] = true;
}
function web_siparis_post_anahtari(array $post): string
{
    $key = $post['web_intent_key'] ?? null;
    if (!is_string($key)) { throw new SiparisIdempotencyCakisma('Sipariş anahtarı eksik.'); }
    return $key;
}
function web_siparis_hidden(string $key, array $fields): string
{
    $html = '';
    foreach (['web_intent_key'=>$key] + $fields as $name=>$value) {
        $html .= '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
            . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
    }
    return $html;
}
function web_siparis_form(string $key, array $fields, string $message = ''): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="tr"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sipariş aç</title><style>body{margin:0;background:#f9fafb;color:#1f2937;font:17px/1.6 system-ui}main{max-width:540px;margin:8vh auto;padding:28px;background:white;border:1px solid #e5e7eb;border-radius:16px}button{padding:14px 24px;background:#6f1022;color:white;border:0;border-radius:9px;font:inherit;cursor:pointer}button:disabled{opacity:.6}a{color:#6f1022}@media(max-width:600px){main{margin:24px 12px}}</style><body><main><h1>Sipariş aç</h1><p>'
        . htmlspecialchars($message ?: 'Sipariş başlığı açıldıktan sonra ürünleri ekleyebilirsiniz.', ENT_QUOTES, 'UTF-8')
        . '</p><form method="post">' . csrf_field() . web_siparis_hidden($key, $fields)
        . '<button type="submit">' . ($message ? 'Aynı siparişle tekrar dene' : 'Siparişi aç') . '</button></form><p><a href="../siparis/lg_essiparis.php">Mevcut siparişleri kontrol et</a></p><script src="../siparis/web_intent.js"></script></main></body></html>';
}
function web_siparis_hata(Throwable $e): string
{
    if ($e instanceof SiparisIdempotencyCakisma) { http_response_code(409); return $e->getMessage(); }
    if ($e instanceof SiparisIdempotencyHazirDegil || $e instanceof SiparisIdempotencyBekle) { http_response_code(503); }
    else { http_response_code(500); }
    return 'Sipariş sonucu doğrulanamadı. İçeriği değiştirmeden aynı formu tekrar gönderin. Yeni sipariş açmadan önce mevcut siparişleri kontrol edin.';
}
