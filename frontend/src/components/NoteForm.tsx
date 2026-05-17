import { useState } from 'react';
import { createNote } from '../api/notes';

type Props = {
  readonly onCreated: () => void;
};

export function NoteForm({ onCreated }: Props) {
  const [title, setTitle] = useState('');
  const [body, setBody] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    setError(null);

    try {
      await createNote({ title, body });
      setTitle('');
      setBody('');
      onCreated();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Unknown error');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="note-form" onSubmit={handleSubmit} noValidate>
      <div className="note-form-field">
        <label htmlFor="note-title">Title</label>
        <input
          id="note-title"
          type="text"
          value={title}
          onChange={(e) => {
            setTitle(e.target.value);
          }}
          required
          minLength={1}
          placeholder="Note title"
          disabled={submitting}
        />
      </div>

      <div className="note-form-field">
        <label htmlFor="note-body">Body</label>
        <textarea
          id="note-body"
          value={body}
          onChange={(e) => {
            setBody(e.target.value);
          }}
          required
          minLength={1}
          placeholder="Note body"
          rows={3}
          disabled={submitting}
        />
      </div>

      {error !== null && <p className="note-error">{error}</p>}

      <button
        type="submit"
        className="note-submit"
        disabled={submitting || title.trim() === '' || body.trim() === ''}
      >
        {submitting ? 'Creating…' : 'Create note'}
      </button>
    </form>
  );
}
