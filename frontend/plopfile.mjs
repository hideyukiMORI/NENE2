/**
 * NENE2 スターター同梱 generator（gen:entity / gen:feature / gen:page — 規約 05 §6）。
 *
 * - entity / feature / page の手書き作成は MUST NOT（GEN-1）— この generator の出力が正本。
 * - テンプレの変更は fleet-tooling への昇格対象（GEN-2 — 暫定はスターター同梱・W0.starter が正本）。
 * - 決定性: 同入力 → 同出力（tests/gen.test.ts が 2 回生成の同一性を検査する）。
 * - NENE2_GEN_DEST: 出力先ベースの上書き（決定性テスト用 — 既定はリポの frontend/）。
 *
 * 使い方:
 *   npm run gen:entity  <noun>            例: npm run gen:entity order
 *   npm run gen:entity  <noun> y          （--write 相当: mutations.ts を追加生成）
 *   npm run gen:feature <verb-noun> <noun> 例: npm run gen:feature view-orders order
 *   npm run gen:feature <verb-noun> <noun> --mutation  （mutation archetype = 4値 union）
 *   npm run gen:page    <name>            例: npm run gen:page dashboard
 */
import { readFileSync } from 'node:fs';
import prettier from 'prettier';

const DEST = process.env.NENE2_GEN_DEST ?? '.';
const KEBAB = /^[a-z][a-z0-9]*(-[a-z0-9]+)*$/;
const PRETTIER_CONFIG = JSON.parse(
  readFileSync(new URL('.prettierrc.json', import.meta.url), 'utf8'),
);

/** 生成物は pinned prettier の固定点で出力する（決定性 — themegen T-1 と同型の規律） */
async function formatTs(content) {
  return prettier.format(content, { ...PRETTIER_CONFIG, parser: 'typescript' });
}

/** @param {import('plop').NodePlopAPI} plop */
export default function (plop) {
  plop.setHelper('plural', (text) => `${text}s`);

  const kebabInput = (name, message) => ({
    type: 'input',
    name,
    message,
    validate: (value) =>
      KEBAB.test(String(value)) ||
      `kebab-case で入力する（例: payment, view-orders）`,
  });

  /** カタログへキーを追記する modify アクション（既存キーはスキップ = 冪等） */
  const addMessage = (file, key, value) => ({
    type: 'modify',
    path: `${DEST}/src/shared/i18n/messages/${file}`,
    transform: async (content, data) => {
      const resolvedKey = plop.renderString(key, data);
      const resolvedValue = plop.renderString(value, data);
      if (content.includes(`'${resolvedKey}'`)) return content;
      return formatTs(
        content.replace(
          /^(\s*)(\/\/ \[nene2-gen:messages\])/m,
          `$1'${resolvedKey}': '${resolvedValue}',\n$1$2`,
        ),
      );
    },
  });

  plop.setGenerator('entity', {
    description: 'entity 8ファイル（7点セット＋handlers — 01 §2/05 §6.1）',
    prompts: [
      kebabInput('noun', 'entity 名（kebab-case 単数形名詞。例: payment）'),
      {
        type: 'confirm',
        name: 'write',
        message: '書き込み（mutations.ts）を生成するか（--write）',
        default: false,
      },
    ],
    actions: (data) => {
      const dir = `${DEST}/src/entities/{{kebabCase noun}}`;
      const files = [
        'api-types.ts',
        'model.ts',
        'mapper.ts',
        'mapper.test.ts',
        'queries.ts',
        'query-keys.ts',
        'handlers.ts',
        'index.ts',
      ];
      const actions = files.map((file) => ({
        type: 'add',
        path: `${dir}/${file}`,
        templateFile: `gen/templates/entity/${file}.hbs`,
        transform: formatTs,
      }));
      if (data?.write === true) {
        actions.push({
          type: 'add',
          path: `${dir}/mutations.ts`,
          templateFile: 'gen/templates/entity/mutations.ts.hbs',
          transform: formatTs,
        });
      }
      actions.push(
        // MSW central server へ handlers を合成（tests/msw/server.ts — R2⑧）
        {
          type: 'modify',
          path: `${DEST}/tests/msw/server.ts`,
          transform: async (content, d) => {
            const camel = plop.renderString('{{camelCase noun}}', d);
            const kebab = plop.renderString('{{kebabCase noun}}', d);
            if (content.includes(`${camel}Handlers`)) return content;
            return formatTs(
              content
                .replace(
                  /^(\/\/ \[nene2-gen:handler-imports\])/m,
                  `import { ${camel}Handlers } from '@/entities/${kebab}/handlers';\n$1`,
                )
                .replace(
                  /^(\s*)(\/\/ \[nene2-gen:handlers\])/m,
                  `$1...${camel}Handlers,\n$1$2`,
                ),
            );
          },
        },
        addMessage(
          'ja.ts',
          'error.{{camelCase noun}}.idRequired',
          'ID は必須です。',
        ),
        addMessage(
          'en.ts',
          'error.{{camelCase noun}}.idRequired',
          'ID is required.',
        ),
      );
      return actions;
    },
  });

  plop.setGenerator('feature', {
    description:
      'feature 4ファイル（ui＋container hook＋遷移テスト＋barrel — 05 §6.2）。--mutation で mutation archetype（4値 union）',
    prompts: [
      kebabInput(
        'verbNoun',
        'feature 名（kebab-case 動詞-名詞。例: view-orders）',
      ),
      kebabInput('noun', '消費する entity 名（kebab-case 単数形。例: order）'),
    ],
    actions: () => {
      const dir = `${DEST}/src/features/{{kebabCase verbNoun}}`;
      // archetype は flag で選ぶ（決定性・LLM 自動化前提 — 対話プロンプトにしない）。
      // query（既定）= 3値 loading/error/success。mutation = 4値 idle/submitting/error/success。
      // mutation は消費 entity を --write 生成（useCreate<Noun> と POST handler）していることが前提。
      const isMutation = process.argv.includes('--mutation');
      const suffix = isMutation ? '.mutation' : '';
      const actions = [
        {
          type: 'add',
          path: `${dir}/model/use-{{kebabCase verbNoun}}.ts`,
          templateFile: `gen/templates/feature/use-hook${suffix}.ts.hbs`,
          transform: formatTs,
        },
        {
          type: 'add',
          path: `${dir}/model/use-{{kebabCase verbNoun}}.test.tsx`,
          templateFile: `gen/templates/feature/use-hook${suffix}.test.tsx.hbs`,
          transform: formatTs,
        },
        {
          type: 'add',
          path: `${dir}/ui/{{pascalCase verbNoun}}.tsx`,
          templateFile: `gen/templates/feature/view${suffix}.tsx.hbs`,
          transform: formatTs,
        },
        {
          type: 'add',
          path: `${dir}/index.ts`,
          templateFile: 'gen/templates/feature/index.ts.hbs',
          transform: formatTs,
        },
      ];
      if (isMutation) {
        actions.push(
          addMessage(
            'ja.ts',
            '{{camelCase noun}}.create.success',
            '作成しました。',
          ),
          addMessage('en.ts', '{{camelCase noun}}.create.success', 'Created.'),
        );
      } else {
        actions.push(
          addMessage(
            'ja.ts',
            '{{camelCase noun}}.list.empty',
            'まだデータがありません。',
          ),
          addMessage('en.ts', '{{camelCase noun}}.list.empty', 'No items yet.'),
        );
      }
      return actions;
    },
  });

  plop.setGenerator('page', {
    description: 'page 2ファイル＋router lazy 登録（05 §6.3・01 6-2）',
    prompts: [
      kebabInput('name', 'page 名（kebab-case ルート名。例: dashboard）'),
    ],
    actions: () => [
      {
        type: 'add',
        path: `${DEST}/src/pages/{{kebabCase name}}/ui/{{pascalCase name}}Page.tsx`,
        templateFile: 'gen/templates/page/page.tsx.hbs',
        transform: formatTs,
      },
      {
        type: 'add',
        path: `${DEST}/src/pages/{{kebabCase name}}/index.ts`,
        templateFile: 'gen/templates/page/index.ts.hbs',
        transform: formatTs,
      },
      {
        // named export と React.lazy の橋渡しは 01 6-2 の 1 形（generator が挿入）
        type: 'modify',
        path: `${DEST}/src/app/router.tsx`,
        transform: async (content, data) => {
          const pascal = plop.renderString('{{pascalCase name}}', data);
          const kebab = plop.renderString('{{kebabCase name}}', data);
          if (content.includes(`${pascal}Page`)) return content;
          return formatTs(
            content
              .replace(
                /^(\/\/ \[nene2-gen:lazy-imports\])/m,
                `const ${pascal}Page = lazy(() =>\n  import('@/pages/${kebab}').then((m) => ({ default: m.${pascal}Page })),\n);\n$1`,
              )
              .replace(
                /^(\s*)(\{\/\* \[nene2-gen:routes\])/m,
                `$1<Route path="/${kebab}" element={<${pascal}Page />} />\n$1$2`,
              ),
          );
        },
      },
      addMessage(
        'ja.ts',
        '{{camelCase name}}.pageTitle',
        '{{pascalCase name}} ページ',
      ),
      addMessage(
        'en.ts',
        '{{camelCase name}}.pageTitle',
        '{{pascalCase name}} page',
      ),
    ],
  });
}
