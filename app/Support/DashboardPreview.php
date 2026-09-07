<?php

namespace App\Support;

class DashboardPreview
{
    /**
     * Dati dimostrativi: non rappresentano ordini o incassi reali.
     *
     * @return array{metrics: array, requests: array, shipments: array}
     */
    public function data(): array
    {
        return [
            'metrics' => [
                ['label' => 'Ordini in entrata', 'value' => '12', 'note' => 'In attesa di conferma', 'icon' => 'inbox', 'tone' => 'orange'],
                ['label' => 'Spedizioni in corso', 'value' => '5', 'note' => 'Verso la destinazione', 'icon' => 'truck', 'tone' => 'blue'],
                ['label' => 'Completati oggi', 'value' => '8', 'note' => 'Consegnati con cura', 'icon' => 'check2-circle', 'tone' => 'green'],
                ['label' => 'Incasso previsto', 'value' => '€ 156,00', 'note' => 'Stima della giornata', 'icon' => 'wallet2', 'tone' => 'neutral'],
            ],
            'requests' => [
                ['id' => 'EA-1048', 'shop' => 'Atelier Centro', 'initials' => 'AC', 'customer' => 'Giulia · Negozio di abbigliamento', 'from' => 'Napoli, Vomero', 'to' => 'Pozzuoli, Centro', 'pickup' => 'Via Luca Giordano 18, Napoli', 'delivery' => 'Corso della Repubblica 12, Pozzuoli', 'window' => '15:30 – 16:00', 'delivery_window' => 'Entro le 18:00', 'parcels' => 2, 'category' => 'Abbigliamento', 'urgent' => true, 'note' => 'Chiedere di Giulia in negozio. Pacchi già pronti al banco.'],
                ['id' => 'EA-1047', 'shop' => 'Casa & Dettagli', 'initials' => 'CD', 'customer' => 'Marco · Articoli per la casa', 'from' => 'Caserta, Centro', 'to' => 'Aversa, Centro', 'pickup' => 'Via Roma 24, Caserta', 'delivery' => 'Via Vittorio Veneto 8, Aversa', 'window' => '16:00 – 17:00', 'delivery_window' => '17:00 – 19:00', 'parcels' => 1, 'category' => 'Fragile', 'urgent' => false, 'note' => 'Collo fragile: mantenere in posizione verticale.'],
                ['id' => 'EA-1046', 'shop' => 'Studio Carta', 'initials' => 'SC', 'customer' => 'Elena · Cartoleria', 'from' => 'Napoli, Chiaia', 'to' => 'Portici, Centro', 'pickup' => 'Via Chiaia 36, Napoli', 'delivery' => 'Via Libertà 10, Portici', 'window' => '17:00 – 18:00', 'delivery_window' => 'Da concordare', 'parcels' => 3, 'category' => 'Pacchi standard', 'urgent' => false, 'note' => 'Ingresso del destinatario dal cortile interno.'],
            ],
            'shipments' => [
                ['id' => 'EA-1043', 'shop' => 'Boutique Riviera', 'destination' => 'Napoli, Posillipo', 'status' => 'In consegna', 'estimate' => 'Consegna prevista 15:00 – 15:30', 'updated' => 'Ultimo aggiornamento 14:35', 'step' => 2],
                ['id' => 'EA-1041', 'shop' => 'Emporio Verde', 'destination' => 'Salerno, Centro', 'status' => 'Pacco ritirato', 'estimate' => 'Consegna prevista 16:00 – 17:00', 'updated' => 'Ultimo aggiornamento 14:20', 'step' => 0],
            ],
        ];
    }
}
