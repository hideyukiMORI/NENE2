import { useEffect, useState } from 'react';
import { fetchNotes, type Note } from '../api/notes';

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'ok'; notes: readonly Note[] };

type Props = {
  readonly refresh: number;
};

export function NoteList({ refresh }: Props) {
  const [state, setState] = useState<LoadState>({ phase: 'loading' });

  useEffect(() => {
    let isActive = true;

    fetchNotes()
      .then((res) => {
        if (isActive) setState({ phase: 'ok', notes: res.items });
      })
      .catch((err: unknown) => {
        if (isActive) {
          setState({
            phase: 'error',
            message: err instanceof Error ? err.message : 'Unknown error',
          });
        }
      });

    return () => {
      isActive = false;
    };
  }, [refresh]);

  if (state.phase === 'error') {
    return <p className="note-error">{state.message}</p>;
  }

  if (state.phase === 'loading') {
    return <p className="note-loading">Loading notes…</p>;
  }

  if (state.notes.length === 0) {
    return (
      <p className="note-empty">No notes yet. Create the first one below.</p>
    );
  }

  return (
    <ul className="note-list">
      {state.notes.map((note) => (
        <li key={note.id} className="note-item">
          <span className="note-id">#{note.id}</span>
          <div className="note-content">
            <strong className="note-title">{note.title}</strong>
            <p className="note-body">{note.body}</p>
          </div>
        </li>
      ))}
    </ul>
  );
}
