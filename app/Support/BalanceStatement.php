<?php

namespace App\Support;

/**
 * Single source of the balance formulas shown in «Bilancio».
 *
 * - Saldo incassato = quota EA-Express degli incassi registrati (fuori regione: tariffa − costo vettore), al netto di storni e rimborsi già pagati, + altre entrate manuali.
 * - Spese = spese registrate + uscite manuali.
 * - Saldo disponibile = Saldo incassato − Spese.
 * - Saldo sospeso = Sospesi in entrata − Sospesi in uscita (posizioni aperte alla data di riferimento).
 * - Saldo contabile = Saldo disponibile + Saldo sospeso.
 *
 * A pending position that gets settled leaves the open snapshot and enters the monetary flows once, so it is never counted twice.
 */
class BalanceStatement
{
    /**
     * @param  array{receipts: int, expenses: int, manual_in: int, manual_out: int}  $period
     * @param  array{receipts: int, expenses: int, manual_in: int, manual_out: int}  $before
     * @return array{opening: int, collected: int, expenses: int, available: int, closing: int, pending: ?int, accounting: ?int}
     */
    public static function make(array $period, array $before, ?int $pendingIncoming, ?int $pendingOutgoing): array
    {
        $collected = $period['receipts'] + $period['manual_in'];
        $expenses = $period['expenses'] + $period['manual_out'];
        $available = $collected - $expenses;
        $opening = self::available($before);
        $pending = $pendingIncoming === null || $pendingOutgoing === null ? null : $pendingIncoming - $pendingOutgoing;

        return [
            'opening' => $opening, 'collected' => $collected, 'expenses' => $expenses, 'available' => $available,
            'closing' => $opening + $available, 'pending' => $pending, 'accounting' => $pending === null ? null : $available + $pending,
        ];
    }

    /** @param  array{receipts: int, expenses: int, manual_in: int, manual_out: int}  $flows */
    public static function available(array $flows): int
    {
        return $flows['receipts'] + $flows['manual_in'] - $flows['expenses'] - $flows['manual_out'];
    }
}
