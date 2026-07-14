// query hooks（02 ST-3: AbortSignal 貫通 MUST・UseQueryResult 型注釈 MUST）
import { useQuery, type UseQueryResult } from '@tanstack/react-query';

import { apiClient, type AppError } from '@/shared/api/client';

import type { NoteDto, NoteListDto } from './api-types';
import { mapNoteDtoToModel, mapNoteListDtoToModel } from './mapper';
import type { Note, NoteId, NoteList, NoteListParams } from './model';
import { noteKeys } from './query-keys';

export function useNote(id: NoteId): UseQueryResult<Note, AppError> {
  return useQuery({
    queryKey: noteKeys.detail(id),
    queryFn: async ({ signal }) => {
      const dto = await apiClient.get<NoteDto>(
        `/api/examples/notes/${String(id)}`,
        signal,
      );
      return mapNoteDtoToModel(dto);
    },
  });
}

export function useNoteList(
  params: NoteListParams,
): UseQueryResult<NoteList, AppError> {
  return useQuery({
    queryKey: noteKeys.list(params),
    queryFn: async ({ signal }) => {
      const query = new URLSearchParams();
      if (params.limit !== undefined) query.set('limit', String(params.limit));
      if (params.offset !== undefined)
        query.set('offset', String(params.offset));
      const suffix = query.size > 0 ? `?${query.toString()}` : '';
      const dto = await apiClient.get<NoteListDto>(
        `/api/examples/notes${suffix}`,
        signal,
      );
      return mapNoteListDtoToModel(dto);
    },
  });
}
