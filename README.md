# RopaExpress Mercado Libre Sync

**🔥 Lightweight, free WooCommerce ↔ Mercado Libre stock sync.**  
Built for store owners who just want inventory to match across platforms — with zero price/title interference.

---

## 🧠 What It Does

- Syncs stock **both ways** between WooCommerce and Mercado Libre using official APIs.
- No price, title, or description syncing — **just inventory**.
- Event-driven: real-time sync via WooCommerce hooks and Mercado Libre webhooks.
- No cron jobs. No bloat. No SaaS middleman.

---

## ⚙️ Requirements

- WordPress 6.0+
- WooCommerce 9.0+
- PHP 7.4+

---

## 🚀 Installation

1. Copy this repo to your plugins folder:
  
  ```bash
  wp-content/plugins/rx-mercadolibre-sync/
  ```
  

2. Activate the plugin from **WP Admin → Plugins**.
  
3. Navigate to **WooCommerce → Mercado Libre Sync**.
  
4. Enter your **App ID** and **Secret** (get them from [ML Dev Console](https://developers.mercadolibre.com.ar/en_us/application-manager)).
  
5. Click **Save**, then **Authorise Mercado Libre**.
  
6. For each product you want to sync:
  
  - Edit it in WooCommerce.
    
  - Add a custom field:  
    `Key: _rx_ml_item_id`  
    `Value: MLA123456789` (your ML listing ID)
    
7. Done! Inventory now syncs both ways instantly.
  

---

## 🕹 How It Works

**Woo ➜ ML:**

- Stock changes trigger `PUT /items/{id}` with updated `available_quantity`.

**ML ➜ Woo:**

- Webhooks (orders_v2) hit your site.
  
- Plugin pulls order, matches ML item to WooCommerce product, reduces local stock.
  

---

## 🧩 FAQ

**Q: Can I keep different prices or titles on ML and Woo?**  
A: Yes. This plugin only syncs stock. Everything else stays untouched.

**Q: Does it support variable products / SKUs?**  
A: Not yet — single products only for now (roadmap item).

**Q: Any cron jobs or third-party services involved?**  
A: Nope. Event-driven. Self-hosted.

---

## 🪪 License

GPL‑2.0‑or‑later  
Fork it, remix it, use it — just don't charge people for what they could get free here.

---

## 🧠 Credits

Built by [@SanThought](https://github.com/SanThought) — originally forked and surgically refactored from [Conexão WC Mercado Livre](https://github.com/conexao-woocommerce/ml).

---

## ✨ Coming Soon

- Variation / SKU support
  
- Debug/log viewer in settings
  
- Selective product sync toggle
