// MSW handlers（R2⑧・05 D-c: スライス直下・名前固定。tests/msw の central server が合成する）
// fixture は mapper.test と MSW handler の共有正本 — 二重定義 MUST NOT。
import { http, HttpResponse } from 'msw';

import type { NoteDto, NoteListDto } from './api-types';

export const noteDtoFixture: NoteDto = {
  id: 1,
  title: 'Example Note',
  body: 'This is the body of an example note.',
};

export const noteListDtoFixture: NoteListDto = {
  items: [noteDtoFixture],
  limit: 20,
  offset: 0,
};

// GET 2 本（detail / list）＋ create（useCreateNote に対応する POST 1 本）
export const noteHandlers = [
  http.get('*/api/examples/notes/:id', () => HttpResponse.json(noteDtoFixture)),
  http.get('*/api/examples/notes', () => HttpResponse.json(noteListDtoFixture)),
  http.post('*/api/examples/notes', () =>
    HttpResponse.json(noteDtoFixture, { status: 201 }),
  ),
];
