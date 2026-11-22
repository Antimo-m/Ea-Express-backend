<?php

namespace App\Http\Requests;

use App\Rules\PlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Segnalazione inviata da un cliente del portale o da un account del gestionale. */
class FeedbackReportRequest extends FormRequest
{
    public const Categories = ['problem' => 'Problema o bug', 'suggestion' => 'Suggerimento', 'other' => 'Altro'];

    public const MaxFiles = 4;

    public const MaxFileKilobytes = 5120;

    public const MaxTotalKilobytes = 12288;

    public const Extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'pdf', 'txt'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(array_keys(self::Categories))],
            'subject' => ['required', 'string', 'min:3', 'max:120', new PlainText(markup: false)],
            'message' => ['required', 'string', 'min:10', 'max:5000', new PlainText(multiline: true)],
            'location' => ['nullable', 'string', 'max:200', new PlainText(markup: false)],
            'client' => ['nullable', 'string', 'max:500', new PlainText(markup: false)],
            'attachments' => ['nullable', 'array', 'max:'.self::MaxFiles],
            'attachments.*' => ['file', 'extensions:'.implode(',', self::Extensions), 'mimes:'.implode(',', self::Extensions), 'max:'.self::MaxFileKilobytes],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'attachments.max' => 'Puoi allegare al massimo '.self::MaxFiles.' file.',
            'attachments.*.extensions' => 'Formato non supportato: usa immagini (JPG, PNG, WEBP, GIF, HEIC), PDF o TXT.',
            'attachments.*.mimes' => 'Formato non supportato: usa immagini (JPG, PNG, WEBP, GIF, HEIC), PDF o TXT.',
            'attachments.*.max' => 'Ogni file può pesare al massimo '.(self::MaxFileKilobytes / 1024).' MB.',
            'attachments.*.uploaded' => 'Il file non è stato caricato: potrebbe essere troppo pesante.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['category' => 'tipo di segnalazione', 'subject' => 'oggetto', 'message' => 'descrizione', 'location' => 'pagina o sezione', 'attachments' => 'allegati'];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $total = collect($this->file('attachments', []))->sum(fn (UploadedFile $file): int => (int) $file->getSize());
            if ($total > self::MaxTotalKilobytes * 1024) {
                $validator->errors()->add('attachments', 'Gli allegati insieme possono pesare al massimo '.(self::MaxTotalKilobytes / 1024).' MB.');
            }
        }];
    }
}
