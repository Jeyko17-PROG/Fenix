# Reestructuración hacia el monolito modular

Plan para llevar `app/` desde su forma actual —el esqueleto por defecto de Laravel: `Http/Controllers` plano, `Models` plano, `Services`, `Support`— a la arquitectura descrita en [ARCHITECTURE.md](ARCHITECTURE.md): un monolito modular dividido en **IAM, Business, Operations, Billing y Shared**, cada uno con sus capas `Domain / Application / Infrastructure / Http`.

Este documento no vuelve a justificar la arquitectura (eso está en `ARCHITECTURE.md`). Responde tres cosas: **dónde está cada pieza hoy**, **a qué módulo y capa va**, y **en qué orden moverla sin romper producción**.

---

## 1. Punto de partida

Medido sobre el código actual:

| Métrica                   | Valor                                                 |
| ------------------------- | ----------------------------------------------------- |
| Líneas PHP en `app/`      | ~12.700                                               |
| Controllers               | 40 (todos en `App\Http\Controllers`, uno en `Admin/`) |
| Modelos Eloquent          | 53 (todos en `App\Models`)                            |
| Services                  | 8 (`app/Services`)                                    |
| Middleware                | 5                                                     |
| FormRequests              | 2                                                     |
| Rutas en `routes/api.php` | 211, en un solo archivo de 512 líneas                 |
| Tests                     | 4 archivos (2 de ellos son `ExampleTest`)             |

Los síntomas que la reestructuración debe eliminar:

- **37 de 40 controllers hacen queries Eloquent directas.** `FacturaController` (734 líneas) valida, decide reglas de negocio, mueve inventario, cobra créditos, genera PDF, envía correo y consulta la base de datos en el mismo archivo.
- **La validación vive inline.** Solo hay 2 `FormRequest` para 211 rutas; el resto es `$request->validate([...])` dentro del método.
- **`routes/api.php` contiene lógica.** El endpoint `/ping` lleva ~55 líneas de diagnóstico (cola de correos, migraciones pendientes, verificación del comercio Wompi), `/tipos-negocio` consulta el modelo desde un closure, y el flujo OAuth de Gmail (`/gmail/conectar`, `/gmail/callback`) está escrito completo en dos closures.
- **Hay duplicación ya visible.** Coexisten `ReportController` (comisiones, hoja de vida) y `ReporteController` (dashboard, exportación a Excel); la resolución de bodega/sucursal está repetida en `FacturaController::resolverBodegaFactura()` y `ServiceOrderController::resolverBodega()`; el bloqueo del rol operario está en `bloquearMecanico()`, `limitarAMecanico()` y `esOperarioLimitado()`, repartido entre dos controllers.
- **Los conceptos transversales están mezclados con los modelos.** El multi-tenant (`Models\Concerns\PerteneceAUsuario` + `Models\Scopes\OwnerScope`) y el control de funcionalidades (`Support\Funcionalidades`, 182 líneas con Eloquent adentro) son reglas de plataforma viviendo como utilidades sueltas.

Lo que **sí** está bien y se conserva tal cual: los 8 services de `app/Services` ya son, de hecho, la capa `Application` embrionaria (`KardexService`, `AgendaService`, `CreditService`, `ReciboService`, `Notificador`, `WompiService`, `NodeRenderService`, `CloudinaryUploader`). No hay que reescribirlos: hay que moverlos a su módulo y hacer que los controllers dejen de saltárselos.

---

## 2. Destino

```text
app/
├── IAM/           Domain/ Application/ Infrastructure/ Http/
├── Business/      Domain/ Application/ Infrastructure/ Http/
├── Operations/    Domain/ Application/ Infrastructure/ Http/
├── Billing/       Domain/ Application/ Infrastructure/ Http/
├── Shared/        Domain/ Application/ Infrastructure/ Http/
├── Console/
└── Providers/
```

El namespace pasa de `App\Http\Controllers\ClienteController` a `App\Operations\Http\Controllers\ClienteController`. **No hay que tocar `composer.json`**: el PSR-4 actual (`"App\\": "app/"`) ya cubre cualquier subcarpeta nueva.

---

## 3. Mapa: dónde va cada pieza

### 3.1 IAM — identidad, acceso, planes y funcionalidades

| Hoy                                                                                                           | Destino                                                                                                                                                                                         |
| ------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AuthController`, `ProfileController`                                                                         | `IAM/Http/Controllers`                                                                                                                                                                          |
| `UsuarioAdminController` (581 líneas: usuarios, equipo, licencias, auditorías)                                | `IAM/Http/Controllers` — se parte en `UsuarioAdminController` (superadmin) y `EquipoController` (dueño del negocio): son dos audiencias distintas bajo prefijos distintos (`/admin`, `/equipo`) |
| `CuentaController` (mis negocios, vincular, entrar)                                                           | `IAM/Http/Controllers`                                                                                                                                                                          |
| `FeatureController`, `PlanController`                                                                         | `IAM/Http/Controllers`                                                                                                                                                                          |
| `Admin/EmpresaAdminController` — parte de plan, límites, membresía y módulos                                  | `IAM/Http/Controllers/Admin`                                                                                                                                                                    |
| `Middleware\EnsureUserHasRole`, `EnsureSuperAdmin`, `CheckFeature`                                            | `IAM/Http/Middleware`                                                                                                                                                                           |
| Modelos `User`, `Role`, `Permiso`, `Plan`, `Modulo`, `EmpresaModulo`, `UserFuncionalidad`, `NegocioVinculado` | `IAM/Infrastructure/Persistence/Eloquent`                                                                                                                                                       |
| `Support\Funcionalidades`                                                                                     | reglas puras (catálogo, estados, orden de precedencia) → `IAM/Domain/Funcionalidades`; la resolución que consulta BD → `IAM/Application/Funcionalidades`                                        |
| `Notifications\RestablecerPasswordNotification`                                                               | `IAM/Infrastructure/Notifications`                                                                                                                                                              |

`Support\Funcionalidades` es el caso más claro de regla de negocio disfrazada de helper: la cadena de precedencia (tipo de negocio → override de empresa → override legado por usuario → default del plan) es _la_ política de acceso de Fenix, y hoy vive en métodos estáticos mezclados con Eloquent. Separarla en Domain (decide) + Application (lee) es de las mejoras con mejor relación valor/riesgo de todo el plan.

### 3.2 Business — estructura del negocio

| Hoy                                                                                  | Destino                                        |
| ------------------------------------------------------------------------------------ | ---------------------------------------------- |
| `BodegaController`                                                                   | `Business/Http/Controllers`                    |
| `OperablesEmployeeController` (empleados operables: mecánicos, estilistas, técnicos) | `Business/Http/Controllers`                    |
| `Admin/EmpresaAdminController` — parte de datos de empresa y tipos de negocio        | `Business/Http/Controllers/Admin`              |
| Modelos `Empresa`, `TipoNegocio`, `Bodega`, `OperablesEmployee`                      | `Business/Infrastructure/Persistence/Eloquent` |
| `Support\BackfillEmpresas`, `Console\Commands\BackfillEmpresasCommand`               | `Business/Application` + `Console`             |

Nota sobre `Bodega`: el propio modelo lo documenta —"además de ubicación de inventario, es la _sucursal_ física del negocio"—. Es decir, la "Sucursal" de `ARCHITECTURE.md` §5 **ya existe en el código, con otro nombre**. La migración **no** debe renombrar tabla ni modelo (rompe referencias y datos vivos); debe reconocer que `Bodega` cumple los dos papeles y ubicarla en Business, que es de donde Operations la consume.

### 3.3 Operations — la operación diaria

| Hoy                                                                                                                                                                                                                                                                                                                     | Destino                                                                                   |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `ClienteController`                                                                                                                                                                                                                                                                                                     | `Operations/Http/Controllers`                                                             |
| `ProductoController`, `CategoriaController`, `ProveedorController`                                                                                                                                                                                                                                                      | `Operations/Http/Controllers`                                                             |
| `InventarioController`, `OrdenCompraController`                                                                                                                                                                                                                                                                         | `Operations/Http/Controllers`                                                             |
| `ServicioController`, `PlanLavadoController`                                                                                                                                                                                                                                                                            | `Operations/Http/Controllers`                                                             |
| `CitaController`, `ConfiguracionAgendaController`, `PortalController` (portal público de reservas)                                                                                                                                                                                                                      | `Operations/Http/Controllers`                                                             |
| `ServiceOrderController` (473 líneas), `AssetVehicleController`                                                                                                                                                                                                                                                         | `Operations/Http/Controllers`                                                             |
| `MesaController`, `ComandaController` (restaurante + KDS)                                                                                                                                                                                                                                                               | `Operations/Http/Controllers`                                                             |
| `ExtraccionController` (OCR con la API de Claude)                                                                                                                                                                                                                                                                       | `Operations/Http/Controllers`; el cliente HTTP → `Operations/Infrastructure/Integrations` |
| `ReportController` (comisiones, hoja de vida de activos)                                                                                                                                                                                                                                                                | `Operations/Http/Controllers`                                                             |
| `KardexService`, `AgendaService`                                                                                                                                                                                                                                                                                        | `Operations/Application`                                                                  |
| Modelos `Cliente`, `Producto`, `Categoria`, `Proveedor`, `StockBodega`, `MovimientoInventario`, `OrdenCompra(+Detalle)`, `Servicio`, `PlanLavado`, `Cita`, `CitaServicio`, `AjusteAgenda`, `HorarioLaboral`, `BloqueoAgenda`, `AssetVehicle`, `AssetHistory`, `ServiceOrder(+Detail)`, `Mesa`, `Comanda`, `ComandaItem` | `Operations/Infrastructure/Persistence/Eloquent`                                          |

`KardexService` (345 líneas, con `DB::transaction` y locks de stock) es el mejor ejemplo de lo que debe ser un servicio de `Application`: ya existe, ya está bien hecho, solo cambia de carpeta.

### 3.4 Billing — facturación, cobro y dinero

| Hoy                                                                                                                                                                                             | Destino                                                                                 |
| ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| `FacturaController` (734 líneas)                                                                                                                                                                | `Billing/Http/Controllers` + `Billing/Application/Facturas/FacturaService`              |
| `MetodoPagoCobroController`, `CajaController`, `GastoController`                                                                                                                                | `Billing/Http/Controllers`                                                              |
| `CreditController`, `PaymentWebhookController`                                                                                                                                                  | `Billing/Http/Controllers`                                                              |
| `Middleware\VerificarMembresia`                                                                                                                                                                 | `Billing/Http/Middleware` — la membresía vencida es una regla de cobro, no de identidad |
| `CreditService`                                                                                                                                                                                 | `Billing/Application`                                                                   |
| `WompiService`                                                                                                                                                                                  | `Billing/Infrastructure/Integrations`                                                   |
| `ReciboService`                                                                                                                                                                                 | `Billing/Application` (la generación real del PDF/HTML la delega en Shared)             |
| Modelos `Factura`, `FacturaDetalle`, `FacturaPago`, `MetodoPagoCobro`, `CajaSesion`, `Gasto`, `PaymentTransaction`, `CreditPackage`, `CreditTransaction`, `UserCredit`, `CommissionLiquidation` | `Billing/Infrastructure/Persistence/Eloquent`                                           |

`FacturaController` es el archivo que más gana con la migración y el que más riesgo tiene. Sus 734 líneas contienen al menos cinco responsabilidades separables: emitir factura, registrar abonos, generar/enviar el PDF, facturar una orden de servicio (`facturarOrden`, que entra desde Operations) y cobrar el crédito por uso. Va **al final** de la migración, cuando el patrón ya esté probado en módulos más chicos.

### 3.5 Shared — realmente transversal

| Hoy                                                                                     | Destino                                                                                                      |
| --------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `NotificacionController`, `Notificador`, modelo `Notificacion`                          | `Shared` (Http / Application / Infrastructure)                                                               |
| `AdjuntoController`, `GaleriaController`, modelos `Adjunto`, `Archivo`, `GaleriaImagen` | `Shared` — archivos y adjuntos                                                                               |
| `DocumentoController`, `FirmaController`, modelos `Documento`, `FirmaElectronica`       | `Shared` — documentación y firma sirven por igual a proveedores, clientes y órdenes de compra                |
| `NotaController` + modelo `Nota` (bloc de notas)                                        | `Shared`                                                                                                     |
| Modelo `Auditoria` + endpoints de auditoría                                             | `Shared`                                                                                                     |
| `ReporteController` (dashboard, exportación)                                            | `Shared/Http/Controllers`, **sin queries propias**: compone servicios de Application de Operations y Billing |
| `NodeRenderService`, `CloudinaryUploader`                                               | `Shared/Infrastructure`                                                                                      |
| `Mail\CorreoLogix`, `Mail\Transport\{Gmail,Brevo}ApiTransport`                          | `Shared/Infrastructure/Mail`                                                                                 |
| `Support\Numero`, `Support\StorageUrl`, `Middleware\NormalizarNumerosLocales`           | `Shared` (Domain / Http/Middleware)                                                                          |

Atención a un falso amigo: `ARCHITECTURE.md` §13 lista "Notas" dentro de Billing —ahí significa _notas crédito/débito_, que todavía no existen— mientras que el `NotaController` actual es un bloc de notas del usuario. Son cosas distintas; el bloc de notas va a Shared.

---

## 4. Reglas fijas de la migración

Se definen una vez y se aplican igual en los cinco módulos.

1. **Namespace = módulo + capa.** `App\{Modulo}\{Capa}\...`. Sin cambios en `composer.json`.
2. **Controllers delgados.** Reciben un `FormRequest`, llaman a un servicio de `Application` y devuelven la respuesta. Cero Eloquent.
3. **Toda validación en `FormRequest`**, dentro de `{Modulo}/Http/Requests`. Se acaba el `$request->validate([...])` inline.
4. **Eloquent solo en `Infrastructure/Persistence/Eloquent`.** Ningún otro namespace importa un modelo.
5. **Nada de repositorios automáticos.** Se crea un repositorio solo cuando aporte una abstracción real (`ARCHITECTURE.md` §15). Para empezar no hace falta ninguno.
6. **Los modelos conservan su nombre actual** (`Producto`, no `ProductoModel`) al moverse. El sufijo `Model` se agrega únicamente si aparece una entidad de Domain con el mismo nombre. Renombrar 53 modelos "porque el ejemplo del documento lo hace" es riesgo puro sin beneficio.
7. **Se mantiene el español** en clases, métodos y rutas: es la convención vigente del proyecto (con las excepciones ya existentes en inglés: `ServiceOrder`, `AssetVehicle`, `OperablesEmployee`, `CreditService`). La migración mueve archivos, no cambia el idioma del código.
8. **Un módulo por PR**, y dentro del PR **dos commits**: primero el movimiento mecánico (mover archivo + cambiar namespace + arreglar imports, sin tocar una línea de lógica), después la separación en capas. Así, si algo se rompe, se ve en cuál de los dos pasos.

### Regla adicional: dependencias entre módulos

`ARCHITECTURE.md` define la dirección de dependencias _dentro_ de un módulo (`Http → Application → Domain`) pero no _entre_ módulos, y Fenix necesita esa regla porque los cruces ya existen. Este plan adopta:

```text
Un módulo puede llamar a otro SOLO a través de su capa Application.
Nunca importa el modelo Eloquent, el repositorio ni el controller de otro módulo.
```

Cruces reales que hay que reencauzar al migrar cada módulo:

| Cruce actual                                                                         | Cómo queda                                                                                      |
| ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| `FacturaController::facturarOrden(ServiceOrder $orden)`                              | Billing recibe un id/DTO, no el modelo de Operations; pide los datos a `Operations\Application` |
| `ComandaController::cobrar()` genera la factura                                      | Operations llama a `Billing\Application\FacturaService`                                         |
| `FacturaController` y `ServiceOrderController` mueven inventario con `KardexService` | Billing llama a `Operations\Application\KardexService` (permitido: es Application)              |
| `OwnerScope` (transversal) lee `Auth::user()->empresaId()`                           | ver §5.2                                                                                        |

---

## 5. Riesgos técnicos concretos

Ninguno es teórico: todos salen del código actual y todos rompen en producción si se ignoran.

### 5.1 Las relaciones polimórficas guardan el nombre de clase en la base de datos

`GaleriaImagen` y `Adjunto` usan `morphTo()` **sin `morphMap`**. Eso significa que las columnas `imageable_type` y `adjuntable_tipo` contienen literalmente `App\Models\Producto`, `App\Models\Servicio`, `App\Models\OperablesEmployee`. En el momento en que el modelo pase a `App\Operations\Infrastructure\Persistence\Eloquent\Producto`, **todas las galerías y adjuntos existentes dejan de resolver**.

Antes de mover el primer modelo:

```php
// Providers/AppServiceProvider::boot()
Relation::enforceMorphMap([
    'producto' => \App\Models\Producto::class,
    'servicio' => \App\Models\Servicio::class,
    'empleado' => \App\Models\OperablesEmployee::class,
    // ... el resto de tipos presentes en la BD
]);
```

y migrar los datos existentes (`UPDATE galeria_imagenes SET imageable_type = 'producto' WHERE imageable_type = 'App\\Models\\Producto'`, ídem en `adjuntos.adjuntable_tipo`). A partir de ahí, mover clases de namespace es libre: solo cambia el lado derecho del mapa.

Este paso es de la Fase 0 y no es negociable.

### 5.2 El multi-tenant es transversal pero depende de IAM y Business

`PerteneceAUsuario` + `OwnerScope` aplican a casi todos los modelos de Operations y Billing, así que conceptualmente son `Shared`. Pero leen `Auth::user()->empresaId()` (IAM) y referencian `Empresa` (Business): si se dejan en Shared tal cual, Shared queda dependiendo de dos módulos, que es exactamente lo que `ARCHITECTURE.md` §14 prohíbe.

Solución del plan: `Shared/Domain/Tenancy/ContextoDeTenant` (interfaz: `empresaId(): ?int`, `esSuperAdmin(): bool`), implementada en `IAM/Infrastructure/Tenancy` y resuelta por el contenedor. El trait y el scope pasan a `Shared/Infrastructure/Persistence/Concerns` y consumen la interfaz, no `Auth::user()`.

Hay que preservar el comportamiento actual tal cual: sin usuario autenticado el scope **no filtra** (de eso dependen el portal público y `AgendaService`), y varios sitios lo desactivan por nombre de clase con `withoutGlobalScope(OwnerScope::class)` — esas llamadas hay que actualizarlas al mover.

### 5.3 289 referencias a `App\Models` repartidas en 112 archivos

Incluye `config/auth.php`, las factories, 7 seeders y los tests. `composer dump-autoload` no avisa de ninguna: fallan en runtime. Después de cada módulo migrado, el chequeo obligatorio es un grep de referencias huérfanas al namespace viejo.

### 5.4 La red de seguridad es casi inexistente

Hay 4 archivos de test y dos son el `ExampleTest` que trae Laravel. **La migración no puede apoyarse en la suite actual.** Antes de tocar un módulo hay que escribir tests de caracterización de sus endpoints (entrada → status + forma de la respuesta): son los que después prueban que el movimiento no cambió nada. Es trabajo extra real, y es lo que separa una migración verificable de un refactor a ciegas.

### 5.5 `routes/api.php` es un archivo único de 512 líneas

Se divide en `routes/api/{iam,business,operations,billing,shared}.php`, cargados desde `bootstrap/app.php`. Los closures con lógica (`/ping`, `/tipos-negocio`, `/gmail/conectar`, `/gmail/callback`) se convierten en controllers del módulo que les corresponde (Shared los dos primeros, `Shared/Infrastructure/Mail` el OAuth de Gmail).

Verificación mecánica: `php artisan route:list --json` antes y después debe dar el mismo conjunto de `uri + método + middleware`; lo único que cambia es el nombre de la clase del action.

---

## 6. Fase 0 — antes de mover el primer archivo

1. Registrar el `morphMap` y migrar los datos de `imageable_type` / `adjuntable_tipo` (§5.1). **Commit propio, desplegado y verificado antes de seguir.**
2. Escribir los tests de caracterización de los endpoints del primer módulo a migrar.
3. Fijar el gate de regresión que se corre en cada PR: `php artisan test`, `php artisan route:list --json` comparado contra la línea base, y grep de referencias al namespace viejo.
4. Crear los cinco directorios de módulo con sus cuatro capas, vacíos. Commit aparte, sin lógica.
5. Extraer los closures de `routes/api.php` a controllers y partir el archivo por módulo (§5.5). Es puramente mecánico y deja el resto de fases mucho más simples.

---

## 7. Plantilla de un módulo

Para cada módulo, en el mismo PR:

**Commit 1 — movimiento mecánico (sin cambios de lógica)**

1. Mover controllers, modelos y services a `{Modulo}/{Capa}/...`.
2. Actualizar namespaces e imports (incluidas las llamadas `withoutGlobalScope`, factories, seeders y tests).
3. Actualizar el archivo de rutas del módulo.
4. Correr el gate: tests + diff de `route:list` + grep de namespaces viejos.

**Commit 2 — separación en capas**

1. Extraer la validación inline a `Http/Requests`.
2. Mover la lógica de negocio del controller a un servicio de `Application` (uno por área, con varios métodos — no una clase por acción, `ARCHITECTURE.md` §15).
3. Sacar el Eloquent que quede en el controller hacia el servicio o el modelo.
4. Subir a `Domain` únicamente las reglas que se puedan expresar sin Laravel (estados de una factura, cálculo de saldo, solapamiento de citas). Lo que no cumpla eso se queda en Application; no se crea `Domain` vacío por simetría.
5. Reencauzar los cruces con otros módulos a través de Application (§4).
6. Gate otra vez + prueba manual del flujo completo del módulo en el navegador.

---

## 8. Orden de las fases

De menor a mayor riesgo, y respetando quién depende de quién.

| #   | Módulo / alcance                                                                                                                                  | Por qué en esta posición                                                                                                                                                                           |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **Shared** — notificaciones, archivos/adjuntos/galería, documentos y firma, notas, auditoría, `Numero`, `StorageUrl`, correo, `NodeRenderService` | Es de quien todos dependen: moverlo primero hace que los imports de los demás módulos cambien **una sola vez**. Además es casi todo movimiento mecánico, ideal para estrenar el gate de regresión. |
| 2   | **Operations — Clientes**                                                                                                                         | Módulo chico y aislado (`ClienteController`, 162 líneas, más `historialPos`). Primera rebanada vertical completa: aquí se afina el patrón `FormRequest → Service → modelo`, no en el más grande.   |
| 3   | **Operations — Catálogo e inventario** (Productos, Categorías, Proveedores, stock, órdenes de compra, `KardexService`)                            | `KardexService` ya está bien hecho: el trabajo real es adelgazar `InventarioController` (270 líneas) y `ProductoController` (200).                                                                 |
| 4   | **Business** — Empresa, TipoNegocio, Bodega como sucursal, empleados operables                                                                    | Va después de inventario porque `Bodega` se comparte con Operations y conviene que ese consumidor ya esté ordenado.                                                                                |
| 5   | **Operations — Agenda** (Citas, configuración, portal público, `AgendaService`)                                                                   | Tiene un consumidor sin autenticar (el portal del QR) que depende del comportamiento exacto de `OwnerScope`: se migra con el tenancy ya resuelto (§5.2).                                           |
| 6   | **Operations — Taller y Restaurante** (`ServiceOrder` 473 líneas, `AssetVehicle`, Mesas, Comandas, `ReportController`)                            | Volumen alto y reglas por tipo de negocio; ya con el patrón probado en tres rebanadas.                                                                                                             |
| 7   | **Billing — Caja, gastos y métodos de pago**                                                                                                      | Entrada suave a Billing: CRUD con reglas acotadas, y ya existe un test (`CajaIngresoTest`).                                                                                                        |
| 8   | **Billing — Facturación, pagos y créditos** (`FacturaController` 734 líneas, `CreditService`, `WompiService`, webhooks)                           | El de mayor complejidad interna, y depende de Operations (órdenes, inventario) ya migrado.                                                                                                         |
| 9   | **IAM** — Auth, usuarios, equipo, funcionalidades, planes, panel superadmin                                                                       | Al final: es de quien todo lo demás depende para autorizar. Aquí se cierra además la separación Domain/Application de `Funcionalidades` (§3.1) y la interfaz de tenancy (§5.2).                    |

Cada fila es un punto seguro al que volver. Si el plan se detiene después de cualquiera de ellas, el código queda consistente: unos módulos migrados y el resto en `App\Http\Controllers`, conviviendo sin problema.

---

## 9. Frontend

`ARCHITECTURE.md` describe el backend. El frontend (`resources/src`: 36 páginas planas en `pages/`, `components/` sin agrupar, un único `api/client.js`) tiene el mismo problema al revés —el fetch, el estado y el render en el mismo archivo— pero **no forma parte de este plan** y no debe mezclarse en los mismos PRs.

Cuando se aborde, la recomendación es que sus carpetas espejen exactamente los cinco módulos del backend (`resources/src/modules/{operations,billing,...}/`), para que "todo lo que toca Facturación" sea una carpeta en ambos lados y no un grep.

---

## 10. Qué NO hacer en esta migración

Repetido de `ARCHITECTURE.md` §18 porque es donde este tipo de reestructuración se descarrila:

- No crear un repositorio por modelo.
- No crear una clase por cada acción (`CrearFacturaHandler`, `AnularFacturaHandler`, ...): un `FacturaService` con varios métodos.
- No crear interfaces solo para "cumplir la arquitectura". La única que este plan justifica de entrada es `ContextoDeTenant` (§5.2), y es porque resuelve una dependencia circular real.
- No crear carpetas `Domain/` vacías por simetría entre módulos.
- No renombrar modelos, tablas ni rutas durante el movimiento. Cualquier renombrado es un PR aparte, después.
- No hacer un big-bang: mover todo `app/` de una vez deja un PR imposible de revisar y sin punto de retorno.
