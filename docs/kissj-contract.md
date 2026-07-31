# Předpokládaný kontrakt kissj API (OVĚŘIT před implementací!)

Base URL: `KISSJ_BASE_URL` z .env (např. https://kissj.net/api).
Všechny odpovědi JSON, UTF-8.

## GET /events/{eventSlug}/programs
Seznam všech programů akce.

    [{
        "id": 5,
        "name": "Ukázková vycházka",
        "sectionId": 10,
        "start": "2027-06-03T08:00:00+02:00",
        "end": "2027-06-03T12:00:00+02:00",
        "lector": "Jana Testová",
        "location": "Sraz u brány",
        "description": "Perex programu.",
        "tools": "Pevné boty"
    }]

## GET /events/{eventSlug}/participants/tie/{tieCode}/programs
Programy účastníka podle TIE kódu. 404 = neznámý kód.
Odpověď: `{"participant": {"nickname": "Jana"}, "programs": [<stejný tvar>]}`

## GET /events/{eventSlug}/participants/skautis/{skautisUserId}/programs
Programy účastníka podle SkautIS user id. 404 = účastník nenalezen
(mapujeme na prázdný seznam — přihlášený neúčastník není chyba).
Odpověď: stejný tvar jako u TIE.

## Mapování na interní tvar
kissj `sectionId` → `section.id`; `start`/`end` (ISO 8601) → `{"date": "Y-m-d H:i:s"}`
v Europe/Prague; `description` → `perex`; ostatní pole 1:1, chybějící → null.
