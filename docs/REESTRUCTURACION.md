# Reestructuración a capas (Controller → Service → Repository)

Plan de migración de `app/*` (backend) y `resources/src/*` (frontend) de su forma actual — controladores gordos con Eloquent y lógica de negocio mezclados, páginas de React con las llamadas a la API sueltas adentro — a una arquitectura por capas, dividida por módulo de negocio (Clientes, Facturación, Inventario, etc).

## Por qué

Controladores como `FacturaController.php` (734 líneas), `UsuarioAdminController.php` (581) o `AuthController.php` (468) mezclan tres responsabilidades en un mismo archivo: leer el `Request`, decidir la lógica de negocio, y hacer las queries a la base de datos. Eso tiene tres costos concretos a medida que el proyecto crece (multiempresa, multi-negocio, más módulos como Taller/Restaurante/Barbería):

- **Cambiar algo se vuelve riesgoso.** Si la query de "productos con stock bajo" vive inline en el controller, no hay forma de saber quién más depende de esa misma lógica sin leer todo el archivo. Repetirla en otro controller (u otro endpoint) es lo que normalmente pasa, y ahí empiezan a aparecer inconsistencias entre pantallas que deberían mostrar lo mismo.
- **No se puede probar la lógica de negocio sin HTTP.** Un test de "el service de crédito descuenta bien las unidades" hoy requiere simular una request completa. Con la lógica en una clase de Service, se prueba directo, sin pasar por rutas ni middlewares.
- **El frontend tiene el mismo problema al revés.** `resources/src/pages/*.jsx` mezcla el fetch a la API, el manejo de estado y el render en el mismo archivo de 30 páginas planas, sin agrupar por módulo. Encontrar "todo lo que toca Facturación" hoy implica grep, no una carpeta.

La meta no es "arquitectura por moda": es que agregar un módulo nuevo, o modificar uno existente, no obligue a releer un archivo de 700 líneas para entender qué se puede tocar sin romper otra cosa.

## Cómo — reglas fijas (se definen una vez, se aplican igual a todos los módulos)

**Backend**, por módulo:
- `Controller`: solo valida entrada (`FormRequest`) y llama al `Service`. Cero Eloquent aquí.
- `Service`: la lógica de negocio — orquesta, valida reglas, decide. Llama al `Repository`, nunca al modelo directo.
- `Repository`: el único lugar con queries Eloquent de ese módulo (`Model::where(...)`, joins, scopes complejos).

**Frontend**, por módulo, bajo `resources/src/modules/{modulo}/`:
- `pages/`: las pantallas de ese módulo.
- `components/`: componentes que solo usa ese módulo.
- `api/{modulo}Api.js`: las llamadas HTTP de ese módulo, envolviendo el cliente compartido (`resources/src/api/client.js`).
- Lo transversal (`Layout.jsx`, `ProtectedRoute.jsx`, `context/`, `utils/`) se queda fuera de los módulos, en un `shared/` o donde ya vive hoy.

**Por qué en ese orden (Repository debajo de Service debajo de Controller) y no al revés:** así el Controller no sabe que existe Eloquent, y el Service no sabe que existe HTTP. Cada capa se puede cambiar (cambiar de HTTP a un job en cola, cambiar de Eloquent a otra fuente de datos) sin tocar las otras dos.

## Cómo — proceso, módulo por módulo

Ir de a un módulo por vez (no un big-bang de todo `app/*` junto) porque cada módulo migrado y probado es un punto seguro al que volver si algo sale mal — y porque el patrón se termina de afinar en el primer módulo chico, no en el más grande.

### Fase 0 — antes de tocar cualquier módulo
1. Arreglar las referencias rotas a `resources/frontend` (quedaron así tras el commit `a865eba` "move folders"; la carpeta real ahora es `resources/`) en `composer.json`, `Dockerfile`, `routes/web.php` y `app/Http/Controllers/SpaController.php`. Si no, cualquier build/deploy falla de forma confusa a mitad de la migración.
2. Confirmar que corren limpio como gate de regresión para cada módulo: `php artisan test`, `composer test`, `npm run lint` y `npm run build` (dentro de `resources/`).
3. Crear las carpetas base (`app/Services/`, `app/Repositories/` o `app/Modules/{Modulo}/...`, y `resources/src/modules/`) sin mover nada de lógica todavía. Commit aparte.

### Por cada módulo (backend + frontend en el mismo PR, para no dejar nada a medias)
**Backend:**
1. Mover las queries del controller al `Repository` nuevo.
2. Mover la lógica de negocio/validación al `Service` nuevo.
3. Adelgazar el controller (validación vía `FormRequest` + llamada al service).
4. Correr `php artisan test` + probar manualmente los endpoints del módulo.

**Frontend:**
1. Crear `resources/src/modules/{modulo}/` y mover sus páginas/componentes.
2. Sacar las llamadas a la API sueltas en las páginas a `modules/{modulo}/api/{modulo}Api.js`.
3. Actualizar imports y rutas del router.
4. Probar manualmente en el navegador (flujo completo, no solo que cargue).
5. Commit del módulo.

### Orden sugerido (de menor a mayor riesgo)

Empezar por algo chico y aislado valida el patrón sin arriesgar nada crítico; lo más grande y lo más transversal (Auth, multiempresa) queda al final, cuando el patrón ya está probado en 8-9 módulos:

1. Notas / Notificaciones
2. Clientes
3. Proveedores / Categorías / Bodegas
4. Inventario / Órdenes de compra
5. Agenda / Citas
6. Servicios / Comandas / Mesas
7. Taller (AssetVehicle, ServiceOrder, OperablesEmployee)
8. Documentos / Adjuntos / Firma / Galería
9. Reportes / Auditoría
10. Caja / Gastos / Métodos de pago
11. Facturación (734 líneas — el controller más grande, se deja para cuando el patrón ya esté maduro)
12. Auth / Usuarios / Empresas / Planes / Créditos (el core multiempresa/SaaS — el de mayor riesgo, al final)
