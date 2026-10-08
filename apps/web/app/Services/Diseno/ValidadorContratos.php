<?php

namespace App\Services\Diseno;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Valida contenido contra los esquemas de packages/contracts.
 * `npm run gen` copia los esquemas a resources/contracts; aquí se registran con su $id.
 */
class ValidadorContratos
{
    private const PREFIJO = 'https://clt4bp.local/schema/';

    private Validator $validador;

    public function __construct(?string $directorio = null)
    {
        $this->validador = new Validator;
        $this->validador->setMaxErrors(10);
        $this->validador->resolver()->registerPrefix(self::PREFIJO, $directorio ?? resource_path('contracts'));
    }

    /**
     * @param  string  $esquema  nombre del archivo, p. ej. 'tarea.schema.json'
     * @param  object|array  $datos  de preferencia el objeto decodificado del JSON original (conserva {} frente a [])
     * @return array<string, list<string>> errores por ruta JSON (vacío = válido)
     */
    public function errores(string $esquema, object|array $datos): array
    {
        // Opis trabaja con objetos (stdClass). Un arreglo de PHP se convierte, pero un {} vacío llegaría como []
        $objeto = is_array($datos) ? json_decode(json_encode($datos, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)) : $datos;
        $resultado = $this->validador->validate($objeto, self::PREFIJO.$esquema);

        return $resultado->isValid() ? [] : (new ErrorFormatter)->formatKeyed($resultado->error());
    }
}
