<?php

namespace App\Mail;

use App\Http\Requests\FeedbackReportRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

class FeedbackReport extends Mailable
{
    /**
     * @param  array{category: string, subject: string, message: string, location?: string|null, client?: string|null}  $report
     * @param  list<UploadedFile>  $files
     */
    public function __construct(public User $reporter, public string $source, public array $report, public array $files = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'EA-Express · '.FeedbackReportRequest::Categories[$this->report['category']].': '.Str::limit($this->report['subject'], 80),
            replyTo: [new Address($this->reporter->email, $this->reporter->name)],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.feedback.report', with: [
            'category' => FeedbackReportRequest::Categories[$this->report['category']],
            'sentAt' => now('Europe/Rome')->format('d/m/Y H:i'),
        ]);
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return collect($this->files)->values()->map(function (UploadedFile $file, int $index): Attachment {
            $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());
            $name = Str::of(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))->ascii()->replaceMatches('/[^A-Za-z0-9._ -]+/', '_')->trim(' ._')->limit(60, '')->value() ?: 'allegato-'.($index + 1);

            return Attachment::fromPath($file->getRealPath())->as($name.'.'.$extension)->withMime((string) $file->getMimeType());
        })->all();
    }
}
