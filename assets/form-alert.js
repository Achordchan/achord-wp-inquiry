/**
 * 表单报警（浏览器端）：询盘或目录表单发不出去时，把错误和失败前的几步操作发给报警服务（includes/alert.php）。
 *
 * - 平时只在本页内存里记最近 25 步：打开页面、点了哪个按钮链接、填过哪些字段（只记字段名，不记内容）、提交、
 *   请求结果、脚本报错。不写浏览器存储，不往外发。
 * - 盯着提交地址里带 config.watch 片段的 POST（插件给的是询盘和目录表单）：断网、服务器 5xx、403、返回的不是 JSON、
 *   保存失败就上报；访客自己漏填（requiredFields）不报；人机验证没过、提交太频繁照报，由报警服务按访客数决定要不要开
 *   Issue（一个人没过不算，几个人都没过就是验证坏了）。
 * - 没离开页面、请求却被中断（主题自己等太久超时）：报 aborted；访客跳走或关页时的中断不报。
 * - 不用脚本的表单失败时会回到原页带 ?inquiry=error|verification，打开页面时报。
 * - 验证码脚本（challenges.cloudflare.com）加载不出来也报。
 * - 点了提交、脚本报错、之后一直没发出请求：报「卡住」。
 * 主题也可以自己报：window.achordFormAlert.report(kind, detail)。
 */
(() => {
  const config = window.achordFormAlertConfig || {};
  const ENDPOINT = String(config.endpoint || '');
  const WATCH = Array.isArray(config.watch) ? config.watch.map(String).filter(Boolean) : [];
  if (!ENDPOINT || typeof window.fetch !== 'function') return;

  const started = Date.now();
  const steps = [];
  const MAX_STEPS = 25;
  let sentReports = 0;
  const sentKinds = new Set();
  let lastSubmitAt = 0;
  let lastRequestAt = 0;
  let lastErrorAt = 0;

  // 邮箱、电话样的串一律换掉；只留一行、截短。
  const redact = (value, length = 300) => String(value ?? '')
    .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[已隐去]')
    .replace(/\+?\d[\d\s().-]{6,}\d/g, '[已隐去]')
    .replace(/[\u0000-\u001f\u007f]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, length);
  const seconds = () => Math.round((Date.now() - started) / 100) / 10;
  const step = (what) => {
    steps.push(`${seconds()}s ${redact(what, 120)}`);
    if (steps.length > MAX_STEPS) steps.shift();
  };

  const formName = (url) => (/catalog/i.test(url) ? 'catalog' : 'inquiry');
  const watched = (url) => WATCH.some((part) => url.includes(part));

  // 断网时没发出去的报告，网络恢复后补发；最多留 3 条，离开页面就没了。
  const waiting = [];
  // 访客真的离开页面（跳转、关闭）：这时浏览器中断请求是正常的，不报。只认 pagehide（真走了才触发）；
  // 不用 beforeunload，主题弹「确定离开吗」、访客点了取消时它也会触发，会把之后真的故障都漏掉。
  let leaving = false;
  window.addEventListener('pagehide', () => { leaving = true; });
  window.addEventListener('pageshow', () => { leaving = false; });

  const report = (kind, detail = '', extra = {}) => {
    kind = String(kind || '').replace(/[^a-z_]/g, '').slice(0, 30);
    if (!kind || sentKinds.has(kind) || sentReports >= 3) return false;
    sentKinds.add(kind);
    sentReports += 1;
    const body = JSON.stringify({
      site: String(config.site || location.hostname),
      page: location.pathname.slice(0, 200),
      form: extra.form || 'inquiry',
      kind,
      status: Number(extra.status) || 0,
      detail: redact(detail),
      elapsed: Number(extra.elapsed) || 0,
      ua: String(navigator.userAgent || '').slice(0, 250),
      lang: String(document.documentElement?.lang || '').slice(0, 20),
      width: Number(window.innerWidth) || 0,
      steps: steps.slice(),
      at: new Date().toISOString(),
    });
    // 访客这会儿断网：先留在本页内存里，等网络回来再发（发出去之前不算数）。
    if (navigator.onLine === false) {
      waiting.push(body);
      return true;
    }
    deliver(body);
    return true;
  };

  const deliver = (body) => {
    // text/plain 不触发跨域预检；页面马上跳走也照样发出。
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain' }))) return;
    } catch { /* 改用 fetch */ }
    try {
      window.fetch(ENDPOINT, { method: 'POST', body, keepalive: true, mode: 'no-cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } })
        .catch(() => { if (waiting.length < 3) waiting.push(body); });
    } catch { /* 报不出去就算了 */ }
  };
  window.addEventListener('online', () => {
    waiting.splice(0).forEach(deliver);
  });

  /**
   * 看一次提交的结果，返回 [kind, detail] 或 null（成功，或访客自己漏填）。
   * 插件的失败答复带 code（requiredFields、verificationRequired、tooMany、saveFailed、invalidRequest）。
   */
  const classify = (status, text) => {
    let data = null;
    try { data = JSON.parse(text); } catch { /* 下面按不是 JSON 处理 */ }
    if (data && typeof data === 'object') {
      if (data.success === true && status < 400) return null;
      const code = String(data.data?.code || '');
      const message = String(data.data?.message || '');
      if (code === 'requiredFields') return null;
      if (code === 'verificationRequired') return ['verification', message];
      if (code === 'tooMany' || status === 429) return ['rate_limited', message];
      if (code === 'saveFailed') return ['save_failed', message];
      if (code === 'invalidRequest' || status === 403) return ['forbidden', message];
      if (status >= 500) return ['server_error', message];
      // 没有 code 的 400（主题自己的表单校验）多半是访客漏填，不报。
      if (status >= 400 && status < 500) return null;
      if (data.success === false) return ['rejected', message];
      // 不是 WordPress 的成功答复（{}、[]、少了 success）：不能确定询盘收下了，主题也走不完成功流程。
      return ['bad_response', `答复格式不对：${String(text).slice(0, 120)}`];
    }
    const title = (String(text).match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1] || '').trim();
    if (status >= 500) return ['server_error', title || String(text).slice(0, 200)];
    if (status === 403) return ['forbidden', title || String(text).slice(0, 200)];
    return ['bad_response', title || String(text).slice(0, 200)];
  };

  // 包一层 fetch：只看盯着的 POST，别的请求原样放过。
  const originalFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    let url = '';
    let method = 'GET';
    try {
      url = typeof input === 'string' ? input : String(input?.url || input || '');
      method = String(init?.method || input?.method || 'GET').toUpperCase();
    } catch { /* 当作不相干 */ }
    if (method !== 'POST' || !watched(url)) return originalFetch(input, init);
    const form = formName(url);
    const t0 = Date.now();
    lastRequestAt = t0;
    step(`发送 ${form} 表单`);
    return originalFetch(input, init).then((response) => {
      const elapsed = Date.now() - t0;
      // 读副本，不动主题拿到的那份。
      response.clone().text().then((text) => {
        const verdict = classify(response.status, text);
        step(`服务器答复 ${response.status}（${Math.round(elapsed / 100) / 10}s）${verdict ? '：' + verdict[0] : ''}`);
        if (verdict) report(verdict[0], verdict[1], { form, status: response.status, elapsed });
      }).catch((error) => {
        // 答复头到了、内容没收完（连接中途断了，或主题等太久中断了）：主题那边也读不到，算没发成功。访客离开页面不算。
        if (leaving) return;
        const aborted = error?.name === 'AbortError';
        step(`答复没收完整（${response.status}）：${aborted ? '被中断' : error?.message || error}`);
        report(aborted ? 'aborted' : 'network', `答复没收完整：${aborted ? '被中断（多半是等太久超时）' : String(error?.message || error || '')}`, { form, status: response.status, elapsed: Date.now() - t0 });
      });
      return response;
    }, (error) => {
      const elapsed = Date.now() - t0;
      // 访客自己离开页面时浏览器中断请求，不算；没离开页面却被中断，多半是主题等太久自己超时了，要报。
      if (!leaving) {
        const aborted = error?.name === 'AbortError';
        step(`请求${aborted ? '被中断' : '没发出去'}（${Math.round(elapsed / 100) / 10}s）：${error?.message || error}`);
        report(aborted ? 'aborted' : 'network', aborted ? `请求被中断（等了 ${Math.round(elapsed / 100) / 10} 秒，多半是超时）` : String(error?.message || error || ''), { form, elapsed });
      }
      throw error;
    });
  };

  const describe = (element) => {
    const tag = element.tagName.toLowerCase();
    const data = Object.keys(element.dataset || {}).filter((key) => /inquiry|quote|catalog|whatsapp|modal|submit/i.test(key)).slice(0, 3);
    const text = redact(element.getAttribute('aria-label') || element.textContent || element.value || '', 40);
    return `${tag}${data.length ? '[' + data.join(',') + ']' : ''}${text ? ' 「' + text + '」' : ''}`;
  };
  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('a, button, [role="button"], input[type="submit"]') : null;
    if (target) step(`点击 ${describe(target)}`);
  }, true);
  const filled = new WeakMap();
  document.addEventListener('focusin', (event) => {
    const field = event.target;
    if (!(field instanceof Element) || !field.matches('input, textarea, select') || !field.form) return;
    const name = String(field.getAttribute('name') || field.id || field.type || '').slice(0, 40);
    const seen = filled.get(field.form) || new Set();
    if (!name || seen.has(name) || /nonce|website|token|turnstile/i.test(name)) return;
    seen.add(name);
    filled.set(field.form, seen);
    step(`填写 ${name}`);
  }, true);
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const action = String(form.getAttribute('action') || '');
    step(`提交表单 ${action ? redact(action.replace(/^https?:\/\/[^/]+/, ''), 60) : ''}`);
    if (!watched(action)) return;
    const at = Date.now();
    lastSubmitAt = at;
    // 点了提交，之后脚本报错、一直没发出请求：表单卡住了。只是访客漏填（没报错）不算。
    window.setTimeout(() => {
      if (lastSubmitAt === at && lastRequestAt < at && lastErrorAt >= at && document.visibilityState !== 'hidden') {
        report('stuck', steps.filter((line) => line.includes('脚本报错')).slice(-1)[0] || '', { form: formName(action) });
      }
    }, 12000);
  }, true);
  window.addEventListener('error', (event) => {
    const target = event.target;
    // 资源加载失败（捕获阶段才看得到）：只关心人机验证脚本。
    if (target && target !== window && target.tagName === 'SCRIPT') {
      const src = String(target.src || '');
      if (src.includes('challenges.cloudflare.com')) {
        step('验证码脚本加载失败');
        report('turnstile_load', src.slice(0, 120));
      }
      return;
    }
    lastErrorAt = Date.now();
    const file = String(event.filename || '').split('/').pop().split('?')[0];
    step(`脚本报错：${event.message || ''}${file ? ' @' + file + ':' + (event.lineno || 0) : ''}`);
  }, true);
  // 报警脚本运行之前就失败了的验证码脚本（页头记录器记下的）：补报。
  const early = Array.isArray(window.achordFormAlertEarly) ? window.achordFormAlertEarly : [];
  if (early.length) {
    step('验证码脚本加载失败（页面打开时）');
    report('turnstile_load', String(early[0]).slice(0, 120));
  }
  window.addEventListener('unhandledrejection', (event) => {
    lastErrorAt = Date.now();
    step(`脚本报错：${String(event.reason?.message || event.reason || '').slice(0, 100)}`);
  });

  step(`打开 ${location.pathname}`);
  // 不用脚本的表单失败后回到原页，地址带 ?inquiry=。
  const outcome = new URLSearchParams(location.search).get('inquiry');
  const reason = new URLSearchParams(location.search).get('inquiry_reason') || '';
  // 提交太频繁（429）也是回到原页带 inquiry=error：按「被限流」报，由报警服务凑够几位访客才开 Issue。
  if (outcome === 'error' && reason === 'tooMany') report('rate_limited', '提交后回到原页：提交太频繁', { form: 'inquiry', status: 429 });
  else if (outcome === 'error' && reason === 'saveFailed') report('save_failed', '提交后回到原页：询盘存不进后台', { form: 'inquiry', status: 500 });
  else if (outcome === 'error') report('page_error', '提交后回到原页：inquiry=error', { form: 'inquiry' });
  else if (outcome === 'verification') report('verification', '提交后回到原页：inquiry=verification', { form: 'inquiry' });

  window.achordFormAlert = {
    report: (kind, detail = '', extra = {}) => report(kind, detail, extra || {}),
    step,
    // 测试用。
    _classify: classify,
    _redact: redact,
    _steps: () => steps.slice(),
  };
})();
