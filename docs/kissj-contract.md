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
  per-event config key `kissj.eventSlug` is obsolete; `KISSJ_API_KEY` joins
  `KISSJ_BASE_URL` as a required env var when `PROGRAM_PROVIDER=kissj`.
- Missing/malformed/unknown key → `401` with a plain-text body
  (`Unauthorized - <reason>`). Not JSON — treat any non-200 as provider
  failure.
- All 200 responses are JSON objects (never bare arrays), UTF-8,
  `Content-Type: application/json`.
- Datetimes are ISO 8601 with offset (PHP `DATE_ATOM`, Europe/Prague), e.g.
  `2027-06-03T08:00:00+02:00`.
- Calls are server-to-server (Guzzle in eventApp) — no CORS, no `OPTIONS`.

## GET /v3/programme/list

All programmes of the authorized event that are not soft-deleted, including
admin-preregistered ones.

    {
        "programmes": [
            {
                "id": 5,
                "name": "Ukázková vycházka",
                "description": "Perex programu.",
                "place": "Sraz u brány",
                "start": "2027-06-03T08:00:00+02:00",
                "end": "2027-06-03T12:00:00+02:00",
                "isPreregistered": false,
                "targetRoles": ["ist", "guest"]
            }
        ]
    }

- `place` may be an empty string; `description` may be an empty string.
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

- `404` (empty body) — no participant with that TIE code in the event. The
  TIE code doubles as the access secret, exactly as in kissj's vendor API.
- `nickname` may be null; eventApp falls back to a generic greeting.

## GET /v3/programme/participant/skautis/{skautisUserId}

Same response shape as the TIE endpoint, keyed by SkautIS user id.

- `404` (empty body) — no participant for that SkautIS user in the event.
  eventApp maps this to an empty list: a logged-in SkautIS user without a
  registration is not an error.

## Deliberately not in the contract

- `capacity`, occupancy/attendee counts, `exclusiveGroupName`, programme
  images — eventApp's Program screen is read-only and shows none of these;
  registration conflicts are kissj's business.
- Write operations — registering stays in kissj's own UI.
- `sectionId`, `lector`, `tools` from the old speculative contract — kissj
  has no such fields. The timeline's stage axis comes from `place` and any
  section grouping is derived on the eventApp side.

## Mapping to eventApp's internal shape

kissj `place` → `location`; `description` → `perex`; `start`/`end`
(ISO 8601) → `{"date": "Y-m-d H:i:s"}` in Europe/Prague; `lector`, `tools`
and `section` have no kissj source and stay null/absent. Response key is
`programmes` (kissj house spelling), participant endpoints wrap it beside
`participant`.

## Expected growth

Non-registerable schedule items (budíček, nástupy, táborák) will eventually
arrive through `/v3/programme/list` as ordinary programmes (likely a flag or
a role-less entry); the contract will be extended additively — eventApp must
ignore unknown fields.
