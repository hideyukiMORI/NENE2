# Por que injeção de dependência explícita?

NENE2 usa injeção de dependências explícita e escrita à mão em vez de autowiring ou magia de container baseada em convenções. Esta página explica o porquê.

## O que significa injeção explícita

```php
// RuntimeServiceProvider.php — cada dependência é escrita explicitamente
$container->bind(NoteRepositoryInterface::class, function (ContainerInterface $c) {
    return new PdoNoteRepository($c->get(DatabaseQueryExecutorInterface::class));
});
```

## As razões para injeção explícita

### 1. O wiring é sempre encontrável

Com autowiring, responder "como esta classe é construída?" requer entender as regras de resolução do container. Com injeção explícita, `grep -r 'NoteRepository'` nos arquivos de service provider dá a resposta completa.

### 2. Erros falham na inicialização, não em runtime

Um binding explícito que referencia uma classe inexistente falha quando o container é construído.

### 3. Agentes de IA e análise estática podem seguir o grafo

A injeção explícita produz um grafo de dependências que grep, PHPStan e agentes LLM podem percorrer sem executar o container.

### 4. Sem acoplamento por anotações ou atributos

As classes de domínio da NENE2 não carregam anotações de container.

## Compromissos

| Injeção explícita | Autowiring |
|-----------------|------------|
| Sempre legível | Menos boilerplate |
| Falha rápida na inicialização | Conveniente para scaffolding rápido |
| Sem magia | Requer aprender as regras do container |
| Verboso para muitas classes | Escala automaticamente |
