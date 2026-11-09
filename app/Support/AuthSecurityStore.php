<?php

namespace App\Support;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AuthSecurityStore
{
    public static function key(string $namespace, string $identifier): string
    {
        return 'auth:'.$namespace.':'.hash_hmac('sha256', $identifier, (string) config('app.key'));
    }

    public static function email(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function cache(): Repository
    {
        return $this->available(function (): Repository {
            $name = config('auth_security.store');
            $driver = config('cache.stores.'.$name.'.driver');
            if ((! app()->environment('testing', 'local') && ! in_array($driver, ['database', 'redis', 'memcached'], true))
                || in_array($driver, ['failover', 'null'], true)) {
                throw new \RuntimeException('Unsupported security store.');
            }
            $cache = Cache::store($name);
            if (! $cache->getStore() instanceof LockProvider) {
                throw new \RuntimeException('Security store requires atomic locks.');
            }

            return $cache;
        });
    }

    public function limiter(): RateLimiter
    {
        return new RateLimiter($this->cache());
    }

    public function available(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            SecurityEvent::record('counter_store_unavailable');
            throw new HttpException(503, 'Accesso temporaneamente non disponibile. Riprova tra poco.', headers: ['Retry-After' => '30']);
        }
    }

    /** @param list<string> $keys */
    public function atomic(array $keys, Closure $callback): mixed
    {
        $locks = [];
        sort($keys);
        try {
            foreach (array_unique($keys) as $key) {
                $lock = $this->available(fn () => $this->cache()->lock($key.':lock', config('auth_security.lock_seconds')));
                if (! $this->available(fn () => $lock->get())) {
                    $this->limited(1);
                }
                $locks[] = $lock;
            }

            return $callback();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $this->available(fn () => $lock->release());
            }
        }
    }

    public function consume(string $key, int $maximum, int $seconds): int
    {
        return $this->atomic([$key], function () use ($key, $maximum, $seconds): int {
            return $this->available(function () use ($key, $maximum, $seconds): int {
                $limiter = $this->limiter();
                if ($limiter->tooManyAttempts($key, $maximum)) {
                    $this->limited($limiter->availableIn($key));
                }

                return $limiter->hit($key, $seconds);
            });
        });
    }

    public function limited(int $seconds): never
    {
        SecurityEvent::record('rate_limited');
        throw new ThrottleRequestsException('Troppe richieste. Riprova più tardi.', headers: ['Retry-After' => (string) max(1, $seconds)]);
    }
}
