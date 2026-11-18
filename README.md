# CaliChat — multi-tenant agentic messaging AI

WhatsApp (Meta or Twilio) · Messenger · Instagram → a Gemini agent that answers customers in English, Sinhala and Tamil, looks up products and orders in the business's own systems, and can create, update and cancel orders and record leads — with a human able to step in at any time. **No ERP? Use the built-in Store and Leads CRM.** WhatsApp templates and automatic follow-ups (order shipped, payment reminder, review request, quiet-chat nudge) are included.

> **License: All Rights Reserved.** Copyright (c) 2026 Mohammed Jamaal Rushdi. This repository is a portfolio showcase: the source is public so it can be viewed and assessed, but it is **not open source**. You may not use it commercially, host it for others, copy it, modify it or build on it without written permission. See [LICENSE](LICENSE).

**Stack:** PHP 8 (works on 8.0), MySQL/MariaDB, HTML/CSS/vanilla JS, Google Gemini API, optional TencentDB Agent Memory. No framework, no Composer packages required.

Contents: [1 Requirements](#1-requirements) · [2 Install](#2-install-step-by-step) · [3 First sign-in](#3-first-sign-in-and-setup) · [4 Using the system](#4-using-the-system) · [5 Test with Twilio](#5-test-from-your-own-whatsapp-twilio-sandbox) · [6 Connect Meta](#6-connect-meta-whatsapp-messenger-instagram) · [7 Operations](#7-operations-cheat-sheet) · [8 Production checklist](#8-production-checklist) · [9 Troubleshooting](#9-troubleshooting) · [10 What is in this package](#10-whats-in-this-package)

---

## 1. Requirements
| Need | Notes |
|---|---|
| XAMPP (or any Apache + PHP + MySQL/MariaDB) | Apache 2.4 with `mod_rewrite` and `AllowOverride All` for the web folder (XAMPP default). |
| PHP 8.0 or newer | Extensions: `curl`, `mbstring`, `openssl`, `pdo_mysql` (all on in XAMPP). |
| MariaDB 10.4+ / MySQL 8 | InnoDB, utf8mb4. |
| A Gemini API key | https://aistudio.google.com/apikey |
| For testing channels (optional) | A free Twilio account and [ngrok](https://ngrok.com) (Twilio/Meta must reach your computer over public https). |

## 2. Install step by step
1. **Unzip** the package into `C:\xampp\htdocs\calichat` (so the site is `http://localhost/calichat`). Another folder name works too; then set `APP_URL` in step 3.
2. **Start Apache and MySQL** in the XAMPP Control Panel.
3. **Create your settings file.** Copy `.env.example` to `.env` and edit:
   ```
   APP_URL=http://localhost/calichat
   APP_KEY=<64 random hex characters>      # create one:  php -r "echo bin2hex(random_bytes(32));"
   DB_HOST=127.0.0.1
   DB_NAME=calichat_ai                     # a NEW database name; do not reuse another project's database
   DB_USER=root
   DB_PASS=
   GEMINI_API_KEY=<your key>
   GEMINI_MODEL=gemini-3.1-flash-lite      # gemini-3.5-flash-lite is faster; check what your key can use
   ```
   Keep `.env` private. **Never lose `APP_KEY`**: tokens and API keys saved in the database are encrypted with it.
4. **Create the database — choose ONE:**
   - **A. Fresh install (recommended for a new setup).** Open a terminal in the project folder and run:
     ```
     C:\xampp\php\php.exe install.php
     ```
     It creates the database, applies all migrations, creates a **super admin** and a **Demo Store** tenant (with 24 sample products and 12 orders), and prints both passwords once. Save them.
   - **B. Restore your own backup** (a `mysqldump` from an earlier install of yours; the original development backup is not part of the public repository):
     ```
     C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE calichat_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
     C:\xampp\mysql\bin\mysql.exe -uroot calichat_ai < backup.sql
     C:\xampp\php\php.exe install.php          # applies any newer migrations (does nothing if up to date)
     C:\xampp\php\php.exe cli\seed_demo.php    # re-links the demo connector to THIS machine's key and URL
     C:\xampp\php\php.exe cli\reset_password.php admin@calichat.local   # prints a new password
     C:\xampp\php\php.exe cli\reset_password.php demo@calichat.local
     ```
     (A backup restored under a different `APP_KEY` cannot decrypt secrets saved in it; re-enter channel tokens and per-tenant Gemini keys.)
5. **Start the queue worker** (this is what answers customers). Double-click `start-worker.bat`, or run `C:\xampp\php\php.exe cli\worker.php` in its own window and leave it open. Without it the webhook still works but processes only a few messages itself.
6. **Open** `http://localhost/calichat` and sign in.

## 3. First sign-in and setup
- The first time an **owner** signs in, the **Setup guide** opens: profile → AI agent (it can draft the briefing for you) → knowledge → connect your systems → test in the Playground → connect channels → first real message. Steps turn green automatically.
- The **super admin** (platform operator) manages tenants, limits, Meta app settings and platform health. Log in with the admin account; to see a tenant's workspace you must enter a reason, which is recorded.
- Change passwords under *Change password*. Passwords need 12+ characters.

## 4. Using the system
| Area | What it does |
|---|---|
| **Playground** | Chat with your agent as a customer. “Run write actions” lets it really create/update/cancel in your connected systems (untick for read-only). |
| **AI Agents** | Persona, tone, languages, instructions, which tools each agent may use. |
| **Knowledge** | FAQs/policies, searched by meaning (works across English/Sinhala/Tamil). |
| **Store** | Your own products, stock and delivery areas (add, import CSV, or load samples). One click connects it to the AI, so you need no separate ERP. |
| **Catalogue designer** | Your logo, business name and colours, and the look of the catalogue customers open from WhatsApp (layout, theme, corners, sort, featured products, hidden categories, texts), with a live preview. The same brand is on the product cards the AI sends. |
| **Orders** | Orders from the AI or your team. Pack, ship (courier + tracking), deliver, cancel; mark paid. Customers can be messaged automatically. |
| **Leads** | A board: New, Contacted, Qualified, Won, Lost. The AI adds leads; you assign, add notes and values. |
| **WA Templates** | Write WhatsApp templates with placeholders, see a live preview, get Meta-rule checks, submit for approval and see the result. |
| **Follow-ups** | Automatic messages: order updates, payment reminders, review requests, nudges for quiet chats, appointment confirmations and reminders, ad-lead welcomes, alerts for stale leads. SMS fallback. Every send or skip is logged with the reason. |
| **Appointments** | Services, free times inside your opening hours, bookings by the AI or your team, no double-booking. |
| **Reports** | Revenue and orders from chat, chat-to-order rate, first reply time, how chats ended, ad sources, best sellers, leads, team results, CSV downloads. |
| **Quality tests** | Write conversations your agent must handle well, run them with the real AI before and after you change anything. |
| **Plan & billing** | Your plan, usage, invoices; pay by bank transfer. Self sign-up at `/signup`. |
| **Team** | Besides inviting people, an owner ticks what each person may see and do, and whether they see full phone numbers. |
| **Connectors** | Link your ERP/CRM/store (REST). Each endpoint becomes a *tool*; set what the AI may see; mark write tools and whether they need team approval. |
| **Channels** | WhatsApp, Messenger, Instagram (one-click or manual) and WhatsApp via Twilio. |
| **Inbox** | Every chat; take over, resume AI, assign, internal notes, saved replies, search, desktop alerts. |
| **Approvals** | Actions the AI is waiting to take on tools set to “Require team approval”. |
| **Contacts** | Marketing consent (opt in/out, STOP keywords), data export, delete, forget AI memory. |
| **Campaigns** | Messages to opted-in contacts (WhatsApp templates need Meta approval). |
| **Usage & Monitoring / Alerts / Audit log** | Tokens, cost estimate, latency, tool success, queue health, plan gauges, failures, who did what. |
| **Settings** | Timezone, team hours, own Gemini key, memory engine, retention, notifications. |

**How a customer action is protected:** the AI first shows a *preview* (nothing changes), the customer must reply, then the same details are submitted once. Limits, audit log and optional team approval apply. The full guide is `docs/USER_MANUAL.html` (open it in a browser).

**Demo store:** try “Do you have jeans?”, “ජීන්ස් තියෙනවද?”, “Where are my orders?”, “I'd like 2 black cotton caps delivered to … , cash on delivery”, “Cancel that order”. To get sample orders for *your own* phone number: `php cli\seed_demo.php 94771234567 "Your Name"` (country code, digits only).

## 5. Test from your own WhatsApp (Twilio Sandbox)
1. Twilio Console: note **Account SID** and **Auth Token**; *Messaging → Try it out → Send a WhatsApp message*: note the sandbox number and your `join <code>`; send that join message from your WhatsApp.
2. Run `ngrok http 80`; open CaliChat through the `https://….ngrok…/calichat` address (it then shows the right callback URL).
3. CaliChat → **Channels** → type **WhatsApp via Twilio** → sandbox number, Account SID, Auth Token → Create.
4. Twilio Sandbox settings → *When a message comes in* = the callback URL on the channel card, method **POST**.
5. Press **Verify connection**, run `php cli\seed_demo.php <your number>`, keep the worker running, message the sandbox number.
Free-form replies work within 24 hours of your last message; Twilio templates are not supported. Free ngrok addresses change on restart.

## 6. Connect Meta (WhatsApp, Messenger, Instagram)
- **Manual (pilots/testing):** Channels → add a channel with your Meta app's Phone number ID / Page ID, access token and app secret; paste the shown callback URL and verify token into your Meta app and subscribe to `messages`.
- **One-click (for many customers):** Super admin → *Platform → Settings*: enter the platform Meta app's App ID, App secret (and Embedded Signup configuration ID). Copy the shown webhook URL, verify token and OAuth redirect URI into the Meta app. Tenants then press *Connect Facebook Page & Instagram* / *Connect WhatsApp number*.
- **Reality check:** other businesses can use one-click connect only after your Meta app passes **business verification and App Review** (advanced access to the messaging permissions; Tech Provider status for WhatsApp Embedded Signup). Until then it works only for people with a role on the app. The one-click flows were tested against a local Graph stand-in, not live Meta.

### Using the built-in store, templates and follow-ups
1. **Store** > *Add sample products* (or import your CSV) > set delivery areas and rules > *Use this store with the AI*. Try it in the Playground with *Run write actions* ticked.
2. **Orders** shows what the AI placed; move orders along. **Leads** shows enquiries.
3. **WA Templates** > *Add starter templates* > review > *Submit to Meta*. Enter the WhatsApp Business Account ID first (one-click connected numbers have it already). Press *Check approval status* later; the worker also checks every 5 minutes.
4. **Follow-ups** > *Add recommended set*. Free text is sent only within 24 hours of the customer's last message; after that an approved template is needed. Review requests and quiet-chat nudges go only to customers marked Opted in. Quiet hours (default 21:00 to 08:00) delay sending.
5. **Real-world check before you rely on it.** This was not done in development because no Meta or Twilio accounts were available. Submit one template and wait for approval; ship a test order and confirm the message arrives on your phone; send STOP and confirm promotions stop; message again after 24 hours and confirm the template path is used.

### Product cards, catalogue and the order form (Phase 4)
- **Ask about one product** ("do you have jeans?"): the AI sends a branded **product card** on WhatsApp (your logo and name, photo, price, stock, sizes) with *Add to order* and *More products* buttons.
- **Ask about many** ("what menswear do you have?"): the AI sends your **catalogue** as a *View catalogue* button. The page is private to that customer, opens in the browser, and lets them browse, pick sizes and quantities, change or remove items, and place the order. Design it under **Catalogue designer**; set your logo and name there or in the Setup guide.
- **Place the order** (said directly or indirectly): the customer gets an **order summary with the delivery fee** and **Confirm order / Cancel order** buttons (on the catalogue page too). Confirm places it; Cancel discards it.
- **Hand a chat back to the AI**: after taking over, press *Hand back to AI* in the Inbox. You may leave a note for the AI; if the customer is still waiting, the AI answers at once.
- Meta fetches the logo and card images from your site, so use a public https `APP_URL` in production (on localhost the buttons still work, without images).

### Orders by chat, payments, voice and more (Phase 2 and 3)
- **The AI extracts order details itself.** A customer can write everything in one message (products, size, colour, name, address, payment) in English, Sinhala, Tamil or Singlish. The AI checks it against your catalogue (`check_order`), asks once for what is missing, shows one summary, and places or changes the order after the customer's yes. It remembers name, address and usual payment per customer and offers them next time. WhatsApp location pins are used as the address.
- **Buttons and lists** (Meta WhatsApp): order summaries get Yes / No buttons, product searches get a pick-one list. Settings > *How the assistant replies*.
- **Voice-note replies:** when a customer sends a voice note, short answers come back as a voice note (Gemini speech, converted with ffmpeg). Needs ffmpeg on the server (`FFMPEG_PATH`). Off by default; listen to it in your languages before switching it on.
- **Bank slips:** a customer who owes a bank transfer sends the slip photo; the system reads it, attaches it to the order, flags a wrong amount, and puts the order in *Payments to verify*. A person confirms against the bank statement; the AI never marks anything paid.
- **Invoices:** signed public invoice links; the AI can share one, staff can send one from the order.
- **Product photos** are sent on WhatsApp when a customer asks to see a product (needs an https image link on the product).
- **Catalogue import:** Store > *Import from WooCommerce or Shopify* (read-only, optional 6-hourly refresh).
- **SMS fallback:** Follow-ups > *SMS fallback*: enter your SMS provider's web address and body template; follow-ups can then fall back to SMS when WhatsApp cannot reach the customer.
- **Lead Ads and ad attribution:** point your Meta app's `leadgen` webhook field at `/webhook/meta` (needs the `leads_retrieval` permission); form fills become leads and can trigger a welcome template. Click-to-WhatsApp ad referrals are stored on the contact and shown in Reports.
- **Self sign-up and billing:** `/signup` creates a workspace with a 14-day trial. The platform admin sets bank details and plans under *Billing* and confirms payments. After the trial or paid period plus a 3-day grace period, the AI stops answering new chats (customers get the holding message) until the invoice is paid.
- **Not included on purpose:** courier integrations, payment gateways (PayHere and similar), multi-factor sign-in.

## 7. Operations cheat sheet
| Task | Command (run in the project folder) |
|---|---|
| Install / upgrade the database | `php install.php` |
| Queue worker (leave running) | `php cli\worker.php` · stop: create file `storage\worker.stop` |
| Campaign sending, follow-ups and cleanup without the worker | `php cron\worker.php` every minute |
| Live checks with the real AI (throw-away tenants) | `php tests\e2e\store_e2e.php`, `extract_e2e.php` (order extraction), `quality_e2e.php` (quality tests) |
| Run a business's quality tests (exit code 1 on failure) | `php cli\agent_tests.php <tenant_id> [name]` |
| Reset a password | `php cli\reset_password.php user@example.com` |
| Sample orders for your phone | `php cli\seed_demo.php <number> "<name>"` |
| Build semantic search vectors | `php cli\reindex.php [tenant_id]` |
| Rotate encryption keys | add `APP_KEYS=k2:<64hex>,k1:<old key>` to `.env`, then `php cli\rotate_keys.php` |
| Check TencentDB Agent Memory tools | `php cli\verify_tencent.php` |
| Run the automated tests (97) | `php tests\run.php` (uses a throw-away `calichat_test` database) |
| Health check URL | `/health` (JSON; 503 if DB is down or the queue lags) |
| Back up the database | `mysqldump -uroot --single-transaction calichat_ai > backup.sql` |
Logs: `storage\logs\app-YYYYMMDD.log` (JSON lines with a request id).

## 8. Production checklist
Set `FFMPEG_PATH` only if you want voice replies; `BILLING_TRIAL_DAYS` / `BILLING_GRACE_DAYS` tune sign-up trials. `APP_ENV=production` (forces debug and private-host access off) · HTTPS · a dedicated least-privilege database user with a password (not root) · keep `.env` outside the web folder (`../calichat.env` is read first) · `APP_KEYS` with key ids · worker under a supervisor (systemd / Task Scheduler / NSSM) · real `GEMINI_PRICE_*` values for cost estimates · scheduled database backups · review **Platform → Operations → posture**. There is **no multi-factor authentication yet** (planned). Meta app review as described above.

## 9. Troubleshooting
| Symptom | Fix |
|---|---|
| Blank/500 page | Set `APP_DEBUG=1` (local only) and check `storage\logs`. Confirm the `curl`, `mbstring`, `openssl`, `pdo_mysql` extensions. |
| `/calichat/app/...` shows 403 or 404 | `mod_rewrite` off, or `AllowOverride None`. Enable both and restart Apache. |
| Customers get no reply | Worker not running; no active agent; chat is in Human mode; monthly limit reached; Gemini key/model wrong (see *Platform → Operations* and *Alerts*). |
| Gemini 404 “model not found” | Models change. List what your key can use: `https://generativelanguage.googleapis.com/v1beta/models?key=KEY`, then set `GEMINI_MODEL` / `GEMINI_FALLBACK_MODEL`. |
| MariaDB “Host not allowed” / odd Aria errors | Repair the server's `mysql` system tables (this happened once on the original machine); it is not caused by CaliChat. |
| Twilio/Meta webhook rejected | Use the public **https** URL shown on the channel card; Twilio 403 = wrong Auth Token or URL mismatch; Meta 401 = wrong app secret. |
| Restored backup: tools fail | Run `php cli\seed_demo.php` (re-encrypts the demo connector); re-enter other secrets under the new `APP_KEY`. |
| Locked out | Wait 15 minutes or run `php cli\reset_password.php`. |

## 10. What is in this package
```
index.php            front controller (routes)         .htaccess   rewrite + blocks internals
app/                 pages, API, webhooks, mock ERP    config/     bootstrap + helpers
src/                 Core (db, auth, crypto), Services (agent, gemini, tools, queue, meta, twilio…), Memory
database/migrations  001…009 SQL                       LICENSE   All Rights Reserved (portfolio showcase)
cli/                 worker + admin tools              cron/       periodic tick
assets/              CSS + JS                          tests/      88 automated tests + manual live e2e scripts
docs/                USER_MANUAL.html, AUDIT_REPORT.html
HANDOVER.md          technical hand-over for developers / AI assistants
.env.example         settings template (no secrets)
```
Not included on purpose: `.env` (your secrets), logs, any API keys.
