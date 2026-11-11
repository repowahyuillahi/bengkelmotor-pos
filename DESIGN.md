# DESIGN.md — A3 Motor Service Station (kasir)

Arah desain untuk aplikasi kasir di https://a3motor.pages.dev. Ditulis 2026-11-09 dari bukti nyata, bukan karangan.

## Identitas (dari spanduk toko, foto Google Maps)

- Bukti: foto etalase di Google Maps (disimpan sebagai `brand-banner-reference.jpg` di folder ini). Spanduk toko adalah satu-satunya identitas visual resmi yang terverifikasi. Tidak ada logo terpisah di profil Maps (hanya inisial default Google).
- Warna spanduk:
  - Latar spanduk: slate biru-abu gelap, sekitar #59647A (rentang foto #4F5A70 sampai #6B768C).
  - Aksen huruf "MOTOR" dan bayangan huruf S raksasa: coral merah muda salmon, sekitar #F07E7E.
  - Huruf utama dan ikon kunci pas + obeng menyilang: putih #FFFFFF.
- Suara tipografi spanduk: huruf kapital tebal, condensed, sedikit miring (italic), rapat. Dipakai hanya untuk wordmark dan judul pandangan, bukan teks isi, supaya keterbacaan kasir tetap tinggi.
- Motif identitas: huruf raksasa berlapis (S putih dengan bayangan coral bergeser). Diterjemahkan menjadi ubin merek "A3" putih dengan bayangan coral bergeser di sidebar dan layar kunci. Satu motif, diulang di dua tempat itu saja.

## Referensi struktur

- Template satnaing shadcn-admin (https://www.shadcn.io/template/satnaing-shadcn-admin), atas arahan pemilik: sidebar kiri berkelompok, header tipis di atas konten, token komponen shadcn (kartu, tabel, tombol, badge, dialog). Yang diadopsi adalah bahasa layoutnya, bukan kode React-nya, karena aplikasi ini satu file mandiri tanpa build.
- Filter kualitas: antislop (https://antislop.ai.id), mode during, atas arahan pemilik.

## Design Read

Reading this as: aplikasi kasir bengkel (dashboard POS) untuk staf toko, in a shadcn-admin layout language tinted with the shop banner identity, dial ENERGY 2 / RHYTHM 1 / MOTION 1.

- ENERGY 2: wordmark dan judul meniru keberanian spanduk (kapital tebal miring), sisanya tenang.
- RHYTHM 1: alat kerja harian harus bisa ditebak. Grid dan jarak seragam disengaja.
- MOTION 1: hanya keadaan hover dan transisi sidebar di ponsel. Tidak ada animasi dekoratif.

## Token

- `--sidebar`: #454F63 (pendalaman dari slate spanduk agar teks putih lolos kontras AA).
- `--slate`: #59647A (slate spanduk, untuk elemen sekunder di sidebar dan hover).
- `--coral`: #F07E7E (aksen tunggal yang disengaja).
- `--coral-strong`: #C93A36 (teks coral di latar terang dan cincin fokus, agar lolos kontras).
- `--background`: #F6F7F9, `--card`: #FFFFFF, `--border`: #E3E6EC, `--foreground`: #1E2430, `--muted-foreground`: #5B6472.
- Radius mengikuti shadcn: 8px kartu dan tombol, 6px input, badge pill hanya untuk status (fungsi: membedakan keadaan sekilas), bukan dekorasi.

## Keputusan beserta alasannya (Purpose-Gate)

- Sidebar gelap slate: identitas spanduk sekaligus pemisah hierarki navigasi dari area kerja yang terang.
- Bilah coral 3px pada menu aktif: penanda keadaan aktif, bukan hiasan. Tanpa itu, menu aktif hanya beda tipis warna latar.
- Tombol "Bayar & Buat Nota" memakai coral dengan teks slate sangat gelap: satu titik fokus per layar (layar Kasir), momen paling penting di aplikasi ini. Tombol lain tetap slate gelap netral.
- Harga pada kartu barang ditegaskan tebal slate gelap, bukan coral: coral disimpan untuk aksi dan keadaan, bukan semua angka (aksen di mana-mana = tidak ada aksen).
- Tanpa gradasi, tanpa glassmorphism, tanpa bayangan melayang: alat kasir dipakai di bawah cahaya bengkel, kejelasan mengalahkan efek.
- Font sistem (bukan unduhan): aplikasi harus terbuka seketika di koneksi toko, tanpa permintaan pihak ketiga. Karakter merek dibawa oleh bobot, kemiringan wordmark, dan warna, bukan oleh berkas font.

## Revisi 2026-11-09: logo resmi dari pemilik (menggantikan arah spanduk)

Pemilik menyerahkan logo resmi A3 (berkas asli tersimpan sebagai `logo-source.png`; lambang lingkaran dipotong bersih menjadi `dist/logo.png`): monogram "A3" neon biru elektrik di atas karbon gelap, cincin bertuliskan nama toko, ikon motor, busi, dan smartkey, dengan moto "Fuel Injection • Smartkey Specialist".

- Identitas utama sekarang mengikuti logo ini, bukan spanduk Maps. Warna spanduk (slate + coral) tetap fakta fisik di toko, tapi tidak lagi memimpin tema aplikasi.
- Token berubah: `--sidebar` menjadi navy sangat gelap #0E1930, `--sidebar-2` #1D2D4D, aksen tunggal menjadi biru elektrik logo `--accent` #4FB2FF (dengan `--accent-strong` #1B7BD4 untuk cincin fokus dan teks di latar terang).
- Logo tampil di tiga tempat dengan fungsi jelas: sidebar (identitas aplikasi), layar kunci (identitas saat tertutup), dan favicon. Bukan dekorasi tambahan.
- Poster logo memuat klaim seperti "24/7 Service Support" dan "Certified Specialist". Klaim itu TIDAK disalin ke teks aplikasi, karena jam layanan di Google Maps adalah Senin–Sabtu 09.00–17.00. Aplikasi hanya memakai lambang dan warnanya.

## Revision 2026-11-10: Typography (user request)
- UI font: Inter (400-800) via Google Fonts, tabular-nums on body.
- Numbers font: JetBrains Mono (500-800) via a `.num` utility class applied to prices, cart line totals, subtotal/discount/total/change, revenue chips and stats, katalog price/cost cells, riwayat totals and invoice numbers; receipt and number inputs also use it. Fallbacks: system sans / ui-monospace when offline.
- Deployed to production 2026-11-10 (deployment 2e766322), verified live.

## Revision 2026-11-10: Audit fixes (user approved "fix all")
- Accent hover: coral #EE6F6F leftover removed; hover is now blue #2E9BF0 (production verified EE6F6F-free after deploy a1cc6c69).
- Cart qty stepper buttons enlarged 30px to 40px.
- Modals: X close button on all four dialogs, backdrop-click to close, auto-focus first field on open; Escape routes through closeModal.
- Native confirm() replaced by a shadcn-style AlertDialog (askConfirm) for item delete and transaction cancel.
- Boot flash fixed: body starts .booting (shell hidden), lock overlay renders immediately with a "Memuat..." state until auth status resolves.

## Revision 2026-11-10: Laporan (reports) feature
- New sidebar view "Laporan" + GET /api/report?from&to (auth-gated like all API routes).
- Period presets (Hari Ini, Kemarin, 7 Hari, 30 Hari, Bulan Ini) + custom date range; cards: pendapatan, transaksi selesai, rata-rata, perkiraan laba kotor (line profit from current item cost minus discounts; cancelled excluded and counted separately); breakdowns per payment method, top 10 items, revenue per day, transaction table, CSV download (semicolon, UTF-8 BOM for Excel ID).
- Verified end-to-end on local staging with seeded multi-day data (all aggregates matched hand-computed values), then deployed (96bcc374) and cross-checked against production D1.

## Revision 2026-11-10: Shift buka/tutup
- New shifts table + transactions.shift_id (one-off ALTER on existing DBs; schema.sql carries both for fresh installs).
- Flow: Buka Shift with opening cash (sidebar "Shift Kasir" box + Kasir banner); checkout is rejected server-side when no shift is open and stamps shift_id otherwise; Tutup Shift shows live recap (per-method sales, expected cash = opening + Tunai sales), takes actual counted cash + optional note, stores selisih. Riwayat Shift table lives in Laporan.
- Verified end-to-end on local staging (blocked checkout without shift; open 200000; Tunai 50000 + QRIS 75000 attached to the shift; expected 250000; close at 245000 gave difference -5000), local data wiped after. Production migrated (column + table; 3 pre-existing transactions keep shift_id NULL) and deployed (8fc5e1b3).

## Revision 2026-11-10: Pengaturan Toko (store profile)
- Store name/address/phone moved from hardcoded strings into the settings table (keys store_name, store_address, store_phone) with the current values as defaults (phone 0813-7466-3567 from the Maps listing).
- GET /api/settings is public (lock screen needs it pre-login; the data is receipt-printed public info anyway); PUT /api/settings stays behind the PIN session.
- New "Pengaturan Toko" sidebar view edits the profile; sidebar brand (split-name heuristic), sidebar footer, lock screen, page title and receipt (now incl. "Telp:" line) all render from the profile.
- Verified locally (public GET defaults, PUT 401 unauthed, PUT roundtrip after login) and in production (deployment ba4b67c9; public GET returns the live profile).

## Revision 2026-11-10: Ganti logo + warna tema
- Custom logo: uploaded via Pengaturan Toko, processed client-side (center-crop square, resize 512x512 PNG, JPEG fallback if >850KB data URL), stored as data URL in settings key store_logo (server cap 1.2M chars). GET /api/logo is public and serves the decoded image (lock screen/favicon need it pre-login); PUT/DELETE /api/settings/logo sit behind the PIN session. Without a custom logo the app falls back to the bundled /logo.png; "Pakai Logo Bawaan" deletes the key.
- The settings page carries the size guide: square 1:1 at 512x512, PNG transparent preferred, logo displays as a circle so keep the core inside a ~410px safe circle (~50px padding), avoid small text (sidebar renders it at 46px).
- Theme: four settings keys (theme_accent, theme_accent_strong, theme_sidebar, theme_sidebar2) with the current blue/navy as defaults; 6 presets (Biru Elektrik, Hijau Zamrud, Toska, Ungu, Oranye, Merah) plus custom color inputs for accent and sidebar (strong/sidebar2 derived by shade/mix), live preview before saving. PUT /api/settings accepts the theme keys only when valid #RRGGBB.
- Verified locally (logo roundtrip served the exact uploaded bytes, 404 before/after reset, theme persisted) and in production (deployment 008df702; settings returns defaults with has_custom_logo:false, logo PUT 401 unauthed).

## Revision 2026-11-10: Katalog dipecah menjadi Stok Barang + Kelola Jasa
- The combined "Katalog & Stok" menu/view was split per the user's request into two sidebar entries under a "Katalog" group label: "Stok Barang" (goods table with SKU, prices, stock badge, Stok/Edit/Hapus, plus stat cards: jenis barang, stok menipis <=5, stok habis, nilai stok at cost) and "Kelola Jasa" (services table without stock, note that services carry no stock). Separate searches per view.
- Item form now presets the type from the entry point (Tambah Barang / Tambah Jasa), titles read Tambah/Edit Barang/Jasa, and the stock row hides when the type is jasa. Data model and API unchanged (items.type); Kasir still shows both types together for checkout. The Kasir empty-state hint now points to the new menus.
- Verified locally (stats math against seeded 2 barang/1 jasa) and in production (deployments 5592c8ce + 2455bb87 text fix; production split is 15 barang with Rp8.878.000 stock value at cost, 8 jasa).

## Revision 2026-11-10: Lokasi & peta ringan
- Store coordinates are settings keys (store_lat/store_lng, defaults -0.9504468, 100.4300209 = the A3 Motor Maps pin the user shared; the embed they pasted carries the identical place id and coordinates, and reverse geocoding resolves it to A3 Motor Service Station, Jalan Piai Tangah, Bandar Buat, Lubuk Kilangan, Padang 25231).
- The Pengaturan "Lokasi & Peta" card uses a click-to-load facade: zero iframes in the static markup (verified in production HTML), the Google Maps embed (google.com/maps?q=lat,lng&z=17&output=embed) is injected only when the user clicks "Muat Peta", because a raw Maps iframe pulls several hundred KB of JS/tiles even when idle. Coordinates are editable with server-side range validation; "Buka di Google Maps" deep-links out.
- NOTE: during verification the production store_address was found changed to "Jalan Bandar Buat, Kec. Lubuk Kilangan, Kota Padang 25231" — the user's own edit via Pengaturan (earlier deploys that day still showed the long default). Left untouched.

## Revision 2026-11-10: Alamat disamakan + perbaikan tampilan mobile
- Production store_address set to the map pin's address ("Jalan Piai Tangah, Bandar Buat, Kec. Lubuk Kilangan, Kota Padang 25231") via D1 at the user's explicit approval, replacing their earlier in-app edit ("Jalan Bandar Buat, ..."); verified through the public settings endpoint.
- Mobile fix pack (deployment 0c63f46c), from a static audit of the responsive rules (no phone-viewport capture available in this environment): (1) sidebar drawer now overflow-y:auto — its content is ~800px tall, so on short phones the Akun buttons/footer were unreachable; (2) stat cards switch to a 2-column grid at <=640px instead of one full-width card per row (flex min-width:180px), number font 19px; (3) topbar revenue chip drops its "Pendapatan hari ini" label at <=640px, keeping the amount; (4) toolbar search inputs flex-grow so search + action button share a row; (5) table cell padding tightens, modal padding shrinks; (6) kasir gains a fixed bottom cart bar at <=900px (item count + total + "Lihat Keranjang" scroll button, only while the cart is non-empty) since the stacked layout buries the cart below the whole catalog; content bottom padding compensates.

## Revision 2026-11-10: Data Pelanggan + landing page (UI UX Pro Max)
- Data Pelanggan view (sidebar "Pelanggan"): customer list auto-derived from transaction customer_name + plate. Grouping by normalized plate when present (name variants merge), else by name; anonymous transactions excluded. Aggregates (visits, total spent) count only completed transactions; newest name wins for display. Detail modal shows the full service history per vehicle/customer with line items and a Nota button reusing the receipt viewer. Endpoints: GET /api/customers and /api/customers/detail (PIN-gated). Verified locally end to end (plate BA 1234 XY, two name spellings, merged: 2 visits, Rp125.000; name-only customer; anonymous excluded), local data wiped after.
- Landing page (public, dist/landing.html, served at /landing): built on a UI UX Pro Max design-system search (upstream nextlevelbuilder/ui-ux-pro-max-skill, cloned to ~/workspace/tools/ui-ux-pro-max-skill): Hero + services + why-us + location + CTA pattern, vibrant block-based style, Russo One display + Chakra Petch body, large sections/type, and its pre-delivery checklist (SVG icons only, focus states, 4.5:1 contrast, reduced motion, responsive 375-1440px). Reconciliations: the shop's own navy/electric-blue theme and logo (live from /api/settings and /api/logo) win over the skill's generic purple/pink palette; the testimonials block was dropped (no verified testimonials; never fabricate social proof). Services and prices load live from the new public endpoint GET /api/services so the page always matches the in-app catalog. Map is the same click-to-load facade; WhatsApp CTAs deep-link with a prefilled message; staff enter via footer link; the kasir lock screen links to the landing.
- Deployment 9b23c73d. Verified in production: /landing serves the page (308 from /landing.html), /api/services returns the 8 production jasa with prices, /api/customers is 401 unauthenticated, store profile unchanged.
- USER FEEDBACK (2026-11-10 18:31): landing v1 "kurang suka" — too plain/quiet. User asked to study busy, well-known workshop landing pages first. Research done: Planet Ban (category nav, symptom-first entry, detailed service cards, 4-step "cara kerja", big numbers, FAQ), Shop & Bike Astra (customer testimonials + Google rating shown, real mechanic photos, WA/call-center everywhere), Otoklix (promo bundles with clear prices up top, 14-day warranty badge, trust numbers, long FAQ, repeated booking CTAs), Midas/Firestone (booking CTA in nav + hero + every service, coupons above the fold, trust signals before scrolling).

## Revision 2026-11-10: Landing page v2 (Planet Ban + Otoklix mix)
- User picked the mix direction. Landing rewritten (deployment 05ef854a): amber promo strip (free WhatsApp symptom consultation), photo hero with live open/closed badge (WIB, Mon–Sat 09:00–17:00), trust chips, dynamic stats strip (service count from /api/services), "Mulai dari Gejala" with 8 symptom cards that deep-link to WhatsApp with a prefilled symptom message, service cards with per-service icons and "Tanya layanan ini" WA links, navy "Cara Kerja" 4-step section (periksa dulu, persetujuan, baru kerjakan) with photos, Kenapa Kami checklist with photo, Google-reviews CTA card (links to the Maps listing; still no fabricated testimonials), 5-question FAQ (native details), location/map (facade kept), final CTA, and a sticky mobile action bar (WhatsApp/Telepon/Rute).
- Imagery: 4 AI-generated photo-style images (hero.jpg workshop scene, galeri-cvt.jpg, galeri-parts.jpg, nota.jpg receipt handover), visually inspected, recompressed to 1600px/q80 (224–295 KB). They are placeholders: replace with real shop photos whenever the user provides them.
- Housekeeping: the media tool's media-generation-*.json metadata files landed in dist and were published by one deploy; they were moved out and a redeploy removed them from the manifest (the URLs now hit the SPA fallback).

## Revision 2026-11-10: Landing v3 (gradients + fonts) and v4 (motion + tidiness)
- v3 (deployment d392d0ce), at the user's request to mirror greenstudio.id and jadiincuan.com while keeping the shop's colors: their actual stacks were inspected (Green Studio: Syne 700/800 display + Plus Jakarta Sans + Google Sans Flex; JadiinCuan: Plus Jakarta Sans throughout, signature CTA gradient linear-gradient(105deg, two brand colors), pill buttons, cards 14–18px radius, deep soft shadows). Adopted: fonts switched to Syne 800 headings + Plus Jakarta Sans body; all primary buttons became pills with 105deg gradients in our own colors (blue: accent to accent-strong; WhatsApp: green range; amber promo strip), layered soft shadows, gradient text on the hero highlight and step numbers, gradient icon chips, soft-tinted card gradients, gradient stats bar. WhatsApp target confirmed as +62 813-7466-3567 (derived from the settings phone, wa.me/6281374663567), the number the user specified.
- v4 (deployment d942ec69), at the user's request to mirror antislop.ai.id's own landing polish: its motion DNA was extracted from source (signature easing cubic-bezier(0.16, 1, 0.3, 1) used pervasively, reveals rising 28px via IntersectionObserver, a soft pulsing glow keyframe, short precise color transitions, backdrop blur). Adopted: a single --ease variable drives all transitions; reveals are fade + 28px rise + deblur over 0.8s with per-grid stagger delays; hero content enters with staggered fadeUp keyframes; the open-now dot pulses softly; gradient buttons gained a shine sweep on hover; FAQ answers animate open; the mobile sticky bar slides up on load; all animations respect prefers-reduced-motion. Tidiness: fluid clamp typography (h1/h2/lead/stats), uniform 72px/52px section rhythm, scroll-margin under the sticky header, overflow-x clip, the stats phone number sized with its own clamp so it cannot overflow small screens, smaller topbar CTA on phones.
- Font correction the same evening (deployment after d942ec69): the user found Syne hard to read ("Font nya gabagus. Pakai yang gampang dibaca"), so Syne was dropped entirely; the whole landing now uses Plus Jakarta Sans (headings ExtraBold 800, body 400–700), verified zero Syne references in production.
- Offline Windows package (2026-11-11, at the user's request): the live cloud version cannot run offline, so a self-contained offline edition was built at ~/workspace/a3motor-offline/ and zipped as ~/workspace/a3motor-offline-windows.zip (1.5MB): dist/ + schema.sql + wrangler.toml (placeholder DB id) + package.json/lock + README.md (full Indonesian guide) + start.bat (CRLF; checks/installs Node.js via winget or opens nodejs.org, runs npm install once, applies schema.sql to the local D1, opens the browser, serves on 127.0.0.1:8788). The full first-run sequence was verified from the pristine folder on this VM: schema creates all 5 tables, the app boots, /api/auth/status reports pin_set:false. Its database is fully separate from production; internet is needed only for the first-run downloads.
