# kissj programme API contract

Status: **agreed shape, not yet implemented in kissj.** This document is the
single source of truth for the contract (kissj's `/docs` directory is
gitignored, so the producer side cannot host it). It replaces the earlier
speculative contract and follows the conventions kissj's existing
external-app APIs (`/v3/entry`, `/v3/vendor`, `/v3/deal`) already use.

## Conventions

- Base URL: `KISSJ_BASE_URL` from `.env` (e.g. `https://kissj.net`), no
  trailing slash. All paths below are absolute under it.
- **The event is resolved from the API key, not from the URL.** Every request
  carries `Authorization: Bearer <KISSJ_API_KEY>`; kissj looks up the event
  owning that key (a per-event programme API key, mirroring its entry/vendor
  key pattern) and scopes every response to it. Consequence for eventApp: the
  per-event config key `kissj.eventSlug` is obsolete; `KISSJ_API_KEY_<SLUG>` (e.g.
  `KISSJ_API_KEY_KORBO26`) joins `KISSJ_BASE_URL` as a required env var when
  `PROGRAM_PROVIDER_<SLUG>=kissj`.
- Missing/malformed/unknown key → `401` with a plain-text body
  (`Unauthorized - <reason>`). Not JSON — treat any non-200 as provider
  failure.
- All 200 responses are JSON objects (never bare arrays), UTF-8,
  `Content-Type: application/json`.
- Datetimes are ISO 8601 with offset (PHP `DATE_ATOM`, Europe/Prague), e.g.
  `2027-06-03T08:00:00+02:00`.
- Calls are server-to-server (Guzzle in eventApp) — no CORS, no `OPTIONS`.

## GET /v3/programme/list

The programme sections of the authorized event, and all its programmes that are
not soft-deleted, including admin-preregistered ones.

    {
        "sections": [
            {
                "id": 1,
                "name": "Hlavní program",
                "subtitle": null,
                "imageUrl": "https://kissj.net/files/obrok27/map-hlavni.png",
                "attachment": {
                    "url": "https://kissj.net/files/obrok27/pravidla.pdf",
                    "label": "Pravidla a více informací zde"
                }
            }
        ],
        "programmes": [
            {
                "id": 5,
                "name": "Ukázková vycházka",
                "sectionId": 1,
                "description": "Perex programu.",
                "place": "Sraz u brány",
                "start": "2027-06-03T08:00:00+02:00",
                "end": "2027-06-03T12:00:00+02:00",
                "isPreregistered": false,
                "targetRoles": ["ist", "guest"]
            }
        ]
    }

- `sections` is the event's list of programme sections. The timeline pages by day
  and shows only programmes whose section is listed here. kissj must add this — it
  has no section entity yet. Each section:
  - `id` (int, required) — referenced by a programme's `sectionId`.
  - `name` (string, required) — shown in the programme sheet.
  - `subtitle` (string or null, optional) — appended to the name, e.g. two
    sections both named `Vapro` with subtitles `1. blok` and `2. blok`.
  - `imageUrl` (string or null, optional) — a map shown in the programme sheet.
  - `attachment` (object or null, optional) — a link shown in the programme
    sheet: `url` (string) and `label` (string), both required when the object
    is present.
  - `imageUrl` and `attachment.url` are **absolute URLs served by kissj**
    (`http` or `https`). eventApp rejects any other scheme as a provider error.
    Optional fields may be absent, null or an empty string; all three mean "none".
- `sectionId` (int, required) on every programme — the `id` of one of the
  `sections`. kissj must add this: its `Programme` entity on the `programmes`
  branch does not have it yet. A programme whose `sectionId` is not among the
  listed sections is not shown on the timeline.
- kissj sends only programmes meant to be shown on the schedule. Placeholders
  such as "Osobní volno" (personal free time) are not sent; eventApp does no
  name-based filtering and shows every programme it receives.
- `place` may be an empty string; `description` may be an empty string, which
  means "no description".
- `description` is **plain text**: no Markdown, no HTML and no HTML entities.
  kissj decodes entities before it sends the text, so `->` arrives as `->` and
  never as `-&gt;`. Line breaks (`\n`) are meaningful: eventApp keeps them and
  shows each one as a new line. eventApp escapes the text once, when it renders
  it, and interprets nothing in it.
- A programme may run over several days. eventApp shows it on the timeline page
  of every day it overlaps, clipped to that day. An `end` at exactly midnight
  ends the day before: `23:45` to `00:00` is one evening.
- `isPreregistered: true` marks programmes participants cannot self-register
  for (admins assign them); they are still part of the schedule and belong on
  the timeline.
- `targetRoles` is the list of participant roles the programme is offered to
  (kissj `ParticipantRole` values, e.g. `ist`, `guest`, `pl`); informational
  for eventApp.

## GET /v3/programme/participant/tie/{tieCode}

The participant with this TIE code in the authorized event, and their active
programme registrations (self-registered and admin-assigned alike).

    {
        "participant": { "nickname": "Jana" },
        "programmes": [ <same programme shape as above> ]
    }

- No `sections` here: a programme's `sectionId` references the sections of
  `/v3/programme/list`.
- `404` (empty body) — no participant with that TIE code in the event. The
  TIE code doubles as the access secret, exactly as in kissj's vendor API.
- `nickname` may be null; eventApp falls back to a generic greeting.

## GET /v3/programme/{programmeId}/participants

The TIE codes of the participants with an active registration for this programme of
the authorized event (self-registered and admin-assigned alike). eventApp calls it when an
organiser sends a push message to one programme.

    {
        "tieCodes": ["KORBO1", "KORBO2"]
    }

- `404` (empty body) — no such programme in the event. Like any non-200, eventApp
  treats it as a provider failure and sends nothing.
- An empty list is a valid answer: nobody is registered.
- eventApp compares TIE codes case-insensitively.

## GET /v3/programme/participant/skautis/{skautisUserId}

eventApp no longer calls this endpoint (TIE code is the only login, 2026-09-30); kissj need not build it for eventApp.

Same response shape as the TIE endpoint, keyed by SkautIS user id.

- `404` (empty body) — no participant for that SkautIS user in the event.

## Deliberately not in the contract

- `capacity`, occupancy/attendee counts, `exclusiveGroupName`, programme
  images — eventApp's Program screen is read-only and shows none of these;
  registration conflicts are kissj's business.
- Write operations — registering stays in kissj's own UI.
- `lector`, `tools` from the old speculative contract — kissj has no such
  fields. The timeline's stage axis comes from `place`.

## Mapping to eventApp's internal shape

Programmes: kissj `sectionId` → `section.id`; `place` → `location` and
`description` → `perex` (an empty string becomes null); `start`/`end`
(ISO 8601) → `{"date": "Y-m-d H:i:s"}` in Europe/Prague; `lector` and `tools`
have no kissj source and stay null. Response key is `programmes` (kissj house
spelling), participant endpoints wrap it beside `participant`.

Sections: `name` → `title`; `subtitle` → `subTitle`; `imageUrl` → `image`;
`attachment.url`/`attachment.label` → `attachment.href`/`attachment.label`.
The stub provider's `fixtures/sections.json` is the list's `sections` in this
same shape, so both providers map it identically (the fixtures carry paths
relative to `www/` where kissj sends absolute URLs).

## Expected growth

Non-registerable schedule items (budíček, nástupy, táborák) will eventually
arrive through `/v3/programme/list` as ordinary programmes (likely a flag or
a role-less entry); the contract will be extended additively — eventApp must
ignore unknown fields.
