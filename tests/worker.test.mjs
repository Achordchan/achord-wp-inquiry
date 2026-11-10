// node tests/worker.test.mjs：用假的 KV、假的 GitHub 和钉钉跑 monitor/worker.js。
import worker, { intake, flush, selfCheck, handleReport, handleServer, compose, describeUa } from '../monitor/worker.js';

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
const ghCalls = (calls) => calls.filter((c) => c.url.includes('api.github.com'));
const dingCalls = (calls) => calls.filter((c) => c.url.includes('dingtalk'));
const T0 = 1794300000;
const event = { site: 'bobliftcrane.com', source: 'browser', kind: 'server_error', form: 'inquiry', status: 502, detail: 'Bad Gateway (ref 1001)', page: '/products/sq5/', ua: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Version/17.4 Mobile/15E148 Safari/604.1', steps: ['0s 打开 /products/sq5/', '3.2s 点击 button「Get a quote」'], visitor: 'v1' };

// 第一次：触发开 Issue 并推钉钉（带加签）。
let env = makeEnv();
let net = fakeNet();
check(await intake(env, event, { now: T0, fetcher: net.fetcher }) === 'sent', '第一次出现：触发开 Issue');
const gh = ghCalls(net.calls)[0];
const payload = JSON.parse(JSON.parse(gh.init.body).inputs.payload);
check(gh.url === 'https://api.github.com/repos/Achordchan/bobliftcrane/actions/workflows/form-alert.yml/dispatches' && gh.init.headers.Authorization === 'Bearer gh', '调用起重机站仓库的 form-alert 流程');
check(payload.title === '[起重机站] 询盘表单服务器出错：HTTP 502' && payload.body.includes('Safari 17 / iOS 17.4') && payload.body.includes('https://bobliftcrane.com/products/sq5/') && payload.body.includes('点击 button「Get a quote」') && /^[0-9a-f]{12}$/.test(payload.fp), 'Issue 标题、浏览器、页面、足迹齐全');
const ding = dingCalls(net.calls)[0];
check(ding && ding.url.includes('timestamp=') && ding.url.includes('sign=') && JSON.parse(ding.init.body).markdown.text.includes('起重机站询盘表单报警'), '推钉钉，带加签');

// 一小时内再出现：只计数，不再触发。
net = fakeNet();
check(await intake(env, { ...event, visitor: 'v2' }, { now: T0 + 600, fetcher: net.fetcher }) === 'held', '一小时内再出现：只计数');
check(await intake(env, { ...event, visitor: 'v3', detail: 'Bad Gateway (ref 2002)' }, { now: T0 + 700, fetcher: net.fetcher }) === 'held', '错误信息只差数字也算同一个');
check(net.calls.length === 0, '期间不调 GitHub、不推钉钉');

// 每小时补报：一条评论带次数，钉钉 6 小时内不再推。
net = fakeNet();
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
await flush(env, { now: T0 + 60, fetcher: net.fetcher });
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
res = await handleReport(req(browserBody), env, { now: T0, fetcher: net.fetcher });
const sentPayload = JSON.parse(JSON.parse(ghCalls(net.calls)[0].init.body).inputs.payload);
check(res.status === 200 && res.headers.get('Access-Control-Allow-Origin') === 'https://bobliftcrane.com', '本站发来的收下，带跨域头');
check(!JSON.stringify(sentPayload).includes('ana@client.test') && !JSON.stringify(sentPayload).includes('gclid') && !JSON.stringify(sentPayload).includes('203.0.113.9'), '报出去的内容没有邮箱、广告参数、IP');
for (let i = 0; i < 10; i++) res = await handleReport(req({ ...browserBody, kind: 'stuck' }), env, { now: T0, fetcher: net.fetcher });
check(res.status === 429, '同一 IP 每小时超过 10 条就拒收');

// 服务器上报：要密钥。
const serverReq = (auth, body) => new Request('https://form-alert.test/server', { method: 'POST', headers: { Authorization: auth }, body: JSON.stringify(body) });
res = await handleServer(serverReq('Bearer wrong', { site: 'bobliftcrane.com', kind: 'mail_failed', message: 'x' }), env, { now: T0, fetcher: net.fetcher });
check(res.status === 403, '服务器上报密钥不对：拒收');
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

// 其他。
check(describeUa('Mozilla/5.0 (Linux; Android 14) AppleWebKit Chrome/131.0 Mobile Safari/537.36') === 'Chrome 131 / Android 14', '浏览器说法');
check(!compose(SITES['bobliftcrane.com'], { ...event, detail: '@someone [x](http://a)' }, { now: T0, count: 1, visitors: 1 }).body.includes('[x](http'), '内容里的 Markdown 和 @ 被转义');
res = await worker.fetch(new Request('https://form-alert.test/nope'), makeEnv());
check(res.status === 404, '其他地址 404');

console.log(failures ? `\n${failures} failed` : '\nall passed');
process.exit(failures ? 1 : 0);
