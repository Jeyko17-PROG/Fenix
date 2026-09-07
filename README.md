# Fénix (Logix)

POS/ERP multi-negocio en la nube (SaaS), con enfoque inicial en **talleres de motos**. Pensado para escalar a otros tipos de negocio (lavaderos, barberías, tatuajes, restaurantes, tiendas, spas). Cada negocio es una cuenta aislada (multi-inquilino) con su propio plan, módulos y datos.

## Stack

- **Backend**: API REST en Laravel (PHP), en la raíz del repo (`app/`, `routes/`, `database/`...).
- **Frontend**: SPA en React + Vite + Tailwind (PWA), en `resources/`.

## Cómo arrancar en local

```bash
composer install
npm --prefix resources install
composer run dev
```

Esto levanta backend, frontend, cola y logs en paralelo (ver script `dev` en [composer.json](composer.json)).

## Documentación

- [docs/ENTORNOS.md](docs/ENTORNOS.md) — entornos (local, producción) y variables de configuración.
- [docs/MIGRACIONES.md](docs/MIGRACIONES.md) — guía de las migraciones de base de datos, agrupadas por módulo.
- [docs/REESTRUCTURACION.md](docs/REESTRUCTURACION.md) — plan de migración a arquitectura por capas (Controller/Service/Repository), módulo por módulo.
