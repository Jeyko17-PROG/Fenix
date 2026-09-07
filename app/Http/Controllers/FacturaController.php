<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Auditoria;
use App\Models\Factura;
use App\Services\Notificador;
use App\Services\KardexService;
use App\Services\CreditService;
use App\Services\ReciboService;
use App\Support\StorageUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FacturaController extends Controller
{
    public function __construct(
        private Notificador $notificador,
        private KardexService $kardex,
        private CreditService $creditService,
        private ReciboService $recibo,
    ) {}

    public function index(Request $request)
    {
        $this->bloquearMecanico($request);
        $q = Factura::with('cliente:id,nombre_completo,email,telefono')
            ->withSum('pagos as pagos_sum_monto', 'monto');
        if ($request->user()?->estaLimitadoABodega()) {
            $q->where('bodega_id', $request->user()->bodega_id);
        }
        if ($cliente = $request->query('cliente_id')) {
            $q->where('cliente_id', $cliente);
        }
        return $q->latest()->paginate(20);
    }

    public function show(Factura $factura)
    {
        $this->bloquearMecanico(request());
        return $factura->load(['cliente', 'detalles', 'pagos.usuario:id,name']);
    }

    /**
     * Historial de abonos/pagos hechos sobre una factura.
     */
    public function pagos(Factura $factura)
    {
        $this->bloquearMecanico(request());
        return $factura->pagos()->with('usuario:id,name')->get();
    }

    /**
     * Registra un abono/pago parcial contra una factura. Cuando la suma de
     * los pagos cubre el total, la factura pasa automáticamente a PAGADA.
     */
    public function registrarPago(Request $request, Factura $factura)
    {
        $this->bloquearMecanico($request);

        if ($factura->estado === 'ANULADA') {
            throw ValidationException::withMessages(['estado' => ['No se pueden registrar pagos sobre una factura anulada.']]);
        }

        $data = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0'],
            'metodo_pago' => ['nullable', 'in:EFECTIVO,TARJETA,TRANSFERENCIA,NEQUI,DAVIPLATA'],
            'fecha' => ['nullable', 'date'],
            'nota' => ['nullable', 'string', 'max:500'],
        ]);

        $saldoActual = $factura->saldo_pendiente;
        if ($data['monto'] > $saldoActual + 0.01) {
            throw ValidationException::withMessages(['monto' => ["El abono (\${$data['monto']}) supera el saldo pendiente (\${$saldoActual})."]]);
        }

        $pago = DB::transaction(function () use ($data, $factura, $request) {
            // Cotización (BORRADOR): el primer abono real es lo que la convierte
            // en una venta de verdad - recién aquí se descuenta el inventario y
            // se cobra el crédito de facturación (si aplica), no antes. Así,
            // mientras solo era una cotización enviada al cliente, nunca afectó
            // caja/reportes/inventario.
            if ($factura->estado === 'BORRADOR') {
                $this->cobrarUsoPorFactura($request->user());
                foreach ($factura->detalles as $d) {
                    if (! empty($d->producto_id)) {
                        $this->kardex->salida(
                            (int) $d->producto_id, $factura->bodega_id, (float) $d->cantidad,
                            $request->user()->id, 'VENTA_FACTURA', ['tipo' => 'FACTURA', 'id' => $factura->id],
                        );
                    }
                }
                $factura->update(['estado' => 'EMITIDA']);
                Auditoria::registrar($request->user()->id, null, 'FACTURA', 'CONFIRMAR_COTIZACION', null, $factura->numero, $factura->bodega_id);
            }

            $pago = $factura->pagos()->create([
                'monto' => $data['monto'],
                'metodo_pago' => $data['metodo_pago'] ?? null,
                'fecha' => $data['fecha'] ?? now()->toDateString(),
                'nota' => $data['nota'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            if ($factura->fresh()->saldo_pendiente <= 0.01 && $factura->estado === 'EMITIDA') {
                $factura->update(['estado' => 'PAGADA']);
            }

            return $pago;
        });

        $this->notificador->aUsuario($factura->owner_id, 'FACTURA_PAGO',
            "Abono registrado en factura {$factura->numero}", "Monto: \${$pago->monto} · Saldo pendiente: \${$factura->fresh()->saldo_pendiente}");

        Auditoria::registrar($request->user()->id, null, 'FACTURA_PAGO', 'REGISTRAR', null, $factura->numero, $factura->bodega_id);

        return response()->json($pago->load('usuario:id,name'), 201);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'exists:clientes,id'],
            'fecha' => ['required', 'date'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notas' => ['nullable', 'string'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.descripcion' => ['required', 'string', 'max:255'],
            'lineas.*.producto_id' => ['nullable', 'exists:productos,id'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'lineas.*.impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Firma digital: data URL (data:image/png;base64,...) dibujada o subida.
            'firma' => ['nullable', 'string'],
            'currency' => ['nullable', 'in:COP,USD,MXN'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
            // Medio de pago (para el cierre de caja desglosado por método).
            'metodo_pago' => ['nullable', 'in:EFECTIVO,TARJETA,TRANSFERENCIA,NEQUI,DAVIPLATA'],
            'propina' => ['nullable', 'numeric', 'min:0'],
            // Cotización/pre-factura: se le puede enviar al cliente (PDF/correo)
            // sin que cuente todavía como venta real - no descuenta inventario
            // ni cobra crédito de facturación, y no aparece en caja/reportes.
            // Se "activa" sola (ver registrarPago) apenas se registre el
            // primer abono real.
            'es_cotizacion' => ['nullable', 'boolean'],
        ]);
        $esCotizacion = (bool) ($data['es_cotizacion'] ?? false);

        $factura = DB::transaction(function () use ($data, $request, $esCotizacion) {
            $bodegaId = $this->resolverBodegaFactura($request, $data['bodega_id'] ?? null);

            // Pago por uso: en modo prepago cada factura consume 1 crédito ($500 COP).
            // Si no hay saldo, la venta se detiene aquí (422) y no se crea nada.
            // Una cotización no cobra crédito todavía - eso pasa al confirmarla.
            if (! $esCotizacion) {
                $this->cobrarUsoPorFactura($request->user());
            }

            // IVA general usado como respaldo cuando una linea no define el suyo.
            $pctGeneral = $data['impuesto_porcentaje'] ?? 0;

            $subtotal = 0;
            $impuestos = 0;
            $detalles = [];

            foreach ($data['lineas'] as $l) {
                $base = round($l['cantidad'] * $l['precio_unitario'], 2);
                $pct = $l['impuesto_porcentaje'] ?? $pctGeneral;
                $impLinea = round($base * $pct / 100, 2);

                $subtotal += $base;
                $impuestos += $impLinea;

                $detalles[] = [
                    'producto_id' => $l['producto_id'] ?? null,
                    'descripcion' => $l['descripcion'],
                    'cantidad' => $l['cantidad'],
                    'precio_unitario' => $l['precio_unitario'],
                    'impuesto_porcentaje' => $pct,
                    'subtotal' => $base,
                    'impuesto' => $impLinea,
                ];
            }

            $factura = Factura::create([
                'numero' => $this->siguienteNumero(),
                'bodega_id' => $bodegaId,
                'cliente_id' => $data['cliente_id'],
                'fecha' => $data['fecha'],
                'subtotal' => $subtotal,
                'impuestos' => $impuestos,
                'total' => $subtotal + $impuestos,
                'currency' => $data['currency'] ?? 'COP',
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'estado' => $esCotizacion ? 'BORRADOR' : 'EMITIDA',
                'metodo_pago' => $data['metodo_pago'] ?? 'EFECTIVO',
                'propina' => $data['propina'] ?? null,
                'notas' => $data['notas'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($detalles as $d) {
                $factura->detalles()->create($d);
                // Una cotización todavía no vendió nada de verdad: el stock se
                // descuenta cuando se active (primer abono registrado), no ahora.
                if (! $esCotizacion && ! empty($d['producto_id'])) {
                    $this->kardex->salida(
                        (int) $d['producto_id'],
                        $bodegaId,
                        (float) $d['cantidad'],
                        $request->user()->id,
                        'VENTA_FACTURA',
                        ['tipo' => 'FACTURA', 'id' => $factura->id],
                    );
                }
            }

            // Firma digital (dibujada o imagen subida) recibida como data URL.
            if (! empty($data['firma'])) {
                if ($url = $this->guardarFirma($factura, $data['firma'])) {
                    $factura->update(['firma_url' => $url]);
                }
            }

            // Notificación interna SOLO para el dueño de la factura (su workspace).
            $etiqueta = $esCotizacion ? 'Cotización' : 'Factura';
            $this->notificador->aUsuario($factura->owner_id, 'FACTURA',
                "{$etiqueta} {$factura->numero} generada", "Total: \${$factura->total}");

            Auditoria::registrar($request->user()->id, null, 'FACTURA', $esCotizacion ? 'COTIZAR' : 'EMITIR', null, $factura->numero, $bodegaId);

            return $factura->load(['cliente:id,nombre_completo,email,telefono', 'detalles']);
        });

        // Envío automático del PDF al correo del cliente. Si el correo falla,
        // la venta NO se revierte: queda registrado en el log y se puede reenviar.
        $this->enviarFacturaAutomatica($factura);

        return response()->json($factura, 201);
    }

    /**
     * Actualiza una factura existente (editar líneas y metadatos).
     * Ajusta el stock usando KardexService en base a la diferencia entre
     * líneas antiguas y nuevas.
     */
    public function update(Request $request, Factura $factura)
    {
        $this->autorizarBodega($factura);

        if (in_array($factura->estado, ['PAGADA', 'ANULADA'], true)) {
            return response()->json([
                'message' => 'No se puede editar una factura ' . strtolower($factura->estado) . '.',
            ], 422);
        }

        $data = $request->validate([
            'fecha' => ['nullable', 'date'],
            'bodega_id' => ['nullable', 'exists:bodegas,id'],
            'impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notas' => ['nullable', 'string'],
            'lineas' => ['nullable', 'array', 'min:1'],
            'lineas.*.descripcion' => ['required_with:lineas', 'string', 'max:255'],
            'lineas.*.producto_id' => ['nullable', 'exists:productos,id'],
            'lineas.*.cantidad' => ['required_with:lineas', 'numeric', 'gt:0'],
            'lineas.*.precio_unitario' => ['required_with:lineas', 'numeric', 'min:0'],
            'lineas.*.impuesto_porcentaje' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'in:COP,USD,MXN'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        return DB::transaction(function () use ($data, $request, $factura) {
            $bodegaId = $this->resolverBodegaFactura($request, $data['bodega_id'] ?? $factura->bodega_id);

            // Si vienen lineas, calcular subtotal/impuestos/total y ajustar stock por diferencias.
            if (! empty($data['lineas'])) {
                $pctGeneral = $data['impuesto_porcentaje'] ?? 0;

                $subtotal = 0;
                $impuestos = 0;
                $nuevas = [];
                foreach ($data['lineas'] as $l) {
                    $base = round($l['cantidad'] * $l['precio_unitario'], 2);
                    $pct = $l['impuesto_porcentaje'] ?? $pctGeneral;
                    $impLinea = round($base * $pct / 100, 2);
                    $subtotal += $base;
                    $impuestos += $impLinea;

                    $nuevas[] = [
                        'producto_id' => $l['producto_id'] ?? null,
                        'descripcion' => $l['descripcion'],
                        'cantidad' => $l['cantidad'],
                        'precio_unitario' => $l['precio_unitario'],
                        'impuesto_porcentaje' => $pct,
                        'subtotal' => $base,
                        'impuesto' => $impLinea,
                    ];
                }

                // Una cotización (BORRADOR) nunca descontó stock al crearse, así
                // que tampoco hay que ajustarlo aquí por editar sus líneas -
                // el descuento real pasa una sola vez, al activarse (ver
                // registrarPago).
                if ($factura->estado !== 'BORRADOR') {
                    // Mapear cantidades por producto (solo aquellos con producto_id)
                    $oldMap = [];
                    foreach ($factura->detalles as $d) {
                        if ($d->producto_id) {
                            $oldMap[$d->producto_id] = ($oldMap[$d->producto_id] ?? 0) + (float) $d->cantidad;
                        }
                    }

                    $newMap = [];
                    foreach ($nuevas as $d) {
                        if (! empty($d['producto_id'])) {
                            $newMap[$d['producto_id']] = ($newMap[$d['producto_id']] ?? 0) + (float) $d['cantidad'];
                        }
                    }

                    // Ajustes por producto: si diff>0 => salida, diff<0 => entrada
                    $productoIds = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
                    foreach ($productoIds as $pid) {
                        $oldCant = $oldMap[$pid] ?? 0.0;
                        $newCant = $newMap[$pid] ?? 0.0;
                        $diff = $newCant - $oldCant;
                        if ($diff > 0) {
                            $this->kardex->salida((int) $pid, $bodegaId, (float) $diff, $request->user()->id, 'VENTA_FACTURA', ['tipo' => 'FACTURA', 'id' => $factura->id]);
                        } elseif ($diff < 0) {
                            $this->kardex->revertirSalida((int) $pid, $bodegaId, (float) abs($diff), $request->user()->id, 'DEVOLUCION_FACTURA', ['tipo' => 'FACTURA', 'id' => $factura->id]);
                        }
                    }
                }

                // Reemplaza detalles (fácil y consistente)
                $factura->detalles()->delete();
                foreach ($nuevas as $d) {
                    $factura->detalles()->create($d);
                }

                $factura->subtotal = $subtotal;
                $factura->impuestos = $impuestos;
                $factura->total = $subtotal + $impuestos;
            }

            $updates = [];
            if (isset($data['fecha'])) $updates['fecha'] = $data['fecha'];
            // array_key_exists (no isset): "notas" es nullable a propósito -
            // isset() trata null como "no enviado" y nunca dejaba vaciar las
            // notas de una factura ya creada.
            if (array_key_exists('notas', $data)) $updates['notas'] = $data['notas'];
            if (isset($data['currency'])) $updates['currency'] = $data['currency'];
            if (array_key_exists('exchange_rate', $data)) $updates['exchange_rate'] = $data['exchange_rate'];

            if (! empty($updates)) {
                $factura->update($updates);
            } else {
                $factura->save();
            }

            Auditoria::registrar($request->user()->id, null, 'FACTURA', 'EDITAR', null, $factura->numero, $bodegaId);

            return response()->json($factura->fresh('detalles'));
        });
    }

    /**
     * Elimina (soft) una factura y devuelve el stock de los productos involucrados.
     */
    public function destroy(Request $request, Factura $factura)
    {
        $this->autorizarBodega($factura);

        // Una factura PAGADA tiene pagos registrados (FacturaPago) que quedarían
        // huérfanos, y ANULADA ya pasó por su propio flujo de reversión — en
        // ambos casos borrar aquí duplicaría o rompería la reversión de stock.
        // Mismo criterio que ya usa update() para bloquear la edición.
        if (in_array($factura->estado, ['PAGADA', 'ANULADA'], true)) {
            return response()->json([
                'message' => 'No se puede eliminar una factura ' . strtolower($factura->estado) . '.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $factura) {
            $bodegaId = $factura->bodega_id;

            // Una cotización (BORRADOR) nunca descontó stock - nada que devolver.
            if ($factura->estado !== 'BORRADOR') {
                foreach ($factura->detalles as $d) {
                    if (! empty($d->producto_id) && (float) $d->cantidad > 0) {
                        $this->kardex->revertirSalida((int) $d->producto_id, $bodegaId, (float) $d->cantidad, $request->user()->id, 'REVERSO_FACTURA', ['tipo' => 'FACTURA', 'id' => $factura->id]);
                    }
                }
            }

            $factura->delete();
            Auditoria::registrar($request->user()->id, null, 'FACTURA', 'ELIMINAR', null, $factura->numero, $bodegaId);

            return response()->json(['message' => 'Factura eliminada y stock restaurado.']);
        });
    }

    /** Genera (o regenera) el PDF de la factura. */
    public function generarPdf(Factura $factura)
    {
        $this->autorizarBodega($factura);

        try {
            $url = $this->recibo->generarPdf($factura);
        } catch (\Throwable $e) {
            Log::error('No se pudo generar el PDF de la factura', ['factura' => $factura->numero, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'No se pudo generar el PDF. Intenta de nuevo en un momento.'], 502);
        }

        return response()->json([
            'pdf_url' => $url,
            'pdf_public_url' => url($url),
            'pdf_api_url' => url("/api/facturas/{$factura->id}/pdf"),
        ]);
    }

    /** Muestra el PDF con headers correctos para navegadores y dispositivos moviles. */
    public function verPdf(Factura $factura)
    {
        $this->autorizarBodega($factura);

        if (! $factura->pdf_url) {
            try {
                $this->recibo->generarPdf($factura);
                $factura->refresh();
            } catch (\Throwable $e) {
                Log::error('No se pudo generar el PDF de la factura', ['factura' => $factura->numero, 'error' => $e->getMessage()]);
                abort(502, 'No se pudo generar el PDF. Intenta de nuevo en un momento.');
            }
        }

        $ruta = StorageUrl::rutaLocal($factura->pdf_url);
        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            abort(404, 'PDF no encontrado.');
        }

        $contenido = Storage::disk('public')->get($ruta);
        $nombre = "factura_{$factura->numero}.pdf";

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $nombre . '"',
            'Content-Length' => strlen($contenido),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    /** Envía la factura por correo a cualquier dirección. */
    public function enviar(Request $request, Factura $factura)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $this->autorizarBodega($factura);
        $factura->load(['cliente', 'detalles', 'empresa']);

        // Asegura que el PDF exista.
        if (! $factura->pdf_url) {
            try {
                $this->recibo->generarPdf($factura);
                $factura->refresh();
            } catch (\Throwable $e) {
                Log::error('No se pudo generar el PDF de la factura', ['factura' => $factura->numero, 'error' => $e->getMessage()]);
                return response()->json(['message' => 'No se pudo generar el PDF para enviarlo. Intenta de nuevo en un momento.'], 502);
            }
        }
        $adjunto = $factura->pdf_url
            ? Storage::disk('public')->path(StorageUrl::rutaLocal($factura->pdf_url))
            : null;

        // Si la empresa configuró su propio correo de facturación, la factura
        // sale a nombre de ella (From/Reply-To) en vez del remitente genérico.
        $empresa = $factura->empresa;

        $enviado = $this->notificador->correo(
            $data['email'],
            "Factura {$factura->numero} - Fénix",
            "Factura {$factura->numero}",
            [
                "Hola {$factura->cliente->nombre_completo},",
                "Adjuntamos tu factura {$factura->numero} por un total de \${$factura->total}.",
                'Gracias por tu confianza.',
            ],
            $adjunto,
            'FACTURA',
            $empresa?->email_facturacion,
            $empresa?->nombre,
        );

        if (! $enviado) {
            return response()->json([
                'message' => 'No se pudo enviar el correo. Revisa MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS y MAIL_ENCRYPTION en Render.',
            ], 502);
        }

        return response()->json(['mensaje' => "Factura en camino a {$data['email']} (se envía en segundo plano)."]);
    }

    public function whatsapp(Factura $factura)
    {
        $this->autorizarBodega($factura);
        $factura->load('cliente');

        $telefono = preg_replace('/\D+/', '', (string) ($factura->cliente->telefono ?? ''));
        // Enlace público FIRMADO que regenera el PDF bajo demanda: no depende de
        // /storage ni del disco efímero de Render (los /storage/... daban 404
        // tras cada redeploy porque los archivos se pierden).
        $urlPdf = $this->urlPdfPublica($factura);
        $etiqueta = $factura->estado === 'BORRADOR' ? 'cotización' : 'factura';
        $mensaje = "Hola, te compartimos tu {$etiqueta} numero {$factura->numero}: {$urlPdf}";

        return response()->json([
            'whatsapp_url' => 'https://wa.me/' . $telefono . '?text=' . rawurlencode($mensaje),
            'mensaje' => $mensaje,
            'pdf_public_url' => $urlPdf,
        ]);
    }

    /** URL pública firmada del PDF (para WhatsApp y correos). */
    private function urlPdfPublica(Factura $factura): string
    {
        $firma = hash_hmac('sha256', $factura->id . '|' . $factura->numero, (string) config('app.key'));
        return url("/api/publico/facturas/{$factura->id}/pdf?t={$firma}");
    }

    /**
     * Descarga pública del PDF con firma HMAC (sin sesión): el cliente final
     * abre este enlace desde WhatsApp. Si el archivo no existe (redeploy con
     * disco efímero), se regenera al momento.
     */
    public function pdfPublico(Request $request, Factura $factura)
    {
        $esperada = hash_hmac('sha256', $factura->id . '|' . $factura->numero, (string) config('app.key'));
        abort_unless(hash_equals($esperada, (string) $request->query('t', '')), 403, 'Enlace inválido.');

        $ruta = StorageUrl::rutaLocal($factura->pdf_url);
        if (! $factura->pdf_url || ! Storage::disk('public')->exists($ruta)) {
            try {
                $this->recibo->generarPdf($factura);
                $factura->refresh();
                $ruta = StorageUrl::rutaLocal($factura->pdf_url);
            } catch (\Throwable $e) {
                Log::error('No se pudo generar el PDF publico de la factura', ['factura' => $factura->numero, 'error' => $e->getMessage()]);
                abort(502, 'No se pudo generar el PDF. Intenta de nuevo en un momento.');
            }
        }

        $contenido = Storage::disk('public')->get($ruta);

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="factura_' . $factura->numero . '.pdf"',
            'Content-Length' => strlen($contenido),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Cobra una ORDEN DE SERVICIO desde la caja (lavadero, taller, barbería):
     * genera la factura con los detalles de la orden, registra el medio de pago,
     * marca la orden como facturada y envía el PDF al correo del cliente.
     * NO toca el inventario: los repuestos ya se descontaron al agregarlos a la orden.
     */
    public function facturarOrden(Request $request, \App\Models\ServiceOrder $serviceOrder)
    {
        $this->bloquearMecanico($request);

        if ($serviceOrder->estado === 'facturado') {
            return response()->json(['message' => 'Esta orden ya fue facturada.'], 422);
        }

        $serviceOrder->load('details.producto:id,nombre', 'cliente:id,nombre_completo,email,telefono');
        if ($serviceOrder->details->isEmpty()) {
            return response()->json(['message' => 'La orden no tiene repuestos ni trabajos para cobrar.'], 422);
        }

        $data = $request->validate([
            'metodo_pago' => ['required', 'in:EFECTIVO,TARJETA,TRANSFERENCIA,NEQUI,DAVIPLATA'],
            'propina' => ['nullable', 'numeric', 'min:0'],
        ]);

        $factura = DB::transaction(function () use ($data, $request, $serviceOrder) {
            // Pago por uso (modo prepago): cada factura consume 1 crédito.
            $this->cobrarUsoPorFactura($request->user());

            $bodegaId = $this->resolverBodegaFactura($request, null);

            $factura = Factura::create([
                'numero' => $this->siguienteNumero(),
                'bodega_id' => $bodegaId,
                'cliente_id' => $serviceOrder->cliente_id,
                'fecha' => now()->toDateString(),
                'subtotal' => $serviceOrder->subtotal,
                'impuestos' => 0,
                'total' => $serviceOrder->total,
                'estado' => 'EMITIDA',
                'metodo_pago' => $data['metodo_pago'],
                'propina' => $data['propina'] ?? null,
                'notas' => "Orden de servicio {$serviceOrder->numero_orden}",
                'created_by' => $request->user()->id,
            ]);

            foreach ($serviceOrder->details as $d) {
                // Sin producto_id a propósito: el inventario ya salió con la orden.
                $factura->detalles()->create([
                    'descripcion' => $d->producto?->nombre ?? 'Servicio',
                    'cantidad' => $d->cantidad,
                    'precio_unitario' => $d->precio_unitario,
                    'impuesto_porcentaje' => 0,
                    'subtotal' => $d->subtotal,
                    'impuesto' => 0,
                ]);
            }

            $serviceOrder->update(['estado' => 'facturado', 'factura_id' => $factura->id, 'fecha_entrega_real' => $serviceOrder->fecha_entrega_real ?? now()]);

            $this->notificador->aUsuario($factura->owner_id, 'FACTURA',
                "Orden {$serviceOrder->numero_orden} cobrada", "Factura {$factura->numero} · Total: \${$factura->total} ({$data['metodo_pago']})");
            Auditoria::registrar($request->user()->id, null, 'FACTURA', 'COBRAR_ORDEN', $serviceOrder->numero_orden, $factura->numero, $bodegaId);

            return $factura->load(['cliente:id,nombre_completo,email,telefono', 'detalles']);
        });

        // Recibo al correo del cliente (en segundo plano; no revierte el cobro si falla).
        $this->enviarFacturaAutomatica($factura);

        return response()->json($factura, 201);
    }

    /** Los roles operativos restringidos (Mecanico, Instalador...) no acceden a la facturación (montos ni listados). */
    private function bloquearMecanico(Request $request): void
    {
        if ($request->user()?->esMecanico() || $request->user()?->esInstalador()) {
            abort(403, 'Tu rol no tiene acceso a la facturación.');
        }
    }

    /**
     * Modalidad "pago por uso": si el dueño del workspace está en modo prepago,
     * cada factura emitida consume 1 crédito de facturación ($500 COP).
     */
    private function cobrarUsoPorFactura(\App\Models\User $user): void
    {
        $owner = $user->billingOwner();

        if ($owner->esSuperAdmin() || $owner->modo_cobro !== 'prepago') {
            return;
        }

        try {
            $this->creditService->consume($owner->id, 'facturacion', 1);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'credits' => ['Saldo insuficiente en tu billetera: cada factura cuesta $500 COP (1 crédito). Recarga saldo para seguir facturando.'],
            ]);
        }
    }

    /**
     * Envía el PDF de la factura al correo del cliente de forma automática.
     * Nunca lanza excepciones: un fallo de correo no debe tumbar la venta.
     */
    private function enviarFacturaAutomatica(Factura $factura): void
    {
        $this->recibo->enviarPorCorreo($factura);
    }

    private function siguienteNumero(): string
    {
        return Factura::siguienteNumero(request()->user()?->empresaId());
    }

    private function resolverBodegaFactura(Request $request, ?int $bodegaId): int
    {
        $user = $request->user();
        if ($user->estaLimitadoABodega()) {
            return (int) $user->bodega_id;
        }

        if ($bodegaId) {
            return $bodegaId;
        }

        $principal = Bodega::query()
            ->orderByDesc('es_principal')
            ->orderBy('id')
            ->value('id');

        if (! $principal) {
            throw ValidationException::withMessages([
                'bodega_id' => ['Debes crear una bodega antes de emitir facturas con productos.'],
            ]);
        }

        return (int) $principal;
    }

    private function autorizarBodega(Factura $factura): void
    {
        $user = request()->user();
        if ($user?->estaLimitadoABodega() && (int) $factura->bodega_id !== (int) $user->bodega_id) {
            abort(403, 'No tienes acceso a facturas de otro establecimiento.');
        }
    }

    /**
     * Decodifica una firma recibida como data URL (data:image/png;base64,...),
     * la guarda en el disco público y devuelve la URL. Acepta PNG/JPG/JPEG.
     */
    private function guardarFirma(Factura $factura, string $dataUrl): ?string
    {
        if (! preg_match('/^data:image\/(png|jpe?g);base64,(.+)$/', $dataUrl, $m)) {
            return null;
        }
        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $contenido = base64_decode($m[2], true);
        if ($contenido === false) {
            return null;
        }

        $nombre = "firmas/factura_{$factura->id}_" . now()->timestamp . ".{$ext}";
        Storage::disk('public')->put($nombre, $contenido);

        return Storage::url($nombre);
    }
}
