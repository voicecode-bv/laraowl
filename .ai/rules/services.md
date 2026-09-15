---
paths:
  - 'app/Services/Roll*.php'
  - app/Services/RecordService.php
---

# Services

## The rollups have two grains; the daily one is derived, never written to directly
`RollupWriter` writes the fine grain at ingest (per minute for `record_rollups`, per hour for the user/group/IP buckets). `RollupCompactor` folds those, plus raw `uptime_checks`, into the `*_daily_*` tables on a schedule; nothing else may write them.

Each daily table mirrors its source column for column on purpose — that is what lets `BuildsRollupQueries::rollupSource()` swap the table a read queries and change nothing else. Keep them in step when adding a counter.

Every pass is idempotent: a day is rebuilt and `upsert`-replaced, never added to. Two guards keep it honest — `RollupWriter::record()` winds `projects.rollups_compacted_through` back when a batch writes behind it, and each pass redoes `OVERLAP_DAYS` anyway.

`compactDay()` writes nothing for a day that folds to nothing, so a daily row survives the pruning of its sources (the two retentions differ: `rollup_retention_days` vs `daily_rollup_retention_days`). Only a caller that knowingly deleted the sources may delete daily rows — see `BackfillRollups::discardFoldedDays()`.

## Long-period reads route through rollupSource(), and fall back on their own
Any read over `7d`/`14d`/`30d` must go through `$this->rollupSource(Model::class, $period, $project)::query()` rather than naming a rollup model directly, or it will scan the fine grain — 43,200 minute buckets per record type for a 30-day chart.

The helper returns the fine-grained model when any project in scope is not folded through yesterday (`projects.rollups_compacted_through`), so a stalled schedule is slow, never wrong, and heals itself. Do not "optimise" that check away.

The daily grain opens on a calendar day (`Record::periodStartsAtDay()`), not a rolling `now()->subDays(n)`, which is what the charts have always drawn. `UptimeCheck::scopeForPeriod()` is aligned to the same boundary so the list, the summary and the chart share one window.
