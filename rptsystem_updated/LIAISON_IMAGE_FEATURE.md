# Liaison Image Attachment — Ano ang Binago

## Mga na-update na files
1. **api.php**
   - Nagdagdag ng `image_path` column sa `liaison_records` table (auto-add pag existing na table, walang manual ALTER na kailangan).
   - Na-update ang `saveLiaisonRecord()` para i-save/i-update/i-clear ang image path.
2. **upload_liaison_image.php** (BAGONG FILE)
   - Hiwalay na endpoint para sa image upload (multipart/form-data, hindi JSON).
   - Nag-va-validate ng file type (jpg/png/webp), size (max 5MB), at tunay na image.
   - Nagsa-save sa `uploads/liaison/YYYY/MM/` folder na may unique filename.
3. **home.html**
   - May bagong "Attachment (Image)" field sa Add/Edit Liaison modal, may preview at "Alisin ang image" button.
   - May bagong thumbnail column (📎) sa My Liaison Requests table — click para buksan ang full image sa bagong tab.
4. **uploads/** folder
   - Kasama na yung folder structure + `.htaccess` (naka-block ang directory listing at PHP execution dito para safe).

## Paano gamitin
1. I-extract/i-overwrite ang mga files sa XAMPP htdocs mo (existing folder mo).
2. Siguraduhin na **writable** ang `uploads/` folder (default naman writable sa XAMPP/Windows).
3. Buksan lang ang system — automatic na madadagdag ang `image_path` column sa unang request mo sa Liaison view (walang kailangang gawin sa phpMyAdmin).
4. Sa Add/Edit Liaison Record modal, pumili ng image sa "Attachment" field, tapos i-save — mai-upload muna ang image bago ma-save ang record.

## Bakit hindi babagal ang system
- Ang `image_path` column ay text lang (VARCHAR) — hindi binary/BLOB, kaya mabilis pa rin ang mga query at maliit lang ang dagdag sa SQL backups.
- Ang actual image files ay naka-store sa filesystem (`uploads/liaison/...`), hindi sa database.
- Ang image list/table view ay hindi nagla-load ng buong image data — thumbnail lang (32x32) ang lumalabas sa list, at ang full image ay on-demand lang (pag ni-click).
