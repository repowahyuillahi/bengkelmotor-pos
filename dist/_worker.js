// A3 Motor Service Station - POS API (Cloudflare Pages advanced mode worker)
// Bindings: DB (D1), ASSETS (static files)
const JSON_HEADERS = { "Content-Type": "application/json; charset=utf-8" };

function json(data, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: JSON_HEADERS });
}
function fail(message, status = 400) {
  return json({ success: false, error: message }, status);
}
// WIB (Asia/Jakarta) date helpers
function wibDateString(d = new Date()) {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Jakarta", year: "numeric", month: "2-digit", day: "2-digit" }).format(d);
}
const int = (v, d = 0) => {
  const n = Math.trunc(Number(v));
  return Number.isFinite(n) ? n : d;
};

async function listItems(env) {
  const { results } = await env.DB.prepare(
    "SELECT id, name, type, sku, price, cost, unit, stock, created_at, updated_at FROM items ORDER BY type ASC, name ASC"
  ).all();
  return json({ success: true, items: results });
}

async function createItem(env, req) {
  const b = await req.json().catch(() => null);
  if (!b || !String(b.name || "").trim()) return fail("Nama wajib diisi");
  const type = b.type === "jasa" ? "jasa" : "barang";
  const now = new Date().toISOString();
  const r = await env.DB.prepare(
    "INSERT INTO items (name, type, sku, price, cost, unit, stock, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
  ).bind(
    String(b.name).trim(), type, String(b.sku || "").trim(),
    Math.max(0, int(b.price)), Math.max(0, int(b.cost)),
    String(b.unit || (type === "jasa" ? "paket" : "pcs")).trim() || "pcs",
    type === "jasa" ? 0 : Math.max(0, int(b.stock)), now, now
  ).run();
  return json({ success: true, id: r.meta.last_row_id }, 201);
}

async function updateItem(env, req, id) {
  const b = await req.json().catch(() => null);
  if (!b || !String(b.name || "").trim()) return fail("Nama wajib diisi");
  const type = b.type === "jasa" ? "jasa" : "barang";
  await env.DB.prepare(
    "UPDATE items SET name = ?, type = ?, sku = ?, price = ?, cost = ?, unit = ?, stock = ?, updated_at = ? WHERE id = ?"
  ).bind(
    String(b.name).trim(), type, String(b.sku || "").trim(),
    Math.max(0, int(b.price)), Math.max(0, int(b.cost)),
    String(b.unit || "pcs").trim() || "pcs",
    type === "jasa" ? 0 : Math.max(0, int(b.stock)), new Date().toISOString(), id
  ).run();
  return json({ success: true });
}

async function deleteItem(env, id) {
  await env.DB.prepare("DELETE FROM items WHERE id = ?").bind(id).run();
  return json({ success: true });
}

async function adjustStock(env, req, id) {
  const b = await req.json().catch(() => null);
  const qty = int(b && b.qty);
  if (!qty) return fail("Jumlah stok tidak valid");
  const item = await env.DB.prepare("SELECT id, type, stock FROM items WHERE id = ?").bind(id).first();
  if (!item) return fail("Barang tidak ditemukan", 404);
  if (item.type !== "barang") return fail("Jasa tidak memiliki stok");
  if (item.stock + qty < 0) return fail("Stok tidak boleh minus");
  await env.DB.prepare("UPDATE items SET stock = stock + ?, updated_at = ? WHERE id = ?")
    .bind(qty, new Date().toISOString(), id).run();
  return json({ success: true, stock: item.stock + qty });
}

async function listTransactions(env, url) {
  const q = (url.searchParams.get("q") || "").trim();
  let stmt;
  if (q) {
    const like = "%" + q + "%";
    stmt = env.DB.prepare(
      "SELECT * FROM transactions WHERE invoice_no LIKE ? OR customer_name LIKE ? OR plate LIKE ? OR customer_phone LIKE ? ORDER BY id DESC LIMIT 200"
    ).bind(like, like, like, like);
  } else {
    stmt = env.DB.prepare("SELECT * FROM transactions ORDER BY id DESC LIMIT 200");
  }
  const { results } = await stmt.all();
  return json({ success: true, transactions: results });
}

async function getTransaction(env, id) {
  const tx = await env.DB.prepare("SELECT * FROM transactions WHERE id = ?").bind(id).first();
  if (!tx) return fail("Transaksi tidak ditemukan", 404);
  const { results } = await env.DB.prepare(
    "SELECT * FROM transaction_items WHERE transaction_id = ? ORDER BY id ASC"
  ).bind(id).all();
  return json({ success: true, transaction: tx, items: results });
}

async function checkout(env, req) {
  const b = await req.json().catch(() => null);
  if (!b || !Array.isArray(b.lines) || b.lines.length === 0) return fail("Keranjang kosong");
  const lines = b.lines.map(l => ({ item_id: int(l.item_id), qty: Math.max(1, int(l.qty, 1)) })).filter(l => l.item_id > 0);
  if (lines.length === 0) return fail("Keranjang tidak valid");
  const ids = [...new Set(lines.map(l => l.item_id))];
  const placeholders = ids.map(() => "?").join(",");
  const { results: rows } = await env.DB.prepare(
    `SELECT id, name, type, price, stock FROM items WHERE id IN (${placeholders})`
  ).bind(...ids).all();
  const byId = new Map(rows.map(r => [r.id, r]));
  let subtotal = 0;
  const detail = [];
  for (const l of lines) {
    const item = byId.get(l.item_id);
    if (!item) return fail("Ada barang yang tidak ditemukan di katalog");
    if (item.type === "barang" && item.stock < l.qty)
      return fail(`Stok ${item.name} tidak cukup (sisa ${item.stock})`);
    const lineTotal = item.price * l.qty;
    subtotal += lineTotal;
    detail.push({ item, qty: l.qty, lineTotal });
  }
  const discount = Math.min(Math.max(0, int(b.discount)), subtotal);
  const total = subtotal - discount;
  const shift = await getOpenShift(env);
  if (!shift) return fail("Belum ada shift yang dibuka. Buka shift dulu sebelum transaksi.");
  const method = ["Tunai", "QRIS", "Transfer", "Debit"].includes(b.payment_method) ? b.payment_method : "Tunai";
  let paid = int(b.amount_paid);
  if (method !== "Tunai" && paid === 0) paid = total;
  if (paid < total) return fail("Uang diterima kurang dari total");
  const change = paid - total;
  const today = wibDateString();
  const ymd = today.replaceAll("-", "");
  const countRow = await env.DB.prepare(
    "SELECT COUNT(*) AS c FROM transactions WHERE invoice_no LIKE ?"
  ).bind(`INV/${ymd}/%`).first();
  const invoiceNo = `INV/${ymd}/${String((countRow ? countRow.c : 0) + 1).padStart(4, "0")}`;
  const now = new Date().toISOString();
  const stmts = [];
  stmts.push(env.DB.prepare(
    "INSERT INTO transactions (invoice_no, customer_name, plate, customer_phone, subtotal, discount, total, payment_method, amount_paid, change_amount, status, shift_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'selesai', ?, ?)"
  ).bind(invoiceNo, String(b.customer_name || "").trim(), String(b.plate || "").trim().toUpperCase(), String(b.customer_phone || "").trim(),
    subtotal, discount, total, method, paid, change, shift.id, now));
  for (const d of detail) {
    stmts.push(env.DB.prepare(
      "INSERT INTO transaction_items (transaction_id, item_id, item_name, item_type, price, qty, line_total) VALUES ((SELECT MAX(id) FROM transactions), ?, ?, ?, ?, ?, ?)"
    ).bind(d.item.id, d.item.name, d.item.type, d.item.price, d.qty, d.lineTotal));
    if (d.item.type === "barang") {
      stmts.push(env.DB.prepare("UPDATE items SET stock = stock - ?, updated_at = ? WHERE id = ?")
        .bind(d.qty, now, d.item.id));
    }
  }
  await env.DB.batch(stmts);
  const tx = await env.DB.prepare("SELECT * FROM transactions WHERE invoice_no = ?").bind(invoiceNo).first();
  return json({ success: true, transaction: tx }, 201);
}

async function cancelTransaction(env, id) {
  const tx = await env.DB.prepare("SELECT * FROM transactions WHERE id = ?").bind(id).first();
  if (!tx) return fail("Transaksi tidak ditemukan", 404);
  if (tx.status === "batal") return fail("Transaksi sudah dibatalkan");
  const { results } = await env.DB.prepare(
    "SELECT item_id, item_type, qty FROM transaction_items WHERE transaction_id = ?"
  ).bind(id).all();
  const now = new Date().toISOString();
  const stmts = [env.DB.prepare("UPDATE transactions SET status = 'batal' WHERE id = ?").bind(id)];
  for (const r of results) {
    if (r.item_type === "barang" && r.item_id) {
      stmts.push(env.DB.prepare("UPDATE items SET stock = stock + ?, updated_at = ? WHERE id = ?")
        .bind(r.qty, now, r.item_id));
    }
  }
  await env.DB.batch(stmts);
  return json({ success: true });
}

async function summary(env) {
  const today = wibDateString();
  const row = await env.DB.prepare(
    "SELECT COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS count FROM transactions WHERE status = 'selesai' AND date(created_at, '+7 hours') = ?"
  ).bind(today).first();
  return json({ success: true, date: today, revenue: row.revenue, count: row.count });
}

async function report(env, url) {
  const dateRe = /^\d{4}-\d{2}-\d{2}$/;
  let from = url.searchParams.get("from") || "";
  let to = url.searchParams.get("to") || "";
  const today = wibDateString();
  if (!dateRe.test(from)) from = today;
  if (!dateRe.test(to)) to = today;
  if (from > to) { const t = from; from = to; to = t; }
  const range = "date(created_at, '+7 hours') BETWEEN ? AND ?";
  const totals = await env.DB.prepare(
    `SELECT COALESCE(SUM(total), 0) AS revenue, COUNT(*) AS count, COALESCE(SUM(discount), 0) AS discount FROM transactions WHERE status = 'selesai' AND ${range}`
  ).bind(from, to).first();
  const batal = await env.DB.prepare(
    `SELECT COUNT(*) AS count FROM transactions WHERE status = 'batal' AND ${range}`
  ).bind(from, to).first();
  const profitRow = await env.DB.prepare(
    `SELECT COALESCE(SUM((ti.price - COALESCE(i.cost, 0)) * ti.qty), 0) AS profit FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id LEFT JOIN items i ON i.id = ti.item_id WHERE t.status = 'selesai' AND date(t.created_at, '+7 hours') BETWEEN ? AND ?`
  ).bind(from, to).first();
  const { results: byMethod } = await env.DB.prepare(
    `SELECT payment_method AS method, COUNT(*) AS count, COALESCE(SUM(total), 0) AS revenue FROM transactions WHERE status = 'selesai' AND ${range} GROUP BY payment_method ORDER BY revenue DESC`
  ).bind(from, to).all();
  const { results: byDay } = await env.DB.prepare(
    `SELECT date(created_at, '+7 hours') AS day, COUNT(*) AS count, COALESCE(SUM(total), 0) AS revenue FROM transactions WHERE status = 'selesai' AND ${range} GROUP BY day ORDER BY day ASC`
  ).bind(from, to).all();
  const { results: topItems } = await env.DB.prepare(
    `SELECT ti.item_name AS name, ti.item_type AS type, SUM(ti.qty) AS qty, COALESCE(SUM(ti.line_total), 0) AS revenue FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.status = 'selesai' AND date(t.created_at, '+7 hours') BETWEEN ? AND ? GROUP BY ti.item_name, ti.item_type ORDER BY qty DESC, revenue DESC LIMIT 10`
  ).bind(from, to).all();
  const { results: byType } = await env.DB.prepare(
    `SELECT ti.item_type AS type, SUM(ti.qty) AS qty, COALESCE(SUM(ti.line_total), 0) AS revenue FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.status = 'selesai' AND date(t.created_at, '+7 hours') BETWEEN ? AND ? GROUP BY ti.item_type`
  ).bind(from, to).all();
  const topByType = async (type) => {
    const { results } = await env.DB.prepare(
      `SELECT ti.item_name AS name, SUM(ti.qty) AS qty, COALESCE(SUM(ti.line_total), 0) AS revenue FROM transaction_items ti JOIN transactions t ON t.id = ti.transaction_id WHERE t.status = 'selesai' AND ti.item_type = ? AND date(t.created_at, '+7 hours') BETWEEN ? AND ? GROUP BY ti.item_name ORDER BY qty DESC, revenue DESC LIMIT 10`
    ).bind(type, from, to).all();
    return results;
  };
  const topBarang = await topByType("barang");
  const topJasa = await topByType("jasa");
  const { results: transactions } = await env.DB.prepare(
    `SELECT * FROM transactions WHERE ${range} ORDER BY id DESC LIMIT 500`
  ).bind(from, to).all();
  return json({
    success: true, from, to,
    revenue: totals.revenue, count: totals.count, discount: totals.discount,
    avg: totals.count ? Math.round(totals.revenue / totals.count) : 0,
    profit: profitRow.profit - totals.discount, cancelled: batal.count,
    by_method: byMethod, by_day: byDay, top_items: topItems, by_type: byType, top_barang: topBarang, top_jasa: topJasa, transactions
  });
}

/* ---------------- Shifts ---------------- */
async function getOpenShift(env) {
  return env.DB.prepare("SELECT * FROM shifts WHERE status = 'buka' ORDER BY id DESC LIMIT 1").first();
}
async function shiftTotals(env, shiftId) {
  const all = await env.DB.prepare(
    "SELECT COUNT(*) AS count, COALESCE(SUM(total), 0) AS sales FROM transactions WHERE shift_id = ? AND status = 'selesai'"
  ).bind(shiftId).first();
  const tunai = await env.DB.prepare(
    "SELECT COALESCE(SUM(total), 0) AS tunai FROM transactions WHERE shift_id = ? AND status = 'selesai' AND payment_method = 'Tunai'"
  ).bind(shiftId).first();
  const { results: byMethod } = await env.DB.prepare(
    "SELECT payment_method AS method, COUNT(*) AS count, COALESCE(SUM(total), 0) AS revenue FROM transactions WHERE shift_id = ? AND status = 'selesai' GROUP BY payment_method ORDER BY revenue DESC"
  ).bind(shiftId).all();
  return { count: all.count, sales: all.sales, tunai: tunai.tunai, by_method: byMethod };
}
async function currentShift(env) {
  const s = await getOpenShift(env);
  if (!s) return json({ success: true, shift: null });
  const t = await shiftTotals(env, s.id);
  return json({ success: true, shift: s, totals: t, expected_cash: s.opening_cash + t.tunai });
}
async function openShift(env, req) {
  if (await getOpenShift(env)) return fail("Masih ada shift yang terbuka. Tutup dulu shift itu.");
  const b = await req.json().catch(() => null);
  const opening = Math.max(0, int(b && b.opening_cash));
  const now = new Date().toISOString();
  const r = await env.DB.prepare(
    "INSERT INTO shifts (opened_at, opening_cash, status) VALUES (?, ?, 'buka')"
  ).bind(now, opening).run();
  const s = await env.DB.prepare("SELECT * FROM shifts WHERE id = ?").bind(r.meta.last_row_id).first();
  return json({ success: true, shift: s }, 201);
}
async function closeShift(env, req) {
  const s = await getOpenShift(env);
  if (!s) return fail("Tidak ada shift yang sedang terbuka");
  const b = await req.json().catch(() => null);
  if (!b || b.closing_cash === undefined || b.closing_cash === null || String(b.closing_cash).trim() === "")
    return fail("Uang aktual di laci wajib diisi");
  const closing = Math.max(0, int(b.closing_cash));
  const t = await shiftTotals(env, s.id);
  const expected = s.opening_cash + t.tunai;
  const now = new Date().toISOString();
  await env.DB.prepare(
    "UPDATE shifts SET closed_at = ?, closing_cash = ?, expected_cash = ?, difference = ?, status = 'tutup', note = ? WHERE id = ?"
  ).bind(now, closing, expected, closing - expected, String(b.note || "").trim(), s.id).run();
  const updated = await env.DB.prepare("SELECT * FROM shifts WHERE id = ?").bind(s.id).first();
  return json({ success: true, shift: updated, totals: t, expected_cash: expected });
}
async function listShifts(env) {
  const { results } = await env.DB.prepare(
    "SELECT s.*, COALESCE(a.cnt, 0) AS tx_count, COALESCE(a.sales, 0) AS sales FROM shifts s LEFT JOIN (SELECT shift_id, COUNT(*) AS cnt, SUM(total) AS sales FROM transactions WHERE status = 'selesai' AND shift_id IS NOT NULL GROUP BY shift_id) a ON a.shift_id = s.id ORDER BY s.id DESC LIMIT 50"
  ).all();
  return json({ success: true, shifts: results });
}

/* ---------------- Customers (Data Pelanggan) ---------------- */
function normPlate(tx) {
  return String(tx.plate || "").trim().toUpperCase().replace(/\s+/g, " ");
}
function normPhone(tx) {
  let d = String(tx.customer_phone || "").replace(/\D/g, "");
  if (d.startsWith("62")) d = "0" + d.slice(2);
  else if (d.startsWith("8")) d = "0" + d;
  return d.length >= 9 && d.startsWith("0") ? d : "";
}
/* Group transactions into customers: two transactions belong to the same
   customer when they share a plate OR a WhatsApp number (transitively, so a
   changed plate or number still merges through any bridging transaction).
   Transactions with neither plate nor phone fall back to grouping by name. */
function groupCustomers(results) {
  const parent = new Map();
  function find(x) {
    let r = x;
    while (parent.get(r) !== r) r = parent.get(r);
    let c = x;
    while (parent.get(c) !== r) { const n = parent.get(c); parent.set(c, r); c = n; }
    return r;
  }
  function union(a, b) {
    const ra = find(a), rb = find(b);
    if (ra !== rb) parent.set(ra, rb);
  }
  const plateOwner = new Map(), phoneOwner = new Map();
  const eligible = [], nameGroups = new Map();
  for (const tx of results) {
    const plate = normPlate(tx), phone = normPhone(tx);
    const name = String(tx.customer_name || "").trim().toLowerCase();
    if (!plate && !phone) {
      if (!name) continue;
      if (!nameGroups.has(name)) nameGroups.set(name, []);
      nameGroups.get(name).push(tx);
      continue;
    }
    eligible.push(tx);
    parent.set(tx.id, tx.id);
    if (plate) {
      if (plateOwner.has(plate)) union(tx.id, plateOwner.get(plate));
      else plateOwner.set(plate, tx.id);
    }
    if (phone) {
      if (phoneOwner.has(phone)) union(tx.id, phoneOwner.get(phone));
      else phoneOwner.set(phone, tx.id);
    }
  }
  const buckets = new Map();
  for (const tx of eligible) {
    const r = find(tx.id);
    if (!buckets.has(r)) buckets.set(r, []);
    buckets.get(r).push(tx);
  }
  return [...buckets.values(), ...nameGroups.values()].map(txs => ({
    key: "GRP:" + Math.min(...txs.map(t => t.id)),
    txs,
  }));
}
async function listCustomers(env) {
  const { results } = await env.DB.prepare(
    "SELECT id, invoice_no, customer_name, plate, customer_phone, total, status, created_at FROM transactions ORDER BY id DESC LIMIT 2000"
  ).all();
  const customers = groupCustomers(results).map(({ key, txs }) => {
    const c = { key, name: "", plate: "", plates: [], phone: "", visits: 0, total_spent: 0, last_visit: txs[0].created_at, first_visit: txs[txs.length - 1].created_at };
    for (const tx of txs) {
      if (!c.name && tx.customer_name) c.name = tx.customer_name;
      const p = normPlate(tx);
      if (p && !c.plates.includes(p)) c.plates.push(p);
      if (!c.phone && tx.customer_phone) c.phone = tx.customer_phone;
      if (tx.status === "selesai") { c.visits++; c.total_spent += tx.total; }
    }
    c.plate = c.plates[0] || "";
    return c;
  }).sort((a, b) => (a.last_visit < b.last_visit ? 1 : -1));
  return json({ success: true, customers });
}
async function customerDetail(env, url) {
  const key = url.searchParams.get("key") || "";
  const { results } = await env.DB.prepare(
    "SELECT id, invoice_no, customer_name, plate, customer_phone, total, status, payment_method, created_at FROM transactions ORDER BY id DESC LIMIT 2000"
  ).all();
  const group = groupCustomers(results).find(g => g.key === key);
  if (!group) return fail("Pelanggan tidak ditemukan", 404);
  const txs = group.txs;
  const ids = txs.map(t => t.id);
  const ph = ids.map(() => "?").join(",");
  const { results: lines } = await env.DB.prepare(
    `SELECT transaction_id, item_name, item_type, qty, price FROM transaction_items WHERE transaction_id IN (${ph})`
  ).bind(...ids).all();
  const byTx = new Map();
  for (const l of lines) {
    if (!byTx.has(l.transaction_id)) byTx.set(l.transaction_id, []);
    byTx.get(l.transaction_id).push(l);
  }
  const phoneRow = txs.find(t => t.customer_phone);
  return json({ success: true, phone: phoneRow ? phoneRow.customer_phone : "", transactions: txs.map(t => ({ ...t, items: byTx.get(t.id) || [] })) });
}
async function getServices(env) {
  const { results } = await env.DB.prepare(
    "SELECT name, price FROM items WHERE type = 'jasa' ORDER BY name ASC"
  ).all();
  return json({ success: true, services: results });
}

/* ---------------- Store profile settings ---------------- */
const STORE_DEFAULTS = {
  store_name: "A3 Motor Service Station",
  store_address: "Jalan Piai Tangah, Bandar Buat, Kec. Lubuk Kilangan, Kota Padang 25231",
  store_phone: "0813-7466-3567",
  theme_accent: "#4FB2FF",
  theme_accent_strong: "#1B7BD4",
  theme_sidebar: "#0E1930",
  theme_sidebar2: "#1D2D4D",
  store_lat: "-0.9504468",
  store_lng: "100.4300209"
};
const HEX_RE = /^#[0-9a-fA-F]{6}$/;
async function readStoreProfile(env) {
  const { results } = await env.DB.prepare(
    "SELECT key, value FROM settings WHERE key IN ('store_name', 'store_address', 'store_phone', 'theme_accent', 'theme_accent_strong', 'theme_sidebar', 'theme_sidebar2', 'store_lat', 'store_lng', 'store_logo')"
  ).all();
  const out = { ...STORE_DEFAULTS, has_custom_logo: false };
  for (const r of results) {
    if (r.key === "store_logo") { out.has_custom_logo = true; continue; }
    out[r.key] = r.value;
  }
  return out;
}
async function getSettings(env) {
  return json({ success: true, profile: await readStoreProfile(env) });
}
async function updateSettings(env, req) {
  const b = await req.json().catch(() => null);
  if (!b || !String(b.store_name || "").trim()) return fail("Nama toko wajib diisi");
  const vals = {
    store_name: String(b.store_name).trim().slice(0, 80),
    store_address: String(b.store_address || "").trim().slice(0, 300),
    store_phone: String(b.store_phone || "").trim().slice(0, 30)
  };
  for (const k of ["theme_accent", "theme_accent_strong", "theme_sidebar", "theme_sidebar2"]) {
    if (typeof b[k] === "string" && HEX_RE.test(b[k])) vals[k] = b[k];
  }
  const lat = parseFloat(b.store_lat), lng = parseFloat(b.store_lng);
  if (Number.isFinite(lat) && Number.isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
    vals.store_lat = String(b.store_lat).trim().slice(0, 24);
    vals.store_lng = String(b.store_lng).trim().slice(0, 24);
  }
  const stmts = Object.entries(vals).map(([k, v]) =>
    env.DB.prepare("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value").bind(k, v));
  await env.DB.batch(stmts);
  return json({ success: true, profile: await readStoreProfile(env) });
}
async function getLogo(env) {
  const row = await env.DB.prepare("SELECT value FROM settings WHERE key = 'store_logo'").first();
  if (!row || !row.value) return fail("Tidak ada logo khusus", 404);
  const m = /^data:(image\/(?:png|jpeg|webp));base64,(.+)$/.exec(row.value);
  if (!m) return fail("Logo tersimpan tidak valid", 500);
  const bin = atob(m[2]);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new Response(bytes, { headers: { "Content-Type": m[1], "Cache-Control": "no-cache" } });
}
async function updateLogo(env, req) {
  const b = await req.json().catch(() => null);
  const data = b && typeof b.data_url === "string" ? b.data_url : "";
  if (!/^data:image\/(?:png|jpeg|webp);base64,/.test(data)) return fail("Logo harus berupa gambar PNG, JPG, atau WebP");
  if (data.length > 1200000) return fail("Logo terlalu besar. Pakai gambar persegi yang lebih sederhana.");
  await env.DB.prepare("INSERT INTO settings (key, value) VALUES ('store_logo', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value").bind(data).run();
  return json({ success: true });
}
async function deleteLogo(env) {
  await env.DB.prepare("DELETE FROM settings WHERE key = 'store_logo'").run();
  return json({ success: true });
}

/* ---------------- PIN lock ---------------- */
const SESSION_COOKIE = "a3session";
const SESSION_DAYS = 7;

async function sha256Hex(text) {
  const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
  return [...new Uint8Array(buf)].map(x => x.toString(16).padStart(2, "0")).join("");
}
async function hmacHex(keyHex, msg) {
  const key = await crypto.subtle.importKey(
    "raw", new TextEncoder().encode(keyHex), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
  const sig = await crypto.subtle.sign("HMAC", key, new TextEncoder().encode(msg));
  return [...new Uint8Array(sig)].map(x => x.toString(16).padStart(2, "0")).join("");
}
async function getPinHash(env) {
  const row = await env.DB.prepare("SELECT value FROM settings WHERE key = 'pin_hash'").first();
  return row ? row.value : null;
}
function cookieValue(req, name) {
  const raw = req.headers.get("Cookie") || "";
  for (const part of raw.split(";")) {
    const [k, ...rest] = part.trim().split("=");
    if (k === name) return rest.join("=");
  }
  return null;
}
async function isAuthed(env, req) {
  const pinHash = await getPinHash(env);
  if (!pinHash) return false;
  const token = cookieValue(req, SESSION_COOKIE);
  if (!token) return false;
  const [expStr, sig] = token.split(".");
  const exp = Number(expStr);
  if (!exp || exp < Math.floor(Date.now() / 1000)) return false;
  const expected = await hmacHex(pinHash, "a3motor:" + expStr);
  return sig === expected;
}
async function makeSessionCookie(env) {
  const pinHash = await getPinHash(env);
  const exp = Math.floor(Date.now() / 1000) + SESSION_DAYS * 86400;
  const sig = await hmacHex(pinHash, "a3motor:" + exp);
  return `${SESSION_COOKIE}=${exp}.${sig}; Path=/; Max-Age=${SESSION_DAYS * 86400}; HttpOnly; Secure; SameSite=Lax`;
}
function clearSessionCookie() {
  return `${SESSION_COOKIE}=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Lax`;
}
function validPinFormat(pin) {
  return typeof pin === "string" && /^[0-9]{4,8}$/.test(pin);
}

async function handleAuth(env, req, url) {
  const p = url.pathname;
  const m = req.method;
  try {
    if (p === "/api/auth/status" && m === "GET") {
      const pinHash = await getPinHash(env);
      return json({ success: true, pin_set: !!pinHash, authed: pinHash ? await isAuthed(env, req) : false });
    }
    if (p === "/api/auth/setup" && m === "POST") {
      if (await getPinHash(env)) return fail("PIN sudah dibuat. Masuk dengan PIN yang ada.", 409);
      const b = await req.json().catch(() => null);
      if (!b || !validPinFormat(b.pin)) return fail("PIN harus 4-8 digit angka");
      const hash = await sha256Hex("a3motor-pin:" + b.pin);
      await env.DB.prepare("INSERT INTO settings (key, value) VALUES ('pin_hash', ?)").bind(hash).run();
      const res = json({ success: true });
      res.headers.set("Set-Cookie", await makeSessionCookie(env));
      return res;
    }
    if (p === "/api/auth/login" && m === "POST") {
      const pinHash = await getPinHash(env);
      if (!pinHash) return fail("PIN belum dibuat", 409);
      const b = await req.json().catch(() => null);
      const hash = b && typeof b.pin === "string" ? await sha256Hex("a3motor-pin:" + b.pin) : "";
      if (hash !== pinHash) return fail("PIN salah", 401);
      const res = json({ success: true });
      res.headers.set("Set-Cookie", await makeSessionCookie(env));
      return res;
    }
    if (p === "/api/auth/logout" && m === "POST") {
      const res = json({ success: true });
      res.headers.set("Set-Cookie", clearSessionCookie());
      return res;
    }
    if (p === "/api/auth/change" && m === "POST") {
      if (!(await isAuthed(env, req))) return fail("Belum masuk", 401);
      const b = await req.json().catch(() => null);
      if (!b || !validPinFormat(b.new_pin)) return fail("PIN baru harus 4-8 digit angka");
      const pinHash = await getPinHash(env);
      const oldHash = typeof b.old_pin === "string" ? await sha256Hex("a3motor-pin:" + b.old_pin) : "";
      if (oldHash !== pinHash) return fail("PIN lama salah", 401);
      const hash = await sha256Hex("a3motor-pin:" + b.new_pin);
      await env.DB.prepare("UPDATE settings SET value = ? WHERE key = 'pin_hash'").bind(hash).run();
      const res = json({ success: true });
      res.headers.set("Set-Cookie", await makeSessionCookie(env));
      return res;
    }
    return fail("Endpoint tidak ditemukan", 404);
  } catch (e) {
    return fail("Server error: " + (e && e.message ? e.message : String(e)), 500);
  }
}

async function handleApi(env, req, url) {
  const p = url.pathname;
  const m = req.method;
  try {
    if (p === "/api/items" && m === "GET") return await listItems(env);
    if (p === "/api/items" && m === "POST") return await createItem(env, req);
    const itemMatch = p.match(/^\/api\/items\/(\d+)$/);
    if (itemMatch && m === "PUT") return await updateItem(env, req, int(itemMatch[1]));
    if (itemMatch && m === "DELETE") return await deleteItem(env, int(itemMatch[1]));
    const stockMatch = p.match(/^\/api\/items\/(\d+)\/stock$/);
    if (stockMatch && m === "POST") return await adjustStock(env, req, int(stockMatch[1]));
    if (p === "/api/transactions" && m === "GET") return await listTransactions(env, url);
    if (p === "/api/transactions" && m === "POST") return await checkout(env, req);
    const txMatch = p.match(/^\/api\/transactions\/(\d+)$/);
    if (txMatch && m === "GET") return await getTransaction(env, int(txMatch[1]));
    const cancelMatch = p.match(/^\/api\/transactions\/(\d+)\/cancel$/);
    if (cancelMatch && m === "POST") return await cancelTransaction(env, int(cancelMatch[1]));
    if (p === "/api/summary" && m === "GET") return await summary(env);
    if (p === "/api/report" && m === "GET") return await report(env, url);
    if (p === "/api/shift" && m === "GET") return await currentShift(env);
    if (p === "/api/shift/open" && m === "POST") return await openShift(env, req);
    if (p === "/api/shift/close" && m === "POST") return await closeShift(env, req);
    if (p === "/api/shifts" && m === "GET") return await listShifts(env);
    if (p === "/api/settings" && m === "PUT") return await updateSettings(env, req);
    if (p === "/api/settings/logo" && m === "PUT") return await updateLogo(env, req);
    if (p === "/api/settings/logo" && m === "DELETE") return await deleteLogo(env);
    if (p === "/api/customers" && m === "GET") return await listCustomers(env);
    if (p === "/api/customers/detail" && m === "GET") return await customerDetail(env, url);
    return fail("Endpoint tidak ditemukan", 404);
  } catch (e) {
    return fail("Server error: " + (e && e.message ? e.message : String(e)), 500);
  }
}

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    if (url.pathname.startsWith("/api/")) {
      if (!env.DB) return fail("Database belum terhubung", 500);
      if (url.pathname.startsWith("/api/auth/")) return handleAuth(env, request, url);
      // Store profile is public info (printed on receipts), readable before login for the lock screen
      if (url.pathname === "/api/settings" && request.method === "GET") return getSettings(env);
      if (url.pathname === "/api/logo" && request.method === "GET") return getLogo(env);
      if (url.pathname === "/api/services" && request.method === "GET") return getServices(env);
      if (!(await isAuthed(env, request))) return fail("Terkunci. Masukkan PIN dulu.", 401);
      return handleApi(env, request, url);
    }
    // Static assets; fall back to index.html for the SPA
    let res = await env.ASSETS.fetch(request);
    if (res.status === 404) {
      res = await env.ASSETS.fetch(new Request(new URL("/index.html", url.origin), request));
    }
    return res;
  },
};
