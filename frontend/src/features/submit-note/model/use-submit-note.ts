// container hook（02 UI-1: 判別ユニオン 4 値固定・mutation archetype —
// idle / submitting / error(retry=同一 input 再送) / success(reset=idle 復帰)・所属は features）
// 前提: 消費する entity は --write 生成済み（useCreate<Noun> と POST handler が要る）。
import { useCallback, useState } from 'react';

import { useCreateNote, type Note } from '@/entities/note';

export type SubmitNoteState =
  | { status: 'idle'; submit: (input: unknown) => void }
  | { status: 'submitting' }
  | { status: 'error'; retry: () => void }
  | { status: 'success'; note: Note; reset: () => void };

export function useSubmitNote(): SubmitNoteState {
  const mutation = useCreateNote();
  const [lastInput, setLastInput] = useState<unknown>(undefined);

  const submit = useCallback(
    (input: unknown) => {
      setLastInput(input);
      mutation.mutate(input);
    },
    [mutation],
  );
  const retry = useCallback(() => {
    mutation.mutate(lastInput);
  }, [mutation, lastInput]);
  const reset = useCallback(() => {
    mutation.reset();
  }, [mutation]);

  if (mutation.isPending) return { status: 'submitting' };
  if (mutation.isError) return { status: 'error', retry };
  if (mutation.isSuccess) {
    return { status: 'success', note: mutation.data, reset };
  }
  return { status: 'idle', submit };
}
