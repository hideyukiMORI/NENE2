import { useEffect, useState } from 'react';

import './App.css';
import { fetchHealth, type HealthResponse } from './api/health';

const apiHighlights = [
  'JSON APIs first',
  'PSR-first PHP runtime',
  'OpenAPI contract ready',
] as const;

export function App() {
  const [health, setHealth] = useState<HealthResponse | null>(null);
  const [healthError, setHealthError] = useState<string | null>(null);

  useEffect(() => {
    let isActive = true;

    fetchHealth()
      .then((response) => {
        if (isActive) {
          setHealth(response);
          setHealthError(null);
        }
      })
      .catch((error: unknown) => {
        if (isActive) {
          setHealth(null);
          setHealthError(
            error instanceof Error ? error.message : 'Unknown error',
          );
        }
      });

    return () => {
      isActive = false;
    };
  }, []);

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

      <section className="status-panel" aria-labelledby="backend-status-title">
        <div>
          <p className="eyebrow">Backend Integration</p>
          <h2 id="backend-status-title">Health API status</h2>
        </div>

        {health !== null ? (
          <p className="status-message is-ok">
            {health.service} responded with <strong>{health.status}</strong>.
          </p>
        ) : (
          <p className="status-message">
            {healthError ??
              'Waiting for the NENE2 backend health endpoint to respond.'}
          </p>
        )}
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
