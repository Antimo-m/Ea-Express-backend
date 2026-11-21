<?php

namespace App\Support;

use App\Models\User;
use App\SenderType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerIdentity
{
    /**
     * Suggerimenti per la tipologia di attività (stesso elenco del portale clienti).
     * Il campo resta un testo libero: gli account già registrati con altre diciture restano validi.
     *
     * @var list<string>
     */
    public const array BusinessTypes = [
        'Abbigliamento e moda', 'Calzature', 'Accessori e pelletteria', 'Gioielleria e orologeria', 'Elettronica',
        'Informatica e telefonia', 'Cosmetica e profumeria', 'Farmacia e parafarmacia', 'Alimentari', 'Gastronomia e pasticceria',
        'Enoteca e bevande', 'Libreria e cartoleria', 'Articoli sportivi', 'Articoli per la casa', 'Arredamento',
        'Ferramenta e bricolage', 'Fiori e piante', 'Articoli per animali', 'Ricambi e accessori auto', 'E-commerce',
        'Documenti e servizi', 'Artigianato', 'Altro',
    ];

    /** @return array{sender_type: string, business_type: ?string, business_description: ?string} */
    public static function profile(User $customer): array
    {
        if (! is_string($customer->sender_type) || ! SenderType::tryFrom($customer->sender_type)) {
            throw ValidationException::withMessages(['sender_type' => 'Completa la tipologia del tuo profilo prima di prenotare un ritiro.']);
        }

        return self::normalize($customer->only(['sender_type', 'business_type', 'business_description']));
    }

    /** @return array<string, array<int, mixed>> */
    public static function nameRules(bool $private): array
    {
        $rules = ['required', 'string', 'max:150', ...TextFields::identity()];

        return $private ? ['first_name' => $rules, 'last_name' => $rules] : ['name' => $rules];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function accountAttributes(array $data, string $fallback = 'business'): array
    {
        $data = self::normalize($data, $fallback);
        if ($data['sender_type'] === 'private') {
            $data['name'] = $data['first_name'].' '.$data['last_name'];
            if (mb_strlen($data['name']) > 150) {
                throw ValidationException::withMessages(['last_name' => 'Nome e cognome insieme possono contenere al massimo 150 caratteri, spazio incluso.']);
            }
            $policy = app(PortalTextPolicy::class);
            if (! $policy->allows($data['name'])) {
                throw ValidationException::withMessages(['last_name' => $policy->definition()['message']]);
            }
        } else {
            $data['first_name'] = null;
            $data['last_name'] = null;
        }

        return $data;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(bool $required = false): array
    {
        return [
            'sender_type' => [...($required ? [] : ['sometimes']), 'required', Rule::enum(SenderType::class)],
            'business_type' => ['nullable', 'string', 'max:100', ...TextFields::identity()],
            'business_description' => ['nullable', 'string', 'max:500', ...TextFields::description(multiline: true)],
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalize(array $data, string $fallback = 'business'): array
    {
        $data['sender_type'] = $data['sender_type'] ?? $fallback;
        if ($data['sender_type'] === 'private') {
            $data['business_type'] = null;
            $data['business_description'] = null;
        }

        return $data;
    }
}
