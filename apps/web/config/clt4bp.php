<?php

return [
    // Versión vigente de cada texto legal. Al cambiar un texto, sube su versión.
    'legal' => [
        'privacidad' => env('CLT4BP_VERSION_PRIVACIDAD', '2026-09'),
        'investigacion' => env('CLT4BP_VERSION_INVESTIGACION', '2026-09'),
    ],

    // Horas de vigencia del enlace de invitación a instructores.
    'invitacion_horas' => 72,

    // Días de vigencia del token de la consola.
    'token_consola_dias' => 7,

    // Administrador inicial (lo usa AdminSeeder). Borra ADMIN_PASSWORD del .env después de sembrar.
    'admin' => [
        'name' => env('ADMIN_NAME', 'Administración'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Tamaño máximo de un archivo multimedia, en KB (500 MB). PHP también debe permitirlo (php.ini).
    'medios' => ['max_kb' => env('CLT4BP_MEDIOS_MAX_KB', 512000)],

    // Material del curso para el agente (RAG): tamaño máximo por archivo (KB) y cuánto viaja en cada solicitud
    'material' => [
        'max_kb' => (int) env('CLT4BP_MATERIAL_MAX_KB', 51200),
        'fragmentos_por_solicitud' => 6,
        'caracteres_por_solicitud' => 6000,
    ],

    // Agente de diseño (Etapa 4): presupuesto mensual por instructor, en dólares
    'agente' => [
        'limite_mensual_usd' => (float) env('CLT4BP_AGENTE_LIMITE_USD', 20),
    ],

    // Correo al que se dirigen las solicitudes de corrección o cancelación de datos (Etapa 7)
    'contacto_privacidad' => env('CLT4BP_CONTACTO_PRIVACIDAD', 'privacidad@example.edu'),

    // Revisión automática de operación (Etapa 7)
    'alertas' => [
        'correo' => env('CLT4BP_ALERTAS_CORREO'),
        'ping' => env('CLT4BP_PING_REVISION'), // URL de "sigo vivo" del monitoreo externo
        'cola_minutos' => 10,
        'disco_libre_minimo' => 0.15,
        'gasto_diario_usd' => (float) env('CLT4BP_ALERTA_GASTO_DIARIO_USD', 5),
    ],
];
