/**
 * 表单报警服务（Cloudflare Worker）。插件（includes/alert.php、assets/form-alert.js）把表单故障报到这里；这里合并同类
 * 错误、限流后，触发站点仓库的 .github/workflows/form-alert.yml 开 Issue（由 github-actions 机器人开，本人才收得到通知），
 * 并推钉钉。每小时把攒下的次数补报一次；每天新加坡时间 09:00 给每个站做一次自检（提交一条测试询盘）。
 *
 * 接口：
 *   POST /report  访客浏览器上报（text/plain JSON，只认配置里的站点和来源域名）
 *   POST /server  站点服务器上报（Authorization: Bearer <该站报警密钥>）
 *   GET  /health  最近一次自检结果
 *
 * 绑定（wrangler.toml）：KV ALERTS；变量 SITES（JSON，见 wrangler.example.toml）。
 * 密钥（wrangler secret put）：GITHUB_TOKEN（只授权站点仓库 Actions: write）、SITE_SECRETS（JSON：{"域名": "报警密钥"}）、
 *   DINGTALK_WEBHOOK、DINGTALK_SECRET（机器人开了「加签」才要）。
 */

const HOUR = 3600;
const LABEL = '表单报警';
// 访客浏览器能报的错误，和它们的中文说法。
const KINDS = {
  network: '请求没发出去（断网或超时）',
  server_error: '服务器出错',
  forbidden: '服务器拒绝（随机数过期或被拦截）',
  bad_response: '服务器返回的不是正常答复（可能是拦截页或报错页）',
  save_failed: '询盘存不进后台',
  rejected: '提交被拒绝',
  page_error: '提交失败后回到原页（inquiry=error）',
  stuck: '点了提交但脚本报错、一直没发出去',
  turnstile_load: '验证码脚本加载不出来',
  verification: '多位访客人机验证都没通过',
  rate_limited: '多位访客被限流（提交太频繁）',
};
// 站点服务器能报的。
const SERVER_KINDS = {
  save_failed: '询盘存不进后台',
  mail_failed: '询盘已存，但通知邮件发不出去',
};
// 自检发现的问题。
const CHECK_KINDS = {
  check_page: '自检：表单页面打不开或页面上找不到表单',
  check_nonce: '自检：拿不到新的随机数',
  check_submit: '自检：测试询盘提交失败',
  check_secret: '自检：站点没认出报警密钥（两边密钥不一致）',
  check_config: '自检：通知配置有问题',
  check_mail: '自检：最近一次通知邮件发送失败',
  check_quiet: '自检：很久没有收到询盘',
};
// 单个访客失败不算故障，一小时内这么多位不同访客都失败才报。
const CROWD = { verification: 3, rate_limited: 3 };
const LIMITS = { perIpHour: 10, newIssuesPerSiteDay: 5, dingEvery: 6 * HOUR, keepState: 7 * 24 * HOUR };

const json = (data, status = 200, headers = {}) => new Response(JSON.stringify(data), { status, headers: { 'Content-Type': 'application/json; charset=utf-8', ...headers } });

const redact = (value, length = 300) => String(value ?? '')
  .replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[已隐去]')
  .replace(/\+?\d[\d\s().-]{6,}\d/g, '[已隐去]')
  .replace(/[\u0000-\u001f\u007f]/g, ' ')
  .replace(/\s+/g, ' ')
  .trim()
  .slice(0, length);
// Markdown 里原样显示，不让内容变成链接、标题或 @ 提醒。
const plain = (value, length = 300) => redact(value, length).replace(/[`*_\[\]<>#|@]/g, (c) => `\\${c}`);

async function sha(text) {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function sites(env) {
  try {
    const parsed = typeof env.SITES === 'string' ? JSON.parse(env.SITES) : env.SITES;
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}
function siteSecret(env, site) {
  try {
    const all = typeof env.SITE_SECRETS === 'string' ? JSON.parse(env.SITE_SECRETS) : env.SITE_SECRETS;
    const secret = String(all?.[site] || '');
    return secret.length >= 24 ? secret : '';
  } catch {
    return '';
  }
}
function sameSecret(a, b) {
  if (!a || !b || a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

// 浏览器、系统的简短说法，如 Safari 17 / iOS 17。
export function describeUa(ua) {
  ua = String(ua || '');
  const pick = (re) => ua.match(re)?.[1]?.replace(/_/g, '.') || '';
  let browser = '其他浏览器';
  if (/FBAN|FBAV/.test(ua)) browser = 'Facebook 内置浏览器';
  else if (/Instagram/.test(ua)) browser = 'Instagram 内置浏览器';
  else if (/Edg\//.test(ua)) browser = `Edge ${pick(/Edg\/(\d+)/)}`;
  else if (/OPR\//.test(ua)) browser = `Opera ${pick(/OPR\/(\d+)/)}`;
  else if (/SamsungBrowser/.test(ua)) browser = `三星浏览器 ${pick(/SamsungBrowser\/(\d+)/)}`;
  else if (/CriOS|Chrome\//.test(ua)) browser = `Chrome ${pick(/(?:CriOS|Chrome)\/(\d+)/)}`;
  else if (/FxiOS|Firefox\//.test(ua)) browser = `Firefox ${pick(/(?:FxiOS|Firefox)\/(\d+)/)}`;
  else if (/Safari\//.test(ua)) browser = `Safari ${pick(/Version\/(\d+)/)}`;
  let system = '其他系统';
  if (/iPhone|iPad|iPod/.test(ua)) system = `iOS ${pick(/OS (\d+[_\d]*)/).split('.').slice(0, 2).join('.')}`;
  else if (/Android/.test(ua)) system = `Android ${pick(/Android (\d+)/)}`;
  else if (/Windows NT/.test(ua)) system = 'Windows';
  else if (/Mac OS X/.test(ua)) system = 'macOS';
  else if (/Linux/.test(ua)) system = 'Linux';
  return `${browser.trim()} / ${system.trim()}`;
}

// 同类错误的指纹：站点 + 表单 + 类型 + 状态码 + 去掉数字的错误信息；按人数报的类型不看错误信息。
export async function fingerprint(event) {
  const detail = CROWD[event.kind] ? '' : String(event.detail || '').toLowerCase().replace(/\d+/g, '#').slice(0, 80);
  return (await sha([event.site, event.form, event.kind, event.status || 0, detail].join('|'))).slice(0, 12);
}

function kindText(kind) {
  return KINDS[kind] || SERVER_KINDS[kind] || CHECK_KINDS[kind] || kind;
}
const sgt = (seconds) => new Date(seconds * 1000 + 8 * HOUR * 1000).toISOString().replace('T', ' ').slice(0, 16) + '（新加坡时间）';

/** Issue 标题、正文和后续评论（中文，给人看的）。 */
export function compose(siteCfg, event, info) {
  const where = event.source === 'server' ? '网站服务器' : event.source === 'selfcheck' ? '每日自检' : '访客浏览器';
  const form = event.form === 'catalog' ? '目录下载表单' : '询盘表单';
  const status = event.status ? `：HTTP ${event.status}` : '';
  const title = `[${siteCfg.label}] ${form}${kindText(event.kind)}${status}`.slice(0, 120);
  const rows = [
    ['发现方式', where],
    ['时间', sgt(info.now)],
    ['页面', event.page ? `${siteCfg.base || ''}${event.page}` : ''],
    ['错误', event.detail ? plain(event.detail) : ''],
    ['浏览器', event.ua ? describeUa(event.ua) : ''],
    ['页面语言', event.lang ? plain(event.lang, 20) : ''],
    ['屏幕宽度', event.width ? `${event.width}px` : ''],
    ['等待时间', event.elapsed ? `${Math.round(event.elapsed / 100) / 10} 秒` : ''],
    ['访客国家', event.country ? plain(event.country, 10) : ''],
    ['询盘编号', event.inquiry ? `${event.inquiry}（在后台询盘列表里查看）` : ''],
    ['次数', info.count > 1 ? `过去 1 小时 ${info.count} 次，涉及 ${info.visitors} 位访客` : ''],
  ].filter(([, value]) => value);
  const table = ['| 项目 | 内容 |', '| --- | --- |', ...rows.map(([k, v]) => `| ${k} | ${String(v).replace(/\|/g, '\\|')} |`)].join('\n');
  const steps = Array.isArray(event.steps) && event.steps.length
    ? `\n\n**失败前的操作**（只记点了什么、填过哪些字段名，不记填的内容）\n\n${event.steps.slice(-25).map((s, i) => `${i + 1}. ${plain(s, 160)}`).join('\n')}`
    : '';
  const health = event.health ? `\n\n**自检时站点报告的配置**\n\n\`\`\`json\n${JSON.stringify(event.health, null, 2).slice(0, 1500)}\n\`\`\`` : '';
  const body = `${table}${steps}${health}\n\n同一个问题再出现时，报警服务只在这里补评论（每小时最多一条）。修好后请关闭这个 Issue；之后再出现会重新开一个。`;
  const comment = `又出现了：${info.count > 1 ? `过去 1 小时 ${info.count} 次，涉及 ${info.visitors} 位访客` : '1 次'}（最近一次 ${sgt(info.now)}）\n\n${table}${steps}${health}`;
  return { title, body, comment };
}

async function readState(env, key) {
  try {
    return (await env.ALERTS.get(key, 'json')) || null;
  } catch {
    return null;
  }
}
const writeState = (env, key, value, ttl = LIMITS.keepState) => env.ALERTS.put(key, JSON.stringify(value), { expirationTtl: ttl });

/** 计数加一并返回新值（KV 不是严格计数器，限流够用）。 */
async function bump(env, key, ttl) {
  const current = Number(await env.ALERTS.get(key)) || 0;
  await env.ALERTS.put(key, String(current + 1), { expirationTtl: ttl });
  return current + 1;
}

async function dispatchIssue(env, siteCfg, payload, fetcher) {
  const response = await fetcher(`https://api.github.com/repos/${siteCfg.repo}/actions/workflows/form-alert.yml/dispatches`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${env.GITHUB_TOKEN}`,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'achord-form-alert',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ ref: siteCfg.branch || 'main', inputs: { fp: payload.fp, payload: JSON.stringify(payload).slice(0, 60000) } }),
  });
  return response.status === 204;
}

async function sendDingtalk(env, siteCfg, title, event, info, fetcher) {
  if (!env.DINGTALK_WEBHOOK) return false;
  let url = env.DINGTALK_WEBHOOK;
  if (env.DINGTALK_SECRET) {
    const timestamp = String(Date.now());
    const key = await crypto.subtle.importKey('raw', new TextEncoder().encode(env.DINGTALK_SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
    const signature = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(`${timestamp}\n${env.DINGTALK_SECRET}`));
    const sign = btoa(String.fromCharCode(...new Uint8Array(signature)));
    url += `${url.includes('?') ? '&' : '?'}timestamp=${timestamp}&sign=${encodeURIComponent(sign)}`;
  }
  const issues = `https://github.com/${siteCfg.repo}/issues?q=${encodeURIComponent(`is:issue is:open label:${LABEL}`)}`;
  const lines = [
    `#### 🚨 ${siteCfg.label}询盘表单报警`,
    `**${plain(title.replace(/^\[[^\]]*\]\s*/, ''), 120)}**`,
    '',
    event.page ? `- 页面：${plain(event.page, 120)}` : '',
    event.ua ? `- 浏览器：${describeUa(event.ua)}` : '',
    info.count > 1 ? `- 过去 1 小时：${info.count} 次，${info.visitors} 位访客` : '',
    `- 时间：${sgt(info.now)}`,
    '',
    `详情和失败前的操作见 GitHub Issue（1–2 分钟内生成）：[打开](${issues})`,
  ].filter((line, i, all) => line !== '' || (all[i - 1] !== '' && i > 0));
  const response = await fetcher(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ msgtype: 'markdown', markdown: { title: `${siteCfg.label}询盘表单报警`, text: lines.join('\n') } }),
  });
  try {
    return (await response.json()).errcode === 0;
  } catch {
    return false;
  }
}

/**
 * 收下一条故障：按指纹合并；同一指纹每小时最多触发一次 Issue（期间只计数），每站每天最多新开 5 种；
 * 钉钉在第一次和此后每 6 小时最多一次。返回 'sent' | 'held' | 'crowd' | 'capped'。
 */
export async function intake(env, event, { now = Math.floor(Date.now() / 1000), fetcher = fetch } = {}) {
  const siteCfg = sites(env)[event.site];
  if (!siteCfg) return 'unknown-site';
  const fp = await fingerprint(event);
  const visitor = event.visitor || '';

  // 按人数报的：先攒这一小时里的不同访客。
  if (CROWD[event.kind]) {
    const crowdKey = `crowd:${event.site}:${fp}:${Math.floor(now / HOUR)}`;
    const crowd = (await readState(env, crowdKey)) || { visitors: [] };
    if (visitor && !crowd.visitors.includes(visitor)) crowd.visitors.push(visitor);
    await writeState(env, crowdKey, crowd, 2 * HOUR);
    if (crowd.visitors.length < CROWD[event.kind]) return 'crowd';
  }

  const key = `fp:${event.site}:${fp}`;
  const state = (await readState(env, key)) || { first: now, lastSent: 0, lastDing: 0, pending: 0, visitors: [], total: 0 };
  state.total += 1;
  state.pending += 1;
  if (visitor && !state.visitors.includes(visitor) && state.visitors.length < 50) state.visitors.push(visitor);
  state.sample = { ...event, steps: (event.steps || []).slice(-25) };

  if (state.lastSent && now - state.lastSent < HOUR) {
    await writeState(env, key, state);
    return 'held';
  }
  if (!state.lastSent) {
    const cap = await bump(env, `cap:${event.site}:${new Date(now * 1000).toISOString().slice(0, 10)}`, 2 * 24 * HOUR);
    if (cap > LIMITS.newIssuesPerSiteDay) {
      await writeState(env, key, state);
      return 'capped';
    }
  }
  return (await send(env, siteCfg, key, fp, state, now, fetcher)) ? 'sent' : 'held';
}

async function send(env, siteCfg, key, fp, state, now, fetcher) {
  const event = state.sample;
  const info = { now, count: state.pending, visitors: Math.max(1, state.visitors.length) };
  const text = compose(siteCfg, event, info);
  const ok = await dispatchIssue(env, siteCfg, { fp, title: text.title, body: text.body, comment: text.comment, assignee: siteCfg.assignee || '' }, fetcher);
  if (ok && (!state.lastDing || now - state.lastDing >= LIMITS.dingEvery)) {
    if (await sendDingtalk(env, siteCfg, text.title, event, info, fetcher)) state.lastDing = now;
  }
  if (ok) {
    state.lastSent = now;
    state.pending = 0;
    state.visitors = [];
  }
  await writeState(env, key, state);
  return ok;
}

/** 每小时：攒着没报的次数补报一条评论（满一小时的才报）。 */
export async function flush(env, { now = Math.floor(Date.now() / 1000), fetcher = fetch } = {}) {
  const all = sites(env);
  let cursor;
  do {
    const page = await env.ALERTS.list({ prefix: 'fp:', cursor });
    for (const { name } of page.keys) {
      const state = await readState(env, name);
      if (!state || state.pending <= 0 || (state.lastSent && now - state.lastSent < HOUR)) continue;
      const [, site, fp] = name.split(':');
      if (!all[site]) continue;
      if (!state.lastSent) {
        // 当天新开的已到上限：等第二天。
        const capKey = `cap:${site}:${new Date(now * 1000).toISOString().slice(0, 10)}`;
        if ((Number(await env.ALERTS.get(capKey)) || 0) >= LIMITS.newIssuesPerSiteDay) continue;
        await bump(env, capKey, 2 * 24 * HOUR);
      }
      await send(env, all[site], name, fp, state, now, fetcher);
    }
    cursor = page.list_complete ? undefined : page.cursor;
  } while (cursor);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** 自检一个站：打开表单页、取新随机数、带报警密钥提交测试询盘、看站点报告的配置。返回发现的问题（没有就是空数组）。 */
export async function selfCheck(env, site, siteCfg, { now = Math.floor(Date.now() / 1000), fetcher = fetch, wait = 20000 } = {}) {
  const check = siteCfg.check || {};
  const secret = siteSecret(env, site);
  const ua = 'Mozilla/5.0 (compatible; BOB-LIFT form self-check; +https://github.com/Achordchan)';
  const problems = [];
  const attempt = async () => {
    const page = await fetcher(check.page, { headers: { 'User-Agent': ua, Accept: 'text/html' }, redirect: 'follow' }).catch((e) => ({ status: 0, text: async () => String(e) }));
    const html = await page.text();
    if (page.status !== 200 || !html.includes(check.marker)) {
      return [{ kind: 'check_page', status: page.status, detail: page.status === 200 ? `页面上找不到表单（${check.marker}）` : html.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1] || '', page: new URL(check.page).pathname }];
    }
    const nonceResponse = await fetcher(`${check.nonce_url}${check.nonce_url.includes('?') ? '&' : '?'}t=${now}`, { headers: { 'User-Agent': ua, Accept: 'application/json' } }).catch(() => ({ status: 0, json: async () => ({}) }));
    const nonce = String((await nonceResponse.json().catch(() => ({})))?.data?.nonce || '');
    if (!nonce) return [{ kind: 'check_nonce', status: nonceResponse.status, detail: check.nonce_url }];
    const form = new URLSearchParams({ action: check.action, [check.nonce_field]: nonce, [check.response_field]: 'json', source_title: '每日自检', ...(check.fields || {}) });
    const submit = await fetcher(check.submit, {
      method: 'POST',
      headers: { 'User-Agent': ua, Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-Achord-Monitor': secret },
      body: form.toString(),
    }).catch((e) => ({ status: 0, text: async () => String(e) }));
    const raw = await submit.text();
    let answer = null;
    try { answer = JSON.parse(raw); } catch { /* 下面报 */ }
    if (!answer || answer.success !== true) {
      return [{ kind: 'check_submit', status: submit.status, detail: answer?.data?.message || raw.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1] || raw.slice(0, 200) }];
    }
    if (answer.data?.monitor !== true) {
      // 站点没认出密钥却收下了：这是一条真询盘（会发邮件），说明两边密钥不一致。
      return [{ kind: 'check_secret', status: submit.status, detail: '站点把自检当成了普通询盘收下（可能已发出一封测试邮件），请核对两边的报警密钥' }];
    }
    const health = answer.data.health || {};
    const found = [];
    if (health.recipient_ok === false) found.push({ kind: 'check_config', detail: '询盘设置里的收件邮箱无效或没填', health });
    if (health.turnstile_on && health.turnstile_ready === false) found.push({ kind: 'check_config', detail: '启用了人机验证但密钥没填全', health });
    if (health.dingtalk === false) found.push({ kind: 'check_config', detail: '钉钉插件没有启用', health });
    if (health.mail_fail_at && health.mail_fail_at > (health.mail_ok_at || 0)) found.push({ kind: 'check_mail', detail: health.mail_fail_reason || '最近一次通知邮件发送失败', health });
    const quietHours = Number(siteCfg.quiet_hours) || 0;
    if (quietHours && health.last_inquiry_at && now - health.last_inquiry_at > quietHours * HOUR) {
      found.push({ kind: 'check_quiet', detail: `已经 ${Math.floor((now - health.last_inquiry_at) / HOUR)} 小时没有收到询盘（提醒阈值 ${quietHours} 小时），表单自检正常，请看看流量或广告`, health });
    }
    return found;
  };
  let found = await attempt();
  // 一次失败可能是网络抖动：等一会儿再试一次，还不行才报。
  if (found.some((p) => ['check_page', 'check_nonce', 'check_submit'].includes(p.kind))) {
    if (wait) await sleep(wait);
    found = await attempt();
  }
  for (const problem of found) {
    problems.push({ site, form: 'inquiry', source: 'selfcheck', page: problem.page || (check.page ? new URL(check.page).pathname : ''), status: problem.status || 0, ...problem });
  }
  return problems;
}

async function runSelfChecks(env, ctxNow, fetcher = fetch) {
  const results = {};
  for (const [site, siteCfg] of Object.entries(sites(env))) {
    if (!siteCfg.check) continue;
    const problems = await selfCheck(env, site, siteCfg, { now: ctxNow, fetcher });
    results[site] = { at: ctxNow, ok: problems.length === 0, problems: problems.map((p) => `${kindText(p.kind)}：${redact(p.detail, 120)}`) };
    for (const problem of problems) await intake(env, problem, { now: ctxNow, fetcher });
  }
  await env.ALERTS.put('selfcheck:last', JSON.stringify(results));
  return results;
}

function cors(origin) {
  return { 'Access-Control-Allow-Origin': origin, 'Access-Control-Allow-Methods': 'POST, OPTIONS', 'Access-Control-Allow-Headers': 'Content-Type', 'Access-Control-Max-Age': '86400', Vary: 'Origin' };
}

/** 访客浏览器发来的：只收认识的站、对得上的来源域名、规定的错误类型，限每个 IP 每小时 10 条。 */
export async function handleReport(request, env, { now = Math.floor(Date.now() / 1000), fetcher = fetch } = {}) {
  const origin = request.headers.get('Origin') || '';
  const text = await request.text();
  if (text.length > 20000) return json({ ok: false }, 413);
  let body;
  try { body = JSON.parse(text); } catch { return json({ ok: false }, 400); }
  const site = String(body.site || '');
  const siteCfg = sites(env)[site];
  if (!siteCfg || !(siteCfg.origins || []).includes(origin)) return json({ ok: false }, 403);
  const headers = cors(origin);
  if (!KINDS[body.kind]) return json({ ok: false }, 400, headers);
  const ip = request.headers.get('CF-Connecting-IP') || '';
  // IP 只用来限流和数「几位访客」，算成不可逆的短编号后就丢掉。
  const visitor = (await sha(`${ip}|${env.SITE_SECRETS || ''}`)).slice(0, 12);
  if ((await bump(env, `ip:${visitor}:${Math.floor(now / HOUR)}`, 2 * HOUR)) > LIMITS.perIpHour) return json({ ok: false }, 429, headers);
  const event = {
    site,
    source: 'browser',
    kind: body.kind,
    form: body.form === 'catalog' ? 'catalog' : 'inquiry',
    status: Number(body.status) || 0,
    detail: redact(body.detail),
    page: redact(String(body.page || '').split('?')[0], 200),
    ua: String(body.ua || '').slice(0, 250),
    lang: redact(body.lang, 20),
    width: Number(body.width) || 0,
    elapsed: Number(body.elapsed) || 0,
    steps: (Array.isArray(body.steps) ? body.steps : []).slice(-25).map((s) => redact(s, 160)),
    country: request.cf?.country || '',
    visitor,
  };
  return json({ ok: true, result: await intake(env, event, { now, fetcher }) }, 200, headers);
}

/** 站点服务器发来的：要带该站的报警密钥。 */
export async function handleServer(request, env, { now = Math.floor(Date.now() / 1000), fetcher = fetch } = {}) {
  let body;
  try { body = JSON.parse(await request.text()); } catch { return json({ ok: false }, 400); }
  const site = String(body.site || '');
  const secret = siteSecret(env, site);
  const given = (request.headers.get('Authorization') || '').replace(/^Bearer\s+/i, '');
  if (!sites(env)[site] || !sameSecret(secret, given)) return json({ ok: false }, 403);
  if (!SERVER_KINDS[body.kind]) return json({ ok: false }, 400);
  const event = { site, source: 'server', kind: body.kind, form: body.form === 'catalog' ? 'catalog' : 'inquiry', detail: redact(body.detail || body.message), inquiry: Number(body.inquiry) || 0, status: 0 };
  return json({ ok: true, result: await intake(env, event, { now, fetcher }) });
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method === 'OPTIONS') {
      const origin = request.headers.get('Origin') || '';
      const allowed = Object.values(sites(env)).some((s) => (s.origins || []).includes(origin));
      return new Response(null, { status: allowed ? 204 : 403, headers: allowed ? cors(origin) : {} });
    }
    if (request.method === 'POST' && url.pathname === '/report') return handleReport(request, env);
    if (request.method === 'POST' && url.pathname === '/server') return handleServer(request, env);
    if (request.method === 'GET' && url.pathname === '/health') {
      return json({ ok: true, selfcheck: (await readState(env, 'selfcheck:last')) || null });
    }
    return json({ ok: false }, 404);
  },
  // 每小时一次（wrangler.toml 的 crons）：补报攒下的次数；UTC 01 点（新加坡 09 点）那次再做自检。
  async scheduled(controller, env, ctx) {
    const now = Math.floor((controller.scheduledTime || Date.now()) / 1000);
    ctx.waitUntil((async () => {
      await flush(env, { now });
      if (new Date(now * 1000).getUTCHours() === 1) await runSelfChecks(env, now);
    })());
  },
};

export { runSelfChecks, KINDS, SERVER_KINDS, CHECK_KINDS, redact };
