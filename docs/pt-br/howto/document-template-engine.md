# Como Fazer: Motor de Templates de Documento

Demonstra CRUD de templates com substituição de `{{variável}}` e escrita protegida por chave de admin.
Field trial: FT197 (`../NENE2-FT/templatelog/`).

## Resumo do padrão
- Constraint `UNIQUE(name)` em templates → 409 em duplicatas
- Endpoint de listagem exclui `body` para reduzir payload
- `POST /templates/{id}/render` aceita objeto `vars`, substitui placeholders `{{chave}}`
- Variáveis desconhecidas são mantidas como estão (sem erro)
- Chave de admin controla criação/atualização/deleção; renderização é pública
