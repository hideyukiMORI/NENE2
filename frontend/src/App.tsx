import { useEffect, useState } from 'react';

import './App.css';
import { fetchHealth, type HealthResponse } from './api/health';
import { NoteList } from './components/NoteList';
import { NoteForm } from './components/NoteForm';
import { TagList } from './components/TagList';
import { TagForm } from './components/TagForm';

export function App() {
  const [health, setHealth] = useState<HealthResponse | null>(null);
  const [healthError, setHealthError] = useState<string | null>(null);
  const [noteRefresh, setNoteRefresh] = useState(0);
  const [tagRefresh, setTagRefresh] = useState(0);

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

  function handleNoteCreated() {
    setNoteRefresh((n) => n + 1);
  }

  function handleTagChanged() {
    setTagRefresh((n) => n + 1);
  }

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

      <section className="notes-section" aria-labelledby="notes-title">
        <h2 id="notes-title" className="section-title">
          Notes
        </h2>
        <p className="section-desc">
          Live data from <code>GET /examples/notes</code>. Create a note with
          the form below to see the list update.
        </p>
        <NoteList refresh={noteRefresh} />
        <NoteForm onCreated={handleNoteCreated} />
      </section>

      <section className="tags-section" aria-labelledby="tags-title">
        <h2 id="tags-title" className="section-title">
          Tags
        </h2>
        <p className="section-desc">
          Live data from <code>GET /examples/tags</code>. Create, rename, or
          delete tags. Demonstrates full CRUD from the frontend.
        </p>
        <TagList refresh={tagRefresh} onChanged={handleTagChanged} />
        <TagForm onCreated={handleTagChanged} />
      </section>
    </main>
  );
}
