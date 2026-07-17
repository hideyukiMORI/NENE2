/**
 * 権威カタログ（I18N-5/8: ja が唯一の真実。型はすべてここから導出する）。
 * キー文法: `domain.area.intent` の 2〜5 セグメント・lowerCamelCase（I18N-10）。
 * 補間は `{{lowerCamelCase}}` のみ（I18N-11）。
 */
export const ja = {
  'app.name': 'NENE2 Starter',
  'common.actions.reset': 'リセット',
  'common.actions.retry': '再試行',
  'common.actions.submit': '送信',
  'common.state.loading': '読み込み中…',
  'common.state.submitting': '送信中…',
  'error.conflict': '競合が発生しました。最新の状態を確認してください。',
  'error.forbidden': 'この操作を行う権限がありません。',
  'error.notFound': '対象が見つかりません。',
  'error.rateLimit':
    'リクエストが多すぎます。しばらく待ってから再試行してください。',
  'error.serverError':
    'サーバーエラーが発生しました。時間をおいて再試行してください。',
  'error.unauthorized': 'サインインが必要です。',
  'error.unknown': '予期しないエラーが発生しました。',
  'error.validation': '入力内容を確認してください。',
  'home.description':
    'NeNe フロント統一規約に準拠した、API ファースト製品のための雛形です。',
  'home.notesLinkLabel': 'ノート一覧（API 連携の実例）',
  'home.title': 'NENE2 フロントエンドスターター',
  'note.list.empty': 'ノートはまだありません。',
  'note.list.title': 'ノート一覧',
  // [nene2-gen:messages] — generator がこの行の上にキーを追記する
} as const;

export type MessageCatalog = typeof ja;
export type MessageKey = keyof MessageCatalog;
