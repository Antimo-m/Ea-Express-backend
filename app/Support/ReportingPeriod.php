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

    /** @return array{Carbon, Carbon} */
    public function utcRange(): array
    {
        return [$this->start->copy()->utc(), $this->end->copy()->utc()];
    }
}
