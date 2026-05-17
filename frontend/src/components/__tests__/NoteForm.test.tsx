// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { NoteForm } from '../NoteForm';
import { createNote } from '../../api/notes';

vi.mock('../../api/notes');

const CREATED_NOTE = { id: 10, title: 'My title', body: 'My body' };

describe('NoteForm', () => {
  beforeEach(() => {
    vi.mocked(createNote).mockResolvedValue(CREATED_NOTE);
  });

  it('submit button is disabled when both fields are empty', () => {
    render(<NoteForm onCreated={vi.fn()} />);
    expect(screen.getByRole('button', { name: 'Create note' })).toBeDisabled();
  });

  it('submit button is disabled when only title is filled', async () => {
    const user = userEvent.setup();
    render(<NoteForm onCreated={vi.fn()} />);
    await user.type(screen.getByLabelText('Title'), 'A title');
    expect(screen.getByRole('button', { name: 'Create note' })).toBeDisabled();
  });

  it('submit button is enabled when both title and body are filled', async () => {
    const user = userEvent.setup();
    render(<NoteForm onCreated={vi.fn()} />);
    await user.type(screen.getByLabelText('Title'), 'A title');
    await user.type(screen.getByLabelText('Body'), 'A body');
    expect(
      screen.getByRole('button', { name: 'Create note' }),
    ).not.toBeDisabled();
  });

  it('calls onCreated and clears fields on successful submit', async () => {
    const user = userEvent.setup();
    const onCreated = vi.fn();
    render(<NoteForm onCreated={onCreated} />);

    await user.type(screen.getByLabelText('Title'), 'My title');
    await user.type(screen.getByLabelText('Body'), 'My body');
    await user.click(screen.getByRole('button', { name: 'Create note' }));

    await vi.waitFor(() => expect(onCreated).toHaveBeenCalledOnce());
    expect(screen.getByLabelText('Title')).toHaveValue('');
    expect(screen.getByLabelText('Body')).toHaveValue('');
  });

  it('shows an error message when the API call fails', async () => {
    vi.mocked(createNote).mockRejectedValue(new Error('Server error'));
    const user = userEvent.setup();
    render(<NoteForm onCreated={vi.fn()} />);

    await user.type(screen.getByLabelText('Title'), 'A title');
    await user.type(screen.getByLabelText('Body'), 'A body');
    await user.click(screen.getByRole('button', { name: 'Create note' }));

    await vi.waitFor(() => {
      expect(screen.getByText('Server error')).toBeInTheDocument();
    });
  });
});
