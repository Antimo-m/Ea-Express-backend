<?php

namespace App\Support;

use App\Models\EconomicAudit;
use App\Models\FinancialMovement;
use App\OrderStatus;
use Illuminate\Support\Carbon;

class EconomicAuditPresenter
{
    private const Fields = [
        'carrier_cost_cents' => 'Costo vettore', 'ea_amount_cents' => 'Quota EA-Express', 'amount_cents' => 'Importo', 'settled_cents' => 'Totale saldato', 'price_cents' => 'Tariffa',
        'previous_price_cents' => 'Tariffa precedente', 'quoted_price_cents' => 'Tariffa iniziale', 'parcel_value_cents' => 'Valore merce',
        'description' => 'Causale', 'subject' => 'Soggetto', 'notes' => 'Note', 'note' => 'Nota',
        'reason' => 'Motivazione', 'receipt_void_reason' => 'Motivo dello storno', 'state' => 'Stato', 'price_state' => 'Stato tariffa',
        'status' => 'Stato spedizione', 'kind' => 'Tipo di movimento', 'direction' => 'Direzione',
        'occurred_on' => 'Data contabile', 'spent_on' => 'Data della spesa', 'due_on' => 'Scadenza',
        'paid_at' => 'Incasso registrato il', 'settled_at' => 'Saldato il', 'voided_at' => 'Annullato il', 'receipt_voided_at' => 'Stornato il',
        'closed_through' => 'Registrazioni chiuse fino al', 'method' => 'Metodo storico', 'payment_method' => 'Metodo storico della spedizione',
        'city' => 'Località', 'postal_code' => 'CAP', 'delivery_time' => 'Tempi di consegna', 'is_active' => 'Attivo',
        'recipient' => 'Dati destinatario', 'correction_reason' => 'Motivo rettifica', 'dismissed_at' => 'Segnalazione rimossa il',
        'customer_id' => 'Account cliente', 'order_id' => 'Ordine', 'pending_account_id' => 'Sospeso', 'settlement_id' => 'Pagamento',
        'pickup_date' => 'Data ritiro', 'pickup_from' => 'Inizio fascia', 'pickup_to' => 'Fine fascia',
    ];

    /** @return array{title: string, icon: string, changes: list<array{label: string, before: ?string, after: string}>} */
    public function present(EconomicAudit $entry): array
    {
        $before = $entry->before ?? [];
        $after = $entry->after ?? [];
        $changes = [];
        foreach (self::Fields as $field => $label) {
            if (! array_key_exists($field, $before) && ! array_key_exists($field, $after)) {
                continue;
            }
            $previous = $before[$field] ?? null;
            $current = $after[$field] ?? null;
            if ($previous === $current || ($entry->before === null && $current === null)) {
                continue;
            }
            if (in_array($field, ['method', 'payment_method']) && $entry->action !== 'payment.proposed' && $entry->action !== 'payment.confirmed') {
                continue;
            }
            $changes[] = ['label' => $label, 'before' => $entry->before === null ? null : $this->format($field, $previous), 'after' => $this->format($field, $current)];
        }
        $title = match ($entry->action) {
            'recipient_incident.created' => 'Precedente destinatario registrato', 'recipient_incident.correct' => 'Dati del precedente corretti', 'recipient_incident.dismiss' => 'Segnalazione destinatario rimossa',
            'expense.created' => 'Spesa registrata', 'expense.updated', 'expense.corrected' => 'Spesa corretta', 'expense.voided' => 'Spesa annullata',
            'payment.received' => 'Incasso registrato', 'payment.reversed' => 'Movimento di storno registrato', 'payment.corrected' => 'Importo dell’incasso corretto',
            'receipt.receive' => 'Incasso confermato', 'receipt.reverse' => 'Incasso spostato negli storni', 'receipt.restore' => 'Incasso ripristinato', 'receipt.corrected' => 'Saldo della spedizione aggiornato', 'receipt.backfilled' => 'Storno storico riconosciuto',
            'settlement.created' => 'Pagamento del sospeso registrato', 'settlement.corrected' => 'Pagamento del sospeso corretto',
            'pending.created' => 'Sospeso creato', 'pending.settled' => 'Pagamento applicato al sospeso', 'pending.corrected', 'pending.update' => 'Sospeso aggiornato', 'pending.cancel' => 'Sospeso annullato',
            'movement.created' => 'Movimento creato', 'movement.updated' => 'Movimento modificato', 'movement.voided' => 'Movimento annullato',
            'price.quoted' => 'Tariffa calcolata', 'price.confirmed' => 'Tariffa confermata', 'price.requoted' => 'Tariffa ricalcolata', 'order.economics_updated' => 'Dati economici aggiornati',
            'payment.proposed' => 'Proposta storica di pagamento', 'payment.confirmed' => 'Accordo storico di pagamento',
            'accounting.closed' => 'Periodo contabile chiuso', 'accounting.reopened' => 'Periodo contabile riaperto',
            default => str_ends_with($entry->action, '.created') ? 'Registrazione creata' : 'Operazione registrata',
        };
        $icon = str_contains($entry->action, 'void') || str_contains($entry->action, 'reverse') || str_contains($entry->action, 'cancel') ? 'arrow-counterclockwise' : (str_contains($entry->action, 'created') || str_contains($entry->action, 'received') ? 'plus-lg' : 'pencil');

        return compact('title', 'icon', 'changes');
    }

    private function format(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Non impostato';
        }
        if ($field === 'recipient' && is_array($value)) {
            return implode(' · ', array_filter($value, fn ($part) => is_scalar($part) && $part !== ''));
        }
        if (str_ends_with($field, '_cents')) {
            return Money::format((int) $value);
        }
        if (str_ends_with($field, '_at')) {
            return Carbon::parse($value)->timezone('Europe/Rome')->format('d/m/Y H:i');
        }
        if (str_ends_with($field, '_on') || in_array($field, ['closed_through', 'pickup_date'])) {
            return Carbon::parse($value)->format('d/m/Y');
        }
        if ($field === 'status') {
            return OrderStatus::tryFrom((string) $value)?->label() ?? (string) $value;
        }
        if (in_array($field, ['state', 'price_state'])) {
            return ['open' => 'Non pagato', 'partially_paid' => 'Parziale', 'paid' => 'Saldato', 'cancelled' => 'Annullato', 'agreed' => 'Concordato', 'awaiting_customer' => 'In attesa del cliente', 'awaiting_rider' => 'In attesa del corriere', 'rejected' => 'Rifiutato', 'pending' => 'In attesa', 'accepted' => 'Accettato'][$value] ?? (string) $value;
        }
        if (in_array($field, ['method', 'payment_method'])) {
            return PaymentMethod::Labels[$value] ?? (string) $value;
        }
        if ($field === 'kind') {
            return FinancialMovement::Kinds[$value] ?? (string) $value;
        }
        if ($field === 'direction') {
            return $value === 'incoming' ? 'Entrata' : 'Uscita';
        }
        if (is_bool($value) || $field === 'is_active') {
            return $value ? 'Sì' : 'No';
        }

        return is_scalar($value) ? (string) $value : 'Dati aggiornati';
    }
}
