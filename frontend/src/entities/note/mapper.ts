// Dto → domain の純関数（境界の 1 点で全部変換する — 02 TY-3/TY-4）
import type { NoteDto, NoteListDto } from './api-types';
import type { Note, NoteId, NoteList } from './model';

export function mapNoteDtoToModel(dto: NoteDto): Note {
  return {
    id: dto.id as NoteId,
    title: dto.title,
    body: dto.body,
  };
}

export function mapNoteListDtoToModel(dto: NoteListDto): NoteList {
  return {
    items: dto.items.map(mapNoteDtoToModel),
    limit: dto.limit,
    offset: dto.offset,
    total: null, // API が total を返さないため（02 TY-5 の null 腕）
  };
}
