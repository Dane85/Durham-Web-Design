# Durham Web Design — Official Agency Flagship

> Ultra-fast, bespoke web development and managed Canadian cloud hosting engineered for businesses across Durham Region, Ontario.

🌐 **Live Website**: [https://durhamweb.design](https://durhamweb.design) (also [https://www.durhamweb.design](https://www.durhamweb.design))  
📊 **Operations HUD**: [https://ops.durhamweb.design](https://ops.durhamweb.design)

---

## ⚡ Overview

**Durham Web Design** is a high-converting digital agency landing page tailored for small and medium businesses in Durham Region (Oshawa, Whitby, Ajax, Pickering, Bowmanville, Courtice, and Brooklin).

Built on dedicated **Epic Expressions** cloud infrastructure (Hetzner Ashburn Node CPX 11 at `5.161.161.222`), this project delivers sub-second page performance (<0.6s GTA latency), zero dependency overhead, and transparent Canadian Dollar (CAD) pricing.

---

## 🛠️ Tech Stack & Architecture

- **Format:** Zero-dependency, standalone HTML5 / CSS3 / Vanilla JavaScript
- **Design System:** Obsidian dark theme with electric cyan and emerald accents, optimized for AMOLED screens
- **Typography:** `Plus Jakarta Sans` & `JetBrains Mono`
- **Branding:** Official circular glowing "DW" emblem (`assets/images/logo.png`) and browser favicon (`assets/images/favicon.png`)
- **Effects:** High-contrast responsive layout, glassmorphism (`backdrop-filter`), and CSS micro-interactions
- **Form Handling:** Web3Forms API integration for instant lead capture and proposal intake
- **Operations Dashboard:** Live server telemetry and multi-device cloud synchronization (`dashboard/`)

---

## 💰 Commercial Pricing Tiers (CAD + Ontario HST)

- **Local Starter:** $1,450 CAD setup + $49/mo CAD care
- **Growth Business:** $2,850 CAD setup + $129/mo CAD care *(Featured)*
- **Custom App / E-Commerce:** $4,950+ CAD setup + $249+/mo CAD care

---

## 🚀 Deployment Instructions

### 1. Git Workflow
```powershell
git add .
git commit -m "update message"
git push origin main
```

### 2. Live VPS Synchronization
The production server polls changes automatically every 2 minutes. To manually trigger instant synchronization:
```powershell
ssh root@5.161.161.222 "git -C /var/www/durhamwebdesign pull origin main && nginx -s reload"
```
Or use the server deployment CLI directly:
```bash
deploy-site durham
```

---

© 2026 Durham Web Design. Powered by Epic Expressions cloud infrastructure.