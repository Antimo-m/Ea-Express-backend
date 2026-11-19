<?php

namespace Tests\Feature;

use App\Actions\TransitionOrder;
use App\Models\Order;
use App\Models\PendingAccount;
use App\Models\ShippingRate;
use App\Models\User;
use App\OrderStatus;
use App\Support\PostalCodeResolver;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\TestCase;
use Throwable;

/**
 * Misura di riferimento delle pagine principali su un anno di attività realistica in MySQL.
 *
 * Il fixture viene generato una sola volta con i percorsi reali dell'applicazione (checkout del portale,
 * passaggi di stato, incassi, storni, spese, movimenti, sospesi e messaggi) e riutilizzato nelle esecuzioni
 * successive, così le misure prima e dopo ogni ottimizzazione partono dagli stessi dati.
 *
 * Esecuzione: EA_RUN_BASELINE=1 EA_BASELINE_OUTPUT=/private/tmp/... vendor/bin/phpunit --filter=ProductionVolumeBaselineTest
 * Variabili facoltative: EA_BASELINE_ONLY (prefissi separati da virgola), EA_BASELINE_ALL_QUERIES=1 (tutte le query, non solo le 4 più lente), EA_BASELINE_DATABASE, EA_BASELINE_RATES_DATABASE (listino da copiare, predefinito ea_express), EA_BASELINE_FRESH=1, EA_BASELINE_DAYS, EA_BASELINE_ORDERS_PER_DAY, EA_BASELINE_SAMPLES.
 */
class ProductionVolumeBaselineTest extends TestCase
{
    private const int FixtureVersion = 1;

    private int $hydratedModels = 0;

    /** @var array<string, mixed> */
    private array $fixture = [];

    public function test_one_year_of_realistic_activity_is_measured_on_mysql(): void
    {
        if (getenv('EA_RUN_BASELINE') !== '1') {
            $this->markTestSkipped('Misura MySQL isolata: EA_RUN_BASELINE=1.');
        }
        $output = getenv('EA_BASELINE_OUTPUT') ?: '/private/tmp/ea-baseline-'.date('Ymd-His');
        $this->assertStringStartsWith('/private/tmp/', $output);
        if (! is_dir($output)) {
            mkdir($output, 0700, true);
        }
        $database = getenv('EA_BASELINE_DATABASE') ?: 'ea_baseline_fixture';
        $this->assertMatchesRegularExpression('/\Aea_baseline_[a-z0-9_]{1,40}\z/', $database);
        $source = DB::connection('mysql')->getConfig();
        $this->assertContains($source['host'], ['127.0.0.1', 'localhost', '::1']);
        $owner = new PDO('mysql:host='.$source['host'].';port='.$source['port'].';charset=utf8mb4', $source['username'], $source['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if (getenv('EA_BASELINE_FRESH') === '1') {
            $owner->exec('DROP DATABASE IF EXISTS `'.$database.'`');
        }
        $owner->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        unset($source['name']);
        config(['database.default' => 'baseline', 'database.connections.baseline' => [...$source, 'url' => null, 'database' => $database]]);
        DB::purge('baseline');

        $days = max(2, (int) (getenv('EA_BASELINE_DAYS') ?: 365));
        $ordersPerDay = max(1, (int) (getenv('EA_BASELINE_ORDERS_PER_DAY') ?: 60));
        $end = CarbonImmutable::parse('2026-11-18', 'Europe/Rome');
        $ready = Schema::hasTable('baseline_fixture')
            && DB::table('baseline_fixture')->where(['version' => self::FixtureVersion, 'days' => $days, 'orders_per_day' => $ordersPerDay, 'complete' => true])->exists();
        $generationSeconds = null;
        if (! $ready) {
            $this->artisan('migrate:fresh', ['--database' => 'baseline', '--force' => true])->assertSuccessful();
            Schema::create('baseline_fixture', function (Blueprint $table): void {
                $table->unsignedInteger('version');
                $table->unsignedInteger('days');
                $table->unsignedInteger('orders_per_day');
                $table->boolean('complete')->default(false);
                $table->unsignedInteger('generation_seconds')->nullable();
            });
            $started = hrtime(true);
            $ratesDatabase = getenv('EA_BASELINE_RATES_DATABASE') ?: 'ea_express';
            $this->assertMatchesRegularExpression('/\A[a-z0-9_]{1,64}\z/', $ratesDatabase);
            $this->generate($end->subDays($days - 1), $end, $ordersPerDay, $ratesDatabase);
            $generationSeconds = (int) round((hrtime(true) - $started) / 1e9);
            DB::table('baseline_fixture')->insert(['version' => self::FixtureVersion, 'days' => $days, 'orders_per_day' => $ordersPerDay, 'complete' => true, 'generation_seconds' => $generationSeconds]);
        }

        $this->withMiddleware();
        $this->travelTo($end->setTime(18, 0));
        $results = $this->measureAll((int) (getenv('EA_BASELINE_SAMPLES') ?: 5));
        $report = [
            'metadata' => [
                'php' => PHP_VERSION, 'laravel' => app()->version(), 'mysql' => DB::selectOne('SELECT VERSION() AS version')->version,
                'xdebug' => extension_loaded('xdebug') ? (getenv('XDEBUG_MODE') ?: 'default') : 'not loaded', 'opcache_cli' => (bool) ini_get('opcache.enable_cli'),
                'database' => $database, 'days' => $days, 'orders_per_day_target' => $ordersPerDay,
                'generation_seconds' => $generationSeconds ?? DB::table('baseline_fixture')->value('generation_seconds'),
                'measured_at' => now('Europe/Rome')->toIso8601String(),
                'transport' => 'Laravel HTTP kernel in process; scoped services reset before every sample to mimic a fresh PHP-FPM request; each sample rolled back.',
                'rows' => $this->rowCounts(),
            ],
            'results' => $results,
        ];
        file_put_contents($output.'/baseline.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        file_put_contents($output.'/baseline.md', $this->markdown($report));
        $this->assertNotEmpty($results);
    }

    private function generate(CarbonImmutable $first, CarbonImmutable $last, int $ordersPerDay, string $sourceDatabase): void
    {
        mt_srand(20261118);
        fake()->seed(20261118);
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo($first->setTime(7, 0));
        $columns = collect(Schema::getColumnListing('shipping_rates'))->map(fn (string $column): string => '`'.$column.'`')->implode(', ');
        DB::statement('INSERT INTO shipping_rates ('.$columns.') SELECT '.$columns.' FROM `'.$sourceDatabase.'`.shipping_rates');
        $destinations = $this->destinations();
        $this->assertNotEmpty($destinations, 'Servono tariffe regionali attive con CAP risolvibile.');

        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Admin Baseline']);
        $riders = User::factory()->count(6)->create(['role' => UserRole::Rider]);
        $customers = collect(range(1, 80))->map(fn (int $index): User => User::factory()->create([
            'role' => UserRole::Customer, 'name' => fake()->company(), 'created_at' => $first->subDays(mt_rand(1, 30)),
        ]));
        $weights = $customers->keys()->map(fn (int $index): float => 1 / (1 + $index * 0.15))->all();
        $paidOrders = [];
        $pendingAccounts = [];

        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if ($day->isSunday()) {
                continue;
            }
            $season = match ((int) $day->month) {
                12 => 1.4, 8 => 0.6, 1 => 0.8, default => 1.0,
            };
            $count = max(1, (int) round($ordersPerDay * $season * mt_rand(80, 120) / 100));
            $this->travelTo($day->setTime(7, 0));
            $orders = [];
            for ($index = 0; $index < $count; $index++) {
                $customer = $customers[$this->weighted($weights)];
                $orders[] = $this->checkout($customer, $destinations[mt_rand(0, count($destinations) - 1)], $day);
            }
            $isToday = $day->isSameDay($last);

            $this->travelTo($day->setTime(9, 0));
            foreach ($orders as $order) {
                if (mt_rand(1, 100) <= 2) {
                    $this->transition($order, $admin, ['status' => OrderStatus::Rejected->value]);

                    continue;
                }
                $this->transition($order, $admin, ['status' => OrderStatus::Accepted->value, 'rider_id' => $riders[mt_rand(0, $riders->count() - 1)]->id]);
            }
            $steps = [10 => OrderStatus::RiderArriving, 11 => OrderStatus::PickedUp, 12 => OrderStatus::InTransit, 14 => OrderStatus::OutForDelivery];
            foreach ($steps as $hour => $status) {
                if ($isToday && $hour > 11) {
                    break;
                }
                $this->travelTo($day->setTime($hour, mt_rand(0, 50)));
                foreach ($orders as $order) {
                    if (! in_array($status, $order->allowedTransitions(), true) || ($isToday && mt_rand(1, 3) === 1)) {
                        continue;
                    }
                    if ($status === OrderStatus::InTransit && mt_rand(1, 100) <= 3) {
                        $this->transition($order, $admin, ['status' => OrderStatus::Cancelled->value, 'cancellation_reason' => 'other']);

                        continue;
                    }
                    $this->transition($order, User::find($order->rider_id), ['status' => $status->value]);
                }
            }
            if (! $isToday) {
                $this->travelTo($day->setTime(16, mt_rand(0, 50)));
                foreach ($orders as $order) {
                    if ($order->status !== OrderStatus::OutForDelivery) {
                        continue;
                    }
                    $rider = User::find($order->rider_id);
                    if (mt_rand(1, 100) <= 3) {
                        $this->transition($order, $rider, ['status' => OrderStatus::DeliveryAttempted->value]);
                        $this->transition($order, $admin, ['status' => OrderStatus::Cancelled->value, 'cancellation_reason' => 'recipient_absent']);

                        continue;
                    }
                    $this->transition($order, $rider, ['status' => OrderStatus::Delivered->value]);
                }
            }

            $this->travelTo($day->setTime(19, 0));
            $this->actingAs($admin, 'web');
            foreach ($orders as $order) {
                if ($order->status === OrderStatus::Delivered && mt_rand(1, 100) <= 85) {
                    $this->post('/balance/'.$order->slug.'/payment', ['action' => 'receive', 'version' => $order->fresh()->version])->assertRedirect();
                    $paidOrders[] = $order->id;
                }
                if (mt_rand(1, 100) <= 8) {
                    $this->postJson('/messages/'.$order->slug, ['body' => 'Aggiornamento sulla consegna: '.fake()->sentence(), 'submission_key' => (string) Str::uuid()])->assertSuccessful();
                }
            }
            if (count($paidOrders) > 20 && mt_rand(1, 100) <= 30) {
                $reversed = Order::find($paidOrders[mt_rand(0, count($paidOrders) - 21)]);
                if ($reversed && $reversed->paid_at && ! $reversed->receipt_voided_at) {
                    $this->post('/balance/'.$reversed->slug.'/payment', ['action' => 'reverse', 'version' => $reversed->version, 'note' => 'Storno verificato con il cliente'])->assertRedirect();
                    if (mt_rand(1, 100) <= 40) {
                        $this->post('/balance/'.$reversed->slug.'/payment', ['action' => 'refund', 'version' => $reversed->fresh()->version, 'note' => 'Denaro restituito al cliente'])->assertRedirect();
                    }
                }
            }
            if (mt_rand(1, 100) <= 60) {
                $this->post('/expenses', ['description' => fake()->randomElement(['Carburante', 'Pedaggi', 'Manutenzione furgone', 'Imballaggi', 'Telefonia']), 'amount' => (string) mt_rand(10, 180), 'spent_on' => $day->toDateString(), 'submission_key' => (string) Str::uuid()])->assertRedirect();
            }
            if (mt_rand(1, 100) <= 40) {
                $kind = fake()->randomElement(['cash', 'income', 'extra_expense', 'adjustment_in', 'adjustment_out']);
                $this->post('/movements', ['kind' => $kind, 'description' => 'Movimento '.fake()->words(3, true), 'amount' => (string) mt_rand(5, 300), 'occurred_on' => $day->toDateString(), 'customer_id' => mt_rand(1, 2) === 1 ? $customers->random()->slug : null, 'submission_key' => (string) Str::uuid()])->assertRedirect();
            }
            if (mt_rand(1, 100) <= 25) {
                $this->post('/pending', ['subject' => fake()->company(), 'direction' => mt_rand(1, 3) === 1 ? 'outgoing' : 'incoming', 'description' => 'Sospeso '.fake()->words(3, true), 'amount' => (string) mt_rand(20, 400), 'customer_id' => $customers->random()->slug, 'occurred_on' => $day->toDateString(), 'due_on' => $day->addDays(30)->toDateString()])->assertRedirect();
                $pendingAccounts[] = PendingAccount::latest('id')->value('id');
            }
            if ($pendingAccounts !== [] && mt_rand(1, 100) <= 20) {
                $account = PendingAccount::find($pendingAccounts[mt_rand(0, count($pendingAccounts) - 1)]);
                $residual = $account->amount_cents - $account->settled_cents;
                if ($residual > 0) {
                    $this->post('/pending/'.$account->slug.'/settlements', ['amount' => number_format(min($residual, mt_rand(10, 400) * 100) / 100, 2, '.', ''), 'submission_key' => (string) Str::uuid(), 'version' => $account->version])->assertRedirect();
                }
            }
            if ($day->isSaturday()) {
                $this->patch('/notifications/read-all')->assertRedirect();
            }
        }
    }

    /** @return list<array{city: string, postal_code: string, province: string, region: string}> */
    private function destinations(): array
    {
        $resolver = app(PostalCodeResolver::class);
        $destinations = [];
        foreach (DB::table('shipping_rates')->where('active', true)->whereNull('archived_at')->where('shipping_type', 'regional')->whereNotNull('postal_code')->get(['city', 'postal_code']) as $rate) {
            try {
                $place = $resolver->canonical($rate->city, $rate->postal_code);
                $destinations[] = ['city' => $place['name'], 'postal_code' => $place['postal_code'], 'province' => $place['province'], 'region' => $place['region']];
            } catch (Throwable) {
                continue;
            }
        }

        return $destinations;
    }

    /** @param array{city: string, postal_code: string, province: string, region: string} $destination */
    private function checkout(User $customer, array $destination, CarbonImmutable $day): Order
    {
        $this->actingAs($customer, 'customer');
        $start = fake()->randomElement(['09:00', '10:00', '14:00', '15:00']);
        $data = [
            'store_name' => $customer->name, 'sender_type' => 'business', 'recipient_name' => fake()->name(), 'recipient_phone' => '+39 333 '.mt_rand(1000000, 9999999),
            'pickup_address' => 'Via '.fake()->lastName(), 'pickup_city' => 'Napoli', 'pickup_street_number' => (string) mt_rand(1, 200), 'pickup_postal_code' => '80100',
            'delivery_address' => 'Via '.fake()->lastName(), 'delivery_city' => $destination['city'], 'delivery_street_number' => (string) mt_rand(1, 200), 'delivery_postal_code' => $destination['postal_code'],
            'delivery_province' => $destination['province'], 'delivery_region' => $destination['region'],
            'pickup_date' => $day->toDateString(), 'pickup_from' => $start, 'pickup_to' => sprintf('%02d:00', (int) $start + 3),
            'parcel_count' => mt_rand(1, 3), 'package_type' => fake()->randomElement(['standard', 'standard', 'fragile']), 'category' => fake()->randomElement(['clothing', 'other', 'documents']),
            'urgency' => mt_rand(1, 10) === 1 ? 'urgent' : 'standard', 'parcel_value' => (string) mt_rand(0, 300), 'payment_method' => 'cash',
        ];
        $review = $this->postJson('/api/v1/customer/orders/checkout', $data)->assertOk()->json();
        $slug = $this->postJson('/api/v1/customer/orders', [...$review['data'], 'checkout_token' => $review['checkout_token']])->assertCreated()->json('data.slug');
        $order = Order::where('slug', $slug)->firstOrFail();
        if (mt_rand(1, 100) <= 10) {
            $this->postJson('/api/v1/customer/orders/'.$slug.'/messages', ['body' => 'Il citofono è al secondo piano, grazie.', 'submission_key' => (string) Str::uuid()])->assertSuccessful();
        }

        return $order;
    }

    /** @param array<string, mixed> $data */
    private function transition(Order $order, User $user, array $data): void
    {
        $order->refresh();
        app(TransitionOrder::class)->handle($order, $user, [...$data, 'version' => $order->version]);
        $order->refresh();
    }

    /** @param list<float> $weights */
    private function weighted(array $weights): int
    {
        $target = mt_rand() / mt_getrandmax() * array_sum($weights);
        foreach ($weights as $index => $weight) {
            $target -= $weight;
            if ($target <= 0) {
                return $index;
            }
        }

        return array_key_last($weights);
    }

    /** @return array<string, array<string, mixed>> */
    private function measureAll(int $samples): array
    {
        Event::listen('eloquent.retrieved: *', function (): void {
            $this->hydratedModels++;
        });
        $admin = User::where('role', UserRole::Admin)->firstOrFail();
        $rider = User::findOrFail(Order::query()->whereNotNull('rider_id')->groupBy('rider_id')->orderByRaw('COUNT(*) DESC')->value('rider_id'));
        $customer = User::findOrFail(Order::query()->whereNotNull('customer_id')->groupBy('customer_id')->orderByRaw('COUNT(*) DESC')->value('customer_id'));
        $delivered = Order::where('status', OrderStatus::Delivered)->latest('id')->firstOrFail();
        $destination = $this->destinations()[0];
        $results = [];
        $only = array_filter(explode(',', (string) getenv('EA_BASELINE_ONLY')));
        $run = function (string $name, User $user, string $guard, callable $request) use (&$results, $samples, $only): void {
            if ($only !== [] && ! collect($only)->contains(fn (string $prefix): bool => str_starts_with($name, $prefix))) {
                return;
            }
            $this->actingAs($user, $guard);
            $results[$name] = $this->measure($request, $samples);
        };
        $resolver = function () use (&$results, $samples, $destination): void {
            $results['cap_resolver_cold'] = $this->measure(function () use ($destination): TestResponse {
                app()->forgetScopedInstances();
                app(PostalCodeResolver::class)->canonical($destination['city'], $destination['postal_code']);

                return TestResponse::fromBaseResponse(response('ok'));
            }, $samples);
        };
        $resolver();

        $staff = [
            'admin_dashboard' => '/dashboard', 'admin_orders_incoming' => '/orders/incoming', 'admin_orders_in_progress' => '/orders/in-progress',
            'admin_orders_history' => '/orders/history', 'admin_orders_history_page_5' => '/orders/history?page=5', 'admin_order_detail' => '/orders/'.$delivered->slug,
            'admin_pickups_calendar' => '/pickups', 'admin_pickups_day' => '/pickups/day?date=2026-11-18',
            'admin_rider_operations' => '/rider-operations', 'admin_rider_operations_feed' => '/rider-operations/feed',
            'admin_customers' => '/customers', 'admin_customer_detail' => '/customers/'.$customer->slug,
            'admin_balance_month' => '/balance', 'admin_balance_year' => '/balance?period=year&year=2026', 'admin_balance_pending' => '/balance?ledger=pending',
            'admin_balance_history' => '/balance?ledger=history', 'admin_balance_search' => '/balance?q=Via',
            'admin_pending' => '/pending', 'admin_movements' => '/movements', 'admin_reports' => '/reports',
            'admin_notifications' => '/notifications', 'admin_notifications_feed' => '/notifications/feed', 'admin_messages' => '/messages',
            'admin_rates' => '/rates', 'admin_rates_quote' => '/rates/quote?'.http_build_query(['city' => $destination['city'], 'postal_code' => $destination['postal_code']]),
            'admin_economic_audits_order' => '/economic-audits?'.http_build_query(['type' => 'orders', 'slug' => $delivered->slug]), 'admin_recipient_incidents' => '/recipient-incidents',
            'admin_orders_search' => '/orders/history?q=Via',
            'admin_customer_detail_year' => '/customers/'.$customer->slug.'?period=year&year=2026',
            'admin_balance_customer_year' => '/balance?'.http_build_query(['customer_ids' => [$customer->slug], 'period' => 'year', 'year' => 2026]),
            'admin_reports_year' => '/reports?from=2025-11-19&to=2026-11-18',
            'admin_rider_operations_month' => '/rider-operations?period=month', 'admin_rider_operations_year' => '/rider-operations?from=2025-11-19&to=2026-11-18',
            'admin_rider_operations_feed_year' => '/rider-operations/feed?from=2025-11-19&to=2026-11-18',
            'admin_rider_detail_month' => '/rider-operations/detail?period=month',
            'admin_rider_show_month' => '/rider-operations/'.$rider->slug.'?period=month', 'admin_rider_show_year' => '/rider-operations/'.$rider->slug.'?from=2025-11-19&to=2026-11-18',
            'admin_labels' => '/labels', 'admin_pending_all' => '/pending?state=all',
            'admin_rate_history' => '/rates/'.ShippingRate::where('active', true)->value('slug').'/history',
            'admin_messages_show' => '/messages/'.Order::whereHas('messages')->latest('id')->value('slug'),
        ];
        $run('admin_pickups_account_orders', $admin, 'web', function (): TestResponse {
            $url = $this->getJson('/pickups/day?date=2026-11-18')->json('data.0.orders_url');

            return $this->getJson($url);
        });
        foreach ($staff as $name => $path) {
            $run($name, $admin, 'web', fn (): TestResponse => $this->get($path));
        }
        foreach (['rider_dashboard' => '/dashboard', 'rider_orders_in_progress' => '/orders/in-progress', 'rider_orders_history' => '/orders/history', 'rider_notifications_feed' => '/notifications/feed'] as $name => $path) {
            $run($name, $rider, 'web', fn (): TestResponse => $this->get($path));
        }
        $portal = [
            'customer_dashboard' => '/api/v1/customer/dashboard', 'customer_orders' => '/api/v1/customer/orders', 'customer_orders_page_10' => '/api/v1/customer/orders?page=10',
            'customer_statistics' => '/api/v1/customer/statistics', 'customer_notifications_feed' => '/api/v1/customer/notifications/feed',
            'customer_notifications' => '/api/v1/customer/notifications', 'customer_pending' => '/api/v1/customer/pending', 'customer_me' => '/api/v1/customer/auth/me',
            'customer_rates_quote' => '/api/v1/customer/rates/quote?'.http_build_query(['city' => $destination['city'], 'postal_code' => $destination['postal_code']]),
            'customer_statistics_week' => '/api/v1/customer/statistics?period=week', 'customer_statistics_year' => '/api/v1/customer/statistics?period=custom&from=2025-11-19&to=2026-11-18',
            'customer_statistics_activity' => '/api/v1/customer/statistics?date_basis=activity', 'customer_order_show' => '/api/v1/customer/orders/'.Order::where('customer_id', $customer->id)->latest('id')->value('slug'),
            'customer_labels' => '/api/v1/customer/orders/labels', 'customer_booking_rules' => '/api/v1/customer/booking-rules',
        ];
        foreach ($portal as $name => $path) {
            $run($name, $customer, 'customer', fn (): TestResponse => $this->getJson($path));
        }

        return $results;
    }

    /** @return array<string, mixed> */
    private function measure(callable $request, int $samples): array
    {
        DB::beginTransaction();
        try {
            app()->forgetScopedInstances();
            $warm = $request();
        } finally {
            DB::rollBack();
        }
        if ($warm->getStatusCode() !== 200) {
            return ['status' => $warm->getStatusCode(), 'error' => mb_substr((string) $warm->getContent(), 0, 300)];
        }
        $runs = [];
        for ($sample = 0; $sample < $samples; $sample++) {
            app()->forgetScopedInstances();
            gc_collect_cycles();
            DB::beginTransaction();
            try {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $this->hydratedModels = 0;
                memory_reset_peak_usage();
                $memory = memory_get_usage();
                $started = hrtime(true);
                $response = $request();
                $elapsed = (hrtime(true) - $started) / 1e6;
                $peak = memory_get_peak_usage() - $memory;
                $queries = DB::getQueryLog();
                DB::disableQueryLog();
            } finally {
                DB::rollBack();
            }
            if ($sample === 0) {
                usort($queries, fn (array $a, array $b): int => $b['time'] <=> $a['time']);
                $slowest = array_map(fn (array $query): array => ['ms' => $query['time'], 'sql' => mb_substr($query['query'], 0, 600)], array_slice($queries, 0, getenv('EA_BASELINE_ALL_QUERIES') === '1' ? count($queries) : 4));
            }
            $runs[] = ['ms' => $elapsed, 'queries' => count($queries), 'sql_ms' => array_sum(array_column($queries, 'time')), 'peak_kb' => $peak / 1024, 'bytes' => strlen((string) $response->getContent()), 'models' => $this->hydratedModels];
        }
        $median = function (string $key) use ($runs): float {
            $values = array_column($runs, $key);
            sort($values);

            return round($values[intdiv(count($values), 2)], 1);
        };

        return ['status' => 200, 'ms_median' => $median('ms'), 'ms_max' => round(max(array_column($runs, 'ms')), 1), 'queries' => $runs[0]['queries'], 'sql_ms_median' => $median('sql_ms'), 'peak_kb_median' => $median('peak_kb'), 'bytes' => $runs[0]['bytes'], 'models' => $runs[0]['models'], 'slowest_queries' => $slowest ?? []];
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];
        foreach (['users', 'orders', 'order_events', 'order_messages', 'notifications', 'payment_entries', 'receipt_reversals', 'expenses', 'financial_movements', 'pending_accounts', 'pending_settlements', 'economic_audits', 'order_mail_deliveries', 'shipping_rates'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @param array<string, mixed> $report */
    private function markdown(array $report): string
    {
        $lines = ['# Baseline EA-Express', '', '```', json_encode($report['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '```', '', '| Pagina | ms (mediana) | ms max | query | SQL ms | memoria KB | modelli | byte |', '|---|---:|---:|---:|---:|---:|---:|---:|'];
        foreach ($report['results'] as $name => $result) {
            $lines[] = $result['status'] !== 200
                ? '| '.$name.' | errore '.$result['status'].' | | | | | | |'
                : sprintf('| %s | %s | %s | %d | %s | %s | %d | %d |', $name, $result['ms_median'], $result['ms_max'], $result['queries'], $result['sql_ms_median'], $result['peak_kb_median'], $result['models'], $result['bytes']);
        }

        return implode("\n", $lines)."\n";
    }
}
