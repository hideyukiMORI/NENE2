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

// 初期エンドポイントは queries.ts の 2 hook（detail / list）に対応する GET 2 本
export const noteHandlers = [
  http.get('*/api/examples/notes/:id', () => HttpResponse.json(noteDtoFixture)),
  http.get('*/api/examples/notes', () => HttpResponse.json(noteListDtoFixture)),
];
