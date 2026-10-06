<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class ReportingPeriod
{
    public function __construct(public Carbon $start, public Carbon $end) {}

    public static function month(string $month): self
    {
        $start = Carbon::createFromFormat('!Y-m', $month, 'Europe/Rome');

        return new self($start, $start->copy()->endOfMonth());
    }

    public static function year(int $year): self
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Rome');

        return new self($start, $start->copy()->endOfYear());
    }

    public static function operational(string $mode, ?string $date = null): self
    {
        $today = now('Europe/Rome')->startOfDay();
        $start = match ($mode) {
            'week' => $today->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $today->copy()->startOfMonth(),
            'custom' => Carbon::parse($date ?? $today->toDateString(), 'Europe/Rome')->startOfDay(),
            default => $today,
        };
        $end = match ($mode) {
            'week' => $start->copy()->addDays(6)->endOfDay(),
            'month' => $start->copy()->endOfMonth(),
            default => $start->copy()->endOfDay(),
        };

        return new self($start, $end);
    }

    public function reference(): Carbon
    {
        return $this->end->copy()->min(now('Europe/Rome'));
    }

    /** @return array{Carbon, Carbon} */
    public function utcRange(): array
    {
        return [$this->start->copy()->utc(), $this->end->copy()->utc()];
    }
}
