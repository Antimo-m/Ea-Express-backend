<?php

namespace App\Support;

use App\Models\AccountingControl;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

class AccountingPeriod
{
    public function __construct(private AccountingControl $control) {}

    /** Acquire before locking financial entities, so closing and writing serialize. */
    public static function lock(): self
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Il controllo contabile richiede una transazione.');
        }

        return new self(AccountingControl::lockForUpdate()->findOrFail(1));
    }

    public function assertOpen(string|DateTimeInterface $date): void
    {
        $day = $date instanceof DateTimeInterface ? Carbon::instance($date)->timezone('Europe/Rome')->toDateString() : substr($date, 0, 10);
        abort_if($this->control->closed_through && $day <= $this->control->closed_through->toDateString(), 409, 'Il periodo contabile è chiuso. Un amministratore deve riaprirlo con una motivazione prima di modificare queste registrazioni.');
    }
}
