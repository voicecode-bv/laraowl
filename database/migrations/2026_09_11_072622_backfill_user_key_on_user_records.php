<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Populates `records.user_key` for existing `user` records.
 *
 * A `user` record carries its identifier at the payload root, so it was never
 * keyed by the ingest path. The name/email lookup behind the user panels now
 * filters on the indexed `user_key` column instead of on a JSON expression,
 * which only resolves rows that carry a key — hence this one-off backfill.
 *
 * Walked in primary-key windows and written in small batches so a table with
 * millions of records is never read, sorted, or locked in one go.
 */
return new class extends Migration
{
    private const CHUNK = 2_000;

    public function up(): void
    {
        $lastId = 0;

        while (true) {
            $rows = DB::table('records')
                ->where('type', 'user')
                ->whereNull('user_key')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'payload']);

            if ($rows->isEmpty()) {
                return;
            }

            $lastId = $rows->last()->id;
            $idsByKey = [];

            foreach ($rows as $row) {
                $userKey = $this->userKeyFrom($row->payload);

                if ($userKey === null) {
                    continue;
                }

                $idsByKey[$userKey][] = $row->id;
            }

            foreach ($idsByKey as $userKey => $ids) {
                foreach (array_chunk($ids, 1_000) as $batch) {
                    DB::table('records')->whereIn('id', $batch)->update(['user_key' => (string) $userKey]);
                }
            }
        }
    }

    public function down(): void
    {
        // The column is shared with every other record type, so the keys stay put.
    }

    /**
     * The user identifier stored at the root of a `user` payload.
     */
    private function userKeyFrom(mixed $payload): ?string
    {
        $decoded = is_string($payload) ? json_decode($payload, true) : $payload;

        if (! is_array($decoded)) {
            return null;
        }

        $id = $decoded['id'] ?? null;

        if ($id === null || $id === '' || ! is_scalar($id)) {
            return null;
        }

        return substr((string) $id, 0, 64);
    }
};
