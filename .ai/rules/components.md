---
paths:
  - resources/js/components/uptime-chart.tsx
---

# Components

## The aggregate uptime chart is capped at 8 applications by its palette
`RecordService::UPTIME_SERIES_LIMIT` (8) and `SERIES_COLORS` in `uptime-chart.tsx` are one decision in two places: eight categorical hues that stay apart from one another under colour-vision deficiency, assigned in that fixed order (never cycled). Raising the cap means adding validated hues first, not repeating one. The server charts the eight least available applications and reports the rest as `omitted`.
