// @vitest-environment jsdom
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { TagList } from '../TagList';
import { deleteTag, fetchTags, updateTag } from '../../api/tags';

vi.mock('../../api/tags');

const TAGS = [
  { id: 1, name: 'backend' },
  { id: 2, name: 'frontend' },
];

function makeList(items = TAGS) {
  return { items, limit: 20, offset: 0 };
}

describe('TagList', () => {
  beforeEach(() => {
    vi.mocked(fetchTags).mockResolvedValue(makeList());
    vi.mocked(updateTag).mockResolvedValue({ id: 1, name: 'updated' });
    vi.mocked(deleteTag).mockResolvedValue(undefined);
  });

  it('shows loading state on initial render', () => {
    vi.mocked(fetchTags).mockReturnValue(new Promise(() => {}));
    render(<TagList refresh={0} onChanged={vi.fn()} />);
    expect(screen.getByText('Loading tags…')).toBeInTheDocument();
  });

  it('shows empty state when no tags returned', async () => {
    vi.mocked(fetchTags).mockResolvedValue(makeList([]));
    render(<TagList refresh={0} onChanged={vi.fn()} />);
    await waitFor(() => {
      expect(screen.getByText(/No tags yet/)).toBeInTheDocument();
    });
  });

  it('renders tag names after fetch resolves', async () => {
    render(<TagList refresh={0} onChanged={vi.fn()} />);
    await waitFor(() => {
      expect(screen.getByText('backend')).toBeInTheDocument();
    });
    expect(screen.getByText('frontend')).toBeInTheDocument();
  });

  it('shows error message when fetch fails', async () => {
    vi.mocked(fetchTags).mockRejectedValue(new Error('Load failed'));
    render(<TagList refresh={0} onChanged={vi.fn()} />);
    await waitFor(() => {
      expect(screen.getByText('Load failed')).toBeInTheDocument();
    });
  });

  it('enters edit mode when the edit button is clicked', async () => {
    const user = userEvent.setup();
    render(<TagList refresh={0} onChanged={vi.fn()} />);
    await waitFor(() => screen.getByText('backend'));

    await user.click(screen.getByRole('button', { name: 'Edit backend' }));

    expect(screen.getByRole('textbox')).toHaveValue('backend');
    expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });

  it('exits edit mode when cancel is clicked', async () => {
    const user = userEvent.setup();
    render(<TagList refresh={0} onChanged={vi.fn()} />);
    await waitFor(() => screen.getByText('backend'));

    await user.click(screen.getByRole('button', { name: 'Edit backend' }));
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(screen.getByText('backend')).toBeInTheDocument();
    expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  });

  it('calls updateTag and onChanged when save is clicked', async () => {
    const user = userEvent.setup();
    const onChanged = vi.fn();
    render(<TagList refresh={0} onChanged={onChanged} />);
    await waitFor(() => screen.getByText('backend'));

    await user.click(screen.getByRole('button', { name: 'Edit backend' }));
    const input = screen.getByRole('textbox');
    await user.clear(input);
    await user.type(input, 'renamed');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await vi.waitFor(() =>
      expect(vi.mocked(updateTag)).toHaveBeenCalledWith(1, { name: 'renamed' }),
    );
    expect(onChanged).toHaveBeenCalledOnce();
  });

  it('calls deleteTag and onChanged when delete is confirmed', async () => {
    const user = userEvent.setup();
    const onChanged = vi.fn();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<TagList refresh={0} onChanged={onChanged} />);
    await waitFor(() => screen.getByText('backend'));

    await user.click(screen.getByRole('button', { name: 'Delete backend' }));

    await vi.waitFor(() =>
      expect(vi.mocked(deleteTag)).toHaveBeenCalledWith(1),
    );
    expect(onChanged).toHaveBeenCalledOnce();
  });

  it('does not call deleteTag when delete is cancelled', async () => {
    const user = userEvent.setup();
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    render(<TagList refresh={0} onChanged={vi.fn()} />);
    await waitFor(() => screen.getByText('backend'));

    await user.click(screen.getByRole('button', { name: 'Delete backend' }));

    expect(vi.mocked(deleteTag)).not.toHaveBeenCalled();
  });
});
