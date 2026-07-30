# Fix v5: Assessed Value import mula sa Calamba - CRC.xlsx

## Ano itong bago
1. **import_crc_assessed_value.php** — bagong standalone importer.
   I-upload sa root ng rptsystem/, buksan sa browser, i-upload yung
   `crc_av_import.csv` (kasama dito). Ita-tugma nito ang Assessed Value
   (galing sa "AV (2014-2026)", fallback sa "AV (2007-2013)" kung wala)
   papunta sa `lot_inventory` gamit ang **RA Number** bilang key
   (exact match, mabilis dahil unique+indexed na ang column na ito).
   Dinadagdag din nito ang PIN at kasalukuyang TD# kung wala pang laman
   ang mga field na iyon.

2. **crc_av_import.csv** — na-extract ko na mula sa lahat ng 4 sheets
   (V-1 hanggang V-4 — lumabas na hindi pala sila magkaibang "version",
   kundi magkaibang PHASE ng parehong subdivision CRC, kaya kailangan
   silang lahat) — 2,840 lots total, 1,307 dito may Assessed Value data.

3. **api/subdivmon.php** + **js/subdivmon.js** — dinagdagan para
   ipakita na ang totoong Assessed Value sa Subdivision Monitoring
   (dating hardcoded na "---" lagi).

## Paano i-deploy (sunod-sunod)
1. I-overwrite ang: `api/subdivmon.php`, `api/liaison.php`,
   `js/subdivmon.js`, `home.html` (parehas sa v3/v4).
2. I-upload ang `import_crc_assessed_value.php` sa root ng rptsystem/.
3. Buksan ito sa browser, i-upload ang `crc_av_import.csv`, i-click
   Import. Makikita mo agad kung ilang lots ang na-update at kung
   may RA# na hindi nahanap (baka kailangan pang i-import via
   import_lot_inventory.php muna kung wala pang record doon).
4. Balik sa Subdivision Monitoring — dapat lumabas na ang totoong
   Assessed Value sa lots table at sa "OR History" modal.

## Note
Hindi lahat ng 2,186 CRC lots ay may Assessed Value sa xlsx (1,307 lang
ang may laman). Normal lang na "---" pa rin ang makikita sa mga lot na
walang AV data talaga sa source file.
