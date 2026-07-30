# Nasazení na klasický PHP hosting

Každá akce = jedna instance aplikace (jeden adresář na hostingu, jedna doména).

## Požadavky hostingu
- PHP >= 8.3 s rozšířeními: json, pdo_sqlite, soap (SkautIS login), gmp nebo bcmath (push notifikace)
- Apache s mod_rewrite, možnost nastavit docroot na `www/`

## Postup
1. `composer install --no-dev` lokálně, nahrát celý projekt KROMĚ `.env`, `var/`, `.git/`.
2. Docroot domény nasměrovat na `www/` (nebo u hostingů s pevným `htdocs` nahrát obsah
   projektu o úroveň výš a do `htdocs` dát obsah `www/` s upraveným `require` v `index.php`).
3. Vytvořit `.env` v kořeni projektu podle `.env.example`:
   - `EVENT=obrok27`
   - `SKAUTIS_APP_ID` — z registrace aplikace na https://is.skaut.cz
   - `ADMIN_TOKEN`, `VAPID_*` — viz push notifikace níže
4. Vytvořit zapisovatelný adresář `var/` (chmod 775) — SQLite databáze push odběrů.
5. Otevřít web, zkontrolovat homepage, /programy a /harmonogram.

## Push notifikace (Phase B)
1. `php bin/generate-vapid.php` → zkopírovat oba klíče do `.env`.
2. Nastavit `ADMIN_TOKEN` (dlouhý, náhodný — např. `openssl rand -hex 24`).
3. Odesílání: `https://<domena>/admin/notify?token=<ADMIN_TOKEN>` — odkaz lze sdílet
   organizátorům (držení odkazu = přístup, jiné přihlášení není).

## Nová akce
1. Zkopírovat `events/obrok27/` → `events/<slug>/`, upravit config + obsah.
2. Zkopírovat `www/events/obrok27/` → `www/events/<slug>/`, nahradit loga/favicony/manifest.
3. Nasadit novou instanci s `EVENT=<slug>`.
