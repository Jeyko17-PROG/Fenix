<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Cita;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\StockBodega;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReporteController extends Controller
{
    /**
     * Datos para el panel de control (dashboard).
     */
    public function dashboard()
    {
        $valorInventario = StockBodega::sum(DB::raw('cantidad * costo_promedio'));

        $stockPorBodega = Bodega::query()
            ->leftJoin('stock_por_bodega as s', 's.bodega_id', '=', 'bodegas.id')
            ->groupBy('bodegas.id', 'bodegas.nombre')
            ->select('bodegas.nombre', DB::raw('COALESCE(SUM(s.cantidad),0) as cantidad'))
            ->get();

        // Propietario actual (null = super-admin: ve todo).
        $user = Auth::user();
        $ownerId = ($user && ! $user->esSuperAdmin()) ? $user->id : null;

        // Top rotación: productos con más unidades en SALIDA.
        $topRotacion = DB::table('movimientos_inventario as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->where('m.tipo', 'SALIDA')
            ->when($ownerId, fn ($q) => $q->where('m.owner_id', $ownerId))
            ->groupBy('p.id', 'p.nombre')
            ->select('p.nombre', DB::raw('SUM(m.cantidad) as total'))
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        $alertas = StockBodega::where('stock_minimo', '>', 0)
            ->whereColumn('cantidad', '<=', 'stock_minimo')
            ->count();

        $hoy = Carbon::today();
        $inicioMes = Carbon::now()->startOfMonth();

        // Métricas de agenda / clientes / facturación (Bloque E).
        $citasHoy = Cita::whereDate('inicio', $hoy)->whereIn('estado', Cita::ESTADOS_ACTIVOS)->count();
        $citasPendientes = Cita::where('estado', 'PENDIENTE')->count();
        $clientesNuevosMes = Cliente::where('created_at', '>=', $inicioMes)->count();
        $clientesActivos = Cliente::where('estado', 'ACTIVO')->count();
        // Ni anuladas ni cotizaciones (BORRADOR) sin confirmar cuentan como venta real.
        $facturacionHoy = Factura::whereDate('fecha', $hoy)->whereNotIn('estado', ['ANULADA', 'BORRADOR'])->sum('total');
        $facturacionMes = Factura::where('fecha', '>=', $inicioMes)->whereNotIn('estado', ['ANULADA', 'BORRADOR'])->sum('total');

        // Ocupación de agenda hoy: minutos reservados vs. minutos laborales del día.
        $ocupacion = $this->ocupacionHoy($hoy);

        // Productos más vendidos (por líneas de factura).
        $masVendidos = DB::table('factura_detalle as fd')
            ->join('facturas as f', 'f.id', '=', 'fd.factura_id')
            ->whereNotNull('fd.descripcion')
            ->when($ownerId, fn ($q) => $q->where('f.owner_id', $ownerId))
            ->groupBy('fd.descripcion')
            ->select('fd.descripcion', DB::raw('SUM(fd.cantidad) as total'))
            ->orderByDesc('total')
            ->limit(5)
            ->get();

        return response()->json([
            'resumen' => [
                'productos' => Producto::count(),
                'proveedores' => Proveedor::count(),
                'bodegas' => Bodega::count(),
                'valor_inventario' => round((float) $valorInventario, 2),
                'alertas' => $alertas,
                'citas_hoy' => $citasHoy,
                'citas_pendientes' => $citasPendientes,
                'clientes_nuevos_mes' => $clientesNuevosMes,
                'clientes_activos' => $clientesActivos,
                'facturacion_hoy' => round((float) $facturacionHoy, 2),
                'facturacion_mes' => round((float) $facturacionMes, 2),
                'ocupacion_pct' => $ocupacion,
            ],
            'stock_por_bodega' => $stockPorBodega,
            'top_rotacion' => $topRotacion,
            'mas_vendidos' => $masVendidos,
            'cuenta' => $this->datosCuenta($user),
        ]);
    }

    /** Información de la cuenta: plan, límite de clientes y consumo (para el dashboard). */
    private function datosCuenta(?\App\Models\User $user): array
    {
        if (! $user) {
            return [];
        }

        $limite = $user->limiteClientesEfectivo();
        $usados = $user->clientesUsados();
        $ilimitado = $limite === PHP_INT_MAX;

        return [
            'plan' => $user->plan?->nombre,
            'es_super_admin' => $user->esSuperAdmin(),
            'clientes_usados' => $usados,
            'clientes_limite' => $ilimitado ? null : $limite,
            'clientes_disponibles' => $ilimitado ? null : max(0, $limite - $usados),
            'porcentaje_uso' => $ilimitado || $limite === 0 ? 0 : round($usados / $limite * 100, 1),
        ];
    }

    /** Porcentaje de ocupación de la agenda para un día. */
    private function ocupacionHoy(Carbon $dia): float
    {
        $minutosLaborales = \App\Models\HorarioLaboral::where('dia_semana', (int) $dia->dayOfWeek)
            ->where('activo', true)
            ->get()
            ->sum(fn ($h) => Carbon::parse($h->hora_inicio)->diffInMinutes(Carbon::parse($h->hora_fin)));

        if ($minutosLaborales <= 0) {
            return 0;
        }

        $minutosOcupados = Cita::whereDate('inicio', $dia)
            ->whereIn('estado', Cita::ESTADOS_ACTIVOS)
            ->get()
            ->sum(fn ($c) => $c->inicio->diffInMinutes($c->fin));

        return round(min(100, $minutosOcupados / $minutosLaborales * 100), 1);
    }

    /**
     * Exporta el estado del inventario a Excel (.xlsx) con gráficas nativas incrustadas.
     */
    public function exportarInventarioExcel(): StreamedResponse
    {
        // Datos: stock total y valor por producto.
        $datos = Producto::with('stocks')->get()->map(function ($p) {
            $cant = (float) $p->stocks->sum('cantidad');
            $valor = (float) $p->stocks->sum(fn ($s) => $s->cantidad * $s->costo_promedio);
            return ['nombre' => $p->nombre, 'cantidad' => $cant, 'valor' => round($valor, 2)];
        })->values();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Inventario');

        // Encabezados
        $sheet->fromArray(['Producto', 'Stock', 'Valor ($)'], null, 'A1');
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);

        $fila = 2;
        foreach ($datos as $d) {
            $sheet->fromArray([$d['nombre'], $d['cantidad'], $d['valor']], null, "A{$fila}");
            $fila++;
        }
        $ultima = $fila - 1;
        foreach (['A', 'B', 'C'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        if ($ultima >= 2) {
            $this->agregarGraficas($sheet, $ultima);
        }

        $nombre = 'inventario_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->setIncludeCharts(true); // imprescindible para incrustar las gráficas
            $writer->save('php://output');
        }, $nombre, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Inserta un gráfico de barras (stock) y uno de pastel (valor) nativos.
     */
    private function agregarGraficas($sheet, int $ultima): void
    {
        $categorias = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "Inventario!\$A\$2:\$A\${$ultima}", null, $ultima - 1)];

        // --- Gráfico de barras: Stock por producto ---
        $valoresStock = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "Inventario!\$B\$2:\$B\${$ultima}", null, $ultima - 1)];
        $etiquetaStock = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Inventario!$B$1', null, 1)];

        $seriesBarras = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($valoresStock) - 1),
            $etiquetaStock,
            $categorias,
            $valoresStock
        );
        $seriesBarras->setPlotDirection(DataSeries::DIRECTION_COL);
        $plotBarras = new PlotArea(null, [$seriesBarras]);
        $graficoBarras = new Chart(
            'stock_chart',
            new Title('Stock por producto'),
            new Legend(Legend::POSITION_RIGHT, null, false),
            $plotBarras
        );
        $graficoBarras->setTopLeftPosition('E2');
        $graficoBarras->setBottomRightPosition('M16');
        $sheet->addChart($graficoBarras);

        // --- Gráfico de pastel: Valor por producto ---
        $valoresValor = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "Inventario!\$C\$2:\$C\${$ultima}", null, $ultima - 1)];
        $etiquetaValor = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Inventario!$C$1', null, 1)];

        $seriesPastel = new DataSeries(
            DataSeries::TYPE_PIECHART,
            null,
            range(0, count($valoresValor) - 1),
            $etiquetaValor,
            $categorias,
            $valoresValor
        );
        $plotPastel = new PlotArea(null, [$seriesPastel]);
        $graficoPastel = new Chart(
            'valor_chart',
            new Title('Valor del inventario por producto'),
            new Legend(Legend::POSITION_RIGHT, null, false),
            $plotPastel
        );
        $graficoPastel->setTopLeftPosition('E18');
        $graficoPastel->setBottomRightPosition('M32');
        $sheet->addChart($graficoPastel);
    }
}
