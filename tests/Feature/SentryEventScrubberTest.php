<?php

namespace Tests\Feature;

use App\Support\SentryEventScrubber;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\UserDataBag;
use Tests\TestCase;

class SentryEventScrubberTest extends TestCase
{
    public function test_personal_data_sql_and_tokens_never_leave_the_server(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['method' => 'POST', 'url' => 'https://area.ea-express.it/track/'.str_repeat('a1B2', 16), 'query_string' => 'email=mario@example.test', 'cookies' => ['session' => 'secret'], 'headers' => ['Authorization' => 'Bearer secret'], 'data' => ['recipient_phone' => '+39 3331234567']]);
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('Utente mario.rossi@example.test, telefono +39 333 123 4567 (Connection: mysql, Host: 127.0.0.1, SQL: insert into users (email) values (mario.rossi@example.test))'))]);
        $event->setMessage('Link https://app.ea-express.it/reset?token=abc&email=x@y.it scaduto il 2026-11-20');
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Invio a cliente@example.test')]);
        $event->setExtra(['payload' => ['name' => 'Mario Rossi']]);
        $event->setUser(UserDataBag::createFromUserIdentifier('42'));

        $clean = SentryEventScrubber::beforeSend($event);

        $this->assertSame(['method' => 'POST', 'url' => 'https://area.ea-express.it/track/[token]'], $clean->getRequest());
        $this->assertSame('Utente [email], telefono [numero] (SQL omessa)', $clean->getExceptions()[0]->getValue());
        $this->assertSame('Link https://app.ea-express.it/reset scaduto il 2026-11-20', $clean->getMessage());
        $this->assertSame('Invio a [email]', $clean->getBreadcrumbs()[0]->getMessage());
        $this->assertSame([], $clean->getExtra());
        $this->assertNull($clean->getUser());
    }

    public function test_sentry_is_configured_without_personal_data_and_survives_config_cache(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertSame('never', config('sentry.max_request_body_size'));
        $this->assertFalse(config('sentry.breadcrumbs.sql_bindings'));
        $this->assertTrue(is_callable(config('sentry.before_send')));
        $this->assertSame(config('sentry.before_send'), unserialize(serialize(config('sentry.before_send'))));
    }
}
