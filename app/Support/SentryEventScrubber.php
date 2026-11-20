<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Ultimo filtro prima dell'invio a Sentry: restano tipo di errore, file, riga e percorso,
 * mentre dati personali, testo SQL, token negli indirizzi e contenuto delle richieste non lasciano il server.
 */
class SentryEventScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            $event->setRequest(array_filter(['method' => $request['method'] ?? null, 'url' => isset($request['url']) ? self::clean((string) $request['url']) : null]));
        }
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::clean($exception->getValue()));
        }
        if ($event->getMessage() !== null) {
            $event->setMessage(self::clean($event->getMessage()));
        }
        $event->setBreadcrumb(array_map(fn (Breadcrumb $breadcrumb): Breadcrumb => $breadcrumb->getMessage() === null ? $breadcrumb : $breadcrumb->withMessage(self::clean($breadcrumb->getMessage())), $event->getBreadcrumbs()));
        $event->setExtra([]);
        $event->setUser(null);

        return $event;
    }

    public static function clean(string $text): string
    {
        $text = preg_replace('/\(Connection: .*?SQL: .*\)\s*$/s', '(SQL omessa)', $text) ?? '';
        $text = preg_replace('#(https?://[^\s?]+)\?\S*#', '$1', $text) ?? '';
        $text = preg_replace('/[^\s@<>()"\']+@[^\s@<>()"\']+\.[a-z]{2,}/i', '[email]', $text) ?? '';
        $text = preg_replace('/(?<![\w-])\+?\d(?:[ .\/]?\d){8,14}(?![\w-])/', '[numero]', $text) ?? '';

        return preg_replace('/\b[A-Za-z0-9]{32,}\b/', '[token]', $text) ?? '';
    }
}
