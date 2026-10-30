/* =====================================================================
   IPTB-LSMS · Module 4 · Reports and Low-Stock Alerts
   Each page sets <body data-page="..."> to select its view.
   Saved reports and alert settings persist in localStorage.
   ===================================================================== */
(function () {
"use strict";
const DB = SEED, OPEN = DB.openingDate, TODAY = DB.today;
const STORE_KEY = "iptb-lsms-m4-v3";   // v3: reports and alerts are per location

// ------------------------------------------------------------------ locations (see ../lsms.js)
// Records in data.js without "loc" belong to Kuantan (PHG). Admin can switch location; staff only see their own.
const LS = window.LSMS;
const ALL_LOCS = LS.LOCATIONS.map(l => l.id);
const VIEW = LS.isAdmin ? (() => { try { const v = sessionStorage.getItem("m4loc") || ""; return ALL_LOCS.includes(v) ? v : ""; } catch (e) { return ""; } })() : LS.myLoc;
const locsFor = loc => (loc ? [loc] : ALL_LOCS);   // "" = every location (admin only)
const locShort = id => LS.locShort(id);
const locLabel = loc => (loc ? LS.locName(loc) : "All locations");
const openOf = (p, loc) => DB.openingByLoc?.[loc]?.[p.id] ?? (loc === "PHG" ? p.openingStock : 0);
const DEFAULT_RULE = { mode: "min", buffer: 20, window: 30, mult: 2 };

// ------------------------------------------------------------------ helpers
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = s => String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const rm = n => Number(n).toLocaleString("en-MY", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const n0 = n => Number(n).toLocaleString("en-MY");
const lit = n => Number(n).toLocaleString("en-MY", { maximumFractionDigits: 1 });
const pack = l => (l < 1 ? l * 1000 + " ml" : l + " L");
const fmtDate = d => new Date(d + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
const fmtShort = d => new Date(d + "T00:00:00").toLocaleDateString("en-GB", { day: "numeric", month: "short" });
const addDays = (d, k) => { const x = new Date(d + "T12:00:00"); x.setDate(x.getDate() + k); return x.toISOString().slice(0, 10); };
const qs = new URLSearchParams(location.search);
const prod = id => DB.products.find(p => p.id === +id);
const brands = () => [...new Set(DB.products.map(p => p.brand))].sort();
const ptypes = () => [...new Set(DB.products.map(p => p.type))].sort();
const periodText = (f, t) => (f === t ? fmtDate(f) : fmtDate(f) + " – " + fmtDate(t));

const ICON = {
  dash: '<path d="M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z"/>',
  bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
  plus: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/>',
  folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  sliders: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
  box: '<path d="M21 8 12 3 3 8l9 5 9-5zM3 8v8l9 5 9-5V8M12 13v8"/>',
  truck: '<path d="M1 4h14v12H1zM15 9h4l3 3v4h-7"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>',
  cart: '<path d="M6 6h15l-1.5 9h-12zM6 6 5 3H2"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/>',
  print: '<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
  trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  back: '<path d="M19 12H5M11 18l-6-6 6-6"/>',
  save: '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/>',
};
const icon = k => `<svg viewBox="0 0 24 24" aria-hidden="true">${ICON[k]}</svg>`;

// ------------------------------------------------------------------ state (localStorage, safe)
function readStore() { try { const s = JSON.parse(localStorage.getItem(STORE_KEY)); return s && s.rule ? s : null; } catch (e) { return null; } }
function persist() { try { localStorage.setItem(STORE_KEY, JSON.stringify(S)); } catch (e) {} }
// ------------------------------------------------------------------ stock movements (from Modules 2 and 3)
const MV = [];
DB.deliveries.forEach(d => d.items.forEach(i => MV.push({ date: d.date, loc: d.loc || "PHG", productId: i.productId, kind: "Received", deliveryId: d.id, ref: d.invoice + " · " + d.supplier, inQty: i.qty, outQty: 0 })));
DB.sales.forEach(s => MV.push({ date: s.date, loc: s.loc || "PHG", productId: s.productId, kind: "Sold", ref: "Daily sales", inQty: 0, outQty: s.qty }));
DB.adjustments.forEach(a => MV.push({ date: a.date, loc: a.loc || "PHG", productId: a.productId, kind: a.type === "expired" ? "Expired" : "Damaged", ref: a.note, inQty: 0, outQty: a.qty }));
const ORDER = { Received: 0, Sold: 1, Damaged: 2, Expired: 3 };
MV.sort((a, b) => a.date.localeCompare(b.date) || ORDER[a.kind] - ORDER[b.kind]);

function summary(p, from, to, locs) {
  let opening = locs.reduce((a, l) => a + openOf(p, l), 0), received = 0, sold = 0, damaged = 0;
  for (const m of MV) {
    if (m.productId !== p.id || m.date > to || !locs.includes(m.loc)) continue;
    if (m.date < from) opening += m.inQty - m.outQty;
    else { received += m.inQty; if (m.kind === "Sold") sold += m.outQty; else damaged += m.outQty; }
  }
  const balance = opening + received - sold - damaged;
  return { opening, received, sold, damaged, balance, litres: balance * p.packL, value: balance * p.cost };
}

// ------------------------------------------------------------------ alert logic
const alertLevel = p => (S.prodRule[p.id]?.level ?? null) === null ? p.minStock : S.prodRule[p.id].level;
const triggerLevel = p => (S.rule.mode === "buffer" ? Math.ceil(alertLevel(p) * (1 + S.rule.buffer / 100)) : alertLevel(p));
function status(p, bal) {
  if (bal <= 0) return "out";
  if (S.prodRule[p.id] && !S.prodRule[p.id].enabled) return "off";
  return bal <= triggerLevel(p) ? "low" : "ok";
}
const PILL = { ok: '<span class="pill ok">In stock</span>', low: '<span class="pill low">Low stock</span>', out: '<span class="pill out">Out of stock</span>', off: '<span class="pill off">Alerts off</span>' };
const STATUS_LABEL = { "": "All stock status", flagged: "Needs re-order", low: "Low stock", out: "Out of stock", ok: "In stock" };
const matchStatus = (st, f) => !f || (f === "flagged" ? st === "low" || st === "out" : st === f);

// one row per product per location: each station re-orders its own stock
function alertRows() {
  const from = addDays(TODAY, -(S.rule.window - 1));
  return locsFor(VIEW).flatMap(loc => DB.products.map(p => {
    const s = summary(p, OPEN, TODAY, [loc]);
    const soldWin = MV.filter(m => m.productId === p.id && m.loc === loc && m.kind === "Sold" && m.date >= from && m.date <= TODAY).reduce((a, m) => a + m.outQty, 0);
    const avg = soldWin / S.rule.window, st = status(p, s.balance);
    const suggest = st === "low" || st === "out" ? Math.max(Math.ceil(alertLevel(p) * S.rule.mult) - s.balance, 0) : 0;
    return { p, loc, bal: s.balance, value: s.value, litres: s.litres, avg, days: avg > 0 ? s.balance / avg : null, st, suggest };
  })).sort((a, b) => ({ out: 0, low: 1, ok: 2, off: 3 }[a.st] - { out: 0, low: 1, ok: 2, off: 3 }[b.st]) || a.p.sku.localeCompare(b.p.sku) || a.loc.localeCompare(b.loc));
}
const flagged = () => alertRows().filter(r => r.st === "low" || r.st === "out");

// ------------------------------------------------------------------ reports
const TITLES = { daily: "Daily stock report", monthly: "Monthly stock report", range: "Stock report (date range)", product: "Per-product stock report" };
const TYPE_LABEL = { daily: "Daily", monthly: "Monthly", range: "Date range", product: "Per product" };
function periodOf(p) {
  if (p.type === "daily") return [p.date, p.date];
  if (p.type === "monthly") {
    const last = new Date(+p.month.slice(0, 4), +p.month.slice(5, 7), 0).getDate();
    let f = p.month + "-01", t = p.month + "-" + String(last).padStart(2, "0");
    return [f < OPEN ? OPEN : f, t > TODAY ? TODAY : t];
  }
  return [p.from, p.to];
}

function buildReport(params) {
  const P = { type: params.type, date: params.date || "", month: params.month || "", from: params.from || "", to: params.to || "",
              brand: params.brand || "", ptype: params.ptype || "", status: params.status || "", productId: params.productId ? +params.productId : null,
              loc: params.loc || "" };
  if (P.type === "product") { P.brand = ""; P.ptype = ""; }
  const [from, to] = periodOf(P);
  const locs = locsFor(P.loc);
  const list = DB.products.filter(p => P.productId ? p.id === P.productId : (!P.brand || p.brand === P.brand) && (!P.ptype || p.type === P.ptype));
  // one row per product per location
  const rows = locs.flatMap(loc => list.map(p => {
    const s = summary(p, from, to, [loc]);
    return { pid: p.id, loc, sku: p.sku, name: p.name, grade: p.grade, brand: p.brand, type: p.type, packL: p.packL, cost: p.cost, price: p.price,
             ...s, salesValue: s.sold * p.price, status: status(p, s.balance) };
  })).filter(r => matchStatus(r.status, P.status));
  const keys = new Set(rows.map(r => r.loc + ":" + r.pid));
  const mv = MV.filter(m => keys.has(m.loc + ":" + m.productId) && m.date >= from && m.date <= to);
  const sum = {
    skus: new Set(rows.map(r => r.pid)).size, brands: new Set(rows.map(r => r.brand)).size,
    deliveries: new Set(mv.filter(m => m.kind === "Received").map(m => m.deliveryId)).size,
    received: 0, receivedCost: 0, sold: 0, salesValue: 0, damaged: 0, damagedLoss: 0,
    opening: 0, balance: 0, litres: 0, value: 0,
    low: rows.filter(r => r.status === "low").length, out: rows.filter(r => r.status === "out").length,
  };
  rows.forEach(r => {
    sum.received += r.received; sum.receivedCost += r.received * r.cost; sum.sold += r.sold; sum.salesValue += r.salesValue;
    sum.damaged += r.damaged; sum.damagedLoss += r.damaged * r.cost; sum.opening += r.opening; sum.balance += r.balance; sum.litres += r.litres; sum.value += r.value;
  });
  const series = [];
  for (let d = from; d <= to; d = addDays(d, 1)) series.push({ label: d, v: mv.filter(m => m.date === d && m.kind === "Sold").reduce((a, m) => a + m.outQty, 0) });
  const byBrand = {};
  rows.forEach(r => (byBrand[r.brand] = (byBrand[r.brand] || 0) + r.value));
  let ledger = [];
  if (P.productId && rows.length) {
    let bal = rows.reduce((a, r) => a + r.opening, 0);
    ledger = mv.map(m => { bal += m.inQty - m.outQty; return { date: m.date, loc: m.loc, kind: m.kind, ref: m.ref, inQty: m.inQty, outQty: m.outQty, balance: bal }; });
  }
  const p = P.productId ? prod(P.productId) : null;
  const filters = [TYPE_LABEL[P.type], locLabel(P.loc), p ? `${p.sku} ${p.name} ${p.grade}` : (P.brand || "All brands"), ...(p ? [] : [P.ptype || "All product types"]), STATUS_LABEL[P.status]];
  return { id: null, params: P, loc: P.loc, type: P.type, title: TITLES[P.type], from, to, rows, sum, series,
           byBrand: Object.entries(byBrand).sort((a, b) => b[1] - a[1]), ledger, filters, rule: { ...S.rule } };
}

function validate(P) {
  if (!TITLES[P.type]) return "Please choose a report type.";
  if (P.type === "daily") {
    if (!P.date) return "Please choose a date.";
    if (P.date < OPEN) return `No stock data before ${fmtDate(OPEN)}.`;
    if (P.date > TODAY) return "The date cannot be in the future.";
  } else if (P.type === "monthly") {
    if (!P.month) return "Please choose a month.";
    if (P.month < OPEN.slice(0, 7)) return `No stock data before ${fmtDate(OPEN)}.`;
    if (P.month > TODAY.slice(0, 7)) return "The month cannot be in the future.";
  } else {
    if (P.type === "product" && !P.productId) return "Please choose a product.";
    if (!P.from || !P.to) return "Please choose both From and To dates.";
    if (P.from > P.to) return "From date must be on or before To date.";
    if (P.from < OPEN) return `No stock data before ${fmtDate(OPEN)}.`;
    if (P.to > TODAY) return "To date cannot be in the future.";
  }
  return null;
}

function saveReport(r, silent) {
  const copy = JSON.parse(JSON.stringify(r));
  copy.id = "RPT-" + String(S.nextNo++).padStart(4, "0");
  const now = new Date();
  copy.generatedAt = TODAY + " " + now.toTimeString().slice(0, 5);
  S.saved.unshift(copy);
  if (!silent) persist();
  return copy;
}
// staff only see reports of their own location; admin sees the location picked in the location bar
const visible = r => LS.isAdmin ? (!VIEW || r.loc === VIEW) : r.loc === LS.myLoc;
const visibleReports = () => S.saved.filter(visible);
const findReport = id => S.saved.find(r => r.id === id && (LS.isAdmin || r.loc === LS.myLoc));
const genText = at => fmtDate(at.slice(0, 10)) + ", " + at.slice(11, 16);

function exportCSV(r) {
  const head = ["Location", "SKU", "Product", "Grade", "Brand", "Type", "Pack (L)", "Opening", "Received", "Sold", "Damaged/expired", "Balance", "Litres", "Stock value (RM)", "Sales value (RM)", "Status"];
  const lines = [head, ...r.rows.map(x => [LS.locName(x.loc), x.sku, x.name, x.grade, x.brand, x.type, x.packL, x.opening, x.received, x.sold, x.damaged, x.balance, x.litres, x.value.toFixed(2), x.salesValue.toFixed(2), x.status])];
  const csv = lines.map(l => l.map(v => /[",\n]/.test(String(v)) ? `"${String(v).replace(/"/g, '""')}"` : v).join(",")).join("\n");
  const a = document.createElement("a");
  a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
  a.download = (r.id || "report-preview") + "_" + r.from + "_" + r.to + ".csv";
  document.body.appendChild(a); a.click(); a.remove();
}

// ------------------------------------------------------------------ charts
function barChart(series) {
  const W = 560, H = 220, L = 38, R = 8, T = 14, B = 26;
  const max = Math.max(1, ...series.map(s => s.v));
  const step = max <= 5 ? 1 : max <= 10 ? 2 : max <= 25 ? 5 : 10;
  const top = Math.ceil(max / step) * step;
  const slot = (W - L - R) / series.length, bw = Math.min(22, slot * 0.58);
  const y = v => T + (H - T - B) * (1 - v / top);
  let g = `<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--chart)"/><stop offset="100%" stop-color="var(--chart)" stop-opacity=".72"/></linearGradient></defs>`;
  for (let v = 0; v <= top; v += step) g += `<line class="grid" x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}"/><text x="${L - 8}" y="${y(v) + 4}" text-anchor="end">${v}</text>`;
  const every = Math.ceil(series.length / 10);
  series.forEach((s, i) => {
    const x = L + slot * i + (slot - bw) / 2, yy = y(s.v), h = y(0) - yy, r = Math.min(4, h);
    const path = h > 0 ? `M${x},${y(0)} V${yy + r} Q${x},${yy} ${x + r},${yy} H${x + bw - r} Q${x + bw},${yy} ${x + bw},${yy + r} V${y(0)} Z` : "";
    g += `<g data-tip="${esc(fmtShort(s.label))}: ${s.v} unit${s.v === 1 ? "" : "s"} sold"><rect x="${L + slot * i}" y="${T}" width="${slot}" height="${H - T - B}" fill="transparent"/>${path ? `<path class="bar" d="${path}" fill="url(#bg)"/>` : ""}</g>`;
    if (i % every === 0) g += `<text x="${x + bw / 2}" y="${H - 8}" text-anchor="middle">${esc(fmtShort(s.label))}</text>`;
  });
  return `<div class="chart"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Units sold per day">${g}</svg><div class="tip"></div></div>`;
}
function hbars(items, fmt) {
  if (!items.length) return `<div class="empty">No data for this filter.</div>`;
  const max = Math.max(1, ...items.map(i => i[1]));
  return items.map(([k, v], i) => `<div class="hbar"><span>${esc(k)}</span><div class="track"><div class="fill" style="width:${(v / max) * 100}%;transition-delay:${i * 60}ms"></div></div><span class="v">${fmt(v)}</span></div>`).join("");
}
function wireTips(root) {
  $$(".chart", root).forEach(c => {
    const tip = $(".tip", c);
    c.addEventListener("mousemove", e => {
      const g = e.target.closest("[data-tip]");
      if (!g) { tip.classList.remove("show"); return; }
      const box = c.getBoundingClientRect();
      tip.textContent = g.dataset.tip; tip.style.left = e.clientX - box.left + "px"; tip.style.top = e.clientY - box.top + "px"; tip.classList.add("show");
    });
    c.addEventListener("mouseleave", () => tip.classList.remove("show"));
  });
}

// ------------------------------------------------------------------ shared report body
function moduleSummary(s) {
  return `<div class="modsum">
    <div class="card"><h3><span class="mod-tag">M1</span>Products</h3><dl>
      <dt>Products (SKUs)</dt><dd>${n0(s.skus)}</dd><dt>Brands</dt><dd>${n0(s.brands)}</dd><dt>Opening stock</dt><dd>${n0(s.opening)} units</dd></dl></div>
    <div class="card"><h3><span class="mod-tag">M2</span>Stock receiving</h3><dl>
      <dt>Deliveries</dt><dd>${n0(s.deliveries)}</dd><dt>Units received</dt><dd>${n0(s.received)}</dd><dt>Value received</dt><dd>RM ${rm(s.receivedCost)}</dd></dl></div>
    <div class="card"><h3><span class="mod-tag">M3</span>Sales & adjustment</h3><dl>
      <dt>Units sold</dt><dd>${n0(s.sold)}</dd><dt>Sales value</dt><dd>RM ${rm(s.salesValue)}</dd><dt>Damaged/expired</dt><dd>${n0(s.damaged)} units</dd><dt>Loss value</dt><dd>RM ${rm(s.damagedLoss)}</dd></dl></div>
    <div class="card"><h3><span class="mod-tag">M4</span>Stock & alerts</h3><dl>
      <dt>Closing balance</dt><dd>${n0(s.balance)} units</dd><dt>Total litres</dt><dd>${lit(s.litres)} L</dd><dt>Stock value</dt><dd>RM ${rm(s.value)}</dd><dt>Low / out</dt><dd>${s.low} / ${s.out}</dd></dl></div>
  </div>`;
}

function reportBody(r) {
  const multiDay = r.from !== r.to;
  const soldBy = r.rows.filter(x => x.sold > 0).sort((a, b) => b.sold - a.sold).slice(0, 6).map(x => [x.sku, x.sold]);
  let h = `<div class="section-title"><h2>Summary by module</h2><p>${periodText(r.from, r.to)}</p></div>${moduleSummary(r.sum)}
  <div class="grid-2" style="margin-top:20px">
    <div class="card"><div class="card-head"><div><h3>${multiDay ? "Units sold per day" : "Units sold by product"}</h3><p>${multiDay ? "All products in this report" : "Top products on this day"}</p></div></div>
      <div class="card-body">${multiDay ? barChart(r.series) : hbars(soldBy, v => n0(v) + " units")}</div></div>
    <div class="card"><div class="card-head"><div><h3>Closing stock value by brand</h3><p>Balance × cost price</p></div></div>
      <div class="card-body">${hbars(r.byBrand, v => "RM " + rm(v))}</div></div>
  </div>
  <div class="card" style="margin-top:20px">
    <div class="card-head"><div><h3>Stock details</h3><p>${r.sum.skus} product${r.sum.skus === 1 ? "" : "s"}${r.loc ? "" : " at every location"} · status at end of period</p></div></div>
    <div class="toolbar no-print">
      <input class="grow" data-rq placeholder="Search in this report (SKU, brand, product)" aria-label="Search in this report">
      <div class="chips" role="group" aria-label="Status">${["", "flagged", "ok"].map(k => `<button type="button" class="chipbtn" data-rs="${k}" aria-pressed="${k === "" ? "true" : "false"}">${k === "" ? "All" : STATUS_LABEL[k]}</button>`).join("")}</div>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>SKU</th><th>Product</th><th class="loccol">Location</th><th class="num">Opening</th><th class="num">Received</th><th class="num">Sold</th><th class="num" title="Damaged or expired">Dmg/exp</th><th class="num">Balance</th><th class="num">Litres</th><th class="num">Value (RM)</th><th>Status</th></tr></thead>
      <tbody data-rbody>${r.rows.map(x => `<tr data-text="${esc((x.sku + " " + x.brand + " " + x.name + " " + x.grade + " " + locShort(x.loc)).toLowerCase())}" data-st="${x.status}">
        <td>${esc(x.sku)}</td><td><span class="pname">${esc(x.name)}</span><span class="sub">${esc(x.brand)} · ${esc(x.grade)} · ${pack(x.packL)}</span></td><td class="loccol">${esc(locShort(x.loc))}</td>
        <td class="num">${n0(x.opening)}</td><td class="num">${n0(x.received)}</td><td class="num">${n0(x.sold)}</td><td class="num">${n0(x.damaged)}</td>
        <td class="num"><b>${n0(x.balance)}</b></td><td class="num">${lit(x.litres)}</td><td class="num">${rm(x.value)}</td><td>${PILL[x.status]}</td></tr>`).join("")
        || `<tr><td colspan="11"><div class="empty"><b>No products match</b>Try adjusting the filters above.</div></td></tr>`}</tbody>
      <tfoot><tr><td colspan="2">Total</td><td class="loccol"></td><td class="num">${n0(r.sum.opening)}</td><td class="num">${n0(r.sum.received)}</td><td class="num">${n0(r.sum.sold)}</td><td class="num">${n0(r.sum.damaged)}</td><td class="num">${n0(r.sum.balance)}</td><td class="num">${lit(r.sum.litres)}</td><td class="num">${rm(r.sum.value)}</td><td></td></tr></tfoot>
    </table></div>
    <p class="note">Balance = opening + received − sold − damaged/expired. Value = balance × cost price. Status uses the alert rule in place when the report was generated.</p>
  </div>`;
  if (r.type === "product") {
    h += `<div class="card" style="margin-top:20px"><div class="card-head"><div><h3>Stock movements</h3><p>Every delivery, sale and adjustment for this product</p></div></div>
      <div class="table-wrap"><table><thead><tr><th>Date</th><th class="loccol">Location</th><th>Movement</th><th>Reference</th><th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead><tbody>
      <tr><td>${fmtDate(r.from)}</td><td class="loccol"></td><td colspan="4"><i>Opening balance</i></td><td class="num">${n0(r.rows.reduce((a, x) => a + x.opening, 0))}</td></tr>
      ${r.ledger.map(m => `<tr><td>${fmtDate(m.date)}</td><td class="loccol">${esc(locShort(m.loc))}</td><td>${m.kind}</td><td>${esc(m.ref)}</td><td class="num">${m.inQty || ""}</td><td class="num">${m.outQty || ""}</td><td class="num">${n0(m.balance)}</td></tr>`).join("")
        || `<tr><td colspan="7"><div class="empty">No movements in this period.</div></td></tr>`}
      </tbody></table></div></div>`;
  }
  return h;
}
function wireReportBody(root) {
  wireTips(root);
  const q = $("[data-rq]", root); if (!q) return;
  let st = "";
  const apply = () => { const t = q.value.trim().toLowerCase(); $$("[data-rbody] tr[data-st]", root).forEach(tr => tr.classList.toggle("hidden", !(tr.dataset.text.includes(t) && matchStatus(tr.dataset.st, st)))); };
  q.addEventListener("input", apply);
  $$("[data-rs]", root).forEach(b => b.addEventListener("click", () => { st = b.dataset.rs; $$("[data-rs]", root).forEach(x => x.setAttribute("aria-pressed", x === b)); apply(); }));
}

// ------------------------------------------------------------------ layout
function layout(page, head, body) {
  document.body.innerHTML = `
  <header class="topbar">
    <div class="hin">
      <img src="assets/logo.png" alt="BURAQOIL logo">
      <div><div class="t1">Lubricant Stock Management System</div><div class="t2">IPTB Sdn Bhd</div></div>
    </div>
  </header>
  <main class="content" id="main"><div class="container">
    ${head.crumbs ? `<div class="crumbs">${head.crumbs}</div>` : ""}
    <div class="page-head"><div><h1>${head.title}</h1>${head.desc ? `<p class="desc">${head.desc}</p>` : ""}${head.extra || ""}${head.noLoc ? "" : locBar()}</div>
    ${head.actions ? `<div class="page-actions">${head.actions}</div>` : ""}</div>
    ${flashHTML()}${body}
  </div></main>
  <div class="modal-bg" id="confirmBg" role="dialog" aria-modal="true" aria-labelledby="confirmTitle"><div class="modal">
    <h3 id="confirmTitle"></h3><p id="confirmText"></p>
    <div class="actions"><button class="btn" id="confirmNo">Cancel</button><button class="btn primary" id="confirmYes">Confirm</button></div></div></div>
  <div class="toast" id="toast" role="status"></div>`;
  $("#confirmNo").onclick = closeConfirm;
  document.addEventListener("keydown", e => { if (e.key === "Escape") closeConfirm(); });
  $("#m4loc")?.addEventListener("change", e => { try { sessionStorage.setItem("m4loc", e.target.value); } catch (x) {} location.reload(); });

  if (!document.getElementById("lsms-sidebar")) {
    var s = document.createElement("script");
    s.src = "../sidebar.js";
    document.body.appendChild(s);
  }
}
// admin: pick the location shown on Overview, Alerts and Saved reports; staff: their fixed location
function locBar() {
  if (!LS.isAdmin) return `<div class="locbar">Location <b>${esc(LS.locName(LS.myLoc))}</b></div>`;
  return `<label class="locbar">Location<select id="m4loc">${opt("", "All locations", VIEW)}${LS.LOCATIONS.map(l => opt(l.id, l.name, VIEW)).join("")}</select></label>`;
}
const crumbs = (...parts) => parts.map(([t, h]) => (h ? `<a href="${h}">${t}</a>` : `<span>${t}</span>`)).join(" / ");
function flashHTML() {
  let f = null; try { f = sessionStorage.getItem("m4flash"); sessionStorage.removeItem("m4flash"); } catch (e) {}
  return f ? `<div class="flash ok" role="status">✓ ${esc(f)}</div>` : "";
}
function setFlash(m) { try { sessionStorage.setItem("m4flash", m); } catch (e) {} }
function toast(m) { const t = $("#toast"); t.textContent = m; t.classList.add("show"); clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove("show"), 2400); }
function confirmBox(title, text, yes, fn) {
  $("#confirmTitle").textContent = title; $("#confirmText").textContent = text; $("#confirmYes").textContent = yes;
  $("#confirmYes").onclick = () => { closeConfirm(); fn(); };
  $("#confirmBg").classList.add("show"); $("#confirmNo").focus();
}
function closeConfirm() { $("#confirmBg")?.classList.remove("show"); }
const opt = (v, l, sel) => `<option value="${esc(v)}" ${String(v) === String(sel) ? "selected" : ""}>${esc(l)}</option>`;
const productOptions = sel => DB.products.map(p => opt(p.id, `${p.sku} · ${p.name} ${p.grade}`, sel)).join("");

let S = readStore();
if (!S) {
  S = { rule: { ...DEFAULT_RULE }, prodRule: {}, saved: [], nextNo: 1 };
  DB.products.forEach(p => (S.prodRule[p.id] = { level: null, enabled: true }));
  [[{ type: "monthly", month: "2026-09" }, "2026-10-01 09:15"],
   [{ type: "monthly", month: "2026-09", loc: "KTN" }, "2026-10-01 09:30"],
   [{ type: "product", productId: 4, from: "2026-09-01", to: "2026-10-05", loc: "PHG" }, "2026-10-05 08:40"],
   [{ type: "daily", date: "2026-10-04", status: "flagged", loc: "PHG" }, "2026-10-05 08:55"],
   [{ type: "daily", date: "2026-10-05", status: "flagged", loc: "KTN" }, "2026-10-05 09:10"]]
    .forEach(([p, at]) => { const r = saveReport(buildReport(p), true); r.generatedAt = at; });
  persist();
}

// ================================================================== PAGES
const Pages = {};

// ---------------- Dashboard ----------------
Pages.dashboard = () => {
  const month = TODAY.slice(0, 7);
  const mtd = buildReport({ type: "monthly", month, loc: VIEW });
  const fl = flagged();
  const last14 = buildReport({ type: "range", from: addDays(TODAY, -13), to: TODAY, loc: VIEW });
  const where = r => (VIEW ? "" : " · " + locShort(r.loc));
  layout("dashboard", {
    crumbs: "Home", title: "Overview",
    desc: `Month-to-date stock position and alert status at ${esc(VIEW ? locLabel(VIEW) : "all locations")} (${periodText(mtd.from, mtd.to)}).`,
    actions: `<a class="btn" href="alerts.html">${icon("bell")}View alerts</a><a class="btn primary" href="generate.html">${icon("plus")}Generate report</a>`,
  }, `
  <div class="stack">
    ${fl.length ? `<div class="banner" role="alert"><span class="ico">!</span><div class="grow"><strong>${fl.length} product${fl.length > 1 ? "s need" : " needs"} re-ordering</strong>
      <span class="meta">${fl.slice(0, 4).map(r => esc(r.p.sku + " " + r.p.name + (VIEW ? "" : " (" + locShort(r.loc) + ")"))).join(" · ")}${fl.length > 4 ? " and " + (fl.length - 4) + " more" : ""}</span></div>
      <a class="btn sm" href="alerts.html">Review alerts ${icon("arrow")}</a></div>`
      : `<div class="banner ok"><span class="ico">✓</span><div><strong>All products are above their alert level</strong></div></div>`}
    ${moduleSummary(mtd.sum)}
    <div class="grid-3-1">
      <div class="card"><div class="card-head"><div><h3>Units sold per day</h3><p>Last 14 days · all products</p></div><a class="link" href="generate.html?type=range&from=${addDays(TODAY, -13)}&to=${TODAY}&loc=${VIEW}&run=1">Open as report</a></div>
        <div class="card-body">${barChart(last14.series)}</div></div>
      <div class="card"><div class="card-head"><div><h3>Needs re-order</h3><p>Lowest stock first</p></div><a class="link" href="alerts.html">View all</a></div>
        <ul class="list">${fl.slice(0, 5).map(r => `<li><div class="grow"><span class="pname">${esc(r.p.name)}</span><span class="sub">${esc(r.p.sku)} · ${esc(r.p.grade)} · ${n0(r.bal)} left${esc(where(r))}</span></div>${PILL[r.st]}</li>`).join("") || `<li><div class="empty">Nothing to re-order.</div></li>`}</ul></div>
    </div>
    <div class="card"><div class="card-head"><div><h3>Recent reports</h3><p>Latest saved reports</p></div><a class="link" href="reports.html">View all</a></div>
      <ul class="list">${visibleReports().slice(0, 4).map(r => `<li><div class="grow"><span class="pname">${esc(r.title)}</span><span class="sub">${esc(r.id)} · ${periodText(r.from, r.to)} · ${esc(r.filters.slice(1).join(" · "))}</span></div><a class="btn sm" href="report.html?id=${r.id}">Open</a></li>`).join("")
        || `<li><div class="empty"><b>No reports yet</b><a class="btn primary sm" href="generate.html">Generate report</a></div></li>`}</ul></div>
  </div>`);
  wireTips(document);
};

// ---------------- Low-stock alerts ----------------
Pages.alerts = () => {
  const rows = alertRows();
  const low = rows.filter(r => r.st === "low").length, out = rows.filter(r => r.st === "out").length;
  const cost = rows.reduce((a, r) => a + r.suggest * r.p.cost, 0);
  layout("alerts", {
    crumbs: crumbs(["Home", "index.html"], ["Low-stock alerts"]), title: "Low-stock alerts",
    desc: "Products at or below their alert level. Flagged items need to be re-ordered from the supplier.",
    actions: `<a class="btn" href="settings.html">${icon("sliders")}Alert settings</a><a class="btn primary" href="generate.html?type=daily&date=${TODAY}&status=flagged&loc=${VIEW}&run=1">${icon("plus")}Generate re-order report</a>`,
  }, `
  <div class="stack">
    <details class="help card card-pad" open>
      <summary><span class="banner info" style="padding:0;border:0;background:none"><span class="ico">i</span></span>How low-stock alerts work</summary>
      <div class="how">
        <div><span class="n">1</span><b>Stock changes</b>Deliveries (M2), sales and damaged or expired items (M3) update each product's balance.</div>
        <div><span class="n">2</span><b>System checks</b>The balance is compared with the alert level: the min stock from M1, or a custom level in Alert settings.</div>
        <div><span class="n">3</span><b>Product is flagged</b>At or below the level → <i>Low stock</i>. Zero → <i>Out of stock</i>. The red badge in the menu shows the count.</div>
        <div><span class="n">4</span><b>Staff re-order</b>Generate a re-order report, order from the supplier. The alert clears by itself when the delivery is recorded.</div>
      </div>
    </details>
    <div class="kpis">
      <div class="kpi"><b>${rows.length}</b><span>Products checked</span></div>
      <div class="kpi ${low + out ? "alert" : ""}"><b>${low + out}</b><span>Need re-order</span></div>
      <div class="kpi ${low ? "alert" : ""}"><b>${low}</b><span>Low stock</span></div>
      <div class="kpi ${out ? "alert" : ""}"><b>${out}</b><span>Out of stock</span></div>
      <div class="kpi"><b>RM ${rm(cost)}</b><span>Estimated re-order cost</span></div>
    </div>
    <div class="card">
      <div class="toolbar">
        <label class="field grow">Search<input id="aQ" placeholder="SKU, brand, product or grade"></label>
        <label class="field">Brand<select id="aBrand">${opt("", "All brands", "")}${brands().map(b => opt(b, b, "")).join("")}</select></label>
        <label class="field">Product type<select id="aType">${opt("", "All types", "")}${ptypes().map(b => opt(b, b, "")).join("")}</select></label>
        <div class="field">Stock status<div class="chips" role="group" aria-label="Stock status">${["", "flagged", "out", "ok"].map(k => `<button type="button" class="chipbtn" data-st="${k}" aria-pressed="${k === (qs.get("status") || "") ? "true" : "false"}">${k ? STATUS_LABEL[k] : "All"}</button>`).join("")}</div></div>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>SKU</th><th>Product</th><th class="loccol">Location</th><th class="num">In stock</th><th class="num">Alert level</th><th class="num">Avg sold/day</th><th class="num">Days left</th><th class="num">Re-order qty</th><th>Status</th><th></th></tr></thead>
        <tbody id="aRows"></tbody></table></div>
      <p class="note">Stock as at ${fmtDate(TODAY)}. Avg sold/day uses the last ${S.rule.window} days. Days left = current stock ÷ avg sold/day. Re-order qty tops stock up to ${S.rule.mult} × alert level.</p>
    </div>
  </div>`);
  let st = qs.get("status") || "";
  const draw = () => {
    const q = $("#aQ").value.trim().toLowerCase(), b = $("#aBrand").value, t = $("#aType").value;
    const shown = rows.filter(r => (!q || (r.p.sku + " " + r.p.brand + " " + r.p.name + " " + r.p.grade + " " + locShort(r.loc)).toLowerCase().includes(q)) && (!b || r.p.brand === b) && (!t || r.p.type === t) && matchStatus(r.st, st));
    $("#aRows").innerHTML = shown.map(r => `<tr class="${r.st === "out" ? "flag-out" : r.st === "low" ? "flag-low" : ""}">
      <td>${esc(r.p.sku)}</td><td><span class="pname">${esc(r.p.name)}</span><span class="sub">${esc(r.p.brand)} · ${esc(r.p.type)} · ${esc(r.p.grade)} · ${pack(r.p.packL)}</span></td>
      <td class="loccol">${esc(locShort(r.loc))}</td><td class="num"><b>${n0(r.bal)}</b></td>
      <td class="num">${triggerLevel(r.p)}${S.rule.mode === "buffer" ? `<span class="sub">${alertLevel(r.p)} + ${S.rule.buffer}%</span>` : ""}</td>
      <td class="num">${r.avg.toFixed(1)}</td><td class="num">${r.bal <= 0 ? "0" : r.days === null ? "–" : r.days < 1 ? "&lt; 1" : Math.floor(r.days)}</td>
      <td class="num">${r.suggest ? n0(r.suggest) : "–"}</td><td>${PILL[r.st]}</td>
      <td><a class="link" href="generate.html?type=product&productId=${r.p.id}&from=${OPEN}&to=${TODAY}&loc=${r.loc}&run=1">History</a></td></tr>`).join("")
      || `<tr><td colspan="10"><div class="empty"><b>No products match</b>Clear the search or choose another status.</div></td></tr>`;
  };
  ["#aQ", "#aBrand", "#aType"].forEach(s => $(s).addEventListener("input", draw));
  $$("[data-st]").forEach(b => b.addEventListener("click", () => { st = b.dataset.st; $$("[data-st]").forEach(x => x.setAttribute("aria-pressed", x === b)); draw(); }));
  draw();
};

// ---------------- Generate report ----------------
Pages.generate = () => {
  const P0 = { type: qs.get("type") || "monthly", date: qs.get("date") || TODAY, month: qs.get("month") || TODAY.slice(0, 7),
    from: qs.get("from") || TODAY.slice(0, 8) + "01", to: qs.get("to") || TODAY, brand: qs.get("brand") || "", ptype: qs.get("ptype") || "",
    status: qs.get("status") || "", productId: qs.get("productId") || "",
    loc: LS.isAdmin ? (qs.has("loc") ? qs.get("loc") : VIEW) : LS.myLoc };
  const types = [["daily", "Daily", "Stock for one day"], ["monthly", "Monthly", "Whole month, up to today"], ["range", "Date range", "Any period you choose"], ["product", "Per product", "One product with every movement"]];
  layout("generate", {
    crumbs: crumbs(["Home", "index.html"], ["Reports", "reports.html"], ["Generate report"]), title: "Generate report",
    desc: `Select report type, period and filters. Preview before saving. Data available from ${fmtDate(OPEN)} to ${fmtDate(TODAY)}.`, noLoc: true,
  }, `
  <ol class="steps" id="steps"><li class="done">Report type</li><li class="current">Period & filters</li><li>Preview & save</li></ol>
  <form class="card no-print" id="gForm" novalidate>
    <div class="form-section"><h3>1. Report type</h3><p>Select the type of report to generate.</p>
      <div class="typecards">${types.map(([v, t, d]) => `<label class="typecard"><input type="radio" name="type" value="${v}" ${P0.type === v ? "checked" : ""}><b>${t}</b><span>${d}</span></label>`).join("")}</div></div>
    <div class="form-section"><h3>2. Period</h3><p id="periodHelp"></p>
      <div class="fields">
        <label class="field" data-for="daily">Date<input type="date" name="date" value="${P0.date}" min="${OPEN}" max="${TODAY}"></label>
        <label class="field" data-for="monthly">Month<input type="month" name="month" value="${P0.month}" min="${OPEN.slice(0, 7)}" max="${TODAY.slice(0, 7)}"></label>
        <label class="field" data-for="range product">From<input type="date" name="from" value="${P0.from}" min="${OPEN}" max="${TODAY}"></label>
        <label class="field" data-for="range product">To<input type="date" name="to" value="${P0.to}" min="${OPEN}" max="${TODAY}"></label>
      </div>
      <div class="chips" style="margin-top:12px" data-for="range product" aria-label="Quick periods">
        <button type="button" class="chipbtn" data-quick="7">Last 7 days</button><button type="button" class="chipbtn" data-quick="30">Last 30 days</button><button type="button" class="chipbtn" data-quick="mtd">This month</button><button type="button" class="chipbtn" data-quick="all">All data</button>
      </div></div>
    <div class="form-section"><h3>3. Filters</h3><p>Optional — leave as "All" to include everything.</p>
      <div class="fields">
        <label class="field">Location<select name="loc" ${LS.isAdmin ? "" : "disabled"}>${LS.isAdmin ? opt("", "All locations", P0.loc) : ""}${LS.locs().map(l => opt(l.id, l.name, P0.loc)).join("")}</select></label>
        <label class="field" data-for="product">Product<select name="productId">${opt("", "Choose a product", P0.productId)}${productOptions(P0.productId)}</select></label>
        <label class="field" data-for="daily monthly range">Brand<select name="brand">${opt("", "All brands", P0.brand)}${brands().map(b => opt(b, b, P0.brand)).join("")}</select></label>
        <label class="field" data-for="daily monthly range">Product type<select name="ptype">${opt("", "All types", P0.ptype)}${ptypes().map(b => opt(b, b, P0.ptype)).join("")}</select></label>
        <label class="field" data-for="daily monthly range">Stock status (end of period)<select name="status">${Object.entries(STATUS_LABEL).map(([k, l]) => opt(k, l, P0.status)).join("")}</select></label>
      </div>
      <div class="err" id="gErr" role="alert"></div></div>
    <div class="form-foot"><a class="btn ghost" href="generate.html">Reset</a><button class="btn primary" type="submit">Preview report ${icon("arrow")}</button></div>
  </form>
  <div id="preview" style="margin-top:24px"></div>`);
  const form = $("#gForm");
  const val = n => form.elements[n].value;
  const HELP = { daily: "Pick the day. Balance is the stock at the end of that day.", monthly: "Pick the month. The current month runs up to today.", range: "Pick the start and end date.", product: "Pick the start and end date for this product's history." };
  const syncType = () => {
    const t = form.querySelector("input[name=type]:checked").value;
    $$("[data-for]", form).forEach(el => el.classList.toggle("hidden", !el.dataset.for.split(" ").includes(t)));
    $("#periodHelp").textContent = HELP[t]; $("#gErr").textContent = "";
  };
  $$("input[name=type]", form).forEach(r => r.addEventListener("change", () => { syncType(); clearPreview(); }));
  $$("[data-quick]", form).forEach(b => b.addEventListener("click", () => {
    const k = b.dataset.quick;
    form.elements.to.value = TODAY;
    form.elements.from.value = k === "mtd" ? TODAY.slice(0, 8) + "01" : k === "all" ? OPEN : (d => (d < OPEN ? OPEN : d))(addDays(TODAY, -(+k - 1)));
  }));
  const setStep = n => $$("#steps li").forEach((li, i) => { li.className = i < n ? "done" : i === n ? "current" : ""; });
  const clearPreview = () => { $("#preview").innerHTML = ""; setStep(1); };
  form.addEventListener("input", e => { if (e.target.name !== "type") { $("#gErr").textContent = ""; } });
  let current = null;
  const run = () => {
    const P = { type: form.querySelector("input[name=type]:checked").value, date: val("date"), month: val("month"), from: val("from"), to: val("to"),
                brand: val("brand"), ptype: val("ptype"), status: val("status"), productId: val("productId") ? +val("productId") : null,
                loc: LS.isAdmin ? val("loc") : LS.myLoc };
    const err = validate(P);
    if (err) { $("#gErr").textContent = "⚠ " + err; clearPreview(); return; }
    current = buildReport(P);
    setStep(2);
    $("#preview").innerHTML = `<div class="page-head" style="margin-bottom:16px"><div>
        <h2 style="font-size:22px">${esc(current.title)}<span class="preview-flag">Preview · not saved</span></h2>
        <div class="report-meta"><span><b>Period:</b> ${periodText(current.from, current.to)}</span><span><b>Location:</b> ${esc(locLabel(current.loc))}</span></div>
        <div class="filters-used">${current.filters.map(f => `<span>${esc(f)}</span>`).join("")}</div></div>
      <div class="page-actions"><button class="btn" id="pCsv">${icon("download")}Export CSV</button><button class="btn" id="pPrint">${icon("print")}Print</button><button class="btn primary" id="pSave">${icon("save")}Save report</button></div></div>
      ${reportBody(current)}`;
    wireReportBody($("#preview"));
    $("#pSave").onclick = () => { const s = saveReport(current); setFlash(`${s.id} saved successfully.`); location.href = "report.html?id=" + s.id; };
    $("#pPrint").onclick = () => window.print();
    $("#pCsv").onclick = () => exportCSV(current);
    $("#preview").scrollIntoView({ behavior: "smooth", block: "start" });
  };
  form.addEventListener("submit", e => { e.preventDefault(); run(); });
  syncType();
  if (qs.get("run")) run();
};

// ---------------- Saved reports ----------------
Pages.reports = () => {
  layout("reports", {
    crumbs: crumbs(["Home", "index.html"], ["Reports"], ["Saved reports"]), title: "Saved reports",
    desc: "View, print or export previously saved reports.",
    actions: `<a class="btn primary" href="generate.html">${icon("plus")}New report</a>`,
  }, `
  <div class="card">
    <div class="toolbar">
      <label class="field">Period from<input type="date" id="sFrom"></label>
      <label class="field">Period to<input type="date" id="sTo"></label>
      <label class="field">Type<select id="sType">${opt("", "All types", "")}${Object.entries(TYPE_LABEL).map(([k, l]) => opt(k, l, "")).join("")}</select></label>
      <label class="field">Brand<select id="sBrand">${opt("", "All brands", "")}${brands().map(b => opt(b, b, "")).join("")}</select></label>
      <label class="field">Product<select id="sProd">${opt("", "All products", "")}${productOptions("")}</select></label>
      <button class="btn ghost" id="sClear">Clear</button>
    </div>
    <div class="err" id="sErr" style="margin:12px 20px 0"></div>
    <div class="table-wrap"><table>
      <thead><tr><th>Report ID</th><th>Report</th><th>Period</th><th class="loccol">Location</th><th>Filters</th><th class="num">Closing units</th><th class="num">Stock value (RM)</th><th>Generated</th><th>Actions</th></tr></thead>
      <tbody id="sRows"></tbody></table></div>
  </div>`);
  const draw = () => {
    const f = $("#sFrom").value, t = $("#sTo").value, ty = $("#sType").value, b = $("#sBrand").value, pid = $("#sProd").value;
    $("#sErr").textContent = f && t && f > t ? "⚠ Period from must be on or before Period to." : "";
    const p = pid ? prod(pid) : null;
    const rows = visibleReports().filter(r => (!f || r.to >= f) && (!t || r.from <= t) && (!ty || r.type === ty)
      && (!b || r.rows.some(x => x.brand === b)) && (!p || r.rows.some(x => x.sku === p.sku)));
    $("#sRows").innerHTML = rows.map(r => `<tr>
      <td><a class="link" href="report.html?id=${r.id}">${esc(r.id)}</a></td><td>${esc(r.title)}</td><td>${periodText(r.from, r.to)}</td><td class="loccol">${esc(r.loc ? locShort(r.loc) : "All locations")}</td>
      <td><span class="sub" style="font-size:13px">${esc(r.filters.slice(1).join(" · "))}</span></td>
      <td class="num">${n0(r.sum.balance)}</td><td class="num">${rm(r.sum.value)}</td><td>${genText(r.generatedAt)}</td>
      <td><div class="row-actions"><a class="link" href="report.html?id=${r.id}">View</a><button class="link danger" data-del="${r.id}">Delete</button></div></td></tr>`).join("")
      || `<tr><td colspan="9"><div class="empty">${visibleReports().length ? "<b>No reports match</b>Try adjusting the filters above." : '<b>No saved reports yet</b><br><a class="btn primary" href="generate.html">Generate report</a>'}</div></td></tr>`;
    $$("[data-del]").forEach(btn => btn.onclick = () => {
      const r = findReport(btn.dataset.del);
      confirmBox("Delete report?", `${r.id} · ${r.title} (${periodText(r.from, r.to)}) will be removed. This cannot be undone.`, "Delete", () => {
        S.saved = S.saved.filter(x => x.id !== r.id); persist(); toast(r.id + " deleted"); draw();
      });
    });
  };
  ["#sFrom", "#sTo", "#sType", "#sBrand", "#sProd"].forEach(s => $(s).addEventListener("input", draw));
  $("#sClear").onclick = () => { ["#sFrom", "#sTo", "#sType", "#sBrand", "#sProd"].forEach(s => ($(s).value = "")); draw(); };
  draw();
};

// ---------------- View one report ----------------
Pages.report = () => {
  const r = findReport(qs.get("id"));
  if (!r) {
    layout("reports", { crumbs: crumbs(["Home", "index.html"], ["Saved reports", "reports.html"], ["Not found"]), title: "Report not found", noLoc: true },
      `<div class="card"><div class="empty"><b>Report not found</b>This report may have been deleted.<br><a class="btn" href="reports.html">Back to saved reports</a></div></div>`);
    return;
  }
  layout("reports", {
    crumbs: crumbs(["Home", "index.html"], ["Saved reports", "reports.html"], [r.id]), noLoc: true,
    title: `${esc(r.title)} <span class="sub" style="display:inline;font-size:16px;font-weight:600">${esc(r.id)}</span>`,
    extra: `<div class="report-meta"><span><b>Period:</b> ${periodText(r.from, r.to)}</span><span><b>Location:</b> ${esc(locLabel(r.loc))}</span><span><b>Generated:</b> ${genText(r.generatedAt)}</span><span><b>By:</b> Store manager</span></div>
            <div class="filters-used">${r.filters.map(f => `<span>${esc(f)}</span>`).join("")}</div>`,
    actions: `<a class="btn" href="reports.html">${icon("back")}Back</a><button class="btn danger" id="rDel">${icon("trash")}Delete</button><button class="btn" id="rCsv">${icon("download")}Export CSV</button><button class="btn primary" id="rPrint">${icon("print")}Print</button>`,
  }, reportBody(r));
  wireReportBody(document);
  $("#rPrint").onclick = () => window.print();
  $("#rCsv").onclick = () => exportCSV(r);
  $("#rDel").onclick = () => confirmBox("Delete report?", `${r.id} · ${r.title} will be removed. This cannot be undone.`, "Delete", () => {
    S.saved = S.saved.filter(x => x.id !== r.id); persist(); setFlash(r.id + " deleted."); location.href = "reports.html";
  });
};

// ---------------- Alert settings ----------------
Pages.settings = () => {
  const R = S.rule;
  layout("settings", {
    crumbs: crumbs(["Home", "index.html"], ["Alert settings"]), title: "Alert settings", noLoc: true,
    desc: "Configure when products are flagged for re-ordering. Changes take effect immediately.",
  }, `
  <div class="stack">
    <form class="card" id="ruleForm" novalidate>
      <div class="card-head"><div><h3>Alert rule</h3><p>Applies to every product. Out-of-stock products are always flagged.</p></div></div>
      <div class="card-body">
        <label class="radio-card"><input type="radio" name="mode" value="min" ${R.mode === "min" ? "checked" : ""}><div><b>At or below minimum stock</b><span>Flag when current stock ≤ alert level. Recommended for most products.</span></div></label>
        <label class="radio-card"><input type="radio" name="mode" value="buffer" ${R.mode === "buffer" ? "checked" : ""}><div><b>Early warning</b><span>Flag a little earlier: when current stock ≤ alert level + buffer %. Useful when suppliers take longer to deliver.</span></div></label>
        <div class="fields" style="margin-top:6px">
          <label class="field">Buffer (%)<input type="number" name="buffer" min="0" max="100" step="1" value="${R.buffer}"><small>Whole number, 0 – 100</small></label>
          <label class="field">Average sales period<select name="window">${[7, 14, 30].map(w => opt(w, "Last " + w + " days", R.window)).join("")}</select><small>For "Avg sold/day" and "Days left"</small></label>
          <label class="field">Re-order up to (× alert level)<input type="number" name="mult" min="1" max="5" step="0.5" value="${R.mult}"><small>1 – 5. Used for suggested order</small></label>
        </div>
        <div class="example" id="example"></div>
        <div class="err" id="ruleErr" role="alert"></div>
      </div>
      <div class="form-foot"><button class="btn primary" type="submit">Save alert rule</button></div>
    </form>
    <form class="card" id="prodForm" novalidate>
      <div class="card-head"><div><h3>Alert level per product</h3><p>Leave empty to use the min stock from Module 1. Give fast-selling products a higher level, or switch alerts off for products being phased out.</p></div></div>
      <div class="table-wrap"><table>
        <thead><tr><th>SKU</th><th>Product</th><th class="num">Min stock (M1)</th><th>Custom alert level</th><th>Alerts</th><th class="num">Flag at or below</th></tr></thead>
        <tbody>${DB.products.map(p => `<tr><td>${esc(p.sku)}</td><td><span class="pname">${esc(p.name)}</span><span class="sub">${esc(p.grade)} · ${pack(p.packL)}</span></td>
          <td class="num">${p.minStock}</td>
          <td><input class="settings-input" type="number" min="0" step="1" data-level="${p.id}" value="${S.prodRule[p.id]?.level ?? ""}" placeholder="${p.minStock}" aria-label="Custom alert level for ${esc(p.sku)}"></td>
          <td><label style="display:flex;gap:6px;align-items:center"><input type="checkbox" data-on="${p.id}" ${S.prodRule[p.id]?.enabled !== false ? "checked" : ""}> On</label></td>
          <td class="num">${triggerLevel(p)}</td></tr>`).join("")}</tbody></table></div>
      <div class="err" id="prodErr" role="alert" style="margin:0 20px 14px"></div>
      <div class="form-foot"><button class="btn" type="button" id="resetRules">Reset to default</button><button class="btn primary" type="submit">Save product levels</button></div>
    </form>
  </div>`);
  const f = $("#ruleForm");
  const sync = () => {
    const mode = f.querySelector("input[name=mode]:checked").value, b = Number(f.elements.buffer.value) || 0;
    f.elements.buffer.disabled = mode !== "buffer";
    const p = DB.products[0], lvl = alertLevel(p);
    $("#example").innerHTML = `<b>Example:</b> ${esc(p.sku)} ${esc(p.name)} has alert level ${lvl}. It will be flagged when stock is <b>${mode === "buffer" ? Math.ceil(lvl * (1 + b / 100)) : lvl} or below</b>${mode === "buffer" ? ` (${lvl} + ${b}%, rounded up)` : ""}.`;
  };
  f.addEventListener("input", sync); sync();
  f.addEventListener("submit", e => {
    e.preventDefault();
    const mode = f.querySelector("input[name=mode]:checked").value, braw = f.elements.buffer.value, b = Number(braw), m = Number(f.elements.mult.value);
    let err = "";
    if (mode === "buffer" && (braw === "" || !Number.isInteger(b) || b < 0 || b > 100)) err = "Buffer must be a whole number from 0 to 100.";
    else if (f.elements.mult.value === "" || isNaN(m) || m < 1 || m > 5) err = "Re-order multiplier must be between 1 and 5.";
    $("#ruleErr").textContent = err ? "⚠ " + err : "";
    if (err) return;
    S.rule = { mode, buffer: mode === "buffer" ? b : S.rule.buffer, window: +f.elements.window.value, mult: m };
    persist(); setFlash("Alert rule saved."); location.reload();
  });
  $("#prodForm").addEventListener("submit", e => {
    e.preventDefault();
    const next = {};
    for (const p of DB.products) {
      const raw = $(`[data-level="${p.id}"]`).value.trim(), n = Number(raw);
      if (raw !== "" && (!Number.isInteger(n) || n < 0 || n > 9999)) { $("#prodErr").textContent = `⚠ ${p.sku}: alert level must be a whole number from 0 to 9999.`; return; }
      next[p.id] = { level: raw === "" ? null : n, enabled: $(`[data-on="${p.id}"]`).checked };
    }
    S.prodRule = next; persist(); setFlash("Product alert levels saved."); location.reload();
  });
  $("#resetRules").onclick = () => confirmBox("Reset alert settings?", "The alert rule and all product levels will go back to default.", "Reset", () => {
    S.rule = { ...DEFAULT_RULE }; DB.products.forEach(p => (S.prodRule[p.id] = { level: null, enabled: true })); persist(); setFlash("Alert settings reset to default."); location.reload();
  });
};

// ------------------------------------------------------------------ boot
document.addEventListener("DOMContentLoaded", () => {
  document.body.classList.toggle("staff", !LS.isAdmin);   // hides the Location columns for staff
  (Pages[document.body.dataset.page] || Pages.dashboard)();
});
})();
