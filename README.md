Modulární podpůrná aplikace pro skautské akce (Obrok). Každá akce je konfigurace
v `events/<slug>/` + veřejné assety v `www/events/<slug>/`.

## Vývoj

1. `composer install`
2. `cp .env.example .env`, nastavit `EVENT=obrok19` (referenční akce) a `APP_DEBUG=1`
3. `composer start` → http://localhost:8080 (nebo `docker-compose up`)
4. `composer test`

Programová data jdou přes `ProgramProviderInterface` — v dev/testech ze souborů
`events/<slug>/fixtures/*.json`, v produkci z kissj (`PROGRAM_PROVIDER=kissj`).

Nasazení: viz `docs/deployment.md`.
