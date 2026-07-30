# RPT System + OR System (Pinagsama)

Base: **rptsystem** (may login). Idinagdag ang buong **OR/Lot/Title Conversion module** sa loob ng `or-system/` folder.

## Setup

1. I-upload ang buong `rptsystem/` folder sa `htdocs`, gaya ng dati.
2. Buksan ang phpMyAdmin → piliin ang existing na **`rpt_system`** database mo (yung ginagamit mo na, base sa `rpt_system_xampp.sql`).
3. I-run ang `or-system/database/add_or_module.sql` (SQL tab → paste → Go). Idadagdag lang nito ang mga bagong tables (`or_records`, `lot_master`, `or_settings`, `subdivisions`, `title_conversions`, atbp.) — hindi nito hahawakan/babaguhin ang existing tables mo (`rpt_users`, `rpt_records`, `tax_rates`, `chat_messages`).
4. Ayan lang — mag-login ka gaya ng dati sa `index.html`, makikita mo na sa sidebar ang bagong section na **"OR System"** (Issue OR, Search, Lot Master, Title Conversion, Reports, Import, Settings).

## Notes

- Same login na ginagamit mo (`rpt_user` sa sessionStorage) ang gumagatekeep sa mga OR pages — kung hindi naka-login, babalik sa login page.
- Hiwalay pa rin ang mga file (`or-system/`) para walang nag-overlap na `api.php`/`import.php` sa dalawang system, pero iisa lang ang database.
- Tinanggal ang `hi.php` (test/debug file lang, hindi na kailangan).
