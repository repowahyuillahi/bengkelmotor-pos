CREATE TABLE IF NOT EXISTS items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'barang',
  sku TEXT DEFAULT '',
  price INTEGER NOT NULL DEFAULT 0,
  cost INTEGER NOT NULL DEFAULT 0,
  unit TEXT DEFAULT 'pcs',
  stock INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_no TEXT NOT NULL UNIQUE,
  customer_name TEXT DEFAULT '',
  plate TEXT DEFAULT '',
  customer_phone TEXT DEFAULT '',
  subtotal INTEGER NOT NULL DEFAULT 0,
  discount INTEGER NOT NULL DEFAULT 0,
  total INTEGER NOT NULL DEFAULT 0,
  payment_method TEXT NOT NULL DEFAULT 'Tunai',
  amount_paid INTEGER NOT NULL DEFAULT 0,
  change_amount INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'selesai',
  shift_id INTEGER,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS transaction_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transaction_id INTEGER NOT NULL,
  item_id INTEGER,
  item_name TEXT NOT NULL,
  item_type TEXT NOT NULL DEFAULT 'barang',
  price INTEGER NOT NULL DEFAULT 0,
  qty INTEGER NOT NULL DEFAULT 1,
  line_total INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS shifts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  opened_at TEXT NOT NULL,
  closed_at TEXT,
  opening_cash INTEGER NOT NULL DEFAULT 0,
  closing_cash INTEGER,
  expected_cash INTEGER,
  difference INTEGER,
  status TEXT NOT NULL DEFAULT 'buka',
  note TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_tx_items_tx ON transaction_items (transaction_id);
CREATE INDEX IF NOT EXISTS idx_tx_created ON transactions (created_at);
