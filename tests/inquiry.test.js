// node tests/inquiry.test.js：在假的浏览器环境里跑 assets/inquiry.js，检查同意状态未知时不丢广告记录。
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'inquiry.js'), 'utf8');

let failures = 0;
const check = (ok, what) => { console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}`); if (!ok) failures++; };

function run({ search = '', referrer = '', stored = null, consent, google } = {}) {
  const store = new Map(stored ? [['bl_attr', JSON.stringify(stored)]] : []);
  const listeners = {};
  const timers = [];
  class HTMLFormElement {}
  const window = {
    achordInquiryConfig: { prefix: 'bl_', selector: 'form', storageKey: 'bl_attr' },
    setTimeout: (fn) => { timers.push(fn); return timers.length; },
    clearTimeout: () => {},
    addEventListener: () => {},
  };
  if (consent) window.achordInquiryConsent = consent;
  if (google) Object.assign(window, google);
  const context = {
    window,
    location: { search, origin: 'https://site.test', pathname: '/p/', hostname: 'site.test' },
    document: { referrer, addEventListener: (type, fn) => { listeners[type] = fn; } },
    localStorage: { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) },
    navigator: { maxTouchPoints: 0 },
    HTMLFormElement,
    URL, URLSearchParams, JSON, Date, Object, String, Array,
  };
  vm.runInNewContext(source, context);
  return { store, timers, window, listeners };
}

const future = Date.now() + 864e5;
const saved = { v: 1, arrived: 1, first_seen: 'x', landing: 'https://site.test/', referrer: '', params: { gclid: 'old' }, expires: future };

// 没有主题接口、谷歌标签还没加载：记录保留，继续等。
let r = run({ stored: saved });
check(r.store.has('bl_attr'), '谷歌标签还没来时不删已有记录');
check(r.timers.length === 1, '状态未知时继续重试');

// 谷歌标签加载后同意：带新点击的落地页覆盖记录。
r = run({ search: '?gclid=new', stored: saved });
r.window.gtag = () => {};
r.window.google_tag_data = { ics: { entries: { ad_storage: { default: true } } } };
r.timers[0]();
check(JSON.parse(r.store.get('bl_attr')).params.gclid === 'new', '谷歌同意后记下新点击');

// 访客明确拒绝（update 为 false）：删记录。
r = run({ stored: saved, google: { gtag: () => {}, google_tag_data: { ics: { entries: { ad_storage: { default: true, update: false } } } } } });
check(!r.store.has('bl_attr'), '访客明确拒绝时删记录');

// 同意工具先把每页设成默认拒绝、稍后恢复老访客的同意：默认拒绝不算拒绝，不删记录。
r = run({ stored: saved, google: { gtag: () => {}, google_tag_data: { ics: { entries: { ad_storage: { default: false } } } } } });
check(r.store.has('bl_attr') && r.timers.length === 1, '默认拒绝时保留记录并继续等');
r.window.google_tag_data.ics.entries.ad_storage.update = true;
r.timers[0]();
check(JSON.parse(r.store.get('bl_attr')).params.gclid === 'old', '恢复同意后原记录还在');

// 主题说本站没有广告同意项：删记录。
r = run({ stored: saved, consent: { tracking: true, advertising: false, agreed: () => true } });
check(!r.store.has('bl_attr'), '主题明确没有广告项时删记录');

// 主题说还不知道：保留。
r = run({ stored: saved, consent: { tracking: true, advertising: true, agreed: () => null } });
check(r.store.has('bl_attr'), '主题说还不知道时保留');

console.log(failures ? `\n${failures} failed` : '\nall passed');
process.exit(failures ? 1 : 0);
