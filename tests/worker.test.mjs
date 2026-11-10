// node tests/worker.test.mjs：用假的 KV、假的 Durable Object 存储、假的 GitHub 和钉钉跑 monitor/worker.js。
import worker, { intake, flush, selfCheck, handleReport, handleServer, compose, describeUa, AlertDesk } from '../monitor/worker.js';

let failures = 0;
const check = (ok, what) => { console.log(`${ok ? 'ok  ' : 'FAIL'} ${what}`); if (!ok) failures++; };

class KV {
  constructor() { this.map = new Map(); }
  async get(key, type) { const v = this.map.get(key); if (v === undefined) return null; return type === 'json' ? JSON.parse(v) : v; }
  async put(key, value) { this.map.set(key, String(value)); }
  async list({ prefix }) { return { keys: [...this.map.keys()].filter((k) => k.startsWith(prefix)).map((name) => ({ name })), list_complete: true }; }
}
const SECRET = 'x'.repeat(32);
const SITES = {
  'bobliftcrane.com': {
    label: '起重机站', repo: 'Achordchan/bobliftcrane', base: 'https://bobliftcrane.com', origins: ['https://bobliftcrane.com'], quiet_hours: 72,
    check: { page: 'https://bobliftcrane.com/contact.html', marker: 'boblift_inquiry_submit=1', nonce_url: 'https://bobliftcrane.com/?boblift_fresh_nonce=inquiry', nonce_field: 'boblift_inquiry_nonce', action: 'boblift_submit_inquiry', response_field: 'boblift_inquiry_response', submit: 'https://bobliftcrane.com/?boblift_inquiry_submit=1', fields: { name: 'Form self-check', email: 'form-selfcheck@bobliftcrane.com', message: 'check' } },
  },
};
const makeEnv = () => ({ ALERTS: new KV(), SITES: JSON.stringify(SITES), SITE_SECRETS: JSON.stringify({ 'bobliftcrane.com': SECRET }), GITHUB_TOKEN: 'gh', DINGTALK_WEBHOOK: 'https://oapi.dingtalk.com/robot/send?access_token=t', DINGTALK_SECRET: 'SEC' });
function fakeNet(handlers = {}) {
  const calls = [];
  const fetcher = async (url, init = {}) => {
    calls.push({ url: String(url), init });
    for (const [part, handler] of Object.entries(handlers)) if (String(url).includes(part)) return handler(url, init);
    if (String(url).includes('api.github.com')) return new Response(null, { status: 204 });
    if (String(url).includes('dingtalk')) return new Response(JSON.stringify({ errcode: 0 }), { status: 200 });
    return new Response('not found', { status: 404 });
  };
  return { calls, fetcher };
}
const ghCalls = (calls) => calls.filter((c) => c.url.includes('/dispatches'));
const dingCalls = (calls) => calls.filter((c) => c.url.includes('dingtalk'));
const T0 = 1794300000;
const event = { site: 'bobliftcrane.com', source: 'browser', kind: 'server_error', form: 'inquiry', status: 502, detail: 'Bad Gateway (ref 1001)', page: '/products/sq5/', ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Version/17.4 Mobile/15E148 Safari/604.1', steps: ['0s 打开 /products/sq5/', '3.2s 点击 button「Get a quote」'], visitor: 'v1' };

// 第一次：触发开 Issue 并推钉钉（带加签）。
let env = makeEnv();
let net = fakeNet();
check(await intake(env, event, { now: T0, fetcher: net.fetcher }) === 'sent', '第一次出现：触发开 Issue');
const gh = ghCalls(net.calls)[0];
const payload = JSON.parse(JSON.parse(gh.init.body).inputs.payload);
check(JSON.parse(gh.init.body).inputs.fp === payload.fp, '指纹单独作为一个输入传给流程（按指纹排队）');
check(gh.url === 'https://api.github.com/repos/Achordchan/bobliftcrane/actions/workflows/form-alert.yml/dispatches' && gh.init.headers.Authorization === 'Bearer gh', '调用起重机站仓库的 form-alert 流程');
check(payload.title === '[起重机站] 询盘表单服务器出错：HTTP 502' && payload.body.includes('Safari 17 / iOS 17.4') && payload.body.includes('https://bobliftcrane.com/products/sq5/') && payload.body.includes('点击 button「Get a quote」') && /^[0-9a-f]{12}$/.test(payload.fp), 'Issue 标题、浏览器、页面、足迹齐全');
const ding = dingCalls(net.calls)[0];
check(ding && ding.url.includes('timestamp=') && ding.url.includes('sign=') && JSON.parse(ding.init.body).markdown.text.includes('起重机站询盘表单报警'), '推钉钉，带加签');

// 一小时内再出现：只计数，不再触发。
net = fakeNet();
check(await intake(env, { ...event, visitor: 'v2' }, { now: T0 + 600, fetcher: net.fetcher }) === 'held', '一小时内再出现：只计数');
check(await intake(env, { ...event, visitor: 'v3', detail: 'Bad Gateway (ref 2002)' }, { now: T0 + 700, fetcher: net.fetcher }) === 'held', '错误信息只差数字也算同一个');
check(net.calls.length === 0, '期间不调 GitHub、不推钉钉');

// 每小时补报：先核对上一次触发跑成了，再补一条评论带次数，钉钉 6 小时内不再推。
const okRun = (fp, at) => () => new Response(JSON.stringify({ workflow_runs: [{ display_title: `表单报警 ${fp}`, status: 'completed', conclusion: 'success', created_at: new Date(at * 1000).toISOString() }] }), { status: 200 });
net = fakeNet({ '/runs?': okRun(payload.fp, T0 + 5) });
await flush(env, { now: T0 + 3700, fetcher: net.fetcher });
const flushed = JSON.parse(JSON.parse(ghCalls(net.calls)[0]?.init.body || '{"inputs":{"payload":"{}"}}').inputs.payload);
check(ghCalls(net.calls).length === 1 && flushed.comment.includes('过去 1 小时 2 次，涉及 2 位访客') && dingCalls(net.calls).length === 0, '满一小时补报一条评论（2 次 2 位访客），钉钉不重复推');
net = fakeNet();
await flush(env, { now: T0 + 3800, fetcher: net.fetcher });
check(net.calls.length === 0, '没有新次数就不补报');

// 人机验证没过：一两个访客不报，一小时内第三位才报。
env = makeEnv();
net = fakeNet();
const verify = { ...event, kind: 'verification', status: 400, detail: 'Please complete' };
check(await intake(env, { ...verify, visitor: 'a' }, { now: T0, fetcher: net.fetcher }) === 'crowd', '一位访客验证没过：不报');
check(await intake(env, { ...verify, visitor: 'a' }, { now: T0 + 1, fetcher: net.fetcher }) === 'crowd', '同一位再试：还是不报');
check(await intake(env, { ...verify, visitor: 'b' }, { now: T0 + 2, fetcher: net.fetcher }) === 'crowd', '第二位：不报');
check(await intake(env, { ...verify, visitor: 'c' }, { now: T0 + 3, fetcher: net.fetcher }) === 'sent', '一小时内第三位不同访客：报');
{
  const sent = ghCalls(net.calls).map((c) => JSON.parse(JSON.parse(c.init.body).inputs.payload));
  const fpKey = [...env.ALERTS.map.keys()].find((k) => k.startsWith('fp:'));
  const st = await env.ALERTS.get(fpKey, 'json');
  check(sent.length === 1 && sent[0].body.includes('过去 1 小时 4 次，涉及 3 位访客') && st.total === 4 && st.awaiting.count === 4, '够人数那次把之前攒的次数和访客一并算上（4 次、3 位）');
}
check(await intake(env, { ...verify, visitor: 'd' }, { now: T0 + 4, fetcher: net.fetcher }) === 'held', '够人数之后再来：照常合并');
{
  const fpKey = [...env.ALERTS.map.keys()].find((k) => k.startsWith('fp:'));
  const st = await env.ALERTS.get(fpKey, 'json');
  check(st.total === 5 && st.pending === 1 && st.visitors.join() === 'd', '之后每条只记一次，不重复算攒下的');
}

// 每站每天最多新开 5 种。
env = makeEnv();
net = fakeNet();
const results = [];
for (let i = 0; i < 7; i++) results.push(await intake(env, { ...event, kind: 'network', detail: `kind ${'abcdefg'[i]}` }, { now: T0, fetcher: net.fetcher }));
check(results.filter((r) => r === 'sent').length === 5 && results.slice(5).every((r) => r === 'capped'), '每站每天最多新开 5 种，其余攒到第二天');

// GitHub 调不通：不算已报，下次照样触发。
env = makeEnv();
net = fakeNet({ 'api.github.com': () => new Response('bad', { status: 401 }) });
check(await intake(env, event, { now: T0, fetcher: net.fetcher }) === 'held', 'GitHub 调不通：记下待补报');
net = fakeNet();
check(await intake(env, { ...event, visitor: 'v9' }, { now: T0 + 60, fetcher: net.fetcher }) === 'held' && ghCalls(net.calls).length === 0, '刚失败过：10 分钟内来的新报告不再白等一次，只计数');
await flush(env, { now: T0 + 700, fetcher: net.fetcher });
check(ghCalls(net.calls).length === 1, '下一次补报时重新触发');

// 浏览器上报：来源域名、类型、限流。
env = makeEnv();
net = fakeNet();
const req = (body, origin = 'https://bobliftcrane.com', ip = '203.0.113.9') => new Request('https://form-alert.test/report', { method: 'POST', headers: { Origin: origin, 'CF-Connecting-IP': ip, 'Content-Type': 'text/plain' }, body: JSON.stringify(body) });
const browserBody = { site: 'bobliftcrane.com', kind: 'network', form: 'inquiry', detail: 'Failed to fetch for ana@client.test', page: '/p/?gclid=abc', ua: event.ua, steps: ['1s 填写 email'] };
let res = await handleReport(req(browserBody, 'https://evil.test'), env, { now: T0, fetcher: net.fetcher });
check(res.status === 403, '别的域名发来的不收');
res = await handleReport(req({ ...browserBody, kind: 'made_up' }), env, { now: T0, fetcher: net.fetcher });
check(res.status === 400, '不认识的错误类型不收');
res = await handleReport(req({ ...browserBody, kind: 'aborted' }, 'https://bobliftcrane.com', '203.0.113.77'), env, { now: T0, fetcher: net.fetcher });
check(res.status === 200, '「提交被中断」这种类型收下');
res = await handleReport(req(browserBody), env, { now: T0, fetcher: net.fetcher });
const sentPayload = JSON.parse(JSON.parse(ghCalls(net.calls)[0].init.body).inputs.payload);
check(res.status === 200 && res.headers.get('Access-Control-Allow-Origin') === 'https://bobliftcrane.com', '本站发来的收下，带跨域头');
check(!JSON.stringify(sentPayload).includes('ana@client.test') && !JSON.stringify(sentPayload).includes('gclid') && !JSON.stringify(sentPayload).includes('203.0.113.9'), '报出去的内容没有邮箱、广告参数、IP');
for (let i = 0; i < 10; i++) res = await handleReport(req({ ...browserBody, kind: 'stuck' }), env, { now: T0, fetcher: net.fetcher });
check(res.status === 429, '同一 IP 每小时超过 10 条就拒收');
{
  // 访客报来的页面只收成路径：链接写法、@提醒、尖括号都去掉，Issue 里不会出现可点的冒充链接或通知。
  const e2 = makeEnv();
  const n2 = fakeNet();
  await handleReport(req({ ...browserBody, kind: 'server_error', page: '/p/[click](https://attacker.example)<b>@octocat' }, 'https://bobliftcrane.com', '198.51.100.4'), e2, { now: T0, fetcher: n2.fetcher });
  const issue = JSON.parse(JSON.parse(ghCalls(n2.calls)[0].init.body).inputs.payload);
  check(!issue.body.includes('](') && !issue.body.includes('@octocat') && !issue.body.includes('<b>') && issue.body.includes('https://bobliftcrane.com/p/clickhttps//attacker.examplebocto'), '访客报来的页面只留路径字符，不带链接写法和 @ 提醒');
  await handleReport(req({ ...browserBody, kind: 'server_error', detail: 'other', page: 'https://attacker.example/x' }, 'https://bobliftcrane.com', '198.51.100.5'), e2, { now: T0, fetcher: n2.fetcher });
  const issue2 = JSON.parse(JSON.parse(ghCalls(n2.calls)[1].init.body).inputs.payload);
  check(!issue2.body.includes('attacker'), '不是以 / 开头的页面整个不要');
}

// 服务器上报：要密钥。
const serverReq = (auth, body) => new Request('https://form-alert.test/server', { method: 'POST', headers: { Authorization: auth }, body: JSON.stringify(body) });
res = await handleServer(serverReq('Bearer wrong', { site: 'bobliftcrane.com', kind: 'mail_failed', message: 'x' }), env, { now: T0, fetcher: net.fetcher });
check(res.status === 403, '服务器上报密钥不对：拒收');
{
  // 站名、类型填对象原型上的名字（constructor、toString 等）不能冒充配好的站或密钥。
  const native = String(Object);
  const n3 = fakeNet();
  for (const site of ['constructor', '__proto__', 'toString', 'hasOwnProperty']) {
    const r = await handleServer(serverReq(`Bearer ${String(({})[site])}`, { site, kind: 'mail_failed', message: 'x' }), env, { now: T0, fetcher: n3.fetcher });
    check(r.status === 403, `服务器上报站名填 ${site}：拒收`);
  }
  let r = await handleServer(serverReq(`Bearer ${native}`, { site: 'constructor', kind: 'mail_failed' }), env, { now: T0, fetcher: n3.fetcher });
  check(r.status === 403, '拿 Object 构造函数的字符串当密钥：拒收');
  r = await handleServer(serverReq(`Bearer ${SECRET}`, { site: 'bobliftcrane.com', kind: 'constructor' }), env, { now: T0, fetcher: n3.fetcher });
  check(r.status === 400, '服务器上报类型填 constructor：不收');
  r = await handleReport(req({ ...browserBody, site: 'constructor' }), env, { now: T0, fetcher: n3.fetcher });
  check(r.status === 403, '浏览器上报站名填 constructor：拒收');
  r = await handleReport(req({ ...browserBody, kind: 'toString' }, 'https://bobliftcrane.com', '198.51.100.9'), env, { now: T0, fetcher: n3.fetcher });
  check(r.status === 400, '浏览器上报类型填 toString：不收');
  check(n3.calls.length === 0, '这些冒充请求一条都没往外发');
}
net = fakeNet();
res = await handleServer(serverReq(`Bearer ${SECRET}`, { site: 'bobliftcrane.com', kind: 'mail_failed', message: 'SMTP connect() failed', inquiry: 15001 }), env, { now: T0, fetcher: net.fetcher });
const mailPayload = JSON.parse(JSON.parse(ghCalls(net.calls)[0].init.body).inputs.payload);
check(res.status === 200 && mailPayload.title === '[起重机站] 询盘表单询盘已存，但通知邮件发不出去' && mailPayload.body.includes('15001'), '发信失败：开 Issue，带询盘编号');

// 自检：正常时没有问题；用到报警密钥和新随机数。
const siteNet = (overrides = {}) => fakeNet({
  'contact.html': () => new Response('<form action="/?boblift_inquiry_submit=1">', { status: 200 }),
  'boblift_fresh_nonce': () => new Response(JSON.stringify({ success: true, data: { nonce: 'n1' } })),
  'boblift_inquiry_submit=1': (url, init) => new Response(JSON.stringify({ success: true, data: { monitor: init.headers['X-Achord-Monitor'] === SECRET, health: { recipient_ok: true, turnstile_on: true, turnstile_ready: true, dingtalk: true, mail_ok_at: T0 - 100, mail_fail_at: 0, last_inquiry_at: T0 - 3600 } } })),
  ...overrides,
});
env = makeEnv();
net = siteNet();
let problems = await selfCheck(env, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
const post = net.calls.find((c) => c.init.method === 'POST');
check(problems.length === 0 && post.init.body.includes('boblift_inquiry_nonce=n1') && post.init.body.includes('boblift_inquiry_response=json'), '自检正常：取新随机数、带密钥提交，没有问题');

net = siteNet({ 'contact.html': () => new Response('<title>Just a moment...</title>', { status: 403 }) });
problems = await selfCheck(env, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems.length === 1 && problems[0].kind === 'check_page' && problems[0].status === 403 && net.calls.filter((c) => c.url.includes('contact.html')).length === 2, '页面打不开：重试一次后报 check_page');

net = siteNet({ 'boblift_inquiry_submit=1': () => new Response(JSON.stringify({ success: true, data: { id: 5 } })) });
problems = await selfCheck(env, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems[0]?.kind === 'check_secret', '站点没认出密钥：报 check_secret');

net = siteNet({ 'boblift_inquiry_submit=1': () => new Response(JSON.stringify({ success: true, data: { monitor: true, health: { recipient_ok: false, dingtalk: true, mail_ok_at: 10, mail_fail_at: 20, mail_fail_reason: 'SMTP down', last_inquiry_at: T0 - 100 * 3600 } } })) });
problems = await selfCheck(env, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems.map((p) => p.kind).join(',') === 'check_config,check_mail,check_quiet', '自检发现收件人无效、最近发信失败、72 小时没询盘');
net = siteNet({ 'boblift_inquiry_submit=1': () => new Response(JSON.stringify({ success: true, data: { monitor: true, health: { recipient_ok: true, dingtalk: true, mail_ok_at: 1794299000.25, mail_fail_at: 1794299000.75, mail_fail_reason: 'SMTP down', last_inquiry_at: T0 - 100 } } })) });
problems = await selfCheck(env, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems.map((p) => p.kind).join(',') === 'check_mail', '同一秒里先发成、后发失败：按微秒分得出，照样报发信失败');

// GitHub 一时出错反复重试：同一个问题当天只占一个新开名额。
env = makeEnv();
net = fakeNet({ 'api.github.com': () => new Response('bad', { status: 502 }) });
await intake(env, event, { now: T0, fetcher: net.fetcher });
for (let i = 1; i <= 6; i++) await flush(env, { now: T0 + i * 3700, fetcher: net.fetcher });
const capDay = new Date((T0 + 6 * 3700) * 1000).toISOString().slice(0, 10) === new Date(T0 * 1000).toISOString().slice(0, 10);
check(!capDay || (await env.ALERTS.get(`cap:bobliftcrane.com:public:${new Date(T0 * 1000).toISOString().slice(0, 10)}`)) === '1', '重试不重复占名额');
check(await intake(env, { ...event, kind: 'network', detail: 'other' }, { now: T0 + 10, fetcher: fakeNet().fetcher }) === 'sent', '别的问题照样能新开');

// GitHub 请求直接抛错（断网）：先存下，补报时再发；钉钉抛错不影响 Issue 记成已发。
env = makeEnv();
net = fakeNet({ 'api.github.com': () => { throw new TypeError('network down'); } });
check(await intake(env, event, { now: T0, fetcher: net.fetcher }) === 'held', 'GitHub 断网：不丢，记下待补报');
net = fakeNet({ dingtalk: () => { throw new TypeError('ding down'); } });
await flush(env, { now: T0 + 700, fetcher: net.fetcher });
const afterDing = [...env.ALERTS.map.entries()].find(([k]) => k.startsWith('fp:'));
check(ghCalls(net.calls).length === 1 && JSON.parse(afterDing[1]).lastSent === T0 + 700, '钉钉断网：Issue 照样记成已发，不会重复开');

env = makeEnv();
await intake(env, event, { now: T0, fetcher: fakeNet({ dingtalk: () => { throw new TypeError('ding down'); } }).fetcher });
net = fakeNet();
await flush(env, { now: T0 + 700, fetcher: net.fetcher });
check(dingCalls(net.calls).length === 1 && ghCalls(net.calls).length === 0, '钉钉没推成的：下次补报只补推钉钉，不重复开 Issue');

// GitHub 令牌失效：钉钉照样推，并说明 Issue 没开成。
env = makeEnv();
net = fakeNet({ 'api.github.com': () => new Response('bad credentials', { status: 401 }) });
await intake(env, event, { now: T0, fetcher: net.fetcher });
const dingText = JSON.parse(dingCalls(net.calls)[0]?.init.body || '{"markdown":{"text":""}}').markdown.text;
check(dingCalls(net.calls).length === 1 && dingText.includes('没开成'), 'GitHub 调不通：钉钉照样推，写明 Issue 没开成');

// 自检时页面或答复读到一半断了：报问题，不抛错。
const broken = () => ({ status: 200, text: () => Promise.reject(new TypeError('connection reset')) });
net = siteNet({ 'contact.html': broken });
problems = await selfCheck(makeEnv(), 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems[0]?.kind === 'check_page' && problems[0].detail.includes('没收完整'), '自检页面没收完整：报 check_page');
net = siteNet({ 'boblift_inquiry_submit=1': broken });
problems = await selfCheck(makeEnv(), 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems[0]?.kind === 'check_submit' && problems[0].detail.includes('没收完整'), '自检提交答复没收完整：报 check_submit');

// 浏览器上报的新开名额被乱发占满：服务器上报、自检照样能开（名额分开算）。
env = makeEnv();
net = fakeNet();
for (let i = 0; i < 6; i++) await intake(env, { ...event, kind: 'network', detail: `spam ${'abcdef'[i]}` }, { now: T0, fetcher: net.fetcher });
check(await intake(env, { site: 'bobliftcrane.com', source: 'server', kind: 'mail_failed', form: 'inquiry', detail: 'SMTP down', status: 0 }, { now: T0, fetcher: net.fetcher }) === 'sent', '浏览器那份名额满了，服务器上报的发信失败照样开 Issue');
check(await intake(env, { site: 'bobliftcrane.com', source: 'selfcheck', kind: 'check_submit', form: 'inquiry', detail: 'x', status: 500 }, { now: T0, fetcher: net.fetcher }) === 'sent', '自检发现的问题照样开 Issue');
{
  // 浏览器上报同样的「存不进后台」：和服务器上报分开记，改不了服务器那条的来源、询盘编号和名额。
  const e4 = makeEnv();
  const n4 = fakeNet({ 'api.github.com': () => new Response('down', { status: 502 }) });
  const serverSave = { site: 'bobliftcrane.com', source: 'server', kind: 'save_failed', form: 'inquiry', detail: 'DB gone', inquiry: 0, status: 0 };
  await intake(e4, serverSave, { now: T0, fetcher: n4.fetcher });
  await intake(e4, { ...serverSave, source: 'browser', inquiry: 0, visitor: 'zz', detail: 'DB gone' }, { now: T0 + 1, fetcher: n4.fetcher });
  const states = [...e4.ALERTS.map.entries()].filter(([k]) => k.startsWith('fp:')).map(([, v]) => JSON.parse(v));
  check(states.length === 2 && states.some((st) => st.sample.source === 'server' && st.total === 1), '服务器和浏览器报的同一种错误分开记，浏览器那条改不了服务器那条');
}

// 触发成功不等于 Issue 开成：补报时核对流程运行，失败或查不到就重发。
const runsReply = (runs) => () => new Response(JSON.stringify({ workflow_runs: runs }), { status: 200 });
const fpOf = async () => JSON.parse(JSON.parse(ghCalls(net.calls)[0].init.body).inputs.payload).fp;
env = makeEnv();
net = fakeNet();
await intake(env, event, { now: T0, fetcher: net.fetcher });
let fpNow = await fpOf();
const runAt = new Date((T0 + 5) * 1000).toISOString();
net = fakeNet({ '/runs?': runsReply([{ display_title: `表单报警 ${fpNow}`, status: 'completed', conclusion: 'failure', created_at: runAt }]) });
await flush(env, { now: T0 + 3700, fetcher: net.fetcher });
const resent = JSON.parse(JSON.parse(ghCalls(net.calls)[0]?.init.body || '{"inputs":{"payload":"{}"}}').inputs.payload);
check(ghCalls(net.calls).length === 1 && resent.title?.includes('服务器出错'), '流程跑失败：补报时把这次退回来重发');
check(net.calls.some((c) => c.url.includes('/actions/workflows/form-alert.yml/runs?') && c.url.includes('event=workflow_dispatch')), '核对时查的是这个流程的手动触发运行');
{
  // 跑失败退回重发时，这一批涉及的访客也退回来：重发的评论里人数对得上。
  const e3 = makeEnv();
  let n3 = fakeNet();
  for (const [i, v] of ['a', 'a', 'b', 'c'].entries()) await intake(e3, { ...verify, visitor: v }, { now: T0 + i, fetcher: n3.fetcher });
  const fp3 = JSON.parse(JSON.parse(ghCalls(n3.calls)[0].init.body).inputs.payload).fp;
  n3 = fakeNet({ '/runs?': runsReply([{ display_title: `表单报警 ${fp3}`, status: 'completed', conclusion: 'failure', created_at: runAt }]) });
  await flush(e3, { now: T0 + 3700, fetcher: n3.fetcher });
  const again = JSON.parse(JSON.parse(ghCalls(n3.calls)[0]?.init.body || '{"inputs":{"payload":"{}"}}').inputs.payload);
  check(String(again.comment || '').includes('过去 1 小时 4 次，涉及 3 位访客'), '流程跑失败重发：次数和涉及的访客都退回来（4 次 3 位）');
}

env = makeEnv();
net = fakeNet();
await intake(env, event, { now: T0, fetcher: net.fetcher });
fpNow = await fpOf();
net = fakeNet({ '/runs?': runsReply([{ display_title: `表单报警 ${fpNow}`, status: 'completed', conclusion: 'success', created_at: runAt }]) });
await flush(env, { now: T0 + 3700, fetcher: net.fetcher });
const settled = JSON.parse([...env.ALERTS.map.entries()].find(([k]) => k.startsWith('fp:'))[1]);
check(ghCalls(net.calls).length === 0 && !settled.awaiting, '流程跑成了：放下，不重发');

env = makeEnv();
net = fakeNet();
await intake(env, event, { now: T0, fetcher: net.fetcher });
net = fakeNet({ '/runs?': runsReply([]) });
await flush(env, { now: T0 + 3700, fetcher: net.fetcher });
check(ghCalls(net.calls).length === 1, '过了 30 分钟还查不到这次运行：当没跑成，重发');

env = makeEnv();
net = fakeNet();
await intake(env, event, { now: T0, fetcher: net.fetcher });
net = fakeNet({ '/runs?': () => new Response('rate limited', { status: 403 }) });
await flush(env, { now: T0 + 3700, fetcher: net.fetcher });
const unsettled = JSON.parse([...env.ALERTS.map.entries()].find(([k]) => k.startsWith('fp:'))[1]);
check(ghCalls(net.calls).length === 0 && unsettled.awaiting, '查不了运行记录：这次先不核对，也不乱重发');

// 运行记录超过一页：翻页找到这次运行，不误判成没跑而重发。
env = makeEnv();
net = fakeNet();
await intake(env, event, { now: T0, fetcher: net.fetcher });
fpNow = await fpOf();
{
  const filler = Array.from({ length: 100 }, (_, i) => ({ display_title: `表单报警 other${i}`, status: 'completed', conclusion: 'success', created_at: runAt }));
  net = fakeNet({ '/runs?': (url) => new Response(JSON.stringify(String(url).includes('page=1&') ? { total_count: 101, workflow_runs: filler } : { total_count: 101, workflow_runs: [{ display_title: `表单报警 ${fpNow}`, status: 'completed', conclusion: 'success', created_at: runAt }] }), { status: 200 }) });
  await flush(env, { now: T0 + 3700, fetcher: net.fetcher });
  const st = JSON.parse([...env.ALERTS.map.entries()].find(([k]) => k.startsWith('fp:'))[1]);
  check(ghCalls(net.calls).length === 0 && !st.awaiting && net.calls.some((c) => c.url.includes('page=2&')), '运行记录翻到第二页找到这次：放下，不重发');
}
env = makeEnv();
net = fakeNet();
await intake(env, event, { now: T0, fetcher: net.fetcher });
{
  const filler = Array.from({ length: 100 }, (_, i) => ({ display_title: `表单报警 other${i}`, status: 'completed', conclusion: 'success', created_at: runAt }));
  net = fakeNet({ '/runs?': () => new Response(JSON.stringify({ total_count: 5000, workflow_runs: filler }), { status: 200 }) });
  await flush(env, { now: T0 + 3700, fetcher: net.fetcher });
  const st = JSON.parse([...env.ALERTS.map.entries()].find(([k]) => k.startsWith('fp:'))[1]);
  check(ghCalls(net.calls).length === 0 && st.awaiting, '运行记录多到翻不完：当这次查不全，不重发');
}

// 上一批还没核对完，不发下一批：免得两批的次数搅在一起，一批失败另一批成功时漏报或重报。
env = makeEnv();
net = fakeNet();
await intake(env, event, { now: T0, fetcher: net.fetcher });
fpNow = await fpOf();
net = fakeNet({ '/runs?': () => new Response('down', { status: 502 }) });
check(await intake(env, { ...event, visitor: 'v8' }, { now: T0 + 3700, fetcher: net.fetcher }) === 'held' && ghCalls(net.calls).length === 0, '上一批核对不了：新报告只计数，不再触发');
await flush(env, { now: T0 + 3800, fetcher: net.fetcher });
check(ghCalls(net.calls).length === 0, '补报时也核对不了：照样等');
net = fakeNet({ '/runs?': runsReply([{ display_title: `表单报警 ${fpNow}`, status: 'completed', conclusion: 'failure', created_at: runAt }]) });
await flush(env, { now: T0 + 7300, fetcher: net.fetcher });
{
  const again = ghCalls(net.calls).map((c) => JSON.parse(JSON.parse(c.init.body).inputs.payload));
  const st = JSON.parse([...env.ALERTS.map.entries()].find(([k]) => k.startsWith('fp:'))[1]);
  check(again.length === 1 && st.awaiting.count === 2, '上一批跑失败：退回的那批和新来的一起重发一次（2 次），不漏不重');
}
net = fakeNet({ '/runs?': () => new Response('down', { status: 502 }) });
await flush(env, { now: T0 + 7300 + 6 * 3600, fetcher: net.fetcher });
{
  const st = JSON.parse([...env.ALERTS.map.entries()].find(([k]) => k.startsWith('fp:'))[1]);
  check(!st.awaiting, '一直查不了运行记录：等满 6 小时当它成了，不让后面的报警一直卡住');
}

// AlertDesk：同一时刻涌进来的同一个问题排队处理，只开一个 Issue，次数一条不丢。
class FakeStorage {
  constructor() { this.map = new Map(); }
  // 每次读写都让出一下，模拟真实存储的等待，让并发请求有机会交错。
  async tick() { await new Promise((r) => setImmediate(r)); }
  async get(key) { await this.tick(); return this.map.get(key); }
  async put(key, value) { await this.tick(); this.map.set(key, structuredClone(value)); }
  async list({ prefix = '' } = {}) { await this.tick(); return new Map([...this.map].filter(([k]) => k.startsWith(prefix))); }
  async delete(keys) { await this.tick(); for (const k of [].concat(keys)) this.map.delete(k); }
}
net = fakeNet({ 'api.github.com': async () => { await new Promise((r) => setTimeout(r, 5)); return new Response(null, { status: 204 }); } });
const storage = new FakeStorage();
const { ALERTS: _unused, ...deskEnv } = makeEnv();
const deskObj = new AlertDesk({ storage }, deskEnv, { fetcher: net.fetcher });
const burst = await Promise.all(Array.from({ length: 8 }, (_, i) => deskObj.fetch(req({ ...browserBody, kind: 'server_error', status: 502, detail: 'Bad Gateway' }, 'https://bobliftcrane.com', `198.51.100.${i}`))));
const outcomes = await Promise.all(burst.map((r) => r.json()));
const fpRow = [...storage.map.entries()].find(([k]) => k.startsWith('fp:'))[1];
check(ghCalls(net.calls).length === 1 && outcomes.filter((o) => o.result === 'sent').length === 1 && outcomes.filter((o) => o.result === 'held').length === 7, '8 条同时到：只开 1 个 Issue，其余 7 条计数');
check(JSON.parse(fpRow.v).pending === 7 && JSON.parse(fpRow.v).visitors.length === 7, '7 条计数和 7 位访客一个不丢');
storage.map.set('ip:old:1', { v: '3', exp: 1 });
await deskObj.fetch(new Request(`https://desk/cron?now=${Math.floor(Date.now() / 1000)}&selfcheck=0`, { method: 'POST' }));
check(!storage.map.has('ip:old:1'), '每小时顺手清掉过期的记录');
res = await deskObj.fetch(new Request('https://desk/health'));
check(res.status === 200, 'AlertDesk 回答 /health');

// 入口 Worker：转给 AlertDesk，带上访客国家（访客自己伪造的头被覆盖）；内部的 /cron 不对外。
const forwarded = [];
const stubEnv = { ...makeEnv(), DESK: { idFromName: (n) => n, get: () => ({ fetch: async (r) => { forwarded.push(r); return new Response('{}'); } }) } };
const outside = new Request('https://form-alert.test/report', { method: 'POST', headers: { Origin: 'https://bobliftcrane.com', 'X-Desk-Country': 'XX' }, body: '{}' });
Object.defineProperty(outside, 'cf', { value: { country: 'KE' } });
await worker.fetch(outside, stubEnv);
check(forwarded.length === 1 && forwarded[0].headers.get('X-Desk-Country') === 'KE', '入口转给 AlertDesk，国家用 Cloudflare 给的');
res = await worker.fetch(new Request('https://form-alert.test/cron', { method: 'POST' }), stubEnv);
check(res.status === 404 && forwarded.length === 1, '/cron 只能由定时任务调，外面调不到');

// 请求体太大：边读边数，超过就拒，不转给 AlertDesk。
const huge = 'x'.repeat(40 * 1024);
res = await handleReport(new Request('https://form-alert.test/report', { method: 'POST', headers: { Origin: 'https://bobliftcrane.com' }, body: huge }), makeEnv(), { now: T0, fetcher: fakeNet().fetcher });
check(res.status === 413, '浏览器上报超过 16 KB：拒收');
res = await handleServer(new Request('https://form-alert.test/server', { method: 'POST', body: huge }), makeEnv(), { now: T0, fetcher: fakeNet().fetcher });
check(res.status === 413, '服务器上报超过 4 KB：没验密钥就先拒收');
const streamed = new Request('https://form-alert.test/report', { method: 'POST', headers: { Origin: 'https://bobliftcrane.com' }, body: new ReadableStream({ start(c) { for (let i = 0; i < 40; i++) c.enqueue(new TextEncoder().encode('x'.repeat(1024))); c.close(); } }), duplex: 'half' });
forwarded.length = 0;
res = await worker.fetch(streamed, stubEnv);
check(res.status === 413 && forwarded.length === 0, '入口：没写长度的大请求边读边数，超了就拒，不转发');

// GitHub、钉钉卡住不回：到时限算没发成，不拖住后面的报警。
env = { ...makeEnv(), OUTBOUND_MS: 30 };
net = fakeNet({ 'api.github.com': () => new Promise(() => {}), dingtalk: () => new Promise(() => {}) });
const t1 = Date.now();
check(await intake(env, event, { now: T0, fetcher: net.fetcher }) === 'held' && Date.now() - t1 < 1000, 'GitHub、钉钉卡住：到时限就放弃，记下待补报');
check(net.calls.every((c) => c.init.signal), '外发请求都带中断信号');

// 站点没认出密钥、拒收了：报 check_secret。
net = siteNet({ 'boblift_inquiry_submit=1': () => new Response(JSON.stringify({ success: false, data: { code: 'monitorRejected', monitor: false } }), { status: 403 }) });
problems = await selfCheck(makeEnv(), 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems[0]?.kind === 'check_secret' && problems[0].detail.includes('拒收'), '站点拒收自检：报 check_secret');

// 提交地址跳转到别处：不跟跳转（不把密钥带过去），报 check_submit。
{
  const seen = [];
  net = siteNet({ 'boblift_inquiry_submit=1': (url, init) => {
    seen.push(init);
    return new Response(null, { status: 302, headers: { Location: 'https://elsewhere.example/landing?x=1' } });
  } });
  problems = await selfCheck(makeEnv(), 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
  check(seen.length >= 1 && seen.every((init) => init.redirect === 'manual') && problems[0]?.kind === 'check_submit' && problems[0].detail.includes('elsewhere.example/landing') && !problems[0].detail.includes('x=1'), '带密钥的提交不跟跳转，跳走了就报 check_submit');
}

// 测试询盘存下了却删不掉：不重试（第二次成功会把它盖掉），直接报 check_cleanup。
{
  let submits = 0;
  net = siteNet({ 'boblift_inquiry_submit=1': () => {
    submits += 1;
    return submits === 1
      ? new Response(JSON.stringify({ success: false, data: { code: 'cleanupFailed', monitor: true, saved: true, message: '测试询盘已保存，但删不掉（编号 77），请到后台询盘列表手动删除' } }), { status: 500 })
      : new Response(JSON.stringify({ success: true, data: { monitor: true, health: { recipient_ok: true, dingtalk: true, last_inquiry_at: T0 - 100 } } }));
  } });
  problems = await selfCheck(makeEnv(), 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
  check(submits === 1 && problems.length === 1 && problems[0].kind === 'check_cleanup' && problems[0].detail.includes('77'), '测试询盘删不掉：不重试，报 check_cleanup 带编号');
}

// 自检超时：迟到的答复不再往下提交测试询盘。
net = siteNet({ boblift_fresh_nonce: () => new Promise((r) => setTimeout(() => r(new Response(JSON.stringify({ success: true, data: { nonce: 'late' } }))), 60)) });
problems = await selfCheck({ ...makeEnv(), SELFCHECK_MS: 20 }, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
await new Promise((r) => setTimeout(r, 150));
check(problems[0]?.detail.includes('秒没回应') && !net.calls.some((c) => c.init.method === 'POST') && net.calls.every((c) => c.init.signal), '自检超时：报出来，迟到的随机数不再拿去提交');

// 报警服务没配好该站密钥：不提交测试询盘，直接报。
net = siteNet();
problems = await selfCheck({ ...makeEnv(), SITE_SECRETS: '{}' }, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: net.fetcher, wait: 0 });
check(problems[0]?.kind === 'check_secret' && net.calls.length === 0, '没配密钥：不提交，报 check_secret');

// 还没有过有效询盘的新站：从第一次自检算起，超过阈值也报「很久没有询盘」。
env = makeEnv();
const neverNet = () => siteNet({ 'boblift_inquiry_submit=1': () => new Response(JSON.stringify({ success: true, data: { monitor: true, health: { recipient_ok: true, dingtalk: true, mail_ok_at: 0, mail_fail_at: 0, last_inquiry_at: 0 } } })) });
problems = await selfCheck(env, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0, fetcher: neverNet().fetcher, wait: 0 });
check(problems.length === 0, '新站第一次自检：记下开始监控的时间，不报');
problems = await selfCheck(env, 'bobliftcrane.com', SITES['bobliftcrane.com'], { now: T0 + 73 * 3600, fetcher: neverNet().fetcher, wait: 0 });
check(problems[0]?.kind === 'check_quiet' && problems[0].detail.includes('一条有效询盘都没收到'), '新站过了 72 小时还一条有效询盘都没有：报 check_quiet');

// 其他。
check(describeUa('Mozilla/5.0 (Linux; Android 14) AppleWebKit Chrome/131.0 Mobile Safari/537.36') === 'Chrome 131 / Android 14', '浏览器说法');
check(!compose(SITES['bobliftcrane.com'], { ...event, detail: '@someone [x](http://a)' }, { now: T0, count: 1, visitors: 1 }).body.includes('[x](http'), '内容里的 Markdown 和 @ 被转义');
res = await worker.fetch(new Request('https://form-alert.test/nope'), makeEnv());
check(res.status === 404, '其他地址 404');

console.log(failures ? `\n${failures} failed` : '\nall passed');
process.exit(failures ? 1 : 0);
