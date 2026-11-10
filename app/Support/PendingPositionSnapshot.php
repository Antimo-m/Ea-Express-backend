<?php

namespace App\Support;

use App\Models\EconomicAudit;
use App\Models\PendingAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class PendingPositionSnapshot
{
    /**
     * Read the first subsequent audit's BEFORE image: it preserves the position at the cutoff,
     * including later corrections to amounts, settlements and cancellations.
     *
     * @return array{positions: array<int, array<string, mixed>>, incoming: int, outgoing: int, uncertain: int}
     */
    public function at(Builder $accounts, Carbon $cutoff): array
    {
        $result = ['positions' => [], 'incoming' => 0, 'outgoing' => 0, 'uncertain' => 0];
        (clone $accounts)->whereDate('occurred_on', '<=', $cutoff->toDateString())
            ->addSelect(['pending_accounts.*', 'subsequent_snapshot' => EconomicAudit::select('before')
                ->where('entity_type', 'pending_accounts')->whereColumn('entity_id', 'pending_accounts.id')
                ->where('created_at', '>', $cutoff->copy()->utc())->whereNotNull('before')
                ->oldest('created_at')->orderBy('id')->limit(1)])
            ->withSum(['settlements as registered_settled' => fn ($query) => $query->where('created_at', '<=', $cutoff->copy()->utc())], 'amount_cents')
            ->chunkById(200, function ($accounts) use (&$result, $cutoff): void {
                foreach ($accounts as $account) {
                    $snapshot = $account->subsequent_snapshot ? json_decode($account->subsequent_snapshot, true, flags: JSON_THROW_ON_ERROR) : null;
                    $position = $snapshot ?? $account->only(['amount_cents', 'settled_cents', 'state', 'subject', 'description', 'notes', 'customer_id', 'due_on']);
                    $reliable = $account->created_at->lessThanOrEqualTo($cutoff) && isset($position['amount_cents'], $position['settled_cents'], $position['state']);
                    if (! $snapshot && $account->updated_at->greaterThan($cutoff)) {
                        $reliable = false;
                    }
                    if (! $snapshot && (int) $account->registered_settled !== (int) $position['settled_cents']) {
                        $reliable = false;
                    }
                    $position['reliable'] = $reliable;
                    $position['remaining_cents'] = in_array($position['state'], ['open', 'partially_paid']) ? max(0, (int) $position['amount_cents'] - (int) $position['settled_cents']) : 0;
                    $result['positions'][$account->id] = $position;
                    if (! $reliable) {
                        $result['uncertain']++;
                    } else {
                        $result[$account->direction] += $position['remaining_cents'];
                    }
                }
            });

        return $result;
    }

    /** @param array<string, mixed> $position */
    public function apply(PendingAccount $account, array $position): PendingAccount
    {
        return $account->forceFill(collect($position)->except(['reliable', 'remaining_cents'])->all())
            ->setAttribute('snapshot_reliable', $position['reliable']);
    }
}
