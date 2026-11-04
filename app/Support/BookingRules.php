<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class BookingRules
{
    public const Timezone = 'Europe/Rome';

    /** @return array<string, string|int> */
    public function payload(int $leadMinutes = 0): array
    {
        $now = now(self::Timezone);
        $threshold = $now->copy()->addMinutes($leadMinutes);
        $minimum = $threshold->copy()->startOfMinute();
        if ($minimum->lt($threshold)) {
            $minimum->addMinute();
        }

        return ['server_now' => $now->toIso8601String(), 'timezone' => self::Timezone, 'minimum_pickup' => $minimum->toIso8601String(), 'lead_minutes' => $leadMinutes];
    }

    /** @param array<string,mixed> $data */
    public function unchanged(array $data, ?Order $order): bool
    {
        return $order && ($data['pickup_date'] ?? null) === $order->pickup_date->toDateString()
            && ($data['pickup_from'] ?? null) === substr($order->pickup_from, 0, 5)
            && ($data['pickup_to'] ?? null) === substr($order->pickup_to, 0, 5);
    }

    /** @param array<string,mixed> $data */
    public function validate(array $data, ?Order $order = null, int $leadMinutes = 0): void
    {
        if ($leadMinutes === 0 && $this->unchanged($data, $order)) {
            return;
        }
        $value = $data['pickup_date'].' '.$data['pickup_from'];
        $start = Carbon::createFromFormat('!Y-m-d H:i', $value, self::Timezone);
        if ($start->format('Y-m-d H:i') !== $value || $start->lt(now(self::Timezone)->addMinutes($leadMinutes))) {
            throw ValidationException::withMessages(['pickup_from' => $leadMinutes > 0
                ? 'Scegli un orario di ritiro almeno un minuto dopo l’ora attuale italiana.'
                : 'Scegli un orario di ritiro non ancora trascorso, nell’ora italiana.']);
        }
        if ($leadMinutes > 0) {
            $endValue = $data['pickup_date'].' '.$data['pickup_to'];
            $end = Carbon::createFromFormat('!Y-m-d H:i', $endValue, self::Timezone);
            if ($end->format('Y-m-d H:i') !== $endValue || ! $end->gt($start)) {
                throw ValidationException::withMessages(['pickup_to' => 'Scegli un orario di fine ritiro valido nella data indicata, successivo all’orario iniziale.']);
            }
        }
    }
}
