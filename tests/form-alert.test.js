// node tests/form-alert.test.js：在假的浏览器环境里跑 assets/form-alert.js，检查什么情况上报、上报里有什么。
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const source = fs.readFileSync(path.join(__dirname, '..', 'assets', 'form-alert.js'), 'utf8');

let failures = 0;
const check = (ok, what) => { console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}`); if (!ok) failures++; };
const flush = () => new Promise((resolve) => setImmediate(resolve));

function run({ search = '', reply, offline = false } = {}) {
  const beacons = [];
  const listeners = {};
  const winListeners = {};
  class Element {
    constructor(props = {}) { Object.assign(this, { dataset: {}, tagName: 'BUTTON', textContent: '', ...props }); }
    closest() { return this; }
    matches(selector) { return selector.includes(this.tagName.toLowerCase()); }
    getAttribute(name) { return this.attrs?.[name] ?? null; }
  }
  class HTMLFormElement extends Element {}
  class Blob { constructor(parts) { this.text = parts.join(''); } }
  const fakeFetch = (input, init) => {
    if (typeof reply === 'function') return reply(input, init);
    return Promise.resolve(reply);
  };
  const window = {
    achordFormAlertConfig: { endpoint: 'https://alert.test/report', site: 'site.test', watch: ['boblift_inquiry_submit=1', 'boblift_catalog_request=1'] },
    fetch: fakeFetch,
    innerWidth: 390,
    setTimeout: () => 0,
    addEventListener: (type, fn) => { winListeners[type] = fn; },
  };
  const context = {
    window,
    location: { search, pathname: '/products/sq5/', hostname: 'site.test' },
    document: { documentElement: { lang: 'es' }, visibilityState: 'visible', addEventListener: (type, fn) => { listeners[type] = fn; } },
    navigator: { onLine: !offline, userAgent: 'Mozilla/5.0 (iPhone) Safari', sendBeacon: (url, blob) => { beacons.push({ url, body: JSON.parse(blob.text) }); return true; } },
    Element, HTMLFormElement, Blob,
    URLSearchParams, JSON, Date, Object, String, Array, Number, Set, WeakMap, Math, Promise,
  };
  vm.runInNewContext(source, context);
  return { window, beacons, listeners, winListeners, Element, HTMLFormElement, navigator: context.navigator };
}

const response = (status, body) => ({ status, clone: () => ({ text: () => Promise.resolve(body) }) });

(async () => {
  // 服务器 502：上报，带状态码、页面、浏览器、足迹，不带访客填的内容。
  let r = run({ reply: response(502, '<html><title>502 Bad Gateway</title></html>') });
  const button = new r.Element({ textContent: 'Get a quote', dataset: { inquiryOpen: '1' } });
  r.listeners.click({ target: button });
  const email = new r.Element({ tagName: 'INPUT', form: {}, attrs: { name: 'email' }, value: 'ana@client.test' });
  r.listeners.focusin({ target: email });
  await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST', body: 'email=ana@client.test' });
  await flush();
  let b = r.beacons[0]?.body || {};
  check(r.beacons.length === 1 && r.beacons[0].url === 'https://alert.test/report', '服务器 502：上报一次');
  check(b.kind === 'server_error' && b.status === 502 && b.detail === '502 Bad Gateway' && b.page === '/products/sq5/' && b.lang === 'es' && b.site === 'site.test', '上报带错误、状态码、页面、语言');
  check(b.steps.some((s) => s.includes('点击 button[inquiryOpen]')) && b.steps.some((s) => s.includes('填写 email')) && b.steps.some((s) => s.includes('服务器答复 502')), '足迹有点击、填写的字段名、服务器答复');
  check(!JSON.stringify(b).includes('ana@client.test'), '不带访客填的内容');

  // 访客漏填：不报。
  r = run({ reply: response(400, JSON.stringify({ success: false, data: { code: 'requiredFields', message: 'Please enter' } })) });
  await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' });
  await flush();
  check(r.beacons.length === 0, '访客漏填不报');

  // 成功：不报。
  r = run({ reply: response(200, JSON.stringify({ success: true, data: { id: 9 } })) });
  await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' });
  await flush();
  check(r.beacons.length === 0, '成功不报');

  // 别的请求：不看。
  r = run({ reply: response(500, 'x') });
  await r.window.fetch('/wp-json/other', { method: 'POST' });
  await r.window.fetch('/?boblift_inquiry_submit=1');
  await flush();
  check(r.beacons.length === 0, '不相干的请求和 GET 不看');

  // 人机验证没过、随机数过期、返回的不是 JSON、目录表单。
  r = run({ reply: response(400, JSON.stringify({ success: false, data: { code: 'verificationRequired', message: 'verify' } })) });
  await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' });
  await flush();
  check(r.beacons[0]?.body.kind === 'verification', '人机验证没过：报 verification（由报警服务按人数决定开不开）');
  r = run({ reply: response(403, JSON.stringify({ success: false, data: { code: 'invalidRequest' } })) });
  await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' });
  await flush();
  check(r.beacons[0]?.body.kind === 'forbidden', '随机数过期：报 forbidden');
  r = run({ reply: response(200, '<html><title>Just a moment...</title></html>') });
  await r.window.fetch('/?boblift_catalog_request=1', { method: 'POST' });
  await flush();
  check(r.beacons[0]?.body.kind === 'bad_response' && r.beacons[0]?.body.form === 'catalog' && r.beacons[0]?.body.detail === 'Just a moment...', '目录表单返回拦截页：报 bad_response');

  // 断网：报 network，并把错误继续抛给主题。
  r = run({ reply: () => Promise.reject(new TypeError('Failed to fetch')) });
  let thrown = false;
  try { await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' }); } catch { thrown = true; }
  check(thrown && r.beacons[0]?.body.kind === 'network' && r.beacons[0]?.body.detail === 'Failed to fetch', '断网：上报并照样把错误交给主题');

  // 答复头到了、内容没收完：报 network，主题照样拿到原答复。
  r = run({ reply: { status: 200, clone: () => ({ text: () => Promise.reject(new TypeError('network error')) }) } });
  const got = await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' });
  await flush();
  check(got.status === 200 && r.beacons[0]?.body.kind === 'network' && r.beacons[0]?.body.status === 200 && String(r.beacons[0]?.body.detail).includes('答复没收完整'), '答复没收完整：上报 network');

  // 答复是 JSON 但不是 WordPress 的成功格式：报 bad_response。
  for (const odd of ['{}', '[]', '{"error":"x"}']) {
    r = run({ reply: response(200, odd) });
    await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' });
    await flush();
    check(r.beacons[0]?.body.kind === 'bad_response', `答复 ${odd}：报 bad_response`);
  }

  // 访客断网：先不发，网络回来再补发。
  r = run({ offline: true, reply: () => Promise.reject(new TypeError('Failed to fetch')) });
  try { await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' }); } catch { /* 主题照样拿到错误 */ }
  check(r.beacons.length === 0, '断网时先不发报告');
  r.navigator.onLine = true;
  r.winListeners.online();
  check(r.beacons.length === 1 && r.beacons[0].body.kind === 'network', '网络回来后补发');

  // 主题自己超时中断（没离开页面）：报 aborted；访客离开页面时的中断：不报。
  const abortError = () => Object.assign(new Error('The operation was aborted.'), { name: 'AbortError' });
  r = run({ reply: () => Promise.reject(abortError()) });
  try { await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' }); } catch { /* 主题照样拿到错误 */ }
  check(r.beacons[0]?.body.kind === 'aborted', '没离开页面却被中断：报 aborted');
  r = run({ reply: () => Promise.reject(abortError()) });
  r.winListeners.pagehide();
  try { await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' }); } catch { /* 主题照样拿到错误 */ }
  check(r.beacons.length === 0, '离开页面时的中断：不报');

  // 同一页同一种错误只报一次，一页最多报 3 次。
  r = run({ reply: response(500, '{}') });
  for (let i = 0; i < 4; i++) await r.window.fetch('/?boblift_inquiry_submit=1', { method: 'POST' });
  await flush();
  check(r.beacons.length === 1, '同一种错误一页只报一次');
  ['a', 'b', 'c', 'd'].forEach((k) => r.window.achordFormAlert.report(`kind_${k}`));
  check(r.beacons.length === 3, '一页最多报 3 次');

  // 不用脚本的表单失败后回到原页。
  r = run({ search: '?inquiry=error' });
  check(r.beacons[0]?.body.kind === 'page_error', '回到原页带 inquiry=error：上报');
  r = run({ search: '?inquiry=missing' });
  check(r.beacons.length === 0, '回到原页带 inquiry=missing（漏填）：不报');

  // 验证码脚本加载失败。
  r = run();
  r.winListeners.error({ target: { tagName: 'SCRIPT', src: 'https://challenges.cloudflare.com/turnstile/v0/api.js' } });
  check(r.beacons[0]?.body.kind === 'turnstile_load', '验证码脚本加载失败：上报');

  // 隐去邮箱电话。
  check(r.window.achordFormAlert._redact('call +86 158 5249 9415 or a@b.co') === 'call [已隐去] or [已隐去]', '隐去电话和邮箱');

  console.log(failures ? `\n${failures} failed` : '\nall passed');
  process.exit(failures ? 1 : 0);
})();
