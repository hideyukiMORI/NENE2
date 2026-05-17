// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { TagForm } from '../TagForm';
import { createTag } from '../../api/tags';

vi.mock('../../api/tags');

describe('TagForm', () => {
  beforeEach(() => {
    vi.mocked(createTag).mockResolvedValue({ id: 5, name: 'backend' });
  });

  it('submit button is disabled when input is empty', () => {
    render(<TagForm onCreated={vi.fn()} />);
    expect(screen.getByRole('button', { name: 'Add tag' })).toBeDisabled();
  });

  it('submit button is enabled when a name is entered', async () => {
    const user = userEvent.setup();
    render(<TagForm onCreated={vi.fn()} />);
    await user.type(
      screen.getByRole('textbox', { name: 'New tag name' }),
      'backend',
    );
    expect(screen.getByRole('button', { name: 'Add tag' })).not.toBeDisabled();
  });

  it('calls onCreated and clears the input on successful submit', async () => {
    const user = userEvent.setup();
    const onCreated = vi.fn();
    render(<TagForm onCreated={onCreated} />);

    await user.type(
      screen.getByRole('textbox', { name: 'New tag name' }),
      'backend',
    );
    await user.click(screen.getByRole('button', { name: 'Add tag' }));

    await vi.waitFor(() => expect(onCreated).toHaveBeenCalledOnce());
    expect(screen.getByRole('textbox', { name: 'New tag name' })).toHaveValue(
      '',
    );
  });

  it('shows an error message when the API call fails', async () => {
    vi.mocked(createTag).mockRejectedValue(new Error('Tag already exists'));
    const user = userEvent.setup();
    render(<TagForm onCreated={vi.fn()} />);

    await user.type(
      screen.getByRole('textbox', { name: 'New tag name' }),
      'duplicate',
    );
    await user.click(screen.getByRole('button', { name: 'Add tag' }));

    await vi.waitFor(() => {
      expect(screen.getByText('Tag already exists')).toBeInTheDocument();
    });
  });
});
