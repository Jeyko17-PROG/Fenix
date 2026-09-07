import { renderToBuffer } from '@react-pdf/renderer';
import Factura from './templates/Factura.jsx';
import OrdenCompra from './templates/OrdenCompra.jsx';
import ReciboPago from './templates/ReciboPago.jsx';

const TEMPLATES = {
  factura: Factura,
  orden_compra: OrdenCompra,
  recibo_pago: ReciboPago,
};

async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

async function main() {
  const template = process.argv[2];
  const Component = TEMPLATES[template];
  if (!Component) {
    process.stderr.write(`Plantilla PDF desconocida: ${template}\n`);
    process.exitCode = 1;
    return;
  }

  const raw = await readStdin();
  const data = JSON.parse(raw || '{}');

  const buffer = await renderToBuffer(<Component {...data} />);
  process.stdout.write(buffer);
}

main().catch((err) => {
  process.stderr.write(String(err?.stack || err) + '\n');
  process.exitCode = 1;
});
