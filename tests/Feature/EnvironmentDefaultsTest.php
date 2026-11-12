<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EnvironmentDefaultsTest extends TestCase
{
    #[TestWith(['local', false])]
    #[TestWith(['production', true])]
    public function test_copying_example_uses_environment_appropriate_secure_cookies(string $environment, bool $secure): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
Dotenv\Dotenv::createMutable(getcwd(), '.env.example')->load();
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $argv[1];
$session = require 'config/session.php';
echo json_encode([
    'secure' => $session['secure'],
    'http_only' => $session['http_only'],
    'same_site' => $session['same_site'],
    'domain' => $session['domain'],
    'connection' => $session['connection'],
    'encrypt' => $session['encrypt'],
    'debug' => Illuminate\Support\Env::get('APP_DEBUG'),
], JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $environment], base_path(), ['SESSION_SECURE_COOKIE' => false]);

        $process->mustRun();

        $this->assertSame([
            'secure' => $secure, 'http_only' => true, 'same_site' => 'lax',
            'domain' => null, 'connection' => null, 'encrypt' => true, 'debug' => false,
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
