# FENIX

## 1. ¿Qué es Fenix?

Fenix es un software de gestión empresarial para pequeños y medianos negocios.

Su objetivo es centralizar en un solo sistema la operación diaria de un negocio:

- Ventas
- Facturación
- Compras
- Inventario
- Productos y servicios
- Clientes
- Proveedores
- Empleados
- Sucursales
- Bodegas
- Reservas y agenda
- Pagos y caja
- Gastos
- Facturación electrónica

Fenix debe ser **simple de utilizar**, especialmente para personas que no tienen conocimientos técnicos.

La aplicación debe ser **mobile-first**, clara y con la menor cantidad posible de pasos para realizar una operación.

---

# 2. Modelo de negocio

La estructura principal de Fenix es:

```text
Superadmin
    │
    └── Usuarios
          │
          ├── Plan
          │
          └── Negocios
                │
                ├── Sucursales
                │     ├── Bodegas
                │     ├── Empleados
                │     └── Operación
                │
                ├── Clientes
                ├── Productos
                ├── Servicios
                ├── Ventas
                ├── Compras
                ├── Inventario
                ├── Proveedores
                ├── Reservas
                └── Facturación
```

## 2.1 Usuarios

Un usuario representa a una persona que utiliza Fenix.

Un usuario puede tener uno o varios negocios.

```text
Usuario
├── Negocio A
├── Negocio B
└── Negocio C
```

El usuario debe tener acceso únicamente a los negocios y recursos que le correspondan.

---

## 2.2 Planes

El plan determina las capacidades disponibles para un usuario.

El plan puede definir:

- Funcionalidades disponibles
- Cantidad máxima de negocios
- Cantidad de sucursales
- Cantidad de empleados
- Límites de uso
- Funcionalidades premium

Ejemplo:

```text
Plan Básico
├── 1 negocio
├── 1 sucursal
├── Ventas
├── Inventario
└── Clientes

Plan Profesional
├── 3 negocios
├── 5 sucursales
├── Ventas
├── Inventario
├── Reservas
├── Empleados
└── Facturación electrónica
```

El plan **no pertenece al negocio**.

El plan pertenece al usuario y determina qué capacidades tiene disponibles dentro de Fenix.

---

# 3. Superadmin

Los superusuarios son los administradores de Fenix.

Pueden:

- Ver usuarios
- Ver negocios
- Administrar planes
- Administrar funcionalidades
- Consultar información global
- Gestionar la plataforma

El superadmin tiene acceso global y no está limitado por el contexto de un negocio.

```text
Superadmin
    └── Todos los usuarios
          └── Todos los negocios
                └── Toda la información permitida
```

---

# 4. Negocios

Un negocio es el núcleo operativo de Fenix.

Un usuario puede tener múltiples negocios independientes.

```text
Usuario
│
├── Tienda de ropa
│   ├── Sucursal Norte
│   └── Sucursal Centro
│
└── Barbería
    └── Sucursal Principal
```

Cada negocio mantiene separados sus:

- Clientes
- Productos
- Servicios
- Ventas
- Compras
- Inventario
- Empleados
- Reservas
- Facturas
- Pagos
- Gastos

La información de un negocio nunca debe mezclarse accidentalmente con la de otro.

---

# 5. Sucursales

Un negocio puede tener una o varias sucursales.

```text
Negocio
├── Sucursal A
│   ├── Bodega
│   └── Empleados
│
└── Sucursal B
    ├── Bodega
    └── Empleados
```

Las operaciones pueden estar asociadas a una sucursal:

- Ventas
- Inventario
- Compras
- Caja
- Reservas
- Empleados

---

# 6. Bodegas e inventario

Las bodegas pertenecen a una sucursal.

El inventario debe controlar:

- Productos
- Existencias
- Entradas
- Salidas
- Ajustes
- Transferencias
- Movimientos

Ejemplo:

```text
Negocio
└── Sucursal
    ├── Bodega Principal
    │   ├── Producto A: 50
    │   └── Producto B: 20
    │
    └── Bodega Secundaria
        └── Producto A: 10
```

---

# 7. Operaciones

Las operaciones representan el trabajo diario del negocio.

Incluyen:

### Ventas

- Crear venta
- Agregar productos o servicios
- Registrar cliente
- Registrar pagos
- Generar factura
- Actualizar inventario

### Compras

- Crear compra
- Registrar proveedor
- Agregar productos
- Actualizar inventario

### Inventario

- Consultar existencias
- Registrar movimientos
- Ajustar cantidades
- Transferir inventario

### Reservas

Fenix debe permitir que negocios que trabajan con citas o reservas puedan gestionar:

- Clientes
- Servicios
- Empleados
- Horarios
- Disponibilidad
- Reservas
- Cancelaciones
- Reprogramaciones

Ejemplo:

```text
Negocio
└── Reservas
    ├── Cliente
    ├── Servicio
    ├── Empleado
    ├── Fecha
    ├── Hora
    └── Estado
```

Las reservas deben funcionar dentro del contexto del negocio y, cuando aplique, de una sucursal.

---

# 8. Facturación y pagos

Billing concentra las responsabilidades financieras y de facturación.

Incluye:

- Facturas
- Detalles de factura
- Pagos
- Métodos de pago
- Caja
- Gastos
- Notas
- Facturación electrónica
- Integraciones externas

Una venta puede generar una factura y registrar uno o varios pagos.

```text
Venta
 ├── Productos/Servicios
 ├── Cliente
 ├── Pago
 └── Factura
```

Las integraciones externas, como proveedores de facturación electrónica, deben permanecer dentro de `Infrastructure`.

---

# 9. Arquitectura de carpetas

Fenix utiliza un **Monolito Modular**.

No se deben crear microservicios.

La aplicación se divide por grandes dominios de negocio y cada dominio contiene sus propias capas.

```text
app/
│
├── IAM/
│   ├── Domain/
│   ├── Application/
│   ├── Infrastructure/
│   └── Http/
│
├── Business/
│   ├── Domain/
│   ├── Application/
│   ├── Infrastructure/
│   └── Http/
│
├── Operations/
│   ├── Domain/
│   ├── Application/
│   ├── Infrastructure/
│   └── Http/
│
├── Billing/
│   ├── Domain/
│   ├── Application/
│   ├── Infrastructure/
│   └── Http/
│
├── Shared/
│   ├── Domain/
│   ├── Application/
│   ├── Infrastructure/
│   └── Http/
│
├── Console/
└── Providers/
```

---

# 10. IAM

Responsabilidad: identidad y acceso.

```text
IAM/
├── Domain/
├── Application/
├── Infrastructure/
└── Http/
```

Aquí se manejan:

- Usuarios
- Autenticación
- Roles
- Permisos
- Planes
- Funcionalidades
- Acceso a negocios

IAM no debe contener lógica específica de ventas, inventario o facturación.

---

# 11. Business

Responsabilidad: estructura y organización de los negocios.

```text
Business/
├── Domain/
├── Application/
├── Infrastructure/
└── Http/
```

Aquí se manejan:

- Negocios
- Sucursales
- Empleados
- Relaciones entre usuarios y negocios
- Configuración general del negocio
- Bodegas cuando formen parte de la estructura organizacional

---

# 12. Operations

Responsabilidad: operación diaria.

```text
Operations/
├── Domain/
├── Application/
├── Infrastructure/
└── Http/
```

Aquí se manejan:

- Ventas
- Clientes
- Productos
- Servicios
- Categorías
- Compras
- Proveedores
- Inventario
- Reservas
- Agenda
- Operaciones específicas de determinados tipos de negocio

Operations puede crecer internamente sin crear nuevos módulos principales innecesariamente.

---

# 13. Billing

Responsabilidad: facturación y operaciones financieras.

```text
Billing/
├── Domain/
├── Application/
├── Infrastructure/
└── Http/
```

Aquí se manejan:

- Facturas
- Pagos
- Caja
- Gastos
- Notas
- Facturación electrónica
- Integraciones de facturación

---

# 14. Shared

Solo contiene funcionalidades realmente compartidas por varios módulos.

```text
Shared/
├── Domain/
├── Application/
├── Infrastructure/
└── Http/
```

Ejemplos:

- Auditoría
- Archivos
- Adjuntos
- Notificaciones
- Value Objects realmente compartidos
- Excepciones base
- Funcionalidades transversales

No utilizar `Shared` como carpeta para cosas que no sabemos dónde colocar.

---

# 15. Responsabilidad de cada capa

## Domain

Contiene las reglas y conceptos importantes del negocio.

No debe depender de:

- Laravel
- Eloquent
- HTTP
- Base de datos
- APIs externas

---

## Application

Coordina casos de uso y lógica de aplicación.

Ejemplo:

```text
Operations/Application/Sales/SaleService.php
```

Puede contener:

```text
create()
cancel()
refund()
addPayment()
```

No es necesario crear una clase independiente para cada acción.

---

## Infrastructure

Contiene detalles tecnológicos.

Ejemplos:

```text
Infrastructure/
├── Persistence/
│   ├── Eloquent/
│   └── Repositories/
│
└── Integrations/
```

Los modelos Eloquent pertenecen aquí.

Ejemplo:

```text
Operations/
└── Infrastructure/
    └── Persistence/
        └── Eloquent/
            ├── ProductModel.php
            ├── SaleModel.php
            └── CustomerModel.php
```

No crear repositories automáticamente para cada modelo. Solo cuando realmente aporten una abstracción útil.

---

## Http

Contiene la entrada HTTP de la aplicación.

```text
Http/
├── Controllers/
├── Requests/
└── Resources/
```

Los controllers deben ser delgados.

```text
Request
   ↓
Controller
   ↓
Application Service
   ↓
Domain
```

---

# 16. Regla de dependencias

La dirección general debe ser:

```text
Http
 ↓
Application
 ↓
Domain
```

Infrastructure implementa detalles tecnológicos utilizados por Application/Domain.

El Domain nunca debe depender de HTTP, Eloquent o servicios externos.

---

# 17. Contexto de cada petición

Toda operación normal de un usuario debe considerar:

```text
Usuario
  ↓
Plan
  ↓
Negocio activo
  ↓
Sucursal activa
  ↓
Operación
```

Por ejemplo:

```text
"Mostrar las ventas de hoy"
```

Fenix debe saber:

1. Qué usuario realiza la petición.
2. Qué negocio está utilizando.
3. Qué sucursal está utilizando, si aplica.
4. Qué permisos tiene.
5. Qué límites establece su plan.
6. Qué información puede consultar.

Nunca se debe consultar información global simplemente porque el usuario esté autenticado.

---

# 18. Principios de desarrollo

Fenix debe priorizar:

- Simplicidad
- Código mantenible
- Separación clara de responsabilidades
- Bajo acoplamiento
- Reutilización
- Escalabilidad sin sobreingeniería
- UX sencilla
- Mobile-first

Evitar:

- Microservicios innecesarios
- Una clase por cada pequeña acción
- Un repository por cada modelo
- Interfaces creadas solo por cumplir una arquitectura
- Capas vacías sin propósito
- Helpers genéricos sin responsabilidad clara
- Lógica de negocio dentro de Controllers
- Eloquent mezclado directamente con el Domain

La arquitectura debe servir al negocio, no obligar al negocio a adaptarse a una arquitectura excesivamente compleja.
