# 🚀 Cloudflare Worker for WordPress Cron  
### **Single-Site Version (Main Branch)**  
Offload `wp-cron.php` from your WordPress site and run it reliably using Cloudflare Workers.

---

![Cloudflare Workers](https://img.shields.io/badge/Cloudflare-Workers-F38020?logo=cloudflare&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-Cron-21759B?logo=wordpress&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green)
![Status](https://img.shields.io/badge/Mode-Single--Site-blue)
![Cron Powered](https://img.shields.io/badge/Cron-1min%20interval-success)

---

# 📚 Table of Contents
- [✨ Overview](#-overview)
- [🎯 Why Offload wp-cronphp?](#-why-offload-wp-cronphp)
- [🌐 Worker Modes](#-worker-modes)
- [🧩 Key Features (Single-Site)](#-key-features-single-site)
- [⚙️ Setup Instructions](#%EF%B8%8F-setup-instructions)
  - [1. Create the Worker](#1-create-the-worker)
  - [2. Add Environment Variables](#2-add-environment-variables)
  - [3. Add the Route](#3-add-the-route)
  - [4. Security Rule](#4-security-rule)
  - [5. Add Cron Trigger](#5-add-cron-trigger)
  - [6. Disable WP Cron Locally](#6-disable-wp-cron-locally)
  - [7. Testing](#7-testing)
  - [8. Monitoring](#8-monitoring)
- [🛠 Troubleshooting](#-troubleshooting)
- [🛡 Extra Notes & Optional Security Enhancements](#-extra-notes--optional-security-enhancements)

---

# ✨ Overview
This Worker lets Cloudflare call your WordPress `wp-cron.php` reliably every minute, replacing WordPress’s built-in pseudo-cron which depends on user traffic.

This **Main Branch version supports ONE domain via a Worker Route**: example.com/wp-cron.php*


Your Worker automatically:
- fakes the URL based on your env variable  
- forwards the request  
- signs it with a secret header  
- runs every minute  
- logs events  

---

# 🎯 Why Offload wp-cron.php?

### 🧨 Problems with normal wp-cron:
- only runs when a visitor hits the site  
- fails on low-traffic sites  
- can stack up, slowing the site  
- triggers multiple times under load  
- blocked by some hosting providers  

### 🚀 Advantages of Cloudflare-based cron:
- runs **on time, every time**  
- 0 load on your server  
- bypasses slow hosting cron systems  
- secure request signing  
- works even if site is under attack  
- logs available in Cloudflare dashboard  
- 100% free on CF free tier  

---

# 🌐 Worker Modes
This repository provides **two completely separate Workers**:

### ✔ **Main (this file): Single-site mode**
- Uses a Cloudflare Worker **Route**
- Only handles **ONE website**
- Reads the domain from `DEFAULT_DOMAIN`
- Best for people who want standard WordPress-compatible behavior

### ✔ Multi (other branch): Multiple-site mode
- No route  
- No domain detection  
- Cron hits multiple URLs in a list  
- Perfect for agencies or hosting many sites  

➡️ The correct mode is selected simply by choosing the proper branch.

---

# 🧩 Key Features (Single-Site)

| Feature | Description |
|--------|-------------|
| 🎯 Single WordPress Site | Worker bound to `/wp-cron.php*` |
| 🔐 Secure Auth Header | Worker signs cron requests with `X-Worker-Auth` |
| ⏱ 1-Minute Cron | Cloudflare Scheduled Triggers |
| 🚦 Automatic Traffic Passthrough | Worker only executes when route matches |
| 🔍 Logging | Cloudflare Dashboard → Workers → Logs |
| 🧘 Zero overhead | wp-cron.php disabled locally |
| 🛡 Skip Security Rule | Ensures Worker-call is not blocked |

---

# ⚙️ Setup Instructions

## 1. Create the Worker
Go to  
**Cloudflare Dashboard → Workers & Pages → Create Worker**

Paste your JS Worker code (your existing version).

Save & deploy.

---

## 2. Add Environment Variables  
Dashboard → Worker → Settings → Variables & Secrets

| Type      | Name               | Value |
|-----------|--------------------|--------|
| Plaintext | `DEFAULT_DOMAIN`   | yourdomain.com |
| Secret    | `WORKER_SECRET_KEY` | (any long random string) |

---

## 3. Add the Route

**Workers → Routes → Add Route**

Bind it to your Worker.

---

## 4. Security Rule  
Since many sites block wp-cron.php, add:

**Security → WAF → Custom Rules → Create Rule**

**Expression**


**Action:** Skip  
**Skip components:** All remaining custom rules  
**Logging:** Enabled  
**Order:** After any wp-admin rule  

This ensures WordPress cron requests are not blocked.

---

## 5. Add Cron Trigger

Workers → Your Worker → **Triggers**

Add:

Handler: `scheduled()`

---

## 6. Disable WP Cron Locally

Edit your `wp-config.php`:

```php
define('DISABLE_WP_CRON', true);


## 7. Testing
Browser test - visit: https://yourdomain.com/wp-cron.php

Cloudflare test

Workers → Your Worker → Quick Edit → Preview
Select "wp-cron.php" as test path.

8. Monitoring

Workers → Your Worker → Observability

You will see:

total cron executions

success / errors

logs (if enabled)

🛠 Troubleshooting

| Error                     | Meaning                    | Fix                                |
| ------------------------- | -------------------------- | ---------------------------------- |
| **1101 Worker error**     | JS error inside Worker     | Check logs / syntax                |
| **500 Cron Failed**       | WordPress returned non-200 | Open wp-cron.php manually to debug |
| **404 in Worker Preview** | You tested wrong path      | Use `/wp-cron.php`                 |
| **No metrics**            | No requests hit the Worker | Check route & skip rule            |
| **401 or forbidden**      | Server blocks CF cron      | Ensure skip rule is correct        |

🛡 Extra Notes & Optional Security Enhancements
1. Restrict server to only accept Worker-signed cron

Apache

<Files "wp-cron.php">
  Order Deny,Allow
  Deny from all
  <IfModule mod_headers.c>
    SetEnvIf X-Worker-Auth "^.{1,}$" worker_auth=true
  </IfModule>
  Allow from env=worker_auth
</Files>

Nginx

location = /wp-cron.php {
    if ($http_x_worker_auth = "") { return 403; }
    fastcgi_pass php-handler;
}

2. Block wp-cron.php from the public

You can fully block it since Cloudflare Worker hits it directly.

3. Add CF firewall rate limiting

Optional but useful.

✅ Done

This is the official documentation for the MAIN Worker branch.
