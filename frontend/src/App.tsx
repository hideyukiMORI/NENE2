import './App.css';

const apiHighlights = [
  'JSON APIs first',
  'PSR-first PHP runtime',
  'OpenAPI contract ready',
] as const;

export function App() {
  return (
    <main className="app-shell">
      <section className="hero">
        <p className="eyebrow">NENE2 Frontend Starter</p>
        <h1>React + TypeScript starter for API-first applications.</h1>
        <p className="summary">
          This starter stays isolated under <code>frontend/</code> so the PHP
          framework core remains independent from frontend tooling.
        </p>
      </section>

      <section className="cards" aria-label="Starter highlights">
        {apiHighlights.map((highlight) => (
          <article className="card" key={highlight}>
            <h2>{highlight}</h2>
            <p>
              Ready for thin integration with NENE2 without coupling application
              code to React.
            </p>
          </article>
        ))}
      </section>
    </main>
  );
}
