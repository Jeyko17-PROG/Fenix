import { Navigate, Route, Routes } from 'react-router-dom'
import Bienvenida from './pages/Bienvenida'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'
import Perfil from './pages/Perfil'
import Proveedores from './pages/Proveedores'
import Productos from './pages/Productos'
import Inventario from './pages/Inventario'
import Bodegas from './pages/Bodegas'
import Compras from './pages/Compras'
import Documentos from './pages/Documentos'
import Clientes from './pages/Clientes'
import Agenda from './pages/Agenda'
import PlanesLavado from './pages/PlanesLavado'
import Servicios from './pages/Servicios'
import Configuracion from './pages/Configuracion'
import Reserva from './pages/Reserva'
import QR from './pages/QR'
import Facturacion from './pages/Facturacion'
import Taller from './pages/Taller'
import Caja from './pages/Caja'
import Restaurante from './pages/Restaurante'
import Notas from './pages/Notas'
import Calculadora from './pages/Calculadora'
import Reportes from './pages/Reportes'
import Notificaciones from './pages/Notificaciones'
import Equipo from './pages/Equipo'
import Usuarios from './pages/Usuarios'
import Empresas from './pages/Empresas'
import Licencias from './pages/Licencias'
import Planes from './pages/Planes'
import ControlFuncionalidades from './pages/ControlFuncionalidades'
import Auditoria from './pages/Auditoria'
import Restablecer from './pages/Restablecer'
import MisNegocios from './pages/MisNegocios'
import Layout from './components/Layout'
import ProtectedRoute from './components/ProtectedRoute'
import SoloSuperAdmin from './components/SoloSuperAdmin'

function App() {
  return (
    <Routes>
      <Route path="/" element={<Bienvenida />} />
      <Route path="/bienvenida" element={<Bienvenida />} />
      <Route path="/login" element={<Login />} />
      <Route path="/restablecer" element={<Restablecer />} />
      {/* Portal público de reservas (sin login) — destino del QR. Cada usuario tiene su slug. */}
      <Route path="/reservar" element={<Reserva />} />
      <Route path="/reservar/:slug" element={<Reserva />} />

      {/* Selector "Mis negocios": pantalla completa, sin el menú lateral */}
      <Route path="/mis-negocios" element={<ProtectedRoute><MisNegocios /></ProtectedRoute>} />

      {/* Rutas protegidas: requieren sesión */}
      <Route
        element={
          <ProtectedRoute>
            <Layout />
          </ProtectedRoute>
        }
      >
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/perfil" element={<Perfil />} />
        <Route path="/clientes" element={<Clientes />} />
        <Route path="/productos" element={<Productos />} />
        <Route path="/inventario" element={<Inventario />} />
        <Route path="/proveedores" element={<Proveedores />} />
        <Route path="/compras" element={<Compras />} />
        <Route path="/documentos" element={<Documentos />} />
        <Route path="/bodegas" element={<Bodegas />} />

        <Route path="/agenda" element={<Agenda />} />
        <Route path="/planes-lavado" element={<PlanesLavado />} />
        <Route path="/servicios" element={<Servicios />} />
        <Route path="/qr" element={<QR />} />
        <Route path="/configuracion" element={<Configuracion />} />

        <Route path="/taller" element={<Taller />} />
        <Route path="/caja" element={<Caja />} />
        <Route path="/restaurante" element={<Restaurante />} />
        <Route path="/facturacion" element={<Facturacion />} />
        <Route path="/reportes" element={<Reportes />} />
        <Route path="/notas" element={<Notas />} />
        <Route path="/calculadora" element={<Calculadora />} />
        <Route path="/notificaciones" element={<Notificaciones />} />
        <Route path="/equipo" element={<Equipo />} />
        <Route path="/planes" element={<Planes />} />

        {/* Solo Super Administrador */}
        <Route path="/empresas" element={<SoloSuperAdmin><Empresas /></SoloSuperAdmin>} />
        <Route path="/usuarios" element={<SoloSuperAdmin><Usuarios /></SoloSuperAdmin>} />
        <Route path="/licencias" element={<SoloSuperAdmin><Licencias /></SoloSuperAdmin>} />
        <Route path="/funcionalidades" element={<SoloSuperAdmin><ControlFuncionalidades /></SoloSuperAdmin>} />
        <Route path="/auditoria" element={<SoloSuperAdmin><Auditoria /></SoloSuperAdmin>} />
      </Route>

      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}

export default App
