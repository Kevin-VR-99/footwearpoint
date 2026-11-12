<?php

/*
|--------------------------------------------------------------------------
| Documentos legales (aviso de privacidad y términos y condiciones)
|--------------------------------------------------------------------------
|
| TG-323 (K24): cada documento tiene su propia versión y fecha. Cuando el
| texto de uno cambia, se sube SU versión en el mismo PR; quien aceptó una
| versión anterior tiene que aceptar la nueva para seguir (panel y app).
|
| Candado: tests/Feature/Legales/huellas.php guarda la huella del texto de
| cada versión publicada. Cambiar el texto sin subir la versión rompe
| DocumentosLegalesHuellaTest.
|
| Los datos que el equipo aún no ha definido (domicilio, quién atiende ARCO,
| tribunales) NO se inventan: salen del entorno. Si una variable está vacía,
| la vista muestra un texto neutro.
|
*/

return [

    'documentos' => [
        'aviso_privacidad' => [
            'nombre' => 'Aviso de privacidad',
            'version' => '1.2',
            'fecha_actualizacion' => '12 de noviembre de 2026',
            'ruta' => 'aviso-privacidad',
            'vista' => 'components/legales/aviso-privacidad.blade.php',
        ],
        'terminos_condiciones' => [
            'nombre' => 'Términos y condiciones',
            // TG-321 (K22): términos alineados al sistema.
            'version' => '1.3',
            'fecha_actualizacion' => '12 de noviembre de 2026',
            'ruta' => 'terminos',
            'vista' => 'components/legales/terminos.blade.php',
        ],
    ],

    // Correo del proyecto para privacidad, derechos ARCO y aclaraciones.
    'correo_contacto' => env('LEGAL_CORREO_CONTACTO') ?: 'footwearpoint.mx@gmail.com',

    // Domicilio del responsable (art. 15, fracción I, LFPDPPP).
    'domicilio_responsable' => env('LEGAL_DOMICILIO_RESPONSABLE'),

    // Persona o departamento que atiende las solicitudes ARCO.
    'contacto_arco' => env('LEGAL_CONTACTO_ARCO'),

    // Tribunales competentes (p. ej. "los tribunales competentes de Ocosingo, Chiapas").
    'jurisdiccion' => env('LEGAL_JURISDICCION'),

];
