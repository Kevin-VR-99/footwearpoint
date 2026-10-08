<?php

namespace Tests\Unit;

use App\Exceptions\OperacionInvalidaException;
use App\Models\Pedido;
use App\Services\Distribuidora\AprobacionDistribuidoraException;
use App\Support\MensajeError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * TG-224 (G3) — Qué mensaje ve el usuario según la excepción.
 */
class MensajeErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
    }

    public function test_validacion_devuelve_el_primer_mensaje_y_no_reporta(): void
    {
        $e = ValidationException::withMessages(['cantidad' => 'La cantidad debe ser mayor que cero.']);

        $this->assertSame('La cantidad debe ser mayor que cero.', MensajeError::paraUsuario($e));
        Exceptions::assertNothingReported();
    }

    public function test_excepciones_con_mensaje_para_usuario_se_muestran_tal_cual(): void
    {
        $this->assertSame('No hay existencia suficiente.', MensajeError::paraUsuario(new OperacionInvalidaException('No hay existencia suficiente.')));
        $this->assertSame('No hay planes de suscripción configurados.', MensajeError::paraUsuario(AprobacionDistribuidoraException::sinPlan()));
        Exceptions::assertNothingReported();
    }

    public function test_registro_no_encontrado_no_muestra_el_modelo(): void
    {
        $e = (new ModelNotFoundException())->setModel(Pedido::class, [5]);

        $mensaje = MensajeError::paraUsuario($e);

        $this->assertSame(MensajeError::NO_ENCONTRADO, $mensaje);
        $this->assertStringNotContainsString('App\\Models', $mensaje);
        Exceptions::assertNothingReported();
    }

    public function test_sin_permiso_devuelve_mensaje_en_espanol(): void
    {
        $this->assertSame(MensajeError::SIN_PERMISO, MensajeError::paraUsuario(new AuthorizationException('This action is unauthorized.')));
    }

    public function test_error_inesperado_devuelve_el_generico_y_reporta(): void
    {
        $e = new RuntimeException('SQLSTATE[HY000] detalle técnico');

        $this->assertSame(MensajeError::GENERICO, MensajeError::paraUsuario($e));
        $this->assertSame('No se pudo enviar el pedido.', MensajeError::paraUsuario($e, 'No se pudo enviar el pedido.'));

        Exceptions::assertReported(fn (RuntimeException $r) => $r === $e);
    }
}
