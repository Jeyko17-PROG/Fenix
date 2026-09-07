import { build } from 'esbuild';

// bundle:true solo para juntar src/**/*.jsx (los templates locales) en un único
// archivo por entrada y así transpilar todo el JSX que usan. packages:'external'
// evita que empaquete node_modules (react, @react-pdf/renderer, etc.) — esos
// quedan como require() normales, así que en runtime hace falta `node_modules`
// en este directorio (ver plan de despliegue: requiere `npm install` aquí).
await build({
  entryPoints: ['src/render-pdf.jsx', 'src/render-email.jsx'],
  outdir: 'dist',
  outExtension: { '.js': '.cjs' },
  bundle: true,
  packages: 'external',
  platform: 'node',
  format: 'cjs',
  jsx: 'automatic',
  target: 'node20',
});
