---
paths:
  - resources/js/components/uptime-chart.tsx
  - resources/js/components/uptime-alert-banner.tsx
  - resources/js/hooks/use-uptime-alerts.ts
---

# Components

## The availability chart is a band per application, not a stacked series
Each monitored application gets its own timeline row, so identity is carried by the name beside the band and colour is free to encode state. Do not reintroduce a shared stack: eight hues inside one bar was unreadable, which is why this was rebuilt. `RecordService::UPTIME_SERIES_LIMIT` (8) now caps rows for vertical space, not for palette size.

## Three status colours, and never colour alone
`STATUS_COLORS` is up / partial / down, plus a muted "not monitored". A fourth grade was tried and dropped: the palette's `serious` sits 13.6 ΔE from `warning`, under the floor at which full-colour vision separates a pair. The three measure 27.6 ΔE at worst. Amber clears only 1.79:1 on the light surface, so every row also prints its availability as text and every segment names its counts on hover — that text is the mitigation, not decoration.

## A clean slot and an unmonitored slot are different things
`getUptimeSeries()` emits `checks_{id}` for every slot that was measured and `failed_{id}` only when something failed. No `checks_` key means nothing was measured, which the band draws as a grey gap rather than as health. Dropping the clean-slot count again would make an outage in the monitoring look like perfect uptime.

## Bands fold to at most 120 segments
A custom range can be 744 hourly slots, which is sub-pixel at any real card width. `MAX_SEGMENTS` folds neighbours by sum, so a folded segment reports the whole span it covers and stays hoverable.

## The outage banner starts from the server, then follows the socket
`uptime_status.offline` is the state a dashboard arrives with, so a page opened mid-outage is right immediately; `ProjectUptimeChanged` on `team.{teamId}` keeps it current. Only transitions broadcast, and only live events raise a toast — toasting the server's initial list on every page load trains people to dismiss it unread.
