<?php

namespace Tests\Feature;

use App\Support\PostalCodeIndex;
use App\Support\PostalCodeResolver;
use App\Support\ShippingQuote;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PostalCodeIndexTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/ea-postal-index-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_compiled_index_is_identical_to_the_index_built_from_the_official_archive(): void
    {
        $index = new PostalCodeIndex($this->directory.'/postal-codes.php', useCompiled: true);

        $this->assertGreaterThan(7800, $index->compile());
        $this->assertTrue($index->isFresh());
        $this->assertSame($this->referenceIndex(resource_path('italian-postal-codes.json')), (new PostalCodeIndex($this->directory.'/postal-codes.php', useCompiled: true))->load());
    }

    public function test_every_municipality_resolves_exactly_as_before_through_the_compiled_index(): void
    {
        $index = new PostalCodeIndex($this->directory.'/postal-codes.php', useCompiled: true);
        $index->compile();
        $resolver = new PostalCodeResolver($index);
        $reference = $this->referenceIndex(resource_path('italian-postal-codes.json'));
        $archive = json_decode(file_get_contents(resource_path('italian-postal-codes.json')), true, 512, JSON_THROW_ON_ERROR);

        foreach ($archive['municipalities'] as $place) {
            $homonyms = count($reference[ShippingQuote::cityKey($place['name'])]);
            foreach ($place['postal_codes'] as $code) {
                $resolved = $resolver->canonical($place['name'], $code, $homonyms > 1 ? $place['province'] : null, $homonyms > 1 ? $place['region'] : null);
                $this->assertSame([...$place, 'postal_code' => $code], $resolved);
            }
        }
        $this->assertSame('Casalnuovo di Napoli', $resolver->placesByPostalCode('Casalnuovo', ['80013'])[0]['name']);
        $this->assertStringContainsString('84083', $resolver->hint('Castel San Giorgio', []));
        $this->expectException(ValidationException::class);
        $resolver->canonical('Camposanto', '80030');
    }

    public function test_a_changed_archive_rebuilds_and_replaces_the_compiled_index(): void
    {
        $source = $this->directory.'/archive.json';
        file_put_contents($source, json_encode(['municipalities' => [['name' => 'Alfa', 'province' => 'Napoli', 'province_code' => 'NA', 'region' => 'Campania', 'postal_codes' => ['80001']]]]));
        touch($source, time() - 120);
        $index = new PostalCodeIndex($this->directory.'/postal-codes.php', $source, true);
        $index->compile();
        $this->assertArrayHasKey('alfa', $index->load());

        file_put_contents($source, json_encode(['municipalities' => [['name' => 'Beta', 'province' => 'Napoli', 'province_code' => 'NA', 'region' => 'Campania', 'postal_codes' => ['80002'], 'aliases' => ['Beta Vecchia']]]]));
        touch($source, time());
        $this->assertFalse($index->isFresh());

        $this->assertSame($this->referenceIndex($source), $index->load());
        $this->assertTrue($index->isFresh());
        $this->assertSame(['beta', 'beta vecchia'], array_keys((include $this->directory.'/postal-codes.php')['municipalities']));
    }

    public function test_a_corrupted_or_unwritable_cache_falls_back_to_the_archive_without_errors(): void
    {
        file_put_contents($this->directory.'/postal-codes.php', '<?php return "non valido";');
        $index = new PostalCodeIndex($this->directory.'/postal-codes.php', useCompiled: true);
        $this->assertSame($this->referenceIndex(resource_path('italian-postal-codes.json')), $index->load());
        $this->assertTrue($index->isFresh());

        $readOnly = $this->directory.'/sola-lettura';
        mkdir($readOnly, 0500);
        $unwritable = new PostalCodeIndex($readOnly.'/postal-codes.php', useCompiled: true);
        $this->assertSame('Agliè', $unwritable->load()['aglie'][0]['name']);
        $this->assertFileDoesNotExist($readOnly.'/postal-codes.php');
        $this->assertSame([], glob($readOnly.'/postal-codes-*'));
        chmod($readOnly, 0700);
    }

    public function test_without_opcache_the_archive_is_read_directly_and_no_file_is_written(): void
    {
        $index = new PostalCodeIndex($this->directory.'/postal-codes.php', useCompiled: false);

        $this->assertSame($this->referenceIndex(resource_path('italian-postal-codes.json')), $index->load());
        $this->assertFileDoesNotExist($this->directory.'/postal-codes.php');
    }

    public function test_deploy_commands_compile_and_clear_the_index(): void
    {
        $this->app->instance(PostalCodeIndex::class, new PostalCodeIndex($this->directory.'/postal-codes.php', useCompiled: true));

        $this->artisan('shipping:cache-postal-codes')->assertSuccessful();
        $this->assertFileExists($this->directory.'/postal-codes.php');
        $this->assertSame('Caserta', app(PostalCodeResolver::class)->canonical('Caserta', '81100')['name']);

        $this->artisan('shipping:clear-postal-codes')->assertSuccessful();
        $this->assertFileDoesNotExist($this->directory.'/postal-codes.php');
    }

    /**
     * Indice costruito come faceva il resolver prima dell'introduzione della cache compilata.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function referenceIndex(string $source): array
    {
        $municipalities = [];
        $data = json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['municipalities'] as $place) {
            foreach ([$place['name'], ...($place['aliases'] ?? [])] as $name) {
                $municipalities[ShippingQuote::cityKey($name)][] = $place;
            }
        }

        return $municipalities;
    }
}
