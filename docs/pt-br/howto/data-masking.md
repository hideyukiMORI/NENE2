# Como Adicionar Mascaramento de Dados

Mascare campos de PII (e-mail, telefone, nome) nas respostas da API por padrão, com um caminho de desmascaramento para admin auditado.

## Schema

```sql
CREATE TABLE customers (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    name       TEXT NOT NULL,
    email      TEXT NOT NULL,
    phone      TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE mask_audit_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    accessor    TEXT NOT NULL,
    accessed_at TEXT NOT NULL
);
```

## Rotas

| Método | Caminho | Descrição |
|--------|---------|-----------|
| `POST` | `/customers` | Criar cliente (resposta é mascarada) |
| `GET` | `/customers/{id}` | Obter cliente (mascarado por padrão, desmascarado para admin) |
| `GET` | `/customers/{id}/audit` | Ver log de auditoria (somente admin) |

## Padrões de Mascaramento

```php
class MaskService
{
    public function maskEmail(string $email): string
    {
        $at     = strpos($email, '@');
        $local  = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        return substr($local, 0, 1) . '***@' . $domain;
    }

    public function maskPhone(string $phone): string
    {
        // Preservar os últimos 4 dígitos; mascarar todo o restante caractere por caractere
        $digits  = preg_replace('/\D/', '', $phone) ?? '';
        $keepFrom = strlen($digits) - 4;
        $replaced = 0;
        $result   = '';
        for ($i = 0; $i < strlen($phone); $i++) {
            $ch = $phone[$i];
            if (ctype_digit($ch)) {
                $result .= ($replaced < $keepFrom) ? '*' : $ch;
                if (ctype_digit($ch)) { $replaced++; }
            } else {
                $result .= $ch;
            }
        }
        return $result;
    }

    public function maskName(string $name): string
    {
        return implode(' ', array_map(
            fn($w) => mb_substr($w, 0, 1) . '***',
            array_filter(explode(' ', $name))
        ));
    }
}
```

Exemplos:
- `john@example.com` → `j***@example.com`
- `555-123-4567` → `***-***-4567`
- `John Doe` → `J*** D***`

## Desmascaramento Baseado em Papel

O handler verifica o header `X-Role`. O acesso admin requer `X-Accessor` para aplicar a trilha de auditoria:

```php
$role     = $request->getHeaderLine('X-Role');
$accessor = trim($request->getHeaderLine('X-Accessor'));

if ($role === 'admin') {
    if ($accessor === '') {
        return $this->json->create(['error' => 'X-Accessor header required'], 403);
    }
    $this->repo->logAccess($id, $accessor, $this->now());
    return $this->json->create($customer);        // PII bruto
}

return $this->json->create($this->masker->applyMask($customer));  // mascarado
```

## Log de Auditoria

Cada desmascaramento admin escreve em `mask_audit_log`. O log de auditoria não tem rota DELETE ou UPDATE — é append-only por design.

```php
public function logAccess(int $customerId, string $accessor, string $now): void
{
    $stmt = $this->pdo->prepare(
        'INSERT INTO mask_audit_log (customer_id, accessor, accessed_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$customerId, $accessor, $now]);
}
```

## Propriedades de Segurança

- **Mascarado por padrão**: todas as respostas GET mascaram PII a menos que `X-Role: admin` esteja presente.
- **Accessor obrigatório**: desmascaramento admin requer `X-Accessor`; 403 se ausente — sem acesso admin anônimo.
- **Auditoria imutável**: nenhuma rota deleta ou atualiza entradas de auditoria.
- **Armazenamento parametrizado**: PII é armazenado via prepared statements — tentativas de injeção SQL são armazenadas como literais.
- **Precisão de papel**: apenas o valor exato `admin` concede desmascaramento; `ADMIN`, `superuser`, etc. são tratados como usuários regulares.
