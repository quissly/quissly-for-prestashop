# Quissly for PrestaShop

Quissly's AI search, chat and product discovery for your PrestaShop store. When a shopper
searches, Quissly decides which products match and in what order; **your own theme still shows
them**, exactly as it shows any search today. If Quissly ever cannot answer, PrestaShop's own
search answers instead, so search never breaks.

The module edits none of your files (theme, templates, catalog, prices or checkout). Through
PrestaShop's own hooks it adds a search overlay over your theme's search box and, when you switch
them on, voice search, image search and the QChat shopping assistant.

## Requirements

- **PrestaShop 1.7.7 or newer** (tested on 1.7.7.8), one shop.
- **PHP 7.1 or newer**, with the `curl`, `openssl` and `json` extensions. Automatic updates also
  need `zip` and `sodium` (`sodium` is built into PHP 7.2 and newer; on 7.1 updates are installed
  by hand).
- Access to your PrestaShop back office. Server access is needed only to add the cron line (step 4).
- An accurate server clock (NTP on): every request to Quissly is signed with the time, and a clock
  more than a minute off is refused.

## Install in four steps

### 1. Upload the module

Download **[quissly.zip](https://github.com/quissly/quissly-for-prestashop/releases/latest/download/quissly.zip)**
(always the latest version). In the back office open **Modules → Module Manager**, click
**Upload a module** (top right) and drop the zip in. PrestaShop installs it and shows **Configure**.

### 2. Run Quissly Setup

Open **Improve → Quissly** in the menu. Until setup is finished, every Quissly page opens
**Quissly Setup**:

1. **Your details** - your email (Quissly's account and billing emails go there; it cannot be
   changed later, and it must be on a real, public domain), your store name and a short "What do
   you sell?" (drafted from your catalog - check it). **Save & continue** creates your Quissly
   account and connects the store - there is nothing to copy or paste.
2. **Choose a plan** - Search or Chat, monthly or annual, paid by **Manual invoice** (the default)
   or **Credit card**. The free search plan starts at once. Once chosen, the plan is changed in
   **Billing**, not here.
3. **Go live** - Quissly builds your services (about a minute), then your products are sent to
   Quissly; this starts by itself and the page updates without a refresh. When everything shows
   done, press **Finish Setup**: Quissly now answers your store's search. **Do this later** saves
   setup with search left off.

### 3. Check your storefront

Search your store for something you sell. The results look as always - your theme renders them.
Add `?quissly_debug=1` to a search page's address to see which engine answered (a small badge,
visible only with that parameter).

### 4. Add the cron line

Background work - sending product changes to Quissly, shopping activity, update checks - runs
from a cron line. **Quissly → Dashboard → Automatic sync** prints the exact line for your store,
with its own secret token; it looks like this:

```
*/5 * * * * curl -s "https://your-shop.example/module/quissly/cron?token=..."
```

Add it under *Cron jobs* in your hosting panel (or to a server crontab). The Dashboard then says
**Running**. Without it, changed products are sent every few minutes while shoppers visit your shop,
whenever someone opens a page of the back office, or at once when you press a sync button on the
Dashboard - so a shop nobody visits for a while waits that long.

## The Quissly menu

**Improve → Quissly** has four pages:

- **Configuration** - every switch and list described below, in folding sections.
- **Dashboard** - whether Quissly is answering your search, the connection (with **Test
  connection**), which features are on, the catalog sync (products in Quissly, queued, last run),
  the sync buttons, automatic sync (cron) and the sync log.
- **Quissly Admin Panel** - Quissly's own console, signed in for you.
- **Billing** - your plans and this month's usage, invoices with their PDFs, change plan, buy
  extra requests, automatic top-up, cancel or keep a plan, update your card and request a refund.
  Every charge is shown before you confirm it; payments go through Paddle, never through PrestaShop.

## Configuration

| Setting | Default | What it does |
|---|---|---|
| **QSearch: Quissly answers storefront search** | on (set by Finish Setup) | Off = PrestaShop's own search. |
| **Search overlay takes over the theme's search box** | on | Clicking your search box opens Quissly's search bar over the page. A theme without a search box gets a search button either way. |
| **Search overlay layout** | Wide | *Wide* spans the screen and shows products in columns; *Center* is a compact panel in the middle of the screen. Phones look the same either way. |
| **Search button mount point** | empty | Where that search button goes (a CSS selector); empty = chosen for you. |
| **Voice search** | off | A microphone button (up to 5 seconds of speech). Needs your shop on HTTPS. |
| **Image search** | off | A camera button; shoppers can also paste a photo. |
| **QChat assistant** | off | Quissly's chat on every page; its **Add to cart** puts products in your real PrestaShop cart. |
| **Quick Recommendations** | off | Products as the shopper types - under the search overlay's bar, or under your theme's search box - with their image, name and price from your store. Quick must also be switched on for your Quissly account. |
| **Shopping activity for Quissly analytics** | on | Sends product views, searches, adds to cart and wishlist, and paid orders to Quissly's analytics (see *Privacy*). |
| **Search Examples** | generated | The example searches typed into the empty search bar, one list per shop language, up to 20. Quissly writes them from your catalog after the first sync; edit, remove or reset them here. |
| **Search Suggestions** | on, Automatic | Buttons under the empty search bar. *Automatic* shows what Quissly generated; *Manual* shows your own, up to 10 per language. |
| **Show recent searches** | on | The shopper's last 5 searches beside the suggestions, each with a bin to remove it. Kept only in the shopper's own browser, never sent anywhere. |
| **Most Loved Products** | off | 8 product cards in the overlay, picked by you (plus 4 backups that step in when a pick is sold out or hidden). Click a field to browse your catalog, drag to reorder. |
| **Catalog data** | what your shop shows | Which product features are sent to Quissly. Changing it sends your whole catalog again. |
| **Update check frequency** | 30 minutes | How often the module looks for a new version (5 minutes for testing, up to a day, or **Never**). |

A feature your plan does not include - the chat on a Search plan, or search on a Chat plan - is shown
off and greyed out with a link to Billing, and stays off on the storefront until you add it. Your
setting is kept for then.

## Updates

New versions install themselves. At the frequency above, the module checks Quissly's releases on
GitHub; a newer one is downloaded, **checked against Quissly's signature** (a file without it is
never installed), and put in place with the old files kept as a backup - any problem puts the
previous version back. The new version finishes its own setup on the next page load. Your settings,
credentials and synced data are kept. The Dashboard's sync log records each update and, if one is
not arriving, why.

For this the `modules/quissly` folder must be writable by the web server, and the server must reach
`github.com` and `raw.githubusercontent.com` over HTTPS.

## How search behaves

- **Order:** results come in Quissly's order. Sorting by **price** is honoured; other sorts keep
  Quissly's order.
- **Filters:** when a shopper narrows a search with the faceted search filters, PrestaShop's own
  search answers, so the filter is always respected.
- **Products with combinations** are one card, as in your catalog; Quissly matches the combination
  (colour, size...) a shopper asked for.
- **Before the first sync** (or with QSearch off) shoppers get PrestaShop's own search, and nothing
  of Quissly appears on the storefront.
- **Languages:** product names, descriptions and prices are shown by PrestaShop in the shopper's
  language as always. The module's own texts are in English, Georgian and Russian.

## Privacy

- To count a returning visitor once, a guest gets one first-party cookie on their first search,
  `quissly_uid` (a random id, one year).
- Each search tells Quissly `customer:<id>` for a signed-in customer or `guest:<random id>`, plus
  the device type and operating system. Shopping activity sends product ids, quantities, prices
  and order totals. **No name, email, address or IP address is ever sent.**
- Requests are signed on your server; no key or token ever reaches the browser.

## Switching off and removing

- **Off for now:** disable the module in **Module Manager**, or switch QSearch off. Search goes
  back to PrestaShop's own at once; nothing is lost.
- **Remove:** uninstall it in **Module Manager**. ⚠️ This deletes the module's tables, and with
  them the store's Quissly connection, which cannot be made again with the same email. Contact
  Quissly first if you may want it back; prefer disabling for anything temporary.

## Troubleshooting

| What you see | What to do |
|---|---|
| Search looks the same as before | Setup not finished (**Finish Setup**), QSearch off, or the first sync not done yet (Dashboard). Check with `?quissly_debug=1`. |
| Product changes do not reach Quissly | Add the cron line (step 4), or press **Send queued changes now** on the Dashboard. |
| Dashboard shows a 401, 402 or 403 | Quissly refused the store: the plan, payment or connection needs attention. **Test connection** says which; contact Quissly if it persists. |
| Every request fails | The server clock is off - turn NTP on. |
| An update is not arriving | The sync log says why (folder not writable, GitHub unreachable, PHP without `sodium`). |
| Search sometimes falls back to PrestaShop's | Quissly took too long to answer (a few seconds); check the server's connection to `api.quissly.com`. |

Errors are also logged under **Advanced Parameters → Logs** (source "Quissly"). The module never
logs your key or token, so log lines are safe to send to Quissly support.

## What the module adds to your store

- Its own folder only: `modules/quissly/`, plus `var/quissly_update/` for an update's backup.
- Ten database tables starting `ps_quissly_search_` (the sync queue, what Quissly holds, the sync
  log, the module's state - including the connection, encrypted with your shop's own cookie key -
  and small tables for voice/image results, rate limits and shopping activity).

## Manual connection (instead of Setup)

Only if Quissly gave you credentials to use instead of **Save & continue**: put the private key
outside the web root (readable only by the web-server user, `chmod 600`) and add to
`config/defines_custom.inc.php`:

```php
define('QUISSLY_BEARER_TOKEN', 'YOUR_BEARER_TOKEN');
define('QUISSLY_PRIVATE_KEY_PATH', '/etc/quissly/quissly_private.pem');
define('QUISSLY_ENVIRONMENT', 'prod');
define('QUISSLY_PROJECT_ID', 'YOUR_PROJECT_ID');   // for the Admin Panel and QChat
define('QUISSLY_ACCOUNT_EMAIL', 'you@example.com'); // for the Admin Panel and QChat
```

These lines win over a Setup connection. Check the PHP syntax carefully - a mistake in that file
takes the whole shop down. Then press **Start initial sync** on the Dashboard.

## Support

Contact Quissly with what the Dashboard shows and the relevant lines from **Advanced Parameters →
Logs**.
