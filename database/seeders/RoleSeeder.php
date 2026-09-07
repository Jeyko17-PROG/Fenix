<?php

namespace Database\Seeders;

use App\Models\Permiso;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Roles base del ERP (Fase 2).
        $roles = [
            'Administrador' => 'Control absoluto del sistema, reportes y configuración.',
            'Usuario' => 'Propietario de su propio espacio de trabajo (cuenta SaaS).',
            'Almacenista' => 'Acceso limitado al inventario, productos y movimientos.',
            'Ventas/Compras' => 'Acceso a proveedores, órdenes de compra y facturación.',
            'Empleado' => 'Gestiona agenda, citas y clientes asignados.',
            'Mecanico' => 'Mecánico/Técnico del taller: solo ve sus órdenes asignadas, registra trabajos y repuestos; sin acceso a facturación ni precios.',
            'Lavador' => 'Operario del lavadero: solo ve las citas que tiene asignadas; sin acceso a facturación ni precios.',
            'Instalador' => 'Instalador de accesorios de motos (ej. luces): solo ve sus órdenes asignadas, registra el trabajo; sin acceso a facturación ni precios.',
            'Cliente' => 'Acceso al portal: reservar y consultar sus propias citas.',
        ];

        foreach ($roles as $nombre => $descripcion) {
            Role::updateOrCreate(['nombre' => $nombre], ['descripcion' => $descripcion]);
        }

        // Catálogo de permisos granulares (clave => descripción).
        $permisos = [
            'productos.ver' => 'Ver catálogo de productos',
            'productos.gestionar' => 'Crear/editar/eliminar productos',
            'inventario.mover' => 'Registrar movimientos de inventario (kardex)',
            'proveedores.ver' => 'Ver proveedores',
            'proveedores.gestionar' => 'Crear/editar proveedores',
            'compras.gestionar' => 'Crear/gestionar órdenes de compra',
            'reportes.ver' => 'Ver reportes y dashboard',
            'usuarios.gestionar' => 'Administrar usuarios y roles',
            'clientes.ver' => 'Ver clientes',
            'clientes.gestionar' => 'Crear/editar clientes',
            'agenda.ver' => 'Ver agenda y citas',
            'agenda.gestionar' => 'Crear/editar/cancelar citas',
            'facturacion.gestionar' => 'Emitir y gestionar facturas',
        ];

        foreach ($permisos as $clave => $descripcion) {
            Permiso::updateOrCreate(['clave' => $clave], ['descripcion' => $descripcion]);
        }

        // Asignación de permisos por rol.
        $asignaciones = [
            'Administrador' => array_keys($permisos), // todos
            // El "Usuario" administra por completo SU propio workspace (sin gestionar otros usuarios).
            'Usuario' => array_values(array_diff(array_keys($permisos), ['usuarios.gestionar'])),
            'Almacenista' => ['productos.ver', 'productos.gestionar', 'inventario.mover', 'reportes.ver'],
            'Ventas/Compras' => ['productos.ver', 'proveedores.ver', 'proveedores.gestionar', 'compras.gestionar', 'reportes.ver', 'clientes.ver', 'clientes.gestionar', 'facturacion.gestionar'],
            'Empleado' => ['agenda.ver', 'agenda.gestionar', 'clientes.ver', 'clientes.gestionar', 'productos.ver'],
            'Mecanico' => ['productos.ver'],
            'Lavador' => ['agenda.ver'],
            'Instalador' => ['productos.ver'],
            'Cliente' => [], // el portal usa endpoints propios, no permisos administrativos
        ];

        foreach ($asignaciones as $rolNombre => $claves) {
            $rol = Role::where('nombre', $rolNombre)->first();
            $ids = Permiso::whereIn('clave', $claves)->pluck('id');
            $rol->permisos()->sync($ids);
        }
    }
}
