# Durable Furnitures – Backend (PHP + MySQL + JSON)

## What it does
- **Checkout** (`checkout.html`): customer details, delivery address, payment method, live order summary.
- **Orders saved in MySQL** (orders, order_items, status history) **and mirrored as JSON** in `backend/data/orders/<ORDER>.json`.
- **Server re-prices every item** from the `products` table (catalog in `data/products.json`), so browser prices can't be tampered with.
- **Order confirmation / invoice** (`order-success.html`): shows every detail, printable.
- **Track order** (`track-order.html`): order number + phone.
- **Admin panel** (`/backend/admin/`): live dashboard (revenue, orders, top products, activity feed), orders, customers, contact messages, CSV/JSON export.
- **Customer panel** (`/backend/customer/`): overview, order tracking, profile & password. Orders placed while signed in are saved to the account.
- **Sign in / sign up** for both roles: `/account.html`.
- Contact form is saved to the database too. Emails are sent with PHP `mail()` and logged in `data/mail.log`.

## What's new in the advanced panels
**Admin panel**
- Dashboard: 7/14/30/90-day range, period-over-period deltas, alert bar, live activity feed, top cities, payment-method split, open tickets.
- **Orders**: status tabs with counts, search + payment/method/customer/date filters, sortable columns, pagination, **bulk status/payment updates**, filtered CSV export.
- **Order detail**: one-click "next step" button, courier + tracking number + delivery date (shown to the customer), cancel reason, refund marking, packing slip and delivery label (`print.php`), WhatsApp link, re-send email.
- **Customers**: segments (new, repeat, VIP, inactive), sorting, spend/tier, private staff notes, delete account, CSV export. **Reports**: date presets/custom range, KPIs vs previous period, by brand/city/payment/weekday, top products and customers, daily CSV.
- **Support tickets** and **contact-message replies** (emailed and saved), **Coupons**, **Products** (add/edit/hide + sales stats), **Team** (add/reset/remove admins), **My profile**, **Activity log** (audit trail).
- `Ctrl/⌘ + K` global search, alert bell, nav badges, confirm dialogs, toasts.

**Customer panel**
- Dashboard with membership tier, 6-month spending chart, live tracking, buy-again, wishlist, tickets, updates.
- Orders with search/filters/pagination; order page with tracking, **cancel (while pending/confirmed)**, **Order again** (fills the storefront cart) and "Need help?".
- **Browse catalogue** with wishlist hearts and Add-to-cart, **Wishlist**, **Address book** (default address pre-fills checkout), **Support tickets**, **Notifications**, profile with security tab, **download my data** and **delete account**.
- **Coupons at checkout**: customers enter a code on `checkout.html`; the server re-validates it when the order is placed.

**Reviews & ratings**
- Customers: **Reviews** page – rate products from *delivered* orders (1–5 stars + comment, editable), and **rate the website** by topic (design, checkout, delivery…), optionally allowing their comment to be shown publicly.
- Admin: **Inbox → Reviews & feedback** – approve/hide/delete, public replies, rating breakdown, website feedback by topic. Set `reviews_auto_approve` to `false` in `config.php` to approve every review first.
- Storefront: a "What customers say" section on `index.html` (public API `api/reviews.php`, shows first name + initial only) and star ratings on the customer catalogue.

Upgrade: nothing to run. The database upgrades itself on first use (`ensure_schema_v3` in `db.php`; new tables `addresses`, `wishlist`, `tickets`, `ticket_messages`, `coupons`, `activity_log` plus new order/customer columns). Tier thresholds and the customer-cancel rule are in `config.php`.

## Setup (XAMPP / WAMP / any PHP 7.4+ host with MySQL)
1. Copy the whole project folder into `htdocs` (or your web root). The site must be opened through `http://localhost/...`, not by double-clicking the HTML files.
2. Create `backend/config.local.php` with your DB login:
   ```php
   <?php return ['db_user' => 'root', 'db_pass' => '', 'db_name' => 'durable_furnitures'];
   ```
3. Run the installer once (creates DB, tables, loads products, creates admin):
   - Browser: `http://localhost/<folder>/backend/install.php`, or
   - CLI: `php backend/install.php admin YourStrongPassword`
4. **Delete `backend/install.php`** afterwards. Admin panel: `/backend/admin/`.
5. Edit store details, bank account text, delivery fee and payment methods in `backend/config.php` (or override in `config.local.php`).

## Adding / changing products
The site's product cards live in the HTML. Every product must also exist in the `products` table with the **same name and price** or checkout will reject it. Edit `backend/data/products.json` and re-run `php backend/install.php admin <password>` (it upserts), or insert rows directly in MySQL.

## Security notes
- Apache: `.htaccess` files block `config.php`, `db.php`, `schema.sql`, `data/`. **Nginx**: add `location ~ ^/backend/(data|config|db|schema) { deny all; }`.
- Use HTTPS in production. Admin uses sessions, CSRF tokens, hashed passwords.
- Honeypot + per-IP rate limit (5 orders / 10 min) + duplicate-submit protection are built in.
- Online card payment is NOT included (COD and bank/Easypaisa/JazzCash transfer with manual "paid" marking). A gateway like PayFast can be added in `place_order.php`.

## Accounts: admin & customer
- Everyone signs in at `account.html` (Customer / Admin tabs). Customers can sign up freely.
- **Admin sign-up:** the first admin can sign up freely if none exists. After that a secret code is required. Set it in `backend/config.local.php`:
  ```php
  <?php return [admin_signup_code => a-long-secret-only-you-know];
  ```
  Leave it empty to keep admin sign-up closed. Share the code only with trusted staff.
- Existing installs upgrade their database automatically on first use (new `customers` table, `orders.customer_id`, admin email/name). Nothing to run.
- Guest orders placed before an account existed are not auto-linked (that would let anyone claim an order by typing its phone number). Customers can still use Track Order.
- Admins can disable/re-enable a customer from Admin → Customers.

## Site-wide polish layer
`polish.css` + `polish.js` are loaded on every page (storefront, account page, both panels). They add: scroll-progress bar, scroll-reveal animations, cursor-reactive card glow, button ripple, lazy-loaded images with shimmer, smooth page transitions, focus rings, a gold scrollbar, and the `/` shortcut to open search. All motion switches off for visitors who prefer reduced motion. To remove it entirely, delete the two files and the matching `<link>` / `<script>` tags.
