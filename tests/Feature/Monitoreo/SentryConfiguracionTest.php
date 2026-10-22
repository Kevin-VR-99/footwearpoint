<?php

namespace Tests\Feature\Monitoreo;

use Tests\TestCase;

/** Monitoreo: Sentry solo se activa con SENTRY_LARAVEL_DSN y nunca manda PII. */
class SentryConfiguracionTest extends TestCase
{
    public function test_sin_dsn_sentry_queda_inactivo(): void
    {
        $this->assertEmpty(config('sentry.dsn'));
        $this->assertNull(\Sentry\SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn());
    }

    public function test_no_manda_datos_personales_y_muestrea_poco(): void
    {
        $this->assertFalse(config('sentry.send_default_pii'));
        $this->assertLessThanOrEqual(0.1, (float) config('sentry.traces_sample_rate'));
        $this->assertFalse((bool) config('sentry.breadcrumbs.sql_bindings'));
    }

    public function test_no_hay_dsn_en_el_codigo(): void
    {
        $this->assertDoesNotMatchRegularExpression('#https://[a-f0-9]+@#', file_get_contents(config_path('sentry.php')));
    }
}
