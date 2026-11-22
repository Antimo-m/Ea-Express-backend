<?php

namespace Tests\Feature;

use App\Mail\FeedbackReport;
use App\Models\User;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FeedbackReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.feedback_address' => 'segnalazioni@example.test']);
        Mail::fake();
    }

    /** @return array<string, mixed> */
    private function report(array $overrides = []): array
    {
        return [
            'category' => 'problem',
            'subject' => 'Il pulsante non risponde',
            'message' => "Ho premuto Conferma ma non succede nulla.\n<script>alert(1)</script>",
            'location' => 'Nuovo ritiro',
            'client' => 'Test browser · schermo 390×844',
            ...$overrides,
        ];
    }

    public function test_customer_sends_report_with_attachments_to_company_address(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => 'Negozio Uno', 'email' => 'cliente@example.test']);

        $this->actingAs($customer, 'customer')->post('/api/v1/customer/feedback', $this->report(['attachments' => [UploadedFile::fake()->image('schermata.png'), UploadedFile::fake()->create('ricevuta.pdf', 200, 'application/pdf')]]), ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('message', 'Segnalazione inviata. Grazie, la leggeremo al più presto.');

        Mail::assertSent(FeedbackReport::class, function (FeedbackReport $mail) use ($customer): bool {
            $html = $mail->render();

            return $mail->hasTo('segnalazioni@example.test') && $mail->hasReplyTo('cliente@example.test') && $mail->reporter->is($customer)
                && $mail->source === 'Portale clienti' && count($mail->attachments()) === 2
                && $mail->envelope()->subject === 'EA-Express · Problema o bug: Il pulsante non risponde'
                && str_contains($html, 'Ho premuto Conferma') && str_contains($html, '&lt;script&gt;') && ! str_contains($html, '<script>alert(1)')
                && str_contains($html, 'Nuovo ritiro');
        });
    }

    public function test_uploads_stay_blocked_outside_feedback_routes(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $file = ['attachments' => [UploadedFile::fake()->image('schermata.png')]];

        $this->actingAs($customer, 'customer')->post('/api/v1/customer/orders/checkout', $file, ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('message', 'Il caricamento di file non è supportato.');
        $this->actingAs($customer, 'customer')->patch('/api/v1/customer/feedback', $this->report($file), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('message', 'Il caricamento di file non è supportato.');
        $this->call('POST', '/api/v1/customer/feedback', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['message' => str_repeat('x', 300000)]))->assertStatus(413);
        Mail::assertNothingSent();
    }

    public function test_guest_cannot_send_report(): void
    {
        $this->postJson('/api/v1/customer/feedback', $this->report())->assertUnauthorized();
        Mail::assertNothingSent();
    }

    public function test_attachments_are_limited_by_type_count_and_total_size(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer');

        $this->post('/api/v1/customer/feedback', $this->report(['attachments' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->post('/api/v1/customer/feedback', $this->report(['attachments' => array_map(fn (int $index) => UploadedFile::fake()->image("foto-$index.jpg"), range(1, 5))]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->post('/api/v1/customer/feedback', $this->report(['attachments' => [UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf')]]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $this->post('/api/v1/customer/feedback', $this->report(['attachments' => array_map(fn (int $index) => UploadedFile::fake()->create("parte-$index.pdf", 4500, 'application/pdf'), range(1, 3))]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->postJson('/api/v1/customer/feedback', $this->report(['category' => 'spam', 'message' => 'corto']))
            ->assertUnprocessable()->assertJsonValidationErrors(['category', 'message']);

        Mail::assertNothingSent();
    }

    public function test_report_is_refused_when_company_address_is_missing(): void
    {
        config(['mail.feedback_address' => null]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);

        $this->actingAs($customer, 'customer')->postJson('/api/v1/customer/feedback', $this->report())->assertServiceUnavailable();
        Mail::assertNothingSent();
    }

    public function test_customer_can_send_at_most_five_reports_per_hour(): void
    {
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $this->actingAs($customer, 'customer');

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/customer/feedback', $this->report())->assertOk();
        }
        $this->postJson('/api/v1/customer/feedback', $this->report())->assertTooManyRequests();
        Mail::assertSentCount(5);
    }

    public function test_rider_sends_report_from_management_page(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Rider, 'email' => 'rider@example.test']);

        $this->actingAs($rider)->get(route('feedback.create'))->assertOk()->assertSee('Feedback e segnalazioni')->assertSee('Trascina qui foto o file')->assertSee('href="'.route('feedback.create').'"', false);
        $this->actingAs($rider)->post(route('feedback.store'), $this->report(['category' => 'suggestion', 'attachments' => [UploadedFile::fake()->image('zona.jpg')]]))
            ->assertRedirect(route('feedback.create'))->assertSessionHas('feedback-sent', true);
        $this->actingAs($rider)->withSession(['feedback-sent' => true])->get(route('feedback.create'))->assertSee('Grazie, segnalazione inviata!')->assertDontSee('Trascina qui foto o file');

        Mail::assertSent(FeedbackReport::class, fn (FeedbackReport $mail): bool => $mail->hasReplyTo('rider@example.test') && $mail->source === 'Gestionale (Rider)' && count($mail->attachments()) === 1);
    }

    public function test_management_page_keeps_input_when_sending_fails(): void
    {
        config(['mail.feedback_address' => null]);
        $rider = User::factory()->create(['role' => UserRole::Rider]);

        $this->actingAs($rider)->from(route('feedback.create'))->post(route('feedback.store'), $this->report())
            ->assertRedirect(route('feedback.create'))->assertSessionHasErrors('feedback')->assertSessionHasInput('subject', 'Il pulsante non risponde');
        Mail::assertNothingSent();
    }

    public function test_menu_entry_is_shown_to_riders_only(): void
    {
        $rider = User::factory()->create(['role' => UserRole::Rider]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($rider)->get(route('dashboard'))->assertSee('Feedback e segnalazioni');
        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('Feedback e segnalazioni');
    }
}
