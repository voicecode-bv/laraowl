---
paths:
  - resources/js/components/uptime-chart.tsx
---

# Components

## The aggregate uptime chart is capped at 8 applications by its palette
`RecordService::UPTIME_SERIES_LIMIT` (8) and `SERIES_COLORS` in `uptime-chart.tsx` are one decision in two places: eight categorical hues that stay apart from one another under colour-vision deficiency, assigned in that fixed order (never cycled). Raising the cap means adding validated hues first, not repeating one. The server charts the eight least available applications and reports the rest as `omitted`.

## The aggregate uptime chart plots failures, not availability
`getUptimeSeries()` returns `series` as failed checks per slot (`failed_{id}` / `checks_{id}`), and only for slots where something actually failed — a clean slot carries no keys, which is what keeps a quiet period's payload small. Availability per application lives in `projects`, which feeds the legend. Charting availability directly was a flat line at 100%; the stacked bars draw the exception instead.
