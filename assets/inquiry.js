/**
 * Where an inquiry came from, added to the form as hidden fields when it is sent (ported from the crane site's
 * assets/js/theme.js). Two kinds:
 *
 * - Sent with every inquiry whatever the cookie choice, as nothing is kept in the browser for them: the site that linked to
 *   this page ({prefix}page_referrer, host only), whether advertising is agreed to ({prefix}ad_consent) and whether the
 *   screen takes touch ({prefix}touch; iPadOS Safari presents itself as a Mac).
 * - Which ad brought the visitor: a Google Ads click ID or campaign tags (utm_*) on the landing address, with the site the
 *   visitor came from, kept in this browser for 90 days and sent in {prefix}attr_* fields, only while advertising is
 *   agreed to.
 *
 * The cookie banner is the theme's. It tells this script through window.achordInquiryConsent:
 *   { tracking: true when Google's tag is configured, advertising: true when an Ads ID puts Advertising in the banner,
 *     agreed(): true / false / null (not known yet) }
 * and dispatches 'achord-inquiry:consent' on document when the visitor changes their choice. Without it, Google's own
 * consent state decides (when a Google tag runs on the page).
 */
(() => {
  const config = window.achordInquiryConfig || {};
  const PREFIX = String(config.prefix || 'achord_');
  const SELECTOR = String(config.selector || '[data-achord-inquiry]');
  const KEY = String(config.storageKey || 'achord_inquiry_attribution');
  const DAYS = 90;
  const CLICK_IDS = ['gclid', 'gbraid', 'wbraid'];
  const PARAMS = [...CLICK_IDS, 'gad_source', 'gad_campaignid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  const FIELDS = [...PARAMS, 'landing', 'first_seen', 'referrer'];

  // Google's consent state holds the regional default before any choice (an undocumented internal, so anything
  // unexpected counts as unknown).
  const googleAgreed = () => {
    try {
      const entry = window.google_tag_data?.ics?.entries?.ad_storage;
      if (!entry || typeof entry !== 'object') return null;
      const value = entry.update !== undefined ? entry.update : entry.default;
      return typeof value === 'boolean' ? value : null;
    } catch {
      return null;
    }
  };
  const consent = () => {
    const given = window.achordInquiryConsent;
    if (given && typeof given === 'object') {
      return {
        tracking: given.tracking === true,
        advertising: given.advertising === true,
        agreed: () => {
          try {
            const value = typeof given.agreed === 'function' ? given.agreed() : null;
            return typeof value === 'boolean' ? value : null;
          } catch {
            return null;
          }
        },
      };
    }
    const tagged = typeof window.gtag === 'function' || !!window.google_tag_data;
    return { tracking: tagged, advertising: tagged, agreed: googleAgreed };
  };

  // The site that linked to this page, host only (www. dropped); empty for this site's own pages and for direct visits.
  const bareHost = (host) => String(host || '').toLowerCase().replace(/^www\./, '');
  const externalReferrer = () => {
    try {
      const host = bareHost(new URL(document.referrer).hostname);
      return host && host !== bareHost(location.hostname) && host.length <= 120 ? host : '';
    } catch {
      return '';
    }
  };

  // Same limits as the server (achord_inquiry_attribution_clean): one printable line, short; a click ID is dropped, never cut.
  const clean = (value, length) => String(value ?? '').replace(/[\u0000-\u001f\u007f<>]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, length);
  const cleanParams = (source) => {
    const found = {};
    PARAMS.forEach((name) => {
      if (CLICK_IDS.includes(name)) {
        const raw = String(source[name] ?? '').trim();
        if (raw.length <= 255 && /^[A-Za-z0-9_.~=-]+$/.test(raw)) found[name] = raw;
        return;
      }
      const value = clean(source[name], 120);
      if (value) found[name] = value;
    });
    return found;
  };

  let arrivalHandled = false;
  const forget = () => {
    try { localStorage.removeItem(KEY); } catch { /* storage blocked */ }
    // Agreeing again later on this page reads its landing address afresh.
    arrivalHandled = false;
  };
  const read = () => {
    let raw = null;
    try { raw = localStorage.getItem(KEY); } catch { return null; }
    if (raw === null) return null;
    try {
      const saved = JSON.parse(raw);
      if (saved && saved.v === 1 && saved.expires > Date.now() && saved.params && typeof saved.params === 'object') return saved;
    } catch { /* unreadable: removed below */ }
    forget();
    return null;
  };

  const arrivedAt = Date.now();
  // A new click replaces what is kept; tags alone never replace a click; the same click seen again keeps its first landing.
  // A visit from another site with neither (natural search, a link) is kept only over another such visit or nothing.
  // An older tab never overwrites a record made later in another tab.
  const nextRecord = (stored) => {
    const found = cleanParams(Object.fromEntries(new URLSearchParams(location.search)));
    const referrer = externalReferrer();
    const hasClick = CLICK_IDS.some((name) => found[name]);
    const storedHasClick = stored && CLICK_IDS.some((name) => stored.params[name]);
    const record = (params) => ({ v: 1, arrived: arrivedAt, first_seen: new Date(arrivedAt).toISOString(), landing: clean(location.origin + location.pathname, 300), referrer, params, expires: arrivedAt + DAYS * 864e5 });
    if (!hasClick && Object.keys(found).length === 0) {
      if (!referrer || (stored && (Object.keys(stored.params).length || stored.referrer === referrer || (stored.arrived || 0) > arrivedAt))) return stored;
      return record({});
    }
    if (!hasClick && storedHasClick) return stored;
    const primary = CLICK_IDS.find((name) => found[name]);
    if (stored && (primary ? found[primary] === stored.params[primary] : JSON.stringify(found) === JSON.stringify(stored.params))) return stored;
    if (stored && (stored.arrived || 0) > arrivedAt) return stored;
    return record(found);
  };

  const settle = () => {
    const state = consent();
    if (!state.tracking || !state.advertising) {
      forget();
      return false;
    }
    const agreed = state.agreed();
    if (agreed === true) {
      if (!arrivalHandled) {
        const next = nextRecord(read());
        if (next) {
          try { localStorage.setItem(KEY, JSON.stringify(next)); } catch { /* not recorded */ }
        }
      }
      arrivalHandled = true;
    } else if (agreed === false) forget();
    return agreed;
  };

  const setField = (form, name, value) => {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!value) {
      input?.remove();
      return;
    }
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.append(input);
    }
    input.value = value;
  };

  const fill = (form) => {
    const state = consent();
    const agreed = settle();
    const record = agreed === true ? read() : null;
    const values = record ? { ...record.params, landing: clean(record.landing, 300), first_seen: clean(record.first_seen, 40), referrer: bareHost(clean(record.referrer, 120)) } : {};
    FIELDS.forEach((name) => setField(form, `${PREFIX}attr_${name}`, values[name] || ''));
    setField(form, `${PREFIX}page_referrer`, externalReferrer());
    setField(form, `${PREFIX}ad_consent`, state.tracking && state.advertising && typeof agreed === 'boolean' ? String(agreed) : '');
    setField(form, `${PREFIX}touch`, navigator.maxTouchPoints > 1 ? '1' : '0');
  };

  // Capture phase: runs before a theme's script builds its FormData and before a plain form posts.
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches(SELECTOR)) return;
    try { fill(form); } catch { /* the form works without it */ }
  }, true);
  document.addEventListener('achord-inquiry:consent', () => { try { settle(); } catch { /* ignored */ } });
  window.addEventListener('pagehide', () => { try { settle(); } catch { /* ignored */ } });

  // Google's tag loads after the page, so its default is asked for again, quickly at first, then every two seconds.
  let waited = 0;
  const ask = () => {
    let agreed = null;
    try { agreed = settle(); } catch { return; }
    if (agreed !== null || waited >= 120000) return;
    const step = waited < 6000 ? 250 : 2000;
    waited += step;
    window.setTimeout(ask, step);
  };
  ask();
})();
