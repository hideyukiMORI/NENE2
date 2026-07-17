// mutation hooks（02 ST-5 — invalidation は key ファクトリ経由のみ）。
// exemplar 由来: これは `gen:entity note --write` の生テンプレ出力に対し、
// 差分は **endpoint のみ**（note は実 API `/api/examples/notes` に fleshed。
// 生テンプレは `/api/v1/notes`）。input の型付けは **consumer TODO のまま** `unknown` を
// 維持する（生 mutation feature テンプレの `submit(input: unknown)` と対称）。
import {
  useMutation,
  useQueryClient,
  type UseMutationResult,
} from '@tanstack/react-query';

import { apiClient, type AppError } from '@/shared/api/client';

import type { NoteDto } from './api-types';
import { mapNoteDtoToModel } from './mapper';
import type { Note } from './model';
import { noteKeys } from './query-keys';

// TODO: CreateNoteInput 型（model.ts）と mapCreateNoteInputToDto（mapper.ts）を
// 定義して unknown を置き換える（02 FM-5: submit は values → mapper → mutation）
export function useCreateNote(): UseMutationResult<Note, AppError> {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (input: unknown) => {
      const dto = await apiClient.post<NoteDto>('/api/examples/notes', input);
      return mapNoteDtoToModel(dto);
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: noteKeys.lists() });
    },
  });
}
