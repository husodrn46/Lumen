'use strict';
// Execute the actual index.php favorite handler with a DOM/network double.
const fs = require('fs'), vm = require('vm'), assert = require('assert');
const source = fs.readFileSync(__dirname + '/../index.php', 'utf8');
const start = source.indexOf('            var kartFavPending = false;');
const end = source.indexOf('            const hamburger', start);
assert(start >= 0 && end > start);
let checks = 0;
const check = (ok, label) => { checks++; assert(ok, label); };
function classes(initial = []) {
 const values = new Set(initial);
 return { contains: c => values.has(c), toggle: (c, yes) => yes ? values.add(c) : values.delete(c) };
}
const card = { classList: classes(), getAttribute: () => 'a.php' };
let handler, resolveRequest, rejectRequest, calls = 0;
const star = { classList: classes(), closest: () => card, addEventListener: (event, cb) => { handler = cb; } };
const toasts = [];
const context = {
 document: { querySelectorAll: selector => selector === '.card-grid .mc-star' ? [star] : card.classList.contains('mc-fav') ? [card] : [] },
 KART_FAV_CSRF: 'synthetic', URLSearchParams,
 fetch: () => { calls++; return new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; }); },
 showToast: (msg, kind) => toasts.push([msg, kind])
};
vm.runInNewContext(source.slice(start, end), context);
const click = () => handler({ preventDefault() {}, stopPropagation() {} });
const flush = () => new Promise(resolve => setImmediate(resolve));
(async () => {
 click(); check(card.classList.contains('mc-fav'), 'Optimistic toggle');
 click(); check(calls === 1, 'Pending request blocks repeat');
 resolveRequest({ json: async () => ({ ok: false }) }); await flush();
 check(!card.classList.contains('mc-fav') && !star.classList.contains('on'), 'Failed save restores card/star');
 check(toasts.at(-1)[1] === 'error', 'Failure does not claim saved');
 click(); resolveRequest({ json: async () => ({ ok: true }) }); await flush();
 check(card.classList.contains('mc-fav') && toasts.at(-1)[1] === 'success', 'Successful save persists UI');
 click(); rejectRequest(new Error('synthetic-network')); await flush();
 check(card.classList.contains('mc-fav') && star.classList.contains('on'), 'Network failure restores previous favorite');
 click(); resolveRequest({ json: async () => { throw new Error('synthetic-invalid-json'); } }); await flush();
 check(card.classList.contains('mc-fav'), 'Invalid JSON restores previous state');
 check(calls === 4, 'Guard releases after each result');
 console.log(`TAMAM: ${checks} favorite JS assertion (actual handler, DOM/network double; no browser render).`);
})().catch(e => { console.error(e); process.exitCode = 1; });
