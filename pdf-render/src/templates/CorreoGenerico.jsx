import { Html, Head, Body, Container, Section, Heading, Text } from '@react-email/components';

export default function CorreoGenerico({ titulo, lineas = [] }) {
  return (
    <Html lang="es">
      <Head />
      <Body style={{ margin: 0, background: '#f1f5f9', fontFamily: 'Arial, sans-serif' }}>
        <Container style={{ maxWidth: 560, margin: '0 auto', padding: 24 }}>
          <Section style={{ background: '#0f172a', color: '#fff', padding: '16px 24px', borderRadius: '12px 12px 0 0' }}>
            <Heading as="h1" style={{ margin: 0, fontSize: 20, color: '#fff' }}>
              Logix
            </Heading>
          </Section>
          <Section style={{ background: '#fff', padding: 24, borderRadius: '0 0 12px 12px' }}>
            <Heading as="h2" style={{ color: '#0f172a', marginTop: 0, fontSize: 18 }}>
              {titulo}
            </Heading>
            {lineas.map((linea, i) => (
              <Text key={i} style={{ color: '#334155', fontSize: 15, lineHeight: 1.5, margin: '8px 0' }}>
                {linea}
              </Text>
            ))}
            <Text style={{ color: '#94a3b8', fontSize: 12, marginTop: 24 }}>
              Este es un mensaje automático de Logix.
            </Text>
          </Section>
        </Container>
      </Body>
    </Html>
  );
}
