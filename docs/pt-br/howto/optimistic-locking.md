# Bloqueio Otimista

O bloqueio otimista previne o **problema de atualização perdida** — quando dois escritores concorrentes leem o mesmo registro, fazem alterações independentes e o segundo escritor sobrescreve silenciosamente as mudanças do primeiro.

Use bloqueio otimista quando:
- Conflitos são raros (a maioria das atualizações é bem-sucedida)
- Você precisa de leituras sem bloqueio (sem SELECT FOR UPDATE)
- O registro tem um campo `version` ou `updated_at` para rastrear seu estado

## O Problema de Atualização Perdida

Sem bloqueio:

```
tempo | Escritor A              | Escritor B
------|------------------------|-------------------
  1   | GET /articles/1        | GET /articles/1
      | ← version: 1           | ← version: 1
  2   | [edita título]         | [edita corpo]
  3   | PATCH /articles/1      |
      | title = "Título de A"  |
      | ← version: 1, 200 OK   |
  4   |                        | PATCH /articles/1
      |                        | body = "Corpo de B"
      |                        | ← version: 1, 200 OK  ← título de A PERDIDO
```

O Escritor B sobrescreve a mudança de título do Escritor A porque nenhum verificou modificação concorrente.

## Schema

Adicione uma coluna `version` que incrementa a cada atualização:

```sql
CREATE TABLE articles (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT NOT NULL,
    body       TEXT NOT NULL,
    version    INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
);
```

## Implementação do Repositório

```php
/**
 * @throws ConflictException se outro escritor atualizou o registro primeiro
 * @throws \RuntimeException se o artigo não existe
 */
public function update(int $id, string $title, string $body, int $expectedVersion): Article
{
    $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

    // WHERE version = $expectedVersion é a verificação de bloqueio otimista.
    // Se outro escritor já incrementou a versão, este UPDATE corresponde a 0 linhas.
    $affected = $this->executor->execute(
        'UPDATE articles SET title = ?, body = ?, version = version + 1, updated_at = ? WHERE id = ? AND version = ?',
        [$title, $body, $now, $id, $expectedVersion],
    );

    if ($affected === 0) {
        // 0 linhas atualizadas: não encontrado OU conflito de versão — distinguir os casos
        $current = $this->findById($id);
        if ($current === null) {
            throw new \RuntimeException("Article {$id} does not exist.");
        }
        throw new ConflictException($id, $expectedVersion);
    }

    return new Article(id: $id, title: $title, body: $body, version: $expectedVersion + 1, updatedAt: $now);
}
```

### Por que `version = version + 1` no SQL (não no PHP)

```php
// ❌ Condição de corrida: dois escritores leem version=1, ambos calculam version=2
$newVersion = $article->version + 1;
$this->executor->execute('UPDATE ... SET version = ? ...', [$newVersion, $id, $expectedVersion]);

// ✅ Atômico: o banco de dados incrementa — a versão é sempre correta
$this->executor->execute('UPDATE ... SET version = version + 1 ...', [$id, $expectedVersion]);
```

A verificação `WHERE version = $expectedVersion` é a guarda; `version = version + 1` garante que o novo valor seja exatamente um a mais que o que passou pela guarda.

## Integração no Controller

O cliente deve ler a `version` atual e enviá-la de volta em cada atualização:

```php
private function update(ServerRequestInterface $request): ResponseInterface
{
    $id   = (int) Router::param($request, 'id');
    $body = json_decode((string) $request->getBody(), true);

    if (!is_array($body) || !is_int($body['version'] ?? null)) {
        return $this->problems->create($request, 'invalid-body', 'version (int) is required.', 400);
    }

    try {
        $article = $this->repo->update($id, $body['title'], $body['body'], $body['version']);
        return $this->json->create($this->serialize($article));
    } catch (ConflictException $e) {
        $current = $this->repo->findById($id);
        return $this->problems->create(
            $request,
            'conflict',
            'Optimistic lock conflict.',
            409,
            $e->getMessage(),
            $current !== null ? ['current_version' => $current->version] : [],
        );
    } catch (\RuntimeException) {
        return $this->problems->create($request, 'not-found', 'Article not found.', 404);
    }
}
```

## Fluxo do Cliente

```
POST /articles            → 201 { id: 1, version: 1, ... }
GET /articles/1           → 200 { id: 1, version: 1, ... }

PATCH /articles/1         → 200 { id: 1, version: 2, ... }
  { title: "...", version: 1 }

PATCH /articles/1         → 409 { type: "conflict", current_version: 2 }
  { title: "...", version: 1 }   (versão desatualizada — conflito!)

PATCH /articles/1         → 200 { id: 1, version: 3, ... }
  { title: "...", version: 2 }   (buscar novamente ou usar current_version do 409)
```

Incluir `current_version` na resposta 409 permite que o cliente tente novamente sem um GET adicional.

## Payload de Resposta

Sempre inclua `version` em cada resposta para que os clientes sempre tenham o valor mais recente:

```php
/** @return array<string, mixed> */
private function serialize(Article $article): array
{
    return [
        'id'         => $article->id,
        'title'      => $article->title,
        'body'       => $article->body,
        'version'    => $article->version,  // ← cliente precisa disso para enviar de volta
        'updated_at' => $article->updatedAt,
    ];
}
```

## Bloqueio Otimista vs Pessimista

| | Otimista | Pessimista |
|---|---|---|
| Mecanismo | `WHERE version = ?` + verificação de 0 linhas | `SELECT ... FOR UPDATE` |
| Bloqueio de leitura | Nenhum | Bloqueia outros leitores |
| Taxa de conflito | Baixa (maioria das atualizações é bem-sucedida) | Alta contenção OK |
| Custo de retry | Cliente tenta novamente no 409 | Aguarda liberação do lock |
| Suporte SQLite | ✅ | ❌ (não suportado) |
| Melhor para | Conflitos raros, retentativas orientadas ao UX | Alta contenção, operações que devem ter sucesso |

## Checklist de Revisão de Código

- [ ] UPDATE inclui `AND version = ?` na cláusula WHERE
- [ ] O valor de retorno de `execute()` (linhas afetadas) é verificado — 0 significa conflito ou não encontrado
- [ ] O caso de 0 linhas distingue "não encontrado" de "conflito de versão" (findById extra no caminho de conflito)
- [ ] `version = version + 1` é calculado no SQL, não no código PHP da aplicação
- [ ] Todo payload de resposta inclui `version` para que o cliente sempre tenha o mais recente
- [ ] A resposta 409 inclui `current_version` para retry do cliente sem GET extra
- [ ] `version` no corpo da requisição é validado como `int`, não `string` (verificação com `is_int()`)
- [ ] Testes cobrem: atualização bem-sucedida, atualizações sucessivas, conflito concorrente, retry após conflito, 404, versão ausente
