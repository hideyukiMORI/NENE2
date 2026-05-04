# View Rendering Policy

NENE2 is API first. HTML output exists, but it should stay thin and optional.

## Position

Do not make a full template engine a default framework dependency.

The standard NENE2 approach is:

- JSON APIs are the primary interface.
- Rich UI belongs in optional React/Vue frontends.
- Server HTML is for thin pages, SPA shells, API docs, small error pages, and simple operational screens.
- Native PHP templates and static HTML are enough for the first standard path.
- Template engines such as Twig can be added later as adapters.

## Standard Directories

```text
src/View/          # View rendering interfaces and adapters
templates/         # Native PHP templates and thin server HTML
public_html/       # Public document root and built/static public assets
frontend/          # React/Vue/Vite source
```

## Boundaries

`src/View/` is for framework view abstractions:

- renderer interfaces
- native PHP renderer
- escaping helpers
- future adapter contracts

`templates/` is for source templates that are not directly public. Templates may render HTML, but they must not become a place for business logic.

`public_html/` is for files safe to serve directly:

- front controller
- Swagger UI
- built frontend assets
- static public files

## Template Engine Policy

Template engines are optional integrations, not part of the initial core.

If a template engine is introduced later:

- add it through a focused Issue
- expose it behind a small `ViewRenderer` style interface
- keep native PHP templates available
- document escaping rules and test the adapter
- avoid coupling controllers or use cases to the engine

## Escaping

HTML rendering must escape output by default. Native PHP templates should use an explicit helper rather than echoing untrusted values directly.

The first renderer implementation should define a small escape function or helper before rendering user-provided data.

## Testing

View tests should focus on behavior:

- rendered status code and content type
- template variables passed correctly
- escaping behavior for untrusted input
- integration with response objects

Do not snapshot large HTML output unless it protects an important contract.
