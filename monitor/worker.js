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
 * 所有计数和状态放在一个 Durable Object（AlertDesk）里，收报、补报、自检结果都排成一队逐条处理：
 * 故障爆发时几十条同时到，也不会两条同时读到「还没报过」而重复开 Issue、互相覆盖计数（KV 做不到这点）。
 *
 * 绑定（wrangler.toml）：Durable Object DESK（类 AlertDesk）；变量 SITES（JSON，见 wrangler.example.toml）。
 * 密钥（wrangler secret put）：GITHUB_TOKEN（只授权站点仓库 Actions: write）、SITE_SECRETS（JSON：{"域名": "报警密钥"}）、
 *   DINGTALK_WEBHOOK、DINGTALK_SECRET（机器人开了「加签」才要）。
 * 可选变量：OUTBOUND_MS（往 GitHub、钉钉发请求的时限，默认 10000）、SELFCHECK_MS（每个站自检的时限，默认 60000）。
 */

const HOUR = 3600;
const LABEL = '表单报警';
// 访客浏览器能报的错误，和它们的中文说法。
const KINDS = {
  network: '请求没发出去（断网或超时）',
  aborted: '提交等太久被中断（主题超时）',
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
  check_cleanup: '自检：测试询盘存下了但删不掉，留在了后台',
};
// 单个访客失败不算故障，一小时内这么多位不同访客都失败才报。
const CROWD = { verification: 3, rate_limited: 3 };
// newIssuesPerSiteDay 分两份：访客浏览器上报（谁都能发，可能被人乱发）一份，站点服务器上报和自检（带密钥）一份，
// 乱发的占满了自己那份，也挤不掉真正的保存失败、发信失败、自检报警。
const LIMITS = { perIpHour: 10, newIssuesPerSiteDay: 5, dingEvery: 6 * HOUR, keepState: 7 * 24 * HOUR };
const TRUSTED_SOURCES = ['server', 'selfcheck'];
// 触发开 Issue 的流程后多久去核对它跑成没有；超过 CONFIRM_GIVE_UP 还查不到这次运行就当没跑成，重新触发。
const CONFIRM_AFTER = 5 * 60;
const CONFIRM_GIVE_UP = 30 * 60;
// 一直查不了运行记录（比如令牌少了 Actions 读权限）：上一次触发等这么久就当它成了，免得后面的报警一直卡住。
const CONFIRM_BLIND = 6 * HOUR;
const RUN_TITLE = (fp) => `表单报警 ${fp}`;
// 请求体上限（字节）：浏览器上报带 25 步足迹，16 KB 足够；服务器上报只有几个字段。
const BODY_LIMIT = { '/report': 16 * 1024, '/server': 4 * 1024 };
// 往 GitHub、钉钉发请求最多等这么久（毫秒），连读答复在内；超时算没发成，补报时再试，不卡住后面的报警。
const OUTBOUND_MS = 10000;
// GitHub 或钉钉这次没发成：10 分钟内不再试（每小时补报照常），免得故障期间每来一条报告都在队里白等一次超时。
const RETRY_GAP = 10 * 60;

/** 边读边数，超过上限立即停，不把大请求整个读进内存。超过返回 null。 */
export async function readLimited(request, limit) {
  const declared = Number(request.headers.get('Content-Length'));
  if (Number.isFinite(declared) && declared > limit) return null;
  if (!request.body) return '';
  const reader = request.body.getReader();
  const chunks = [];
  let size = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (done) break;
    size += value.byteLength;
    if (size > limit) {
      await reader.cancel().catch(() => {});
      return null;
    }
    chunks.push(value);
  }
  const all = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) { all.set(chunk, offset); offset += chunk.byteLength; }
  return new TextDecoder().decode(all);
}

/** 给一次外发加时限：到点就中断请求，并当作失败。 */
async function withDeadline(ms, work) {
  const controller = new AbortController();
  let timer;
  try {
    return await Promise.race([
      work(controller.signal),
      new Promise((_, reject) => { timer = setTimeout(() => { controller.abort(); reject(new Error(`超过 ${ms / 1000} 秒没回应`)); }, ms); }),
    ]);
  } finally {
    clearTimeout(timer);
  }
}
const outboundMs = (env) => Number(env.OUTBOUND_MS) || OUTBOUND_MS;

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

// 按访客给的键查配置表，只认表里自己写的项：不然 site 填 constructor、toString 之类，
// 会查到对象原型上的内置函数，被当成「配好的站」或「密钥」。
function own(map, key) {
  return map && typeof map === 'object' && Object.prototype.hasOwnProperty.call(map, key) ? map[key] : undefined;
}
// 站点表：只留值是对象的项，放进没有原型的对象里，后面怎么按站名查都碰不到内置属性。
function sites(env) {
  const out = Object.create(null);
  try {
    const parsed = typeof env.SITES === 'string' ? JSON.parse(env.SITES) : env.SITES;
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
      for (const [name, cfg] of Object.entries(parsed)) {
        if (cfg && typeof cfg === 'object' && !Array.isArray(cfg)) out[name] = cfg;
      }
    }
  } catch {
    // 配置坏了就当一个站都没有。
  }
  return out;
}
function siteSecret(env, site) {
  try {
    const all = typeof env.SITE_SECRETS === 'string' ? JSON.parse(env.SITE_SECRETS) : env.SITE_SECRETS;
    const secret = own(all, site);
    return typeof secret === 'string' && secret.length >= 24 ? secret : '';
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
  // 带密钥的上报（站点服务器、自检）和访客浏览器的分开记：谁都能发的浏览器上报改不了服务器报警的记录，也不会把它算进访客那份名额。
  const lane = TRUSTED_SOURCES.includes(event.source) ? 'trusted' : 'public';
  return (await sha([event.site, event.form, event.kind, event.status || 0, detail, lane].join('|'))).slice(0, 12);
}

function kindText(kind) {
  return own(KINDS, kind) || own(SERVER_KINDS, kind) || own(CHECK_KINDS, kind) || kind;
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

/** 计数加一并返回新值（在 AlertDesk 的队里逐条执行，不会丢计数）。 */
async function bump(env, key, ttl) {
  const current = Number(await env.ALERTS.get(key)) || 0;
  await env.ALERTS.put(key, String(current + 1), { expirationTtl: ttl });
  return current + 1;
}

async function dispatchIssue(env, siteCfg, payload, fetcher) {
  return withDeadline(outboundMs(env), async (signal) => (await fetcher(`https://api.github.com/repos/${siteCfg.repo}/actions/workflows/form-alert.yml/dispatches`, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${env.GITHUB_TOKEN}`,
      Accept: 'application/vnd.github+json',
      'X-GitHub-Api-Version': '2022-11-28',
      'User-Agent': 'achord-form-alert',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ ref: siteCfg.branch || 'main', inputs: { fp: payload.fp, payload: JSON.stringify(payload).slice(0, 60000) } }),
    signal,
  })).status === 204);
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
    info.count > 1 ? `- 自上次钉钉提醒以来：${info.count} 次` : '',
    `- 时间：${sgt(info.now)}`,
    '',
    info.gitFailed
      ? 'GitHub Issue 这次没开成（报警服务每小时自动重试），先按这条提醒处理。'
      : `详情和失败前的操作见 GitHub Issue（1–2 分钟内生成）：[打开](${issues})`,
  ].filter((line, i, all) => line !== '' || (all[i - 1] !== '' && i > 0));
  return withDeadline(outboundMs(env), async (signal) => {
    const response = await fetcher(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ msgtype: 'markdown', markdown: { title: `${siteCfg.label}询盘表单报警`, text: lines.join('\n') } }),
      signal,
    });
    try {
      return (await response.json()).errcode === 0;
    } catch {
      return false;
    }
  });
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

  // 按人数报的：先攒这一小时里的不同访客和次数。够人数那一次把攒下的次数和访客一并记进去，之后每条照常记一次。
  let count = 1;
  let crowdVisitors = visitor ? [visitor] : [];
  if (CROWD[event.kind]) {
    const crowdKey = `crowd:${event.site}:${fp}:${Math.floor(now / HOUR)}`;
    const crowd = (await readState(env, crowdKey)) || { visitors: [], count: 0, carried: false };
    crowd.count = (crowd.count || 0) + 1;
    if (visitor && !crowd.visitors.includes(visitor) && crowd.visitors.length < 50) crowd.visitors.push(visitor);
    const reached = crowd.visitors.length >= CROWD[event.kind];
    if (reached && !crowd.carried) {
      count = crowd.count;
      crowdVisitors = crowd.visitors;
      crowd.carried = true;
    }
    await writeState(env, crowdKey, crowd, 2 * HOUR);
    if (!reached) return 'crowd';
  }

  const key = `fp:${event.site}:${fp}`;
  const state = (await readState(env, key)) || { first: now, lastSent: 0, lastDing: 0, pending: 0, dingCount: 0, visitors: [], total: 0 };
  state.total += count;
  state.pending += count;
  state.dingCount = (state.dingCount || 0) + count;
  for (const v of crowdVisitors) if (!state.visitors.includes(v) && state.visitors.length < 50) state.visitors.push(v);
  state.sample = { ...event, steps: (event.steps || []).slice(-25) };

  // 上一批还在等核对、而这批已满一小时该报了：先核对上一批，核对完才发这批。
  if (state.awaiting && now - state.awaiting.at >= CONFIRM_AFTER && now - (state.lastSent || 0) >= HOUR) {
    settleRun(state, fp, await recentRuns(env, siteCfg, now, fetcher), now);
  }
  const due = dueChannels(state, now);
  if (!due.git && !due.ding) {
    await writeState(env, key, state);
    return 'held';
  }
  if (!state.lastSent && !(await reserve(env, event.site, state, now))) {
    await writeState(env, key, state);
    return 'capped';
  }
  return (await send(env, siteCfg, key, fp, state, now, fetcher, due)) && due.git ? 'sent' : 'held';
}

/**
 * GitHub 和钉钉各算各的：GitHub 有没报的次数、离上次触发满 1 小时就该发；钉钉有没提醒过的次数、离上次推满 6 小时就该推。
 * 一边出错（比如 GitHub 令牌过期）不耽误另一边，没发成的那边每小时补报时接着试。
 */
function dueChannels(state, now) {
  const rested = (failedAt) => !failedAt || now - failedAt >= RETRY_GAP;
  return {
    // 上一次触发还没核对完（awaiting）就不再触发：一次只有一批在路上，跑失败了退回来的正是那一批，不会漏也不会重。
    git: state.pending > 0 && !state.awaiting && (!state.lastSent || now - state.lastSent >= HOUR) && rested(state.gitFailedAt),
    ding: (state.dingCount || 0) > 0 && (!state.lastDing || now - state.lastDing >= LIMITS.dingEvery) && rested(state.dingFailedAt),
  };
}

/**
 * 给还没开过 Issue 的问题占当天的新开名额（每站每天 5 个）。同一个问题当天只占一次：
 * GitHub 暂时出错、反复重试时不会把名额耗光。名额满了返回 false。
 */
async function reserve(env, site, state, now) {
  const day = new Date(now * 1000).toISOString().slice(0, 10);
  if (state.reserved === day) return true;
  const lane = TRUSTED_SOURCES.includes(state.sample?.source) ? 'trusted' : 'public';
  const capKey = `cap:${site}:${lane}:${day}`;
  const used = Number(await env.ALERTS.get(capKey)) || 0;
  if (used >= LIMITS.newIssuesPerSiteDay) return false;
  await env.ALERTS.put(capKey, String(used + 1), { expirationTtl: 2 * 24 * HOUR });
  state.reserved = day;
  return true;
}

async function send(env, siteCfg, key, fp, state, now, fetcher, due) {
  const event = state.sample;
  // 先存下这条（没发出去时每小时补报会接着试），再往外发。
  await writeState(env, key, state);
  let ok = false;
  if (due.git) {
    const info = { now, count: state.pending, visitors: Math.max(1, state.visitors.length) };
    const text = compose(siteCfg, event, info);
    try {
      ok = await dispatchIssue(env, siteCfg, { fp, title: text.title, body: text.body, comment: text.comment, assignee: siteCfg.assignee || '' }, fetcher);
    } catch {
      ok = false;
    }
    if (ok) {
      // GitHub 收下了触发请求，不等于 Issue 开成了：记下这次，每小时补报时查流程跑成没有，没跑成就把次数退回来重发。
      state.awaiting = { at: now, count: info.count, visitors: state.visitors.slice(0, 50), prevLastSent: state.lastSent };
      state.lastSent = now;
      state.pending = 0;
      state.visitors = [];
      state.gitFailedAt = 0;
    } else {
      state.gitFailedAt = now;
    }
    await writeState(env, key, state);
  }
  if (due.ding) {
    const title = compose(siteCfg, event, { now, count: 1, visitors: 1 }).title;
    let pushed = false;
    try {
      pushed = await sendDingtalk(env, siteCfg, title, event, { now, count: state.dingCount || 1, gitFailed: (due.git && !ok) || Boolean(state.runFailedAt) }, fetcher);
    } catch { /* 钉钉不通：过一阵再试 */ }
    if (pushed) {
      state.lastDing = now;
      state.dingCount = 0;
      state.dingFailedAt = 0;
      state.runFailedAt = 0;
    } else {
      state.dingFailedAt = now;
    }
    await writeState(env, key, state);
  }
  return ok;
}

/** 每小时：攒着没报的次数补报一条评论（满一小时的才报），上次没推成的钉钉再推。 */
export async function flush(env, { now = Math.floor(Date.now() / 1000), fetcher = fetch } = {}) {
  const all = sites(env);
  const runs = {};
  let cursor;
  do {
    const page = await env.ALERTS.list({ prefix: 'fp:', cursor });
    for (const { name } of page.keys) {
      const state = await readState(env, name);
      if (!state) continue;
      const [, site, fp] = name.split(':');
      if (!all[site]) continue;
      if (state.awaiting && now - state.awaiting.at >= CONFIRM_AFTER) {
        if (!(site in runs)) runs[site] = await recentRuns(env, all[site], now, fetcher);
        if (settleRun(state, fp, runs[site], now)) await writeState(env, name, state);
      }
      const due = dueChannels(state, now);
      if (!due.git && !due.ding) continue;
      // 当天新开的已到上限：等第二天。
      if (!state.lastSent && !(await reserve(env, site, state, now))) continue;
      await send(env, all[site], name, fp, state, now, fetcher, due);
    }
    cursor = page.list_complete ? undefined : page.cursor;
  } while (cursor);
}

/** 站点仓库最近两天的报警流程运行（标题是「表单报警 <指纹>」）；查不到返回 null，这次先不核对。 */
// 一次最多翻这么多页（每页 100 条）；翻不完就当这次查不全，不下「没跑」的结论。
const RUN_PAGES = 10;
async function recentRuns(env, siteCfg, now, fetcher) {
  const since = new Date((now - 2 * 24 * HOUR) * 1000).toISOString().slice(0, 19) + 'Z';
  try {
    return await withDeadline(outboundMs(env), async (signal) => {
      const runs = [];
      for (let page = 1; page <= RUN_PAGES; page++) {
        const response = await fetcher(`https://api.github.com/repos/${siteCfg.repo}/actions/workflows/form-alert.yml/runs?event=workflow_dispatch&per_page=100&page=${page}&created=${encodeURIComponent('>=' + since)}`, {
          headers: { Authorization: `Bearer ${env.GITHUB_TOKEN}`, Accept: 'application/vnd.github+json', 'X-GitHub-Api-Version': '2022-11-28', 'User-Agent': 'achord-form-alert' },
          signal,
        });
        if (response.status !== 200) return null;
        const data = await response.json();
        if (!Array.isArray(data?.workflow_runs)) return null;
        runs.push(...data.workflow_runs);
        // 翻到最后一页（不满 100 条，或已数够总数）才算查全。
        if (data.workflow_runs.length < 100 || runs.length >= Number(data.total_count || 0)) return runs;
      }
      return null;
    });
  } catch {
    return null;
  }
}

/**
 * 核对上次触发的流程：跑成了就放下；跑失败了、或过了 30 分钟还查不到这次运行，就把次数退回待发、恢复上次的发送时间，
 * 这次补报马上重发，钉钉也会写明 Issue 没开成。状态有变化返回 true。
 */
function settleRun(state, fp, runs, now) {
  const awaiting = state.awaiting;
  if (!awaiting) return false;
  if (!runs) {
    if (now - awaiting.at < CONFIRM_BLIND) return false;
    delete state.awaiting; // 查了 6 小时都查不了：当它成了，放后面的报警走。
    return true;
  }
  const run = runs
    .filter((r) => r.display_title === RUN_TITLE(fp) && Date.parse(r.created_at) / 1000 >= awaiting.at - 120)
    .sort((a, b) => Date.parse(b.created_at) - Date.parse(a.created_at))[0];
  if (run && run.status !== 'completed') return false;
  if (run && run.conclusion === 'success') {
    delete state.awaiting;
    return true;
  }
  if (!run && now - awaiting.at < CONFIRM_GIVE_UP) return false;
  state.pending += awaiting.count;
  // 这一批涉及的访客也一并退回，重发时「涉及几位访客」才对。
  for (const v of awaiting.visitors || []) if (!state.visitors.includes(v) && state.visitors.length < 50) state.visitors.push(v);
  state.lastSent = awaiting.prevLastSent || 0;
  state.gitFailedAt = 0;
  state.runFailedAt = now;
  state.dingCount = (state.dingCount || 0) + 1;
  delete state.awaiting;
  return true;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** 第一次自检正常的时间（「很久没有询盘」在站点一条有效询盘都还没有时，从这时算起）；没记过就记下现在。 */
async function monitoringSince(env, site, now) {
  if (!env.ALERTS) return 0;
  const key = `since:${site}`;
  const saved = Number(await env.ALERTS.get(key)) || 0;
  if (saved) return saved;
  await env.ALERTS.put(key, String(now));
  return now;
}

/** 自检一个站：打开表单页、取新随机数、带报警密钥提交测试询盘、看站点报告的配置。返回发现的问题（没有就是空数组）。 */
export async function selfCheck(env, site, siteCfg, { now = Math.floor(Date.now() / 1000), fetcher = fetch, wait = 20000 } = {}) {
  const check = siteCfg.check || {};
  const secret = siteSecret(env, site);
  const ua = 'Mozilla/5.0 (compatible; BOB-LIFT form self-check; +https://github.com/Achordchan)';
  const problems = [];
  // 报警服务这边没配好这个站的密钥：不提交（否则站点会当成真询盘收下、发邮件），直接报配置问题。
  if (!secret) {
    return [{ site, form: 'inquiry', source: 'selfcheck', kind: 'check_secret', status: 0, page: check.page ? new URL(check.page).pathname : '', detail: '报警服务里没有配好这个站的报警密钥（SITE_SECRETS 缺失、格式不对或不足 24 位），没有提交测试询盘' }];
  }
  // 读答复内容：连接在中途断掉时 text() 会抛错，当成没收完整处理，不让错误跑出自检。
  const readText = async (response) => {
    try {
      return { whole: true, text: await response.text() };
    } catch (error) {
      return { whole: false, text: `答复没收完整：${error?.message || error}` };
    }
  };
  // signal：整次自检的时限到了就中断请求；超时后迟到的答复也不再往下走，免得多提交一条测试询盘。
  const attempt = async (signal) => {
    const stopIfLate = () => { if (signal?.aborted) throw new Error('自检超时'); };
    const page = await fetcher(check.page, { headers: { 'User-Agent': ua, Accept: 'text/html' }, redirect: 'follow', signal }).catch((e) => ({ status: 0, text: async () => String(e) }));
    const pageBody = await readText(page);
    const html = pageBody.text;
    if (!pageBody.whole) return [{ kind: 'check_page', status: page.status, detail: html, page: new URL(check.page).pathname }];
    if (page.status !== 200 || !html.includes(check.marker)) {
      return [{ kind: 'check_page', status: page.status, detail: page.status === 200 ? `页面上找不到表单（${check.marker}）` : html.match(/<title[^>]*>([\s\S]*?)<\/title>/i)?.[1] || '', page: new URL(check.page).pathname }];
    }
    const nonceResponse = await fetcher(`${check.nonce_url}${check.nonce_url.includes('?') ? '&' : '?'}t=${now}`, { headers: { 'User-Agent': ua, Accept: 'application/json' }, signal }).catch(() => ({ status: 0, json: async () => ({}) }));
    const nonce = String((await nonceResponse.json().catch(() => ({})))?.data?.nonce || '');
    if (!nonce) return [{ kind: 'check_nonce', status: nonceResponse.status, detail: check.nonce_url }];
    stopIfLate();
    const form = new URLSearchParams({ action: check.action, [check.nonce_field]: nonce, [check.response_field]: 'json', source_title: '每日自检', ...(check.fields || {}) });
    const submit = await fetcher(check.submit, {
      method: 'POST',
      headers: { 'User-Agent': ua, Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded', 'X-Achord-Monitor': secret },
      body: form.toString(),
      // 带着报警密钥：不跟跳转，免得提交地址被跳到别的网站时把密钥一起带过去。
      redirect: 'manual',
      signal,
    }).catch((e) => ({ status: 0, text: async () => String(e) }));
    if (submit.status >= 300 && submit.status < 400) {
      return [{ kind: 'check_submit', status: submit.status, detail: `提交地址跳转到了 ${plainHost(submit.headers?.get?.('Location'))}（没有带密钥跟过去），请核对提交地址` }];
    }
    const submitBody = await readText(submit);
    if (!submitBody.whole) return [{ kind: 'check_submit', status: submit.status, detail: submitBody.text }];
    const raw = submitBody.text;
    let answer = null;
    try { answer = JSON.parse(raw); } catch { /* 下面报 */ }
    // 存下了却删不掉：后台多了一条测试询盘。不重试（重试成功会把这件事盖掉），直接报，提醒手动删。
    if (answer?.data?.code === 'cleanupFailed' || (answer && answer.success !== true && answer.data?.saved === true)) {
      return [{ kind: 'check_cleanup', status: submit.status, detail: answer.data?.message || '测试询盘已保存但没删掉，请到后台询盘列表手动删除' }];
    }
    if (answer?.data?.code === 'monitorRejected') {
      return [{ kind: 'check_secret', status: submit.status, detail: '站点没认出报警密钥，拒收了测试询盘（没有存、没有发邮件），请核对两边的报警密钥' }];
    }
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
    // 时间带小数（微秒）分先后；万一一样，按失败算，宁可多报一次。
    if (health.mail_fail_at && health.mail_fail_at >= (health.mail_ok_at || 0)) found.push({ kind: 'check_mail', detail: health.mail_fail_reason || '最近一次通知邮件发送失败', health });
    const quietHours = Number(siteCfg.quiet_hours) || 0;
    // 还没有过一条有效询盘的站（新站）：从第一次自检正常那天算起。
    const since = health.last_inquiry_at || (await monitoringSince(env, site, now));
    if (quietHours && since && now - since > quietHours * HOUR) {
      const what = health.last_inquiry_at ? '没有收到询盘' : '开始监控以来一条有效询盘都没收到';
      found.push({ kind: 'check_quiet', detail: `已经 ${Math.floor((now - since) / HOUR)} 小时${what}（提醒阈值 ${quietHours} 小时），表单自检正常，请看看流量或广告`, health });
    }
    return found;
  };
  // 意外出错也当成提交失败报出来，不让一个站的错误打断别的站。
  // 整个自检一次最多等 60 秒（页面、随机数、提交三步），站点卡住也不拖住后面的站。
  const safeAttempt = () => withDeadline(Number(env.SELFCHECK_MS) || 60000, (signal) => attempt(signal)).catch((error) => [{ kind: 'check_submit', status: 0, detail: `自检出错：${error?.message || error}` }]);
  let found = await safeAttempt();
  // 一次失败可能是网络抖动：等一会儿再试一次，还不行才报。
  if (found.some((p) => ['check_page', 'check_nonce', 'check_submit'].includes(p.kind))) {
    if (wait) await sleep(wait);
    found = await safeAttempt();
  }
  for (const problem of found) {
    problems.push({ site, form: 'inquiry', source: 'selfcheck', page: problem.page || (check.page ? new URL(check.page).pathname : ''), status: problem.status || 0, ...problem });
  }
  return problems;
}

/** 每日自检所有站。访问站点不占队（要等十几秒），发现的问题和结果交给 serial 排队处理。 */
async function runSelfChecks(env, ctxNow, fetcher = fetch, serial = (fn) => fn()) {
  const results = {};
  for (const [site, siteCfg] of Object.entries(sites(env))) {
    if (!siteCfg.check) continue;
    const problems = await selfCheck(env, site, siteCfg, { now: ctxNow, fetcher });
    results[site] = { at: ctxNow, ok: problems.length === 0, problems: problems.map((p) => `${kindText(p.kind)}：${redact(p.detail, 120)}`) };
    for (const problem of problems) await serial(() => intake(env, problem, { now: ctxNow, fetcher }));
  }
  await serial(() => env.ALERTS.put('selfcheck:last', JSON.stringify(results)));
  return results;
}

/** 访客报来的页面只收成网址路径：去掉查询参数，只留路径里会出现的字符（中文等已被浏览器转成 %XX），Issue 里不会变成链接文字或 @ 提醒。 */
function safePath(value) {
  const path = String(value || '').split(/[?#]/)[0];
  return path.startsWith('/') ? path.replace(/[^\w\-./~%]/g, '').slice(0, 200) : '';
}

/** 跳转目标只报主机和路径，不带查询参数。 */
function plainHost(location) {
  try {
    const url = new URL(String(location || ''), 'https://unknown.invalid');
    return `${url.host}${url.pathname}`.slice(0, 120);
  } catch {
    return '别处';
  }
}

function cors(origin) {
  return { 'Access-Control-Allow-Origin': origin, 'Access-Control-Allow-Methods': 'POST, OPTIONS', 'Access-Control-Allow-Headers': 'Content-Type', 'Access-Control-Max-Age': '86400', Vary: 'Origin' };
}

/** 访客浏览器发来的：只收认识的站、对得上的来源域名、规定的错误类型，限每个 IP 每小时 10 条。 */
export async function handleReport(request, env, { now = Math.floor(Date.now() / 1000), fetcher = fetch } = {}) {
  const origin = request.headers.get('Origin') || '';
  const text = await readLimited(request, BODY_LIMIT['/report']);
  if (text === null) return json({ ok: false }, 413);
  let body;
  try { body = JSON.parse(text); } catch { return json({ ok: false }, 400); }
  const site = String(body.site || '');
  const siteCfg = sites(env)[site];
  if (!siteCfg || !(siteCfg.origins || []).includes(origin)) return json({ ok: false }, 403);
  const headers = cors(origin);
  if (!own(KINDS, body.kind)) return json({ ok: false }, 400, headers);
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
    page: safePath(body.page),
    ua: String(body.ua || '').slice(0, 250),
    lang: redact(body.lang, 20),
    width: Number(body.width) || 0,
    elapsed: Number(body.elapsed) || 0,
    steps: (Array.isArray(body.steps) ? body.steps : []).slice(-25).map((s) => redact(s, 160)),
    // 转进 AlertDesk 后 request.cf 没了，入口 Worker 把国家放在这个请求头里（访客自己带的会被覆盖）。
    country: request.cf?.country || request.headers.get('X-Desk-Country') || '',
    visitor,
  };
  return json({ ok: true, result: await intake(env, event, { now, fetcher }) }, 200, headers);
}

/** 站点服务器发来的：要带该站的报警密钥。 */
export async function handleServer(request, env, { now = Math.floor(Date.now() / 1000), fetcher = fetch } = {}) {
  let body;
  const text = await readLimited(request, BODY_LIMIT['/server']);
  if (text === null) return json({ ok: false }, 413);
  try { body = JSON.parse(text); } catch { return json({ ok: false }, 400); }
  const site = String(body.site || '');
  const secret = siteSecret(env, site);
  const given = (request.headers.get('Authorization') || '').replace(/^Bearer\s+/i, '');
  if (!sites(env)[site] || !sameSecret(secret, given)) return json({ ok: false }, 403);
  if (!own(SERVER_KINDS, body.kind)) return json({ ok: false }, 400);
  const event = { site, source: 'server', kind: body.kind, form: body.form === 'catalog' ? 'catalog' : 'inquiry', detail: redact(body.detail || body.message), inquiry: Number(body.inquiry) || 0, status: 0 };
  return json({ ok: true, result: await intake(env, event, { now, fetcher }) });
}

/** AlertDesk 的存储包成 KV 的样子（get/put/list，带过期），业务代码两边通用。 */
export function deskStore(storage) {
  const nowSec = () => Math.floor(Date.now() / 1000);
  return {
    async get(key, type) {
      const row = await storage.get(key);
      if (!row || (row.exp && row.exp <= nowSec())) return null;
      return type === 'json' ? JSON.parse(row.v) : row.v;
    },
    async put(key, value, { expirationTtl } = {}) {
      await storage.put(key, { v: String(value), exp: expirationTtl ? nowSec() + expirationTtl : 0 });
    },
    async list({ prefix }) {
      const rows = await storage.list({ prefix });
      return { keys: [...rows].filter(([, row]) => !row?.exp || row.exp > nowSec()).map(([name]) => ({ name })), list_complete: true };
    },
    /** 删掉过期的（每小时补报时顺手做）。 */
    async sweep() {
      const rows = await storage.list();
      const old = [...rows].filter(([, row]) => row?.exp && row.exp <= nowSec()).map(([name]) => name);
      for (let i = 0; i < old.length; i += 128) await storage.delete(old.slice(i, i + 128));
      return old.length;
    },
  };
}

/** 唯一的一个 Durable Object：所有改状态的操作排成一队，一条做完再做下一条。 */
export class AlertDesk {
  constructor(ctx, env, { fetcher = fetch } = {}) {
    this.store = deskStore(ctx.storage);
    this.env = { ...env, ALERTS: this.store };
    this.fetcher = fetcher;
    this.queue = Promise.resolve();
  }

  serial(fn) {
    const run = this.queue.then(fn, fn);
    this.queue = run.catch(() => {});
    return run;
  }

  async fetch(request) {
    const url = new URL(request.url);
    const opts = { fetcher: this.fetcher };
    if (request.method === 'POST' && url.pathname === '/report') return this.serial(() => handleReport(request, this.env, opts));
    if (request.method === 'POST' && url.pathname === '/server') return this.serial(() => handleServer(request, this.env, opts));
    if (request.method === 'GET' && url.pathname === '/health') return json({ ok: true, selfcheck: (await readState(this.env, 'selfcheck:last')) || null });
    if (request.method === 'POST' && url.pathname === '/cron') {
      const now = Number(url.searchParams.get('now')) || Math.floor(Date.now() / 1000);
      await this.serial(async () => {
        await this.store.sweep();
        await flush(this.env, { now, fetcher: this.fetcher });
      });
      if (url.searchParams.get('selfcheck') === '1') await runSelfChecks(this.env, now, this.fetcher, (fn) => this.serial(fn));
      return json({ ok: true });
    }
    return json({ ok: false }, 404);
  }
}

const desk = (env) => env.DESK.get(env.DESK.idFromName('main'));

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (request.method === 'OPTIONS') {
      const origin = request.headers.get('Origin') || '';
      const allowed = Object.values(sites(env)).some((s) => (s.origins || []).includes(origin));
      return new Response(null, { status: allowed ? 204 : 403, headers: allowed ? cors(origin) : {} });
    }
    const open = (request.method === 'POST' && (url.pathname === '/report' || url.pathname === '/server')) || (request.method === 'GET' && url.pathname === '/health');
    if (!open) return json({ ok: false }, 404);
    const headers = new Headers(request.headers);
    headers.set('X-Desk-Country', String(request.cf?.country || ''));
    headers.delete('Content-Length');
    if (request.method !== 'POST') return desk(env).fetch(new Request(request, { headers }));
    // 先在入口限量读完请求体，超大的直接拒，不转给 AlertDesk（它一个服务两个站，不能被大请求拖垮）。
    const body = await readLimited(request, BODY_LIMIT[url.pathname]);
    if (body === null) return json({ ok: false }, 413);
    return desk(env).fetch(new Request(request.url, { method: 'POST', headers, body }));
  },
  // 每小时一次（wrangler.toml 的 crons）：补报攒下的次数；UTC 01 点（新加坡 09 点）那次再做自检。
  async scheduled(controller, env, ctx) {
    const now = Math.floor((controller.scheduledTime || Date.now()) / 1000);
    const selfcheck = new Date(now * 1000).getUTCHours() === 1 ? '1' : '0';
    ctx.waitUntil(desk(env).fetch(`https://desk/cron?now=${now}&selfcheck=${selfcheck}`, { method: 'POST' }));
  },
};

export { runSelfChecks, KINDS, SERVER_KINDS, CHECK_KINDS, redact };
