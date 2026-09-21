<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BookingRules
{
    public const Timezone = 'Europe/Rome';

    /** @return array<string, string|int> */
    public function payload(): array
    {
        $now = now(self::Timezone);
        $minimum = $now->copy()->startOfMinute();
        if ($minimum->lt($now)) {
            $minimum->addMinute();
        }

        return ['server_now' => $now->toIso8601String(), 'timezone' => self::Timezone, 'minimum_pickup' => $minimum->toIso8601String(), 'lead_minutes' => 0];
    }

    /** @param array<string,mixed> $data */
    public function unchanged(array $data, ?Order $order): bool
    {
        return $order && ($data['pickup_date'] ?? null) === $order->pickup_date->toDateString()
            && ($data['pickup_from'] ?? null) === substr($order->pickup_from, 0, 5)
            && ($data['pickup_to'] ?? null) === substr($order->pickup_to, 0, 5);
    }

    /** @param array<string,mixed> $data */
    public function validate(array $data, ?Order $order = null): void
    {
        if ($this->unchanged($data, $order)) {
            return;
        }
        $value = $data['pickup_date'].' '.$data['pickup_from'];
        $start = Carbon::createFromFormat('!Y-m-d H:i', $value, self::Timezone);
        if ($start->format('Y-m-d H:i') !== $value || $start->lt(now(self::Timezone))) {
            throw ValidationException::withMessages(['pickup_from' => 'Scegli un orario di ritiro non ancora trascorso, nell’ora italiana.']);
        }
    }
}
