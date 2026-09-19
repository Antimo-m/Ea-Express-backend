<?php

namespace App\Actions;

use App\Models\EconomicAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordEconomicAudit
{
    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after */
    public function handle(?User $user, Model $entity, string $action, ?array $before, ?array $after): void
    {
        EconomicAudit::create(['user_id' => $user?->id, 'entity_type' => $entity->getTable(), 'entity_id' => $entity->getKey(), 'action' => $action, 'before' => $before, 'after' => $after]);
    }
}
