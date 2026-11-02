import { createContext, FormEvent, useCallback, useContext, useEffect, useState } from "react";
import { api, ApiError } from "./api";
import { Dict, dictionaries, Lang } from "./i18n";

const I18n = createContext<Dict>(dictionaries.fa);
const useT = () => useContext(I18n);

function useLoad<T>(path: string | null, refreshMs = 0) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState("");
  const reload = useCallback(() => {
    if (!path) return;
    api<T>(path).then((d) => { setData(d); setError(""); }).catch((e: Error) => setError(e.message));
  }, [path]);
  useEffect(() => {
    reload();
    if (refreshMs) {
      const timer = setInterval(reload, refreshMs);
      return () => clearInterval(timer);
    }
  }, [reload, refreshMs]);
  return { data, error, reload };
}

function Err({ text }: { text: string }) {
  return text ? <p className="err" role="alert">{text}</p> : null;
}

export default function App() {
  const [lang, setLang] = useState<Lang>(() => {
    try { return (localStorage.getItem("wia_lang") as Lang) || "fa"; } catch { return "fa"; }
  });
  const [user, setUser] = useState<string | null | undefined>(undefined);
  const [needsSetup, setNeedsSetup] = useState(false);

  useEffect(() => {
    document.documentElement.lang = lang;
    document.documentElement.dir = lang === "fa" ? "rtl" : "ltr";
    try { localStorage.setItem("wia_lang", lang); } catch { /* private mode */ }
  }, [lang]);

  useEffect(() => {
    api<{ needs_setup: boolean }>("/api/setup").then((s) => setNeedsSetup(s.needs_setup)).catch(() => undefined);
    api<{ username: string }>("/api/auth/me").then((m) => setUser(m.username)).catch(() => setUser(null));
  }, []);

  const t = dictionaries[lang];
  return (
    <I18n.Provider value={t}>
      <header className="top">
        <strong>{t.appName}</strong>
        <select aria-label={t.language} style={{ width: "auto" }} value={lang} onChange={(e) => setLang(e.target.value as Lang)}>
          <option value="fa">فارسی</option>
          <option value="en">English</option>
        </select>
        {user && <button onClick={() => api("/api/auth/logout", { method: "POST" }).then(() => setUser(null))}>{t.logout}</button>}
      </header>
      {user === undefined ? null : user ? <Dashboard /> : <Login needsSetup={needsSetup} onDone={(name) => { setNeedsSetup(false); setUser(name); }} />}
    </I18n.Provider>
  );
}

function Login({ needsSetup, onDone }: { needsSetup: boolean; onDone: (name: string) => void }) {
  const t = useT();
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [token, setToken] = useState("");
  const [error, setError] = useState("");
  const submit = (e: FormEvent) => {
    e.preventDefault();
    const request = needsSetup
      ? api("/api/setup", { method: "POST", body: { username, password, setup_token: token } })
      : api("/api/auth/login", { method: "POST", body: { username, password } });
    request.then((r: { username: string }) => onDone(r.username)).catch((err: ApiError) => setError(err.message));
  };
  return (
    <main className="layout narrow">
      <form className="card" onSubmit={submit}>
        <h1>{needsSetup ? t.setupTitle : t.login}</h1>
        {needsSetup && <p className="muted">{t.setupHelp}</p>}
        {needsSetup && <label>{t.setupToken}<input className="ltr" value={token} onChange={(e) => setToken(e.target.value)} required autoComplete="off" /></label>}
        <label>{t.username}<input value={username} onChange={(e) => setUsername(e.target.value)} required autoComplete="username" /></label>
        <label>{t.password}{needsSetup && <small className="muted"> ({t.passwordHint})</small>}
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required minLength={needsSetup ? 10 : 1} autoComplete={needsSetup ? "new-password" : "current-password"} />
        </label>
        <Err text={error} />
        <button className="primary">{needsSetup ? t.createAdmin : t.login}</button>
      </form>
    </main>
  );
}

function Dashboard() {
  const t = useT();
  const [page, setPage] = useState<"overview" | "sites" | "models">("overview");
  const [site, setSite] = useState<number | null>(null);
  return (
    <main className="layout">
      <nav className="tabs">
        {(["overview", "sites", "models"] as const).map((p) => (
          <button key={p} className={page === p ? "on" : ""} onClick={() => { setPage(p); setSite(null); }}>{t[p]}</button>
        ))}
      </nav>
      {page === "overview" && <Overview />}
      {page === "models" && <Models />}
      {page === "sites" && (site === null ? <Sites onOpen={setSite} /> : <SiteDetail id={site} onBack={() => setSite(null)} />)}
    </main>
  );
}

function Health({ ok }: { ok: boolean }) {
  const t = useT();
  return <span className={ok ? "ok" : "bad"}>{ok ? t.healthy : t.unhealthy}</span>;
}

function Overview() {
  const t = useT();
  const { data: s, error } = useLoad<any>("/api/system/status", 10000);
  if (!s) return <Err text={error} />;
  const r = s.resources;
  return (
    <>
      <h1>{t.overview}</h1>
      <div className="grid">
        <div className="tile"><small>{t.database}</small><b>{s.database.backend}</b><Health ok={s.database.healthy} /></div>
        <div className="tile"><small>{t.model}</small><b>{s.model.name}</b><small>{s.model.mode}</small><br /><Health ok={s.model.name === "none" || s.model.healthy} /></div>
        <div className="tile"><small>{t.embedder}</small><b>{s.embedder.name}</b><Health ok={s.embedder.healthy} /></div>
        <div className="tile"><small>{t.vectorStore}</small><b>{s.vector_store.name}</b><Health ok={s.vector_store.healthy} /></div>
      </div>
      <div className="grid">
        <div className="tile"><small>{t.freeDisk}</small><b>{r.disk_free_gb} / {r.disk_total_gb}</b></div>
        {r.ram_total_gb !== undefined && <div className="tile"><small>{t.ramTotal}</small><b>{r.ram_total_gb}</b>{r.ram_used_percent !== undefined && <small>{r.ram_used_percent}%</small>}</div>}
        <div className="tile"><small>{t.cpu}</small><b>{r.cpu_count}</b></div>
        {r.load_1m !== undefined && <div className="tile"><small>{t.load}</small><b>{r.load_1m}</b></div>}
      </div>
      <div className="card"><h2>{t.jobs}</h2><pre>{JSON.stringify(s.jobs, null, 1)}</pre></div>
    </>
  );
}

function Models() {
  const t = useT();
  const { data, error, reload } = useLoad<any>("/api/models", 15000);
  const [message, setMessage] = useState("");
  if (!data) return <Err text={error} />;
  const use = (backend: string, model: string) => api("/api/models/select", { method: "POST", body: { backend, model } }).then(reload);
  const pull = (name: string) => api("/api/models/pull", { method: "POST", body: { name } }).then(() => setMessage(`${name}: ${t.queued}`));
  return (
    <>
      <h1>{t.models}</h1>
      <div className="card">
        <p>{t.active}: <b className="ltr">{data.active}</b> · {t.embedder}: <b className="ltr">{data.embedder}</b></p>
        <button onClick={() => use("none", "")}>{t.noModel}</button>
        <Err text={message} />
      </div>
      <div className="card">
        <h2>{t.installed}</h2>
        {data.installed.length === 0 && <p className="muted">{t.noData}</p>}
        <table><tbody>
          {data.installed.map((m: any) => (
            <tr key={m.name}><td className="ltr">{m.name}</td><td>{(m.size / 1e9).toFixed(1)} GB</td><td><button onClick={() => use("ollama", m.name)}>{t.use}</button></td></tr>
          ))}
        </tbody></table>
      </div>
      <div className="card">
        <h2>{t.catalog}</h2>
        <table>
          <thead><tr><th>{t.name}</th><th>{t.type}</th><th>{t.download_gb}</th><th>{t.ram}</th><th>fa / ar / en</th><th>{t.tested}</th><th></th></tr></thead>
          <tbody>
            {data.catalog.map((m: any) => (
              <tr key={m.name}>
                <td className="ltr">{m.name}<br /><small className="muted">{m.note}</small></td>
                <td>{m.role}</td><td>{m.download_gb}</td><td>{m.min_ram_gb}</td>
                <td className="ltr">{m.languages.fa} / {m.languages.ar} / {m.languages.en}</td>
                <td>{m.tested ? t.yes : t.no}</td>
                <td><button onClick={() => pull(m.name)}>{t.download}</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}

function Sites({ onOpen }: { onOpen: (id: number) => void }) {
  const t = useT();
  const { data, error, reload } = useLoad<any[]>("/api/sites");
  const [form, setForm] = useState({ name: "", base_url: "", default_language: "fa" });
  const [failure, setFailure] = useState("");
  const add = (e: FormEvent) => {
    e.preventDefault();
    api("/api/sites", { method: "POST", body: form }).then(() => { setForm({ name: "", base_url: "", default_language: "fa" }); setFailure(""); reload(); }).catch((err: Error) => setFailure(err.message));
  };
  return (
    <>
      <h1>{t.sites}</h1>
      <form className="card" onSubmit={add}>
        <h2>{t.addSite}</h2>
        <div className="row">
          <label>{t.name}<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label>
          <label>{t.url}<input className="ltr" type="url" placeholder="https://example.com" value={form.base_url} onChange={(e) => setForm({ ...form, base_url: e.target.value })} required /></label>
          <label>{t.language}<LangSelect value={form.default_language} onChange={(v) => setForm({ ...form, default_language: v })} /></label>
          <button className="primary">{t.add}</button>
        </div>
        <Err text={failure || error} />
      </form>
      <div className="card">
        <table>
          <thead><tr><th>{t.name}</th><th>{t.url}</th><th>{t.documents}</th><th>{t.chunks}</th><th></th></tr></thead>
          <tbody>
            {(data ?? []).map((s) => (
              <tr key={s.id}><td>{s.name}</td><td className="ltr">{s.base_url}</td><td>{s.stats.documents.active ?? 0}</td><td>{s.stats.chunks}</td><td><button onClick={() => onOpen(s.id)}>{t.open}</button></td></tr>
            ))}
          </tbody>
        </table>
        {data?.length === 0 && <p className="muted">{t.noData}</p>}
      </div>
    </>
  );
}

function LangSelect({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <select value={value} onChange={(e) => onChange(e.target.value)}>
      <option value="fa">فارسی</option>
      <option value="ar">العربية</option>
      <option value="en">English</option>
    </select>
  );
}

const SITE_TABS = ["crawl", "knowledge", "faq", "test", "widget", "competitors", "evaluation", "conversations"] as const;
type SiteTab = (typeof SITE_TABS)[number];

function SiteDetail({ id, onBack }: { id: number; onBack: () => void }) {
  const t = useT();
  const [tab, setTab] = useState<SiteTab>("crawl");
  const { data: site, reload } = useLoad<any>(`/api/sites/${id}`, 8000);
  if (!site) return null;
  const remove = () => { if (window.confirm(t.confirmDelete)) api(`/api/sites/${id}`, { method: "DELETE" }).then(onBack); };
  return (
    <>
      <div className="row" style={{ marginBottom: 10 }}>
        <button onClick={onBack}>←</button>
        <h1 style={{ margin: 0, flex: 1 }}>{site.name} <small className="muted ltr">{site.base_url}</small></h1>
        <button onClick={remove}>{t.remove}</button>
      </div>
      <nav className="tabs">{SITE_TABS.map((k) => <button key={k} className={tab === k ? "on" : ""} onClick={() => setTab(k)}>{t[k]}</button>)}</nav>
      {tab === "crawl" && <CrawlTab site={site} reload={reload} />}
      {tab === "knowledge" && <KnowledgeTab id={id} />}
      {tab === "faq" && <FaqTab id={id} />}
      {tab === "test" && <TestTab id={id} />}
      {tab === "widget" && <WidgetTab site={site} reload={reload} />}
      {tab === "competitors" && <CompetitorsTab id={id} />}
      {tab === "evaluation" && <EvaluationTab id={id} />}
      {tab === "conversations" && <ConversationsTab id={id} />}
    </>
  );
}

function CrawlTab({ site, reload }: { site: any; reload: () => void }) {
  const t = useT();
  const { data: runs, reload: reloadRuns } = useLoad<any[]>(`/api/sites/${site.id}/crawls`, 8000);
  const [every, setEvery] = useState(24);
  const [maxPages, setMaxPages] = useState(site.settings?.crawl?.max_pages ?? 500);
  const [delay, setDelay] = useState(site.settings?.crawl?.delay_seconds ?? 1);
  const last = runs?.[0];
  return (
    <>
      <div className="grid">
        <div className="tile"><small>{t.pages}</small><b>{last?.stats?.crawl?.discovered ?? "-"}</b></div>
        <div className="tile"><small>{t.documents}</small><b>{site.stats.documents.active ?? 0}</b></div>
        <div className="tile"><small>{t.chunks}</small><b>{site.stats.chunks}</b></div>
        <div className="tile"><small>{t.lastCrawl}</small><b style={{ fontSize: 14 }}>{last ? `${last.status} · ${String(last.started_at).slice(0, 16)}` : t.neverCrawled}</b></div>
      </div>
      <div className="card">
        <button className="primary" onClick={() => api(`/api/sites/${site.id}/crawl`, { method: "POST" }).then(() => { reloadRuns(); reload(); })}>{t.startCrawl}</button>
        <div className="row" style={{ marginTop: 12 }}>
          <label>{t.every}<input type="number" min={0} value={every} onChange={(e) => setEvery(+e.target.value)} /></label>
          <label>{t.maxPages}<input type="number" min={1} value={maxPages} onChange={(e) => setMaxPages(+e.target.value)} /></label>
          <label>{t.delay}<input type="number" min={0} step={0.5} value={delay} onChange={(e) => setDelay(+e.target.value)} /></label>
          <button onClick={() => api(`/api/sites/${site.id}`, { method: "PATCH", body: { crawl_every_hours: every, crawl: { max_pages: maxPages, delay_seconds: delay } } }).then(reload)}>{t.save}</button>
        </div>
      </div>
      <div className="card">
        <table>
          <thead><tr><th>{t.started}</th><th>{t.status}</th><th>{t.stats}</th><th>{t.failures}</th></tr></thead>
          <tbody>{(runs ?? []).map((r) => (
            <tr key={r.id}>
              <td>{String(r.started_at).slice(0, 16)}</td><td>{r.status}</td>
              <td className="ltr">new {r.stats.new ?? 0}, updated {r.stats.updated ?? 0}, unchanged {r.stats.unchanged ?? 0}, removed {r.stats.removed ?? 0}, duplicates {r.stats.duplicates ?? 0}{r.stats.crawl?.stopped_early ? ` (${r.stats.crawl.stopped_early})` : ""}</td>
              <td className="ltr">{(r.failures ?? []).slice(0, 5).map((f: any) => <div key={f.url}>{f.url}: {f.error}</div>)}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </>
  );
}

function KnowledgeTab({ id }: { id: number }) {
  const t = useT();
  const { data: docs, reload } = useLoad<any[]>(`/api/sites/${id}/documents?status=active&limit=200`);
  const [q, setQ] = useState("");
  const [result, setResult] = useState<any>(null);
  return (
    <>
      <form className="card row" onSubmit={(e) => { e.preventDefault(); api(`/api/sites/${id}/search?q=${encodeURIComponent(q)}`).then(setResult); }}>
        <label>{t.search}<input dir="auto" value={q} onChange={(e) => setQ(e.target.value)} required /></label>
        <button>{t.ask}</button>
      </form>
      {result && <div className="card"><small className="muted ltr">{result.mode} · confident: {String(result.confident)} · coverage: {result.best_coverage}</small>
        <ol>{result.hits.map((h: any) => <li key={h.url + h.heading} dir="auto"><a href={h.url}>{h.title}</a> {h.heading && `- ${h.heading}`}<br /><small className="muted">{h.text}</small></li>)}</ol></div>}
      <div className="card">
        <table>
          <thead><tr><th>{t.title}</th><th>{t.type}</th><th>{t.language}</th><th>{t.checked}</th><th></th></tr></thead>
          <tbody>{(docs ?? []).map((d) => (
            <tr key={d.id}>
              <td dir="auto"><a href={d.url}>{d.title || d.url}</a></td><td>{d.content_type}</td><td>{d.language}</td><td>{String(d.crawl_date).slice(0, 16)}</td>
              <td><button onClick={() => api(`/api/sites/${id}/documents/${d.id}`, { method: "DELETE" }).then(reload)}>{t.remove}</button></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
    </>
  );
}

function FaqTab({ id }: { id: number }) {
  const t = useT();
  const { data, reload } = useLoad<any[]>(`/api/sites/${id}/faqs`);
  const empty = { question: "", answer: "", keywords: "", language: "" };
  const [form, setForm] = useState(empty);
  return (
    <>
      <form className="card" onSubmit={(e) => { e.preventDefault(); api(`/api/sites/${id}/faqs`, { method: "POST", body: form }).then(() => { setForm(empty); reload(); }); }}>
        <label>{t.question}<input dir="auto" value={form.question} onChange={(e) => setForm({ ...form, question: e.target.value })} required /></label>
        <label>{t.answer}<textarea dir="auto" rows={3} value={form.answer} onChange={(e) => setForm({ ...form, answer: e.target.value })} required /></label>
        <label>{t.keywords}<input dir="auto" value={form.keywords} onChange={(e) => setForm({ ...form, keywords: e.target.value })} /></label>
        <button className="primary">{t.add}</button>
      </form>
      <div className="card"><table><tbody>{(data ?? []).map((f) => (
        <tr key={f.id}><td dir="auto"><b>{f.question}</b><br />{f.answer}</td><td><button onClick={() => api(`/api/sites/${id}/faqs/${f.id}`, { method: "DELETE" }).then(reload)}>{t.remove}</button></td></tr>
      ))}</tbody></table></div>
    </>
  );
}

function TestTab({ id }: { id: number }) {
  const t = useT();
  const [q, setQ] = useState("");
  const [busy, setBusy] = useState(false);
  const [a, setA] = useState<any>(null);
  const [error, setError] = useState("");
  return (
    <>
      <form className="card row" onSubmit={(e) => { e.preventDefault(); setBusy(true); setError(""); api(`/api/sites/${id}/chat-test`, { method: "POST", body: { message: q } }).then(setA).catch((err: Error) => setError(err.message)).finally(() => setBusy(false)); }}>
        <label>{t.question}<input dir="auto" value={q} onChange={(e) => setQ(e.target.value)} required maxLength={1000} /></label>
        <button className="primary" disabled={busy}>{t.ask}</button>
      </form>
      <Err text={error} />
      {a && <div className="card">
        <div className="answer" dir="auto">{a.text}</div>
        <p className="muted">{t.mode}: {a.mode} · {t.latency}: {a.latency_ms} ms {a.notes?.length ? `· ${a.notes.join(", ")}` : ""}</p>
        {a.sources?.length > 0 && <><b>{t.sources}</b><ul>{a.sources.map((s: any) => <li key={s.url}><a href={s.url}>{s.title || s.url}</a></li>)}</ul></>}
      </div>}
    </>
  );
}

function WidgetTab({ site, reload }: { site: any; reload: () => void }) {
  const t = useT();
  const [w, setW] = useState<any>(site.settings?.widget ?? {});
  const langText = (key: string, label: string) => (
    <fieldset style={{ border: 0, padding: 0, margin: "0 0 10px" }}>
      <legend><b>{label}</b></legend>
      <div className="row">
        {["fa", "ar", "en"].map((l) => (
          <label key={l}>{l}<input dir="auto" value={w[key]?.[l] ?? ""} onChange={(e) => setW({ ...w, [key]: { ...(w[key] ?? {}), [l]: e.target.value } })} /></label>
        ))}
      </div>
    </fieldset>
  );
  const origin = window.location.origin;
  const snippet = `<script src="${origin}/widget/wia-widget.js" data-endpoint="${origin}/public/v1" data-site-key="${site.public_key}" defer></script>`;
  return (
    <>
      <div className="card">
        <label><input type="checkbox" checked={w.enabled !== false} onChange={(e) => setW({ ...w, enabled: e.target.checked })} /> {t.enabled}</label>
        <div className="row">
          <label>{t.color}<input type="color" value={w.color ?? "#2563eb"} onChange={(e) => setW({ ...w, color: e.target.value })} /></label>
          <label>{t.position}<select value={w.position ?? "right"} onChange={(e) => setW({ ...w, position: e.target.value })}><option value="right">{t.right}</option><option value="left">{t.left}</option></select></label>
          <label>{t.language}<LangSelect value={w.language || site.default_language} onChange={(v) => setW({ ...w, language: v })} /></label>
          <label>{t.avatar}<input className="ltr" value={w.avatar_url ?? ""} onChange={(e) => setW({ ...w, avatar_url: e.target.value })} /></label>
        </div>
        <label style={{ marginTop: 10 }}><input type="checkbox" checked={w.show_sources !== false} onChange={(e) => setW({ ...w, show_sources: e.target.checked })} /> {t.showSources}</label>
        {langText("title", t.title)}
        {langText("welcome", t.welcome)}
        {langText("not_found", t.notFound)}
        <button className="primary" onClick={() => api(`/api/sites/${site.id}`, { method: "PATCH", body: { widget: w } }).then(reload)}>{t.save}</button>
      </div>
      <div className="card"><h2>{t.embed}</h2><pre>{snippet}</pre></div>
    </>
  );
}

function CompetitorsTab({ id }: { id: number }) {
  const t = useT();
  const { data, reload } = useLoad<any[]>(`/api/sites/${id}/competitors`, 8000);
  const { data: reports, reload: reloadReports } = useLoad<any[]>(`/api/sites/${id}/competitor-reports?limit=1`, 8000);
  const [form, setForm] = useState({ name: "", base_url: "" });
  const [failure, setFailure] = useState("");
  const r = reports?.[0]?.report;
  return (
    <>
      <p className="muted">{t.competitorNote}</p>
      <form className="card row" onSubmit={(e) => { e.preventDefault(); api(`/api/sites/${id}/competitors`, { method: "POST", body: form }).then(() => { setForm({ name: "", base_url: "" }); setFailure(""); reload(); }).catch((err: Error) => setFailure(err.message)); }}>
        <label>{t.name}<input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} required /></label>
        <label>{t.url}<input className="ltr" type="url" value={form.base_url} onChange={(e) => setForm({ ...form, base_url: e.target.value })} required /></label>
        <button className="primary">{t.add}</button>
        <Err text={failure} />
      </form>
      <div className="card">
        <table><tbody>{(data ?? []).map((c) => (
          <tr key={c.id}>
            <td>{c.name}</td><td className="ltr">{c.base_url}</td><td>{t.documents}: {c.stats.documents.active ?? 0}</td>
            <td><button onClick={() => api(`/api/sites/${id}/competitors/${c.id}/crawl`, { method: "POST" }).then(reload)}>{t.startCrawl}</button>{" "}
              <button onClick={() => { if (window.confirm(t.confirmDelete)) api(`/api/sites/${id}/competitors/${c.id}`, { method: "DELETE" }).then(reload); }}>{t.remove}</button></td>
          </tr>
        ))}</tbody></table>
        <button style={{ marginTop: 10 }} onClick={() => api(`/api/sites/${id}/competitor-reports`, { method: "POST" }).then(reloadReports)}>{t.buildReport}</button>
      </div>
      {r && <>
        <div className="card"><h2>{t.matched}</h2><table><tbody>{r.products.matched.map((m: any) => (
          <tr key={m.name + m.competitor.url}><td dir="auto">{m.name}</td><td>{t.own}: {m.own.price} {m.own.currency}</td><td>{t.rival}: <a href={m.competitor.url}>{m.competitor.price} {m.competitor.currency}</a></td><td><small className="muted">{t.collected}: {String(m.competitor.collected_at).slice(0, 10)}</small></td></tr>
        ))}</tbody></table>{r.products.matched.length === 0 && <p className="muted">{t.noData}</p>}</div>
        <div className="card"><h2>{t.onlyCompetitor}</h2><ul>{r.products.only_competitor.map((p: any) => <li key={p.url} dir="auto"><a href={p.url}>{p.name}</a> {p.price} {p.currency} <small className="muted">{t.collected}: {String(p.collected_at).slice(0, 10)}</small></li>)}</ul></div>
        <div className="card"><h2>{t.gaps}</h2><p className="muted">{r.content_gaps.note}</p><ul>{r.content_gaps.gaps.map((g: any) => <li key={g.topic} dir="auto"><b>{g.topic}</b> ({g.competitor_pages}) {g.examples[0] && <a href={g.examples[0].url}>{g.examples[0].title}</a>}</li>)}</ul></div>
        <div className="card"><h2>{t.ideas}</h2><p className="muted">{r.blog_ideas.note}</p><ul>{r.blog_ideas.ideas.map((i: any) => <li key={i.url} dir="auto"><a href={i.url}>{i.competitor_title}</a></li>)}</ul></div>
      </>}
    </>
  );
}

const METRICS = ["retrieval_accuracy", "answer_relevance", "groundedness", "source_coverage", "unsupported_claims", "language_quality", "no_answer_precision", "answered_rate", "latency_p50_ms", "latency_p95_ms", "passed", "failed"];

function EvaluationTab({ id }: { id: number }) {
  const t = useT();
  const { data, reload } = useLoad<any[]>(`/api/sites/${id}/evaluations?limit=1`, 8000);
  const run = data?.[0];
  const failed = (run?.items ?? []).filter((i: any) => !i.passed);
  const f = run?.findings ?? {};
  const list = (title: string, items: any[] = [], render = (x: any) => (typeof x === "string" ? x : JSON.stringify(x))) => (
    <div className="card"><h2>{title} ({items.length})</h2>{items.length ? <ul>{items.slice(0, 30).map((x, i) => <li key={i} dir="auto">{render(x)}</li>)}</ul> : <p className="muted">{t.noData}</p>}</div>
  );
  return (
    <>
      <div className="card"><button className="primary" onClick={() => api(`/api/sites/${id}/evaluations`, { method: "POST" }).then(reload)}>{t.runEvaluation}</button>
        {run && <small className="muted"> {run.status} · {String(run.started_at).slice(0, 16)}</small>}</div>
      {run && <div className="grid">{METRICS.filter((m) => run.metrics[m] !== undefined).map((m) => <div className="tile" key={m}><small className="ltr">{m}</small><b>{String(run.metrics[m] ?? "-")}</b></div>)}</div>}
      {run && <>
        <div className="card"><h2>{t.failedItems} ({failed.length})</h2><table><tbody>{failed.slice(0, 50).map((i: any, n: number) => (
          <tr key={n}><td dir="auto">{i.question}</td><td>{i.kind} / {i.mode}</td><td className="ltr">{i.why}</td></tr>
        ))}</tbody></table></div>
        <h2>{t.findings}</h2>
        {list(t.unanswered, f.unanswered_questions)}
        {list(t.contradictions, f.contradictions, (c) => `${c.name}: ${c.values.map((v: any) => `${v.value} (${v.url})`).join(" / ")}`)}
        {list(t.failedPages, f.failed_pages, (p) => `${p.url}: ${p.error}`)}
        {list(t.thinPages, f.thin_pages)}
      </>}
    </>
  );
}

function ConversationsTab({ id }: { id: number }) {
  const t = useT();
  const { data, reload } = useLoad<any[]>(`/api/sites/${id}/conversations`);
  const [open, setOpen] = useState<any[] | null>(null);
  return (
    <div className="card">
      <button onClick={() => { if (window.confirm(t.confirmDelete)) api(`/api/sites/${id}/conversations`, { method: "DELETE" }).then(() => { setOpen(null); reload(); }); }}>{t.deleteAll}</button>
      <table style={{ marginTop: 10 }}><tbody>{(data ?? []).map((c) => (
        <tr key={c.id}><td>{String(c.updated_at).slice(0, 16)}</td><td><button onClick={() => api<any[]>(`/api/sites/${id}/conversations/${c.id}`).then(setOpen)}>{t.open}</button></td></tr>
      ))}</tbody></table>
      {open && open.map((m) => <div key={m.id} className="answer" style={{ marginTop: 8 }} dir="auto"><b>{m.role}:</b> {m.text}</div>)}
    </div>
  );
}
