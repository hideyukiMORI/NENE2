# Why Explicit Dependency Wiring?

NENE2 uses explicit, hand-written dependency wiring rather than autowiring or convention-based container magic. This page explains why.

## What explicit wiring means

```php
// RuntimeServiceProvider.php — every dependency is written out
$container->bind(NoteRepositoryInterface::class, function (ContainerInterface $c) {
    return new PdoNoteRepository($c->get(DatabaseQueryExecutorInterface::class));
});
```

Compare with autowiring, where the container inspects constructor type hints and resolves them automatically.

## The case for explicit wiring

### 1. The wiring is always findable

With autowiring, "how is this class constructed?" requires understanding the container's resolution rules, which interfaces are bound, and whether any decorators are applied. With explicit wiring, `grep -r 'NoteRepository'` in the service provider files shows the complete answer.

### 2. Mistakes fail at startup, not at runtime

An explicit binding that references a missing class fails when the container is built. An autowiring mistake may only surface when a specific code path is exercised in production.

### 3. AI agents and static analysis can follow the graph

Explicit wiring produces a dependency graph that grep, PHPStan, and LLM agents can traverse without running the container. This matters for a framework designed to support AI-assisted delivery.

### 4. No annotation or attribute coupling

Some DI containers use `#[Inject]` attributes or `@inject` docblock annotations to guide resolution. This couples domain classes to the container library. NENE2 domain classes carry no container annotations.

## The trade-offs

| Explicit wiring | Autowiring |
|-----------------|------------|
| Always readable | Less boilerplate |
| Fails fast at startup | Convenient for rapid scaffolding |
| No magic | Requires learning container rules |
| Verbose for large class counts | Scales automatically |

NENE2 targets small, focused API projects where the explicit binding count stays manageable. If a project outgrows a hand-written service provider, that is the signal to evaluate whether NENE2 remains the right foundation — not to add autowiring.

## What this means in practice

Service providers are grouped by concern (`RuntimeServiceProvider`, `DatabaseServiceProvider`). Each provider registers a small set of related services. The provider files are the authoritative record of what the container knows about.

See `docs/development/endpoint-scaffold.md` for the step-by-step pattern when adding a new endpoint.
