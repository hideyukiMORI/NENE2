import { useState } from 'react';
import { createTag } from '../api/tags';

type Props = {
  readonly onCreated: () => void;
};

export function TagForm({ onCreated }: Props) {
  const [name, setName] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (submitting) return;

    setSubmitting(true);
    setError(null);

    try {
      await createTag({ name: name.trim() });
      setName('');
      onCreated();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Unknown error');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <form className="tag-form" onSubmit={handleSubmit} noValidate>
      <div className="tag-form-row">
        <input
          id="tag-name"
          type="text"
          value={name}
          onChange={(e) => {
            setName(e.target.value);
          }}
          required
          minLength={1}
          placeholder="New tag name"
          disabled={submitting}
          aria-label="New tag name"
        />
        <button
          type="submit"
          className="tag-submit"
          disabled={submitting || name.trim() === ''}
        >
          {submitting ? 'Adding…' : 'Add tag'}
        </button>
      </div>

      {error !== null && <p className="tag-error">{error}</p>}
    </form>
  );
}
