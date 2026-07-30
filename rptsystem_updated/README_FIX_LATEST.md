# README_FIX_LATEST.md — Buod ng huling pagbabago

## Mga file na binago/dinagdag sa package na ito
- `api/subdivmon.php` — inayos ang Liaison <-> Subdivision Monitoring
  matching (dating exact-string match lang, kaya "01" vs "1" o extra
  space ay hindi nagtutugma; naka-normalize na ngayon, awtomatikong
  nag-re-rebuild sa unang tawag).
- `js/subdivmon.js` — dagdag na "Lot Status" column sa lots table
  (asul — galing sa imported Excel), tabi ng dating "Status" column
  (pula — galing sa Liaison record).
- `import_crc_assessed_value.php` — updated na, Status na rin ang
  kasama bukod sa Assessed Value (gumagamit pa rin ng dating
  crc_import_with_status.csv, isang subdivision lang).
- `import_lot_full.php` — BAGO, GENERIC na importer (gagana sa
  LAHAT ng subdivision, hindi lang CRC): buong Lot Details
  (Area, House Area, Code, CTS No., Buyer, Location, Lot Owner, TCT,
  Status, Date of Fullpayment, Remarks, PIN, TD#, Assessed Value,
  Last OR#, SOA Batch, SOA Year) + bagong `lot_or_history` table
  (per-year na AS#/JV#/MC#/OR Number/FR/TO/Amount/Date/Remarks,
  2007-2026).
- `extract_lot_data.py` — GENERIC na Python extractor. Patakbuhin
  ito sa ANUMANG subdivision Excel na may parehong layout
  (S/P/B/L + Status + per-year OR columns):
  ```
  python3 extract_lot_data.py "SubdivisionName.xlsx"
  ```
  Lalabas: `lot_details.csv` at `or_history.csv` — i-upload sa
  `import_lot_full.php`.
- `lot_details_CRC.csv` / `or_history_CRC.csv` — sample output na
  (galing sa Calamba - CRC.xlsx) na pwede mo nang i-upload agad sa
  `import_lot_full.php` (Import Lot Details muna, tapos Import OR
  History).

## Paano i-deploy
1. I-overwrite lahat ng files sa itaas sa server mo (parehong path).
2. Buksan `import_lot_full.php` sa browser:
   - Upload `lot_details_CRC.csv` -> "Import Lot Details"
   - Upload `or_history_CRC.csv` -> "Import OR History"
3. Para sa ibang subdivisions, patakbuhin ang `extract_lot_data.py`
   sa kani-kanilang Excel file, tapos ulitin step 2.
4. **Importante:** i-Re-link ulit ang existing Liaison records
   (relinkAllLiaisonRecords sa Subdivision Monitoring) para ma-apply
   ang bagong matching sa mga records na na-save na dati.
