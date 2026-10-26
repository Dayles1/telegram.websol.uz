<?php

declare(strict_types=1);

namespace App\Sending;

use App\Exceptions\ActionRefused;
use App\Models\Catalog;
use App\Models\CatalogUsage;
use Illuminate\Support\Collection;

/**
 * A catalog with timer_minutes may be sent to from one account once per that
 * many minutes. Must run inside the transaction that creates the send, so a
 * failed send never burns the cooldown and two clicks can't both pass.
 */
final class CatalogCooldown
{
    /** @param Collection<int, Catalog> $catalogs */
    public function reserve(Collection $catalogs, int $phoneId): void
    {
        $timed = $catalogs->filter(fn (Catalog $c) => (int) $c->timer_minutes > 0)->keyBy('id');
        if ($timed->isEmpty()) {
            return;
        }

        $usages = CatalogUsage::query()
            ->where('user_phone_id', $phoneId)
            ->whereIn('catalog_id', $timed->keys())
            ->lockForUpdate()
            ->get()
            ->keyBy('catalog_id');

        $blocked = [];
        foreach ($timed as $catalog) {
            $usedAt = $usages->get($catalog->id)?->last_used_at;
            $availableAt = $usedAt?->copy()->addMinutes((int) $catalog->timer_minutes);
            if ($availableAt !== null && $availableAt->isFuture()) {
                $blocked[] = $catalog->title.' ('.__('time.minutes', ['n' => (int) ceil(now()->diffInMinutes($availableAt, true))]).')';
            }
        }

        if ($blocked !== []) {
            throw new ActionRefused('catalog_cooldown', ['catalogs' => implode(', ', $blocked)], 429);
        }

        $this->markUsed($timed, $phoneId);
    }

    /**
     * Starts the timer without checking it: a round of a repeating send runs on
     * its own schedule, but still counts as a send to these catalogs.
     *
     * @param  Collection<int, Catalog>  $catalogs
     */
    public function markUsed(Collection $catalogs, int $phoneId): void
    {
        foreach ($catalogs->filter(fn (Catalog $c) => (int) $c->timer_minutes > 0) as $catalog) {
            CatalogUsage::query()->updateOrCreate(
                ['catalog_id' => $catalog->id, 'user_phone_id' => $phoneId],
                ['last_used_at' => now()],
            );
        }
    }

    /**
     * Minutes left per catalog for an account, for the send form.
     *
     * @param  list<int>  $catalogIds
     * @return array<int, int> catalog id => minutes left (only the blocked ones)
     */
    public function remaining(array $catalogIds, int $phoneId): array
    {
        return CatalogUsage::query()
            ->with('catalog:id,timer_minutes')
            ->where('user_phone_id', $phoneId)
            ->whereIn('catalog_id', $catalogIds)
            ->get()
            ->mapWithKeys(function (CatalogUsage $usage): array {
                $minutes = (int) $usage->catalog?->timer_minutes;
                $availableAt = $usage->last_used_at?->copy()->addMinutes($minutes);

                return $minutes > 0 && $availableAt?->isFuture()
                    ? [$usage->catalog_id => (int) ceil(now()->diffInMinutes($availableAt, true))]
                    : [];
            })
            ->all();
    }
}
