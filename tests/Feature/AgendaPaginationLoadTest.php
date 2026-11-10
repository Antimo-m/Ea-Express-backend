<?php

namespace Tests\Feature;

use App\Http\Controllers\AgendaBeforeController;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\EnsureStaff;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\UserRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PDO;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgendaPaginationLoadTest extends TestCase
{
    public function test_two_level_agenda_is_bounded_and_complete_under_three_mysql_load_profiles(): void
    {
        if (getenv('EA_RUN_AGENDA_LOAD') !== '1') {
            $this->markTestSkipped('Profilo isolato MySQL: EA_RUN_AGENDA_LOAD=1.');
        }
        $source = DB::connection('mysql')->getConfig();
        $this->assertContains($source['host'], ['127.0.0.1', 'localhost', '::1']);
        $owner = new PDO('mysql:host='.$source['host'].';port='.$source['port'].';charset=utf8mb4', $source['username'], $source['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($source['options'] ?? []));
        $database = 'ea_agenda_test_'.bin2hex(random_bytes(8));
        $original = config('database.default');
        $directory = getenv('EA_AGENDA_CAPTURE_DIR') ?: '/private/tmp/ea-agenda-load-'.bin2hex(random_bytes(6));
        $this->assertStringStartsWith('/private/tmp/', $directory);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $results = ['metadata' => [
            'php' => PHP_VERSION, 'laravel' => app()->version(), 'mysql' => $owner->query('SELECT VERSION()')->fetchColumn(),
            'transport' => 'Laravel HTTP kernel in process, authenticated staff; isolated local MySQL; Xdebug mode '.(getenv('XDEBUG_MODE') ?: 'default'),
            'samples' => 'One warm-up followed by three measured requests per endpoint; setup and exhaustive traversal excluded.',
            'memory' => 'Per-request live allocation and reset peak growth; process boot and fixture allocation excluded.',
            'excluded' => ['Production hosting', 'FPM cold starts', 'TLS', 'WAN latency', 'Concurrent users'],
        ], 'scenarios' => []];
        $owner->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        try {
            unset($source['name']);
            config(['database.default' => 'agenda_load', 'database.connections.agenda_load' => [...$source, 'url' => null, 'database' => $database]]);
            $this->artisan('migrate', ['--database' => 'agenda_load', '--force' => true])->assertSuccessful();
            $this->travelTo(now('Europe/Rome')->setDate(2026, 11, 7)->setTime(12, 0));
            $results['metadata']['transaction_isolation'] = DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level;
            $results['metadata']['indexes'] = DB::select('SHOW INDEX FROM orders');
            $results['metadata']['event_indexes'] = DB::select('SHOW INDEX FROM order_events');
            $before = $this->registerPreviousAgenda($directory);
            $hydrated = 0;
            Order::retrieved(function () use (&$hydrated): void {
                $hydrated++;
            });
            foreach (['A_60_accounts_100_each' => array_fill(0, 60, 100), 'B_1_account_5000' => [5000], 'C_100_accounts_variable' => range(1, 100)] as $name => $sizes) {
                try {
                    $fixture = $this->fixtures($sizes);
                    $this->actingAs($fixture['admin']);
                    $url = route('pickups.day', ['date' => '2026-11-07', 'per_page' => 10]);
                    [$summary, $summaryMetrics, $summaryQueries] = $this->measure($url, $hydrated);
                    $summary->assertJsonPath('total_orders', array_sum($sizes))->assertJsonPath('meta.total', count($sizes))
                        ->assertJsonCount(min(10, count($sizes)), 'data')->assertJsonMissingPath('data.0.orders');
                    $this->assertSame(0, $summaryMetrics['hydrated_order_models']);
                    $this->assertLessThanOrEqual(15, $summaryMetrics['query_count']);
                    $this->assertLessThan(10000, $summaryMetrics['payload_bytes']);
                    [$summaryHtml, $summaryHtmlMetrics] = $this->measure($url, $hydrated, false);
                    $summaryHtml->assertViewMissing('orders');
                    $this->assertSame(0, $summaryHtmlMetrics['hydrated_order_models']);
                    $this->assertSame(0, substr_count($summaryHtml->getContent(), 'data-operational-order='));
                    $ordersUrl = $summary->json('data.0.orders_url');
                    [$detail, $detailMetrics, $detailQueries] = $this->measure($ordersUrl, $hydrated);
                    $detail->assertJsonCount(min(20, $sizes[0]), 'data')->assertJsonPath('meta.total', $sizes[0]);
                    $this->assertSame(min(20, $sizes[0]), $detailMetrics['hydrated_order_models']);
                    $this->assertLessThanOrEqual(20, $detailMetrics['query_count']);
                    $this->assertLessThan(40000, $detailMetrics['payload_bytes']);
                    $entry = [
                        'accounts' => count($sizes), 'orders' => array_sum($sizes), 'order_events' => array_sum($sizes) * 2,
                        'summary' => $summaryMetrics, 'summary_html' => $summaryHtmlMetrics, 'account_orders' => $detailMetrics,
                        'plans' => ['summary' => $this->plans($summaryQueries), 'account_orders' => $this->plans($detailQueries)],
                    ];
                    $largestIndex = array_search(max($sizes), $sizes, true);
                    $experimentQueries = $detailQueries;
                    if ($sizes[$largestIndex] > $sizes[0]) {
                        $largestSlug = array_keys($fixture['orders'])[$largestIndex];
                        [$largest, $largestMetrics, $largestQueries] = $this->measure(route('pickups.account-orders', ['account' => $largestSlug, 'kind' => 'customer', 'date' => '2026-11-07']), $hydrated);
                        $largest->assertJsonCount(20, 'data')->assertJsonPath('meta.total', $sizes[$largestIndex]);
                        $this->assertSame(20, $largestMetrics['hydrated_order_models']);
                        $this->assertLessThan(40000, $largestMetrics['payload_bytes']);
                        $entry['largest_account_orders'] = $largestMetrics;
                        $entry['plans']['largest_account_orders'] = $this->plans($largestQueries);
                        $experimentQueries = $largestQueries;
                        unset($largest);
                    }
                    if ($before) {
                        [$response, $metrics, $queries] = $this->measure('/__agenda-before/day?date=2026-11-07', $hydrated, false);
                        $response->assertViewHas('totalOrders', array_sum($sizes));
                        $this->assertSame(array_sum(array_slice($sizes, 0, 20)), $response->viewData('orders')->count());
                        $entry['before_html'] = $metrics;
                        $entry['before_html']['account_groups'] = $response->viewData('groups')->count();
                        $entry['before_html']['order_cards'] = $response->viewData('orders')->count();
                        $entry['plans']['before_html'] = $this->plans($queries);
                        unset($response);
                    }
                    $entry['integrity'] = $this->assertCompleteTraversal($fixture['orders']);
                    $entry['index_experiments'] = $this->indexExperiments($summaryQueries, $experimentQueries);
                    $results['scenarios'][$name] = $entry;
                    file_put_contents($directory.'/result.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    unset($summary, $summaryHtml, $detail, $fixture);
                } finally {
                    DB::table('orders')->delete();
                    DB::table('users')->delete();
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
            }
            $results['concurrent_reads'] = $this->assertReadSnapshots($source);
            $indexMigration = 'database/migrations/2026_11_07_120407_add_pickup_agenda_pagination_indexes.php';
            $this->artisan('migrate:rollback', ['--database' => 'agenda_load', '--path' => $indexMigration, '--force' => true])->assertSuccessful();
            $this->assertNotContains('orders_pickup_account_chronology_index', array_column(DB::select('SHOW INDEX FROM orders'), 'Key_name'));
            $this->assertNotContains('order_events_order_status_index', array_column(DB::select('SHOW INDEX FROM order_events'), 'Key_name'));
            $this->artisan('migrate', ['--database' => 'agenda_load', '--path' => $indexMigration, '--force' => true])->assertSuccessful();
            $this->assertContains('orders_pickup_account_chronology_index', array_column(DB::select('SHOW INDEX FROM orders'), 'Key_name'));
            $this->assertContains('order_events_order_status_index', array_column(DB::select('SHOW INDEX FROM order_events'), 'Key_name'));
            $results['metadata']['index_migration_down_up_passed'] = true;
            $security = new Process([PHP_BINARY, '-d', 'memory_limit=512M', base_path('vendor/bin/phpunit'), base_path('tests/Feature/AgendaAuthorizationTest.php'), '--colors=never'], base_path(), [
                'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $source['host'], 'DB_PORT' => (string) $source['port'],
                'DB_DATABASE' => $database, 'DB_USERNAME' => (string) $source['username'], 'DB_PASSWORD' => (string) $source['password'],
                'DB_SOCKET' => (string) ($source['unix_socket'] ?? ''), 'XDEBUG_MODE' => 'off',
            ]);
            $security->setTimeout(60)->run();
            file_put_contents($directory.'/authorization-mysql.log', $security->getOutput().$security->getErrorOutput());
            $results['security_mysql'] = ['exit_code' => $security->getExitCode(), 'log' => $directory.'/authorization-mysql.log'];
            $this->assertTrue($security->isSuccessful(), $security->getOutput().$security->getErrorOutput());
        } finally {
            DB::purge('agenda_load');
            config(['database.default' => $original]);
            $owner->exec('DROP DATABASE `'.$database.'`');
            $results['metadata']['temporary_database_removed'] = true;
            file_put_contents($directory.'/result.json', json_encode($results, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function assertReadSnapshots(array $source): array
    {
        $database = DB::connection()->getDatabaseName();
        $writer = new PDO('mysql:host='.$source['host'].';port='.$source['port'].';dbname='.$database.';charset=utf8mb4', $source['username'], $source['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION] + ($source['options'] ?? []));
        $isolation = DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level;
        $results = [];
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        try {
            foreach (['accounts', 'orders'] as $phase) {
                $fixture = $this->fixtures([2]);
                $this->actingAs($fixture['admin']);
                $slug = array_key_first($fixture['orders']);
                $slugs = $fixture['orders'][$slug];
                $state = (object) ['armed' => true, 'writes' => 0];
                DB::listen(function (QueryExecuted $event) use ($state, $writer, $slugs): void {
                    $sql = strtolower($event->sql);
                    if (! $state->armed || $event->connectionName !== 'agenda_load' || ! str_contains($sql, 'count(') || ! str_contains($sql, 'from `orders`')) {
                        return;
                    }
                    $state->armed = false;
                    $writer->beginTransaction();
                    try {
                        $writer->prepare('DELETE FROM orders WHERE slug = ?')->execute([$slugs[0]]);
                        $writer->prepare('UPDATE orders SET pickup_date = ? WHERE slug = ?')->execute(['2026-11-08', $slugs[1]]);
                        $writer->commit();
                        $state->writes++;
                    } catch (\Throwable $exception) {
                        $writer->rollBack();
                        throw $exception;
                    }
                });
                try {
                    $url = $phase === 'accounts' ? '/pickups/day?date=2026-11-07' : route('pickups.account-orders', ['account' => $slug, 'kind' => 'customer', 'date' => '2026-11-07']);
                    $response = $this->getJson($url)->assertOk();
                    $this->assertSame(1, $state->writes);
                    if ($phase === 'accounts') {
                        $response->assertJsonPath('total_orders', 2)->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.orders_count', 2);
                    } else {
                        $response->assertJsonPath('account.orders_count', 2)->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
                        $this->assertSame($slugs, array_column($response->json('data'), 'slug'));
                        $this->getJson($url)->assertNotFound();
                    }
                    $this->getJson('/pickups/day?date=2026-11-07')->assertOk()->assertJsonPath('total_orders', 0)->assertJsonCount(0, 'data');
                    $this->getJson('/pickups/day?date=2026-11-08')->assertOk()->assertJsonPath('total_orders', 1)->assertJsonCount(1, 'data');
                    $this->assertSame('READ-COMMITTED', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
                    $results[$phase] = ['snapshot_count' => 2, 'subsequent_day_count' => 0, 'next_day_count' => 1, 'independent_writer_commits' => $state->writes];
                } finally {
                    $state->armed = false;
                    DB::table('orders')->delete();
                    DB::table('users')->delete();
                }
            }
        } finally {
            $level = str_replace('-', ' ', $isolation);
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$level);
        }

        return ['session_isolation_tested' => 'READ-COMMITTED', 'requests_use_repeatable_read_snapshot' => true, 'cases' => $results];
    }

    /** @param list<int> $sizes @return array{admin: User, orders: array<string, list<string>>} */
    private function fixtures(array $sizes): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $orders = [];
        foreach ($sizes as $index => $size) {
            $customer = User::factory()->create(['role' => UserRole::Customer, 'name' => sprintf('Account sintetico %03d', $index)]);
            $orders[$customer->slug] = [];
            for ($offset = 0; $offset < $size; $offset += 500) {
                $rows = Order::factory()->count(min(500, $size - $offset))->make([
                    'customer_id' => $customer->id, 'created_by' => $admin->id, 'rider_id' => $admin->id,
                    'status' => OrderStatus::PickupScheduled, 'pickup_date' => '2026-11-07', 'pickup_from' => '09:00', 'pickup_to' => '12:00',
                    'created_at' => '2026-11-06 08:00:00', 'updated_at' => '2026-11-06 08:00:00',
                    'parcel_count' => 2, 'store_name' => 'Mittente sintetico',
                ])->map(fn (Order $order): array => $order->getAttributes())->all();
                Order::insert($rows);
                $slugs = array_column($rows, 'slug');
                $orders[$customer->slug] = [...$orders[$customer->slug], ...$slugs];
                $ids = DB::table('orders')->whereIn('slug', $slugs)->pluck('id');
                $events = [];
                foreach ($ids as $id) {
                    foreach ([OrderStatus::Received, OrderStatus::PickupScheduled] as $status) {
                        $events[] = ['order_id' => $id, 'user_id' => $admin->id, 'status' => $status->value, 'created_at' => '2026-11-06 08:00:00', 'updated_at' => '2026-11-06 08:00:00'];
                    }
                }
                DB::table('order_events')->insert($events);
            }
        }

        return compact('admin', 'orders');
    }

    /** @return array{TestResponse, array<string,mixed>, list<array<string,mixed>>} */
    private function measure(string $url, int &$hydrated, bool $json = true): array
    {
        $samples = [];
        $response = null;
        $queries = [];
        for ($iteration = 0; $iteration < 4; $iteration++) {
            unset($response);
            gc_collect_cycles();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $hydrated = 0;
            $memory = memory_get_usage();
            memory_reset_peak_usage();
            $start = hrtime(true);
            $response = ($json ? $this->getJson($url) : $this->get($url))->assertOk();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $peak = memory_get_peak_usage() - $memory;
            $growth = memory_get_usage() - $memory;
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            if ($iteration > 0) {
                $samples[] = ['endpoint_ms' => round($elapsed, 3), 'sql_ms' => round(array_sum(array_column($queries, 'time')), 3),
                    'query_count' => count($queries), 'memory_growth_bytes' => $growth, 'peak_growth_bytes' => max(0, $peak)];
            }
        }
        $times = array_column($samples, 'endpoint_ms');
        sort($times);
        $viewData = $json ? [] : $response->original->getData();
        $metrics = ['samples' => $samples, 'endpoint_median_ms' => $times[1],
            'query_count' => count($queries), 'hydrated_order_models' => $hydrated, 'payload_bytes' => strlen($response->getContent()),
            'returned_records' => $json ? count($response->json('data')) : ($viewData['orders'] ?? $viewData['groups'])->count(),
        ];

        return [$response, $metrics, $queries];
    }

    /** @param list<array<string,mixed>> $queries @return list<array<string,mixed>> */
    private function plans(array $queries): array
    {
        $plans = [];
        foreach ($queries as $query) {
            if (! preg_match('/^select /i', $query['query']) || ! str_contains($query['query'], '`orders`')) {
                continue;
            }
            $plan = ['sql' => $query['query'], 'bindings' => $query['bindings'], 'captured_endpoint_query_ms' => $query['time'],
                'explain_json' => DB::select('EXPLAIN FORMAT=JSON '.$query['query'], $query['bindings']),
                'explain_analyze' => DB::select('EXPLAIN ANALYZE '.$query['query'], $query['bindings']),
            ];
            $plans[] = $plan;
        }

        return $plans;
    }

    /** @param list<array<string,mixed>> $summary @param list<array<string,mixed>> $details @return array<string,mixed> */
    private function indexExperiments(array $summary, array $details): array
    {
        if (getenv('EA_AGENDA_COMPARE_INDEXES') !== '1') {
            return [];
        }
        $results = [];
        $created = [];
        try {
            foreach ([
                'events_cover' => ['order_events', ['order_id', 'status']],
                'day_account_time' => ['orders', ['pickup_date', 'customer_id', 'pickup_from', 'created_at']],
                'account_day_time' => ['orders', ['customer_id', 'pickup_date', 'pickup_from', 'created_at']],
            ] as $name => [$table, $columns]) {
                if ($name === 'account_day_time' && isset($created['day_account_time'])) {
                    DB::statement('DROP INDEX `ea_agenda_probe_day_account_time` ON `orders`');
                    unset($created['day_account_time']);
                }
                $existing = [];
                foreach (DB::select('SHOW INDEX FROM `'.$table.'`') as $index) {
                    $existing[$index->Key_name][$index->Seq_in_index] = $index->Column_name;
                }
                $alreadyExists = false;
                foreach ($existing as $parts) {
                    ksort($parts);
                    if (array_values($parts) === $columns) {
                        $alreadyExists = true;
                    }
                }
                if ($alreadyExists) {
                    $results[$name] = ['already_exists' => true];

                    continue;
                }
                DB::statement('CREATE INDEX `ea_agenda_probe_'.$name.'` ON `'.$table.'` (`'.implode('`,`', $columns).'`)');
                $created[$name] = $table;
                $results[$name] = ['summary' => $this->plans($summary), 'account_orders' => $this->plans($details)];
            }
        } finally {
            foreach ($created as $name => $table) {
                DB::statement('DROP INDEX `ea_agenda_probe_'.$name.'` ON `'.$table.'`');
            }
        }

        return $results;
    }

    /** @param array<string,list<string>> $expected @return array<string,int> */
    private function assertCompleteTraversal(array $expected): array
    {
        $seenAccounts = [];
        $seenOrders = [];
        $next = route('pickups.day', ['date' => '2026-11-07', 'per_page' => 10]);
        $requestCount = 0;
        do {
            $page = $this->getJson($next)->assertOk();
            $requestCount++;
            foreach ($page->json('data') as $account) {
                $seenAccounts[] = $account['slug'];
                $this->assertSame(count($expected[$account['slug']]), $account['orders_count']);
                $this->assertSame($account['orders_count'] * 2, $account['parcel_count']);
                $accountOrders = [];
                $ordersNext = $account['orders_url'];
                do {
                    $details = $this->getJson($ordersNext)->assertOk();
                    $requestCount++;
                    $this->assertLessThanOrEqual(20, count($details->json('data')));
                    $accountOrders = [...$accountOrders, ...array_column($details->json('data'), 'slug')];
                    $ordersNext = $details->json('links.next');
                } while ($ordersNext);
                $this->assertSame($expected[$account['slug']], $accountOrders);
                $seenOrders = [...$seenOrders, ...$accountOrders];
            }
            $next = $page->json('links.next');
        } while ($next);
        $this->assertSame(array_keys($expected), $seenAccounts);
        $count = array_sum(array_map('count', $expected));
        $this->assertSame($count, count($seenOrders));
        $this->assertSame($count, count(array_unique($seenOrders)));

        return ['accounts_seen' => count($seenAccounts), 'orders_seen' => count($seenOrders), 'unique_orders_seen' => count(array_unique($seenOrders)), 'requests' => $requestCount];
    }

    private function registerPreviousAgenda(string $directory): bool
    {
        $snapshot = getenv('EA_AGENDA_BASELINE_DIR');
        if (! $snapshot || ! is_file($snapshot.'/PickupGroupController.php') || ! is_file($snapshot.'/day.blade.php')) {
            return false;
        }
        $source = str_replace('class PickupGroupController extends Controller', 'class AgendaBeforeController extends Controller', file_get_contents($snapshot.'/PickupGroupController.php'));
        $source = str_replace("view('pickups.day',", "view('agenda_before::day',", $source);
        file_put_contents($directory.'/AgendaBeforeController.php', $source);
        require $directory.'/AgendaBeforeController.php';
        view()->addNamespace('agenda_before', $snapshot);
        Route::middleware(['web', 'auth', 'auth.session', EnsureStaff::class, EnsureEmailVerified::class])
            ->get('/__agenda-before/day', [AgendaBeforeController::class, 'show']);

        return true;
    }
}
