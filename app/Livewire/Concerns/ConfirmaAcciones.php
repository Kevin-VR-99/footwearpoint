<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * Confirmación con modal (x-ui.confirmar-accion) antes de borrar, quitar o
 * desconectar algo.
 *
 * El método público que llama el botón solo pregunta (preguntar()); lo que de
 * verdad borra vive en un método protegido ejecutar<Accion>() que únicamente
 * corre desde confirmarAccion(). Así no se puede borrar sin pasar por el modal.
 * La pregunta va en una propiedad bloqueada: el navegador no la puede cambiar.
 */
trait ConfirmaAcciones
{
    /** @var array{accion:string, argumentos:list<int|string>, titulo:string, detalle:string, etiqueta:string, cargando:string}|null */
    #[Locked]
    public ?array $accionPorConfirmar = null;

    /** @param list<int|string> $argumentos */
    protected function preguntar(string $accion, array $argumentos, string $titulo, string $detalle, string $etiqueta = 'Eliminar', string $cargando = 'Eliminando…'): void
    {
        $this->accionPorConfirmar = compact('accion', 'argumentos', 'titulo', 'detalle', 'etiqueta', 'cargando');
    }

    public function cancelarAccion(): void
    {
        $this->accionPorConfirmar = null;
    }

    public function confirmarAccion(): void
    {
        $pendiente = $this->accionPorConfirmar;
        $this->accionPorConfirmar = null;

        if ($pendiente === null) {
            return;
        }

        $metodo = 'ejecutar'.ucfirst($pendiente['accion']);
        abort_unless(method_exists($this, $metodo), 404);

        $this->{$metodo}(...$pendiente['argumentos']);
    }
}
