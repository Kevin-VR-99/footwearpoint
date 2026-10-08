<?php

namespace App\Http\Requests\Admin;

use App\Services\Distribuidora\RechazarDistribuidoraAction;
use Illuminate\Foundation\Http\FormRequest;

/** TG-195 (G4) — POST /api/admin/distribuidoras/{id}/rechazar */
class RechazarDistribuidoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo_rechazo' => ['required', 'string', 'max:' . RechazarDistribuidoraAction::LARGO_MAXIMO_MOTIVO],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo_rechazo.required' => RechazarDistribuidoraAction::MENSAJE_MOTIVO_OBLIGATORIO,
            'motivo_rechazo.string'   => RechazarDistribuidoraAction::MENSAJE_MOTIVO_OBLIGATORIO,
            'motivo_rechazo.max'      => RechazarDistribuidoraAction::MENSAJE_MOTIVO_LARGO,
        ];
    }
}
