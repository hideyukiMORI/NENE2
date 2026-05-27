# Como Adicionar um Receptor de Webhook de Entrada

Receba webhooks de múltiplos serviços externos, valide assinaturas HMAC por fonte e armazene eventos com idempotência.

## Schema

```sql
CREATE TABLE webhook_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE, secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL
);
CREATE TABLE inbound_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL REFERENCES webhook_sources(id),
    event_id TEXT NOT NULL, event_type TEXT NOT NULL,
    payload TEXT NOT NULL, processed_at TEXT NOT NULL,
    UNIQUE(source_id, event_id)
);
```

## Rotas

| Método | Caminho | Descrição |
|--------|------|-------------|
| `POST` | `/sources` | Registrar uma fonte de webhook |
| `POST` | `/sources/{id}/receive` | Receber um webhook |
| `GET` | `/sources/{id}/events` | Listar eventos recebidos |
| `GET` | `/events/{id}` | Obter um evento específico |

## Validação de Assinatura HMAC-SHA256

Cada fonte tem seu próprio segredo HMAC. Nunca o exponha em respostas.

```php
private function verifySignature(string $body, string $header, string $secret): bool
{
    if (!str_starts_with($header, 'sha256=')) {
        return false;
    }
    $expected = hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, substr($header, 7)); // seguro em termos de temporização
}
```

Ordem de chamada: **valide a assinatura primeiro**, depois verificação de idempotência, depois armazenamento:

```php
if (!$this->verifySignature($rawBody, $sigHeader, $source['secret'])) {
    return $this->json->create(['error' => 'Invalid signature'], 401);
}
// ... verificação de idempotência ...
$this->repo->storeEvent($sourceId, $eventId, $eventType, $rawBody, $now);
```

## Idempotência (event_id por fonte)

```php
$existing = $this->repo->findEventBySourceAndEventId($sourceId, $eventId);
if ($existing !== null) {
    return $this->json->create(['status' => 'already_processed', 'id' => $existing['id']]);
}
```

A constraint `UNIQUE(source_id, event_id)` é o backstop no nível do BD. A verificação PHP acima evita o caminho de exceção no primeiro duplicata.

## Nunca Exponha o Segredo

```php
$source = $this->repo->findSource($id);
unset($source['secret']); // remover antes de retornar
return $this->json->create($source, 201);
```

## Verificação de Fonte Inativa

```php
if (!(bool) $source['active']) {
    return $this->json->create(['error' => 'Source is inactive'], 403);
}
```

## Notas MySQL

A constraint `UNIQUE KEY uq_source_event (source_id, event_id)` funciona da mesma forma no MySQL. Use `VARCHAR(191)` para colunas de texto indexadas para ficar dentro do limite de comprimento de chave do InnoDB.

### Executando Testes de Integração MySQL

Inicie o container MySQL compartilhado do FT (porta 3308, volume persistente):

```bash
docker compose -f ../NENE2-FT/docker-compose.yml up -d mysql
```

Então execute os testes de integração com variáveis de ambiente:

```bash
MYSQL_HOST=127.0.0.1 MYSQL_PORT=3308 MYSQL_DATABASE=ft_test \
  MYSQL_USER=ft_user MYSQL_PASSWORD=ft_pass \
  php8.4 vendor/bin/phpunit --filter Mysql
```

Sem `MYSQL_HOST`, os testes MySQL são automaticamente pulados (`markTestSkipped`).

## Notas de Segurança

- `hash_equals()` previne ataques de temporização na comparação de assinatura.
- O corpo JSON bruto é armazenado como está; não faça parse antes da verificação de assinatura.
- Mesmo `event_id` de duas fontes diferentes cria registros separados — a constraint UNIQUE é `(source_id, event_id)`, não apenas `event_id`.
