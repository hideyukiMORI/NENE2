import { useEffect, useState } from 'react';
import { deleteTag, fetchTags, updateTag, type Tag } from '../api/tags';

type LoadState =
  | { phase: 'loading' }
  | { phase: 'error'; message: string }
  | { phase: 'ok'; tags: readonly Tag[] };

type Props = {
  readonly refresh: number;
  readonly onChanged: () => void;
};

export function TagList({ refresh, onChanged }: Props) {
  const [state, setState] = useState<LoadState>({ phase: 'loading' });
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editName, setEditName] = useState('');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let isActive = true;

    fetchTags()
      .then((res) => {
        if (isActive) setState({ phase: 'ok', tags: res.items });
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

  function startEdit(tag: Tag) {
    setEditingId(tag.id);
    setEditName(tag.name);
  }

  function cancelEdit() {
    setEditingId(null);
    setEditName('');
  }

  async function handleUpdate(id: number) {
    if (saving || editName.trim() === '') return;
    setSaving(true);

    try {
      await updateTag(id, { name: editName.trim() });
      setEditingId(null);
      setEditName('');
      onChanged();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to update tag.');
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(id: number) {
    if (!confirm('Delete this tag?')) return;

    try {
      await deleteTag(id);
      onChanged();
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Failed to delete tag.');
    }
  }

  if (state.phase === 'error') {
    return <p className="tag-error">{state.message}</p>;
  }

  if (state.phase === 'loading') {
    return <p className="tag-loading">Loading tags…</p>;
  }

  if (state.tags.length === 0) {
    return (
      <p className="tag-empty">No tags yet. Create the first one below.</p>
    );
  }

  return (
    <ul className="tag-list">
      {state.tags.map((tag) =>
        editingId === tag.id ? (
          <li key={tag.id} className="tag-chip tag-chip--editing">
            <input
              className="tag-edit-input"
              type="text"
              value={editName}
              onChange={(e) => {
                setEditName(e.target.value);
              }}
              onKeyDown={(e) => {
                if (e.key === 'Enter') void handleUpdate(tag.id);
                if (e.key === 'Escape') cancelEdit();
              }}
              autoFocus
              disabled={saving}
            />
            <button
              className="tag-btn tag-btn--save"
              onClick={() => void handleUpdate(tag.id)}
              disabled={saving || editName.trim() === ''}
              aria-label="Save"
            >
              ✓
            </button>
            <button
              className="tag-btn tag-btn--cancel"
              onClick={cancelEdit}
              disabled={saving}
              aria-label="Cancel"
            >
              ✕
            </button>
          </li>
        ) : (
          <li key={tag.id} className="tag-chip">
            <span className="tag-name">{tag.name}</span>
            <button
              className="tag-btn tag-btn--edit"
              onClick={() => {
                startEdit(tag);
              }}
              aria-label={`Edit ${tag.name}`}
            >
              ✎
            </button>
            <button
              className="tag-btn tag-btn--delete"
              onClick={() => void handleDelete(tag.id)}
              aria-label={`Delete ${tag.name}`}
            >
              ×
            </button>
          </li>
        ),
      )}
    </ul>
  );
}
