<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Request as BaseRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LimitRequestInput
{
    private const int MaxBodyBytes = 262144;

    public function handle(Request $request, Closure $next): Response
    {
        $this->validatePayload($request);

        return $next($request);
    }

    /** Also called before Laravel decodes JSON during Request::capture(). */
    public function validatePayload(BaseRequest $request): void
    {
        if ((int) $request->headers->get('Content-Length', 0) > self::MaxBodyBytes || strlen($request->server->get('QUERY_STRING', '')) > 16384) {
            throw new HttpException(413, 'Richiesta troppo grande.');
        }
        if ($request->files->count() > 0) {
            throw new HttpException(422, 'Il caricamento di file non è supportato.');
        }
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, self::MaxBodyBytes + 1);
        rewind($stream);
        if ($body === false || strlen($body) > self::MaxBodyBytes) {
            throw new HttpException(413, 'Richiesta troppo grande.');
        }
        $count = 0;
        if (str_contains(strtolower($request->headers->get('Content-Type', '')), 'json') && trim($body) !== '') {
            try {
                $data = json_decode($body, true, 14, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new HttpException($exception->getCode() === JSON_ERROR_DEPTH ? 413 : 400, 'JSON non valido o troppo complesso.');
            }
            if (! is_array($data)) {
                throw new HttpException(400, 'Il payload deve essere un oggetto o un array JSON.');
            }
            $this->inspect($data, 0, $count);
        } else {
            $this->inspect($request->request->all(), 0, $count);
        }
        $this->inspect($request->query->all(), 0, $count);
        foreach ($request->query->all() as $key => $value) {
            if ($key === 'page' || str_ends_with($key, '_page')) {
                if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) === false) {
                    throw new HttpException(422, 'Pagina non valida: usa un valore tra 1 e 10000.');
                }
            }
        }
    }

    /** @param array<array-key, mixed> $data */
    private function inspect(array $data, int $depth, int &$count): void
    {
        if ($depth > 12 || count($data) + $count > 2000) {
            throw new HttpException(413, 'Richiesta troppo complessa.');
        }
        $count += count($data);
        foreach ($data as $key => $value) {
            if (strlen((string) $key) > 255 || (is_string($value) && strlen($value) > 32768)) {
                throw new HttpException(413, 'Campo troppo grande.');
            }
            if (is_array($value)) {
                $this->inspect($value, $depth + 1, $count);
            }
        }
    }
}
