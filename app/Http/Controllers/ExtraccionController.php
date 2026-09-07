<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Lectura inteligente de documentos con la API de Claude (Anthropic).
 * Extrae datos de proveedores desde un PDF o imagen (RUT, cámara de comercio, factura…).
 */
class ExtraccionController extends Controller
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** Esquema de salida estructurada: garantiza JSON con exactamente estos campos. */
    private const ESQUEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'razon_social' => ['type' => 'string'],
            'tipo_documento' => ['type' => 'string', 'enum' => ['NIT', 'CC', 'CE']],
            'numero_documento' => ['type' => 'string'],
            'digito_verificacion' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'telefono' => ['type' => 'string'],
            'direccion' => ['type' => 'string'],
            'representante_legal' => ['type' => 'string'],
        ],
        'required' => [
            'razon_social', 'tipo_documento', 'numero_documento', 'digito_verificacion',
            'email', 'telefono', 'direccion', 'representante_legal',
        ],
    ];

    /** POST /proveedores/extraer (multipart: archivo) */
    public function proveedor(Request $request)
    {
        $request->validate([
            // Solo formatos que Claude lee de forma nativa (imagen / PDF).
            'archivo' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $apiKey = config('services.anthropic.key');
        if (! $apiKey) {
            return response()->json([
                'message' => 'La lectura inteligente no está configurada. Define ANTHROPIC_API_KEY en el servidor.',
            ], 503);
        }

        $file = $request->file('archivo');
        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $mime = $file->getMimeType();

        // Bloque de contenido: PDF como documento, lo demás como imagen.
        $bloque = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $base64]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $base64]];

        try {
            $resp = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(90)->post(self::ENDPOINT, [
                'model' => config('services.anthropic.model', 'claude-opus-4-8'),
                'max_tokens' => 1024,
                'system' => 'Eres un asistente que extrae datos de proveedores colombianos a partir de documentos '
                    .'(RUT, cámara de comercio, facturas, certificados). Devuelve únicamente los campos solicitados. '
                    .'Usa cadena vacía ("") cuando un dato no aparezca en el documento. Para el NIT, separa el número '
                    .'del dígito de verificación. Si es persona natural usa tipo_documento "CC"; si es empresa, "NIT".',
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::ESQUEMA]],
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        $bloque,
                        ['type' => 'text', 'text' => 'Extrae los datos del proveedor de este documento.'],
                    ],
                ]],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo conectar con el servicio de lectura.'], 502);
        }

        if (! $resp->successful()) {
            return response()->json([
                'message' => 'El servicio de lectura devolvió un error.',
                'detalle' => $resp->json('error.message'),
            ], 502);
        }

        $data = $resp->json();

        if (($data['stop_reason'] ?? null) === 'refusal') {
            return response()->json(['message' => 'No fue posible procesar este documento.'], 422);
        }

        // Con output_config.format, el primer bloque de texto es JSON válido.
        $texto = collect($data['content'] ?? [])->firstWhere('type', 'text')['text'] ?? null;
        $campos = $texto ? json_decode($texto, true) : null;

        if (! is_array($campos)) {
            return response()->json(['message' => 'No se pudieron leer los datos del documento.'], 422);
        }

        return response()->json(['campos' => $campos]);
    }
}
