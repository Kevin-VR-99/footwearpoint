<?php

namespace App\Support;

use App\Models\AceptacionLegal;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TG-323 (K24) — Versión vigente de cada documento legal y quién la aceptó.
 *
 * Es el único lugar que decide qué versión se guarda: ni la app ni el panel
 * la mandan. Así una aceptación siempre dice qué texto se aceptó de verdad.
 * Antes la app mandaba «1.0» fija y la API la guardaba tal cual, encima de la
 * aceptación anterior; el alta de distribuidoras también guardaba «1.0».
 */
final class DocumentosLegales
{
    public const AVISO = 'aviso_privacidad';

    public const TERMINOS = 'terminos_condiciones';

    /** @return list<string> */
    public static function tipos(): array
    {
        return array_keys((array) config('legal.documentos'));
    }

    public static function existe(string $tipo): bool
    {
        return in_array($tipo, self::tipos(), true);
    }

    public static function version(string $tipo): string
    {
        return (string) self::dato($tipo, 'version');
    }

    public static function fecha(string $tipo): string
    {
        return (string) self::dato($tipo, 'fecha_actualizacion');
    }

    public static function nombre(string $tipo): string
    {
        return (string) self::dato($tipo, 'nombre');
    }

    public static function url(string $tipo): string
    {
        return route((string) self::dato($tipo, 'ruta'));
    }

    /**
     * El admin general es FootwearPoint mismo: no acepta sus propios
     * documentos para entrar a administrar la plataforma.
     */
    public static function exento(Usuario $usuario): bool
    {
        // Consulta directa a propósito: AccesoPanelWebService::esAdminGeneral()
        // cambia el equipo de permisos de toda la petición, y esto corre en un
        // middleware antes de que cada pantalla revise los suyos.
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $usuario->getMorphClass())
            ->where('model_has_roles.model_id', $usuario->getKey())
            ->where('model_has_roles.team_id', 0)
            ->where('roles.name', 'admin_general')
            ->exists();
    }

    /**
     * Documentos cuya versión vigente todavía no acepta.
     *
     * @return list<string>
     */
    public static function pendientes(Usuario $usuario): array
    {
        if (self::exento($usuario)) {
            return [];
        }

        $aceptadas = AceptacionLegal::query()
            ->where('usuario_id', $usuario->id)
            ->get(['tipo_documento', 'version'])
            ->map(fn (AceptacionLegal $a) => $a->tipo_documento.'|'.$a->version)
            ->all();

        return array_values(array_filter(
            self::tipos(),
            fn (string $tipo) => ! in_array($tipo.'|'.self::version($tipo), $aceptadas, true)
        ));
    }

    public static function alCorriente(Usuario $usuario): bool
    {
        return self::pendientes($usuario) === [];
    }

    /**
     * Guarda que aceptó la versión vigente. Las aceptaciones de versiones
     * anteriores se conservan (una fila por usuario, documento y versión).
     */
    public static function registrar(Usuario $usuario, string $tipo, ?string $ip): AceptacionLegal
    {
        if (! self::existe($tipo)) {
            throw new InvalidArgumentException("Documento legal desconocido: {$tipo}");
        }

        return AceptacionLegal::firstOrCreate(
            [
                'usuario_id' => $usuario->id,
                'tipo_documento' => $tipo,
                'version' => self::version($tipo),
            ],
            [
                'fecha_aceptacion' => now(),
                'ip_origen' => $ip,
            ]
        );
    }

    /**
     * Lo que la app necesita para mostrar un documento pendiente.
     *
     * @return array{tipo_documento: string, nombre: string, version: string, fecha_actualizacion: string, url: string}
     */
    public static function resumen(string $tipo): array
    {
        return [
            'tipo_documento' => $tipo,
            'nombre' => self::nombre($tipo),
            'version' => self::version($tipo),
            'fecha_actualizacion' => self::fecha($tipo),
            'url' => self::url($tipo),
        ];
    }

    private static function dato(string $tipo, string $campo): mixed
    {
        if (! self::existe($tipo)) {
            throw new InvalidArgumentException("Documento legal desconocido: {$tipo}");
        }

        return config("legal.documentos.{$tipo}.{$campo}");
    }
}
