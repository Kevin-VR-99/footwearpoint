<?php

/*
|--------------------------------------------------------------------------
| Documentos legales (aviso de privacidad y términos y condiciones)
|--------------------------------------------------------------------------
|
| Versión 1.1 (24 de octubre de 2026). Los datos que el equipo aún no ha
| definido (domicilio, quién atiende ARCO, tribunales) NO se inventan: salen
| del entorno. Si una variable está vacía, la vista muestra un texto neutro.
|
*/

return [

    // Versión vigente que se guarda en aceptaciones_legales al aceptar.
    'version' => '1.1',

    'fecha_actualizacion' => '24 de octubre de 2026',

    // Correo del proyecto para privacidad, derechos ARCO y aclaraciones.
    'correo_contacto' => env('LEGAL_CORREO_CONTACTO') ?: 'footwearpoint.mx@gmail.com',

    // Domicilio del responsable (art. 15, fracción I, LFPDPPP).
    'domicilio_responsable' => env('LEGAL_DOMICILIO_RESPONSABLE'),

    // Persona o departamento que atiende las solicitudes ARCO.
    'contacto_arco' => env('LEGAL_CONTACTO_ARCO'),

    // Tribunales competentes (p. ej. "los tribunales competentes de Ocosingo, Chiapas").
    'jurisdiccion' => env('LEGAL_JURISDICCION'),

];
