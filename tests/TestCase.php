<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Charge une fixture JSON depuis tests/Fixtures/etf2l/.
     *
     * @return array<mixed>
     */
    protected function fixture(string $name): array
    {
        $path = __DIR__.'/Fixtures/etf2l/'.$name.'.json';
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->fail("Fixture invalide : {$path}");
        }

        return $decoded;
    }

    /**
     * Oriente les JSON générés vers un répertoire dédié pour ne pas polluer
     * les données de dev et isoler chaque suite de tests.
     */
    protected function useIsolatedDataDir(): void
    {
        config()->set('palmares.data_dir', 'app/palmares-test');
    }
}
