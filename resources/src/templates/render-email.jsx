import { render } from '@react-email/render';
import CorreoGenerico from './templates/CorreoGenerico.jsx';

async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

async function main() {
  const raw = await readStdin();
  const data = JSON.parse(raw || '{}');

  const html = await render(<CorreoGenerico {...data} />);
  process.stdout.write(html);
}

main().catch((err) => {
  process.stderr.write(String(err?.stack || err) + '\n');
  process.exitCode = 1;
});
