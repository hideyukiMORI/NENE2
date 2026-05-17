// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { NoteList } from '../NoteList';
import { fetchNotes } from '../../api/notes';

vi.mock('../../api/notes');

const NOTES = [
  { id: 1, title: 'First note', body: 'First body' },
  { id: 2, title: 'Second note', body: 'Second body' },
];

function makeList(items = NOTES) {
  return { items, limit: 20, offset: 0 };
}

describe('NoteList', () => {
  beforeEach(() => {
    vi.mocked(fetchNotes).mockResolvedValue(makeList());
  });

  it('shows loading state on initial render', () => {
    vi.mocked(fetchNotes).mockReturnValue(new Promise(() => {}));
    render(<NoteList refresh={0} />);
    expect(screen.getByText('Loading notes…')).toBeInTheDocument();
  });

  it('renders notes after fetch resolves', async () => {
    render(<NoteList refresh={0} />);
    await waitFor(() => {
      expect(screen.getByText('First note')).toBeInTheDocument();
    });
    expect(screen.getByText('Second note')).toBeInTheDocument();
    expect(screen.getByText('First body')).toBeInTheDocument();
    expect(screen.getByText('#1')).toBeInTheDocument();
    expect(screen.getByText('#2')).toBeInTheDocument();
  });

  it('shows empty state when no notes returned', async () => {
    vi.mocked(fetchNotes).mockResolvedValue(makeList([]));
    render(<NoteList refresh={0} />);
    await waitFor(() => {
      expect(screen.getByText(/No notes yet/)).toBeInTheDocument();
    });
  });

  it('shows error message when fetch fails', async () => {
    vi.mocked(fetchNotes).mockRejectedValue(new Error('Network error'));
    render(<NoteList refresh={0} />);
    await waitFor(() => {
      expect(screen.getByText('Network error')).toBeInTheDocument();
    });
  });

  it('re-fetches when refresh prop changes', async () => {
    const { rerender } = render(<NoteList refresh={0} />);
    await waitFor(() => screen.getByText('First note'));

    vi.mocked(fetchNotes).mockResolvedValue(
      makeList([{ id: 3, title: 'Refreshed', body: 'New' }]),
    );
    rerender(<NoteList refresh={1} />);

    await waitFor(() => {
      expect(screen.getByText('Refreshed')).toBeInTheDocument();
    });
    expect(vi.mocked(fetchNotes)).toHaveBeenCalledTimes(2);
  });
});
