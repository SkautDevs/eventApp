Modulární podpůrná aplikace pro skautské akce (Obrok). Každá akce je konfigurace
v `events/<slug>/` + veřejné assety v `www/events/<slug>/`.

## Vývoj

1. `composer install`
2. `cp .env.example .env`, nastavit `APP_DEBUG=1`
   a vložit pár VAPID klíčů: každá akce má push, takže bez `VAPID_PUBLIC_KEY`,
   `VAPID_PRIVATE_KEY` a `VAPID_SUBJECT` nenaběhne žádná akce, ani při vývoji. Pár vyrobí
   jednou `docker run --rm -v "$PWD":/app -w /app php:8.3-alpine php bin/generate-vapid.php`.
3. `composer start` → http://localhost:8080/obrok19/ (nebo `docker-compose up`)
4. `composer test`

Programová data jdou přes `ProgramProviderInterface` — v dev/testech ze souborů
`events/<slug>/fixtures/*.json`, v produkci z kissj (`PROGRAM_PROVIDER_<SLUG>=kissj`).

Nasazení: viz `docs/deployment.md`.
