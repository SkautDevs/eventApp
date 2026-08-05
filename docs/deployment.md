# Nasazení na klasický PHP hosting

Každá akce = jedna instance aplikace (jeden adresář na hostingu, jedna doména).

## Požadavky hostingu
- PHP >= 8.3 s rozšířeními: json, pdo_sqlite, soap (SkautIS login), gmp nebo bcmath (push notifikace)
- **opcache** zapnutý. Bez něj je aplikace zhruba 4× pomalejší — naměřeno na `/programy`:
  35 ms s opcache, 134 ms bez ní. Ověřit v `phpinfo()` (`opcache.enable=1`).
- Apache s mod_rewrite, možnost nastavit docroot na `www/`
- Doporučeně mod_headers, mod_expires a mod_deflate — `www/.htaccess` je používá pro
  bezpečnostní hlavičky, cachování assetů a kompresi. Všechny bloky jsou v `<IfModule>`,
  takže hosting bez daného modulu funguje dál, jen bez té výhody.
- **HTTPS** (viz sekce níže). Bez něj nelze zapnout `Secure` cookie ani push notifikace —
  service worker se registruje pouze na zabezpečeném původu.

## Postup
1. `composer install --no-dev` lokálně, nahrát celý projekt KROMĚ `.env`, `var/`, `.git/`.
2. Docroot domény nasměrovat na `www/`.

   U hostingů s pevným `htdocs` (docroot nelze přesměrovat): obsah `www/` patří do
   `htdocs`, zbytek projektu **o úroveň výš, mimo docroot**, a v `htdocs/index.php`
   se upraví `require` na novou relativní cestu k `vendor/autoload.php`.

   Mimo to, co server servíruje, musí skončit **všechno** z tohoto seznamu:
   `.env`, `var/`, `vendor/`, `src/`, `events/`, `tests/`, `composer.json`,
   `composer.lock`, `docs/`, `bin/`. `.env` obsahuje `SKAUTIS_APP_ID`, `ADMIN_TOKEN`
   a VAPID klíče — stažitelný `.env` znamená kompromitaci celé instance.

   `www/.htaccess` se musí přesunout do `htdocs` spolu s obsahem `www/`. Když se
   zapomene, funguje jen `/` a **každá další routa vrací 404** (a zároveň chybí
   `Options -Indexes` i bezpečnostní hlavičky).
3. Vytvořit `.env` v kořeni projektu (mimo docroot) podle `.env.example`:
   - `EVENT=obrok27` — slug adresáře pod `events/`
   - `APP_DEBUG=0` — v produkci **vždy `0`**. Zapnuté ladění vypisuje návštěvníkovi
     stack trace včetně cest a konfigurace. Hodnota se čte jako pravdivostní, takže
     `APP_DEBUG=false` nebo `APP_DEBUG=off` **zapíná** ladění (neprázdný řetězec je
     truthy) — jedinou bezpečnou hodnotou je `0`, případně řádek úplně vynechat.
   - `PROGRAM_PROVIDER` — **zdroj programů. Nejdůležitější řádek celého souboru.**
     - `stub` (výchozí, když se řádek vynechá) čte demo data z
       `events/<slug>/fixtures/programs.json`. Slouží pro vývoj a testy.
     - `kissj` čte skutečný harmonogram z registračního API kissj.
     - **Pozor:** když se řádek nevyplní, aplikace naběhne, vypadá funkčně a
       na `/programy` zobrazí *demo harmonogram jako skutečný*. Nic se neohlásí,
       žádná chyba, žádný log. Produkční instance musí mít `PROGRAM_PROVIDER=kissj`.
   - `KISSJ_BASE_URL` — základní URL kissj API. Povinné při `PROGRAM_PROVIDER=kissj`;
     bez něj aplikace při startu skončí `RuntimeException`. Při `stub` se ignoruje.
     Volitelně lze v configu akce přepsat slug klíčem `kissj.eventSlug` (jinak se
     použije slug akce). Před přepnutím produkční akce na `kissj` ověřit
     `docs/kissj-contract.md` proti reálnému API.
   - `SKAUTIS_APP_ID` — z registrace aplikace na https://is.skaut.cz
   - `ADMIN_TOKEN`, `VAPID_*` — viz push notifikace níže
4. Vytvořit zapisovatelný adresář `var/` — SQLite databáze push odběrů.
   - **`var/` nesmí nikdy ležet uvnitř docrootu.** Jinak je `var/push.sqlite`
     stažitelný přes HTTP a s ním všechny push endpointy i klíče odběratelů.
   - Práva `750`, na sdíleném hostingu raději `700`. **Ne `775`** — group-writable
     znamená na sdíleném hostingu zapisovatelné pro ostatní nájemníky téhož serveru.
   - Samotný soubor `var/push.sqlite` chce `0600`.
   - Vlastníkem musí být uživatel, pod kterým běží PHP.
5. **Při aktualizaci běžící instance:** změnil-li se `www/style.css`, `www/app.js`
   nebo `www/programs.js`, zvýšit jim `?v=` v `templates/_layout.twig`. `www/.htaccess`
   jim dává roční cache, takže bez zvýšeného čísla dostane vracející se návštěvník
   dál starou verzi — a to i po vyprázdnění cache na serveru. Nová instalace se
   netýká, tam žádná cache neexistuje.
6. Otevřít web a projít všechny obrazovky: `/`, `/programy`, `/mapa`, `/novinky`,
   `/odkazy`, `/profil`. Které z nich existují, závisí na `features` v configu akce.
   Na `/programy` ověřit, že harmonogram odpovídá skutečnosti, a ne demo datům
   (viz `PROGRAM_PROVIDER` výše).

## HTTPS

Certifikát vyřídit dřív, než se pustí cokoli z tohoto odstavce. Pořadí je podstatné:

1. Vydat certifikát a ověřit, že `https://<domena>/` skutečně odpovídá.
2. Teprve pak zapnout přesměrování HTTP → HTTPS. Do `www/.htaccess` **nad** blok
   front controlleru:

   ```apache
   RewriteCond %{HTTPS} !=on
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
   ```

   Některé hostingy ukončují TLS na proxy a `%{HTTPS}` je pak vždy `off` — v tom
   případě se testuje `%{HTTP:X-Forwarded-Proto} !=https`. Špatná podmínka
   = nekonečná smyčka přesměrování. Proto to v repozitáři není napevno.
3. Přidat HSTS (opět v `<IfModule mod_headers.c>`):

   ```apache
   Header always set Strict-Transport-Security "max-age=31536000"
   ```

   Až po ověření, že vše funguje přes HTTPS — HSTS si prohlížeč zapamatuje na rok
   a zpět už se couvnout nedá.
4. **Až nakonec** zapnout `Secure` flag session cookie. Když se to udělá dřív než
   přesměrování, prohlížeč na HTTP odpověď cookie zahodí, session se neudrží
   a **přihlášení přestane fungovat** — bez chybové hlášky, uživatel se jen pořád
   vrací na přihlašovací formulář.

## Push notifikace (Phase B)
1. `php bin/generate-vapid.php` → zkopírovat oba klíče do `.env`.
2. Nastavit `ADMIN_TOKEN` (dlouhý, náhodný — např. `openssl rand -hex 24`).
3. Odesílání: `https://<domena>/admin/notify?token=<ADMIN_TOKEN>` — odkaz lze sdílet
   organizátorům (držení odkazu = přístup, jiné přihlášení není).
4. `www/sw.js` **musí zůstat v kořeni docrootu**. Scope service workeru je dán jeho
   umístěním: přesun do podadresáře scope tiše zúží na ten podadresář, notifikace
   přestanou fungovat na zbytku webu a už zaregistrovaní návštěvníci zůstanou viset
   na osiřelé registraci, kterou nová nikdy nenahradí.

## Nová akce
1. Zkopírovat `events/obrok27/` → `events/<slug>/`, upravit config + obsah.
2. Zkopírovat `www/events/obrok27/` → `www/events/<slug>/`, nahradit loga/favicony/manifest.
3. Nasadit novou instanci s `EVENT=<slug>`.

Loga a favicony nejsou verzované v URL a `www/.htaccess` jim dává roční cache.
U **běžící** akce proto nestačí soubor přepsat — vrácení návštěvníci uvidí dál to
staré. Nahrát pod novým názvem a přepsat cestu v `assets` v configu akce.
