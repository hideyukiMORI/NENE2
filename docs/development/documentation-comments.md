# Documentation Comments Policy

NENE2 treats PHPDoc and TSDoc as part of the public framework contract.

## Position

Types should carry the shape of the code. Documentation comments should explain public intent, extension rules, and trade-offs that types cannot express.

The standard direction is:

- Write PHPDoc for framework public APIs and extension points.
- Write TSDoc for frontend starter public utilities and exported types.
- Avoid comments that only repeat native types.
- Keep file headers minimal.
- Use repository-level license metadata as the source of truth.

This Issue defines the policy only. Automated documentation generation can be decided later.

## PHPDoc

PHPDoc should be used for:

- public classes that are part of the framework API
- interfaces
- abstract classes
- middleware and request handlers intended for extension
- typed config objects
- value objects exposed to framework users
- exceptions that map to public behavior
- methods where parameter semantics, lifecycle, or side effects need explanation

PHPDoc is not required for every private method or obvious implementation detail.

Prefer this:

```php
/**
 * Creates immutable runtime configuration from trusted environment values.
 */
final readonly class RuntimeConfig
{
}
```

Avoid this:

```php
/**
 * Gets the name.
 *
 * @return string
 */
public function name(): string
{
}
```

Native PHP types should be preferred over PHPDoc-only types whenever possible.

## TSDoc

TSDoc should be used for:

- exported frontend helper functions
- exported React hooks
- exported TypeScript types and interfaces
- API client utilities
- shared constants that affect application behavior

TSDoc should explain how framework-maintained frontend code is intended to be used, not narrate every implementation step.

Prefer this:

```ts
/**
 * Fetches JSON from a NENE2 API endpoint and preserves typed error handling.
 */
export async function fetchJson<TResponse>(input: RequestInfo): Promise<TResponse> {
  // ...
}
```

## File Headers

NENE2 should not require large copyright or project banners at the top of every source file.

Reasons:

- large banners add noise to small framework files
- year ranges become maintenance churn
- repository metadata already carries the license
- generated diffs stay cleaner without repeated headers

The standard PHP file shape is:

```php
<?php

declare(strict_types=1);

namespace Nene2\Example;
```

The standard TypeScript file shape is:

```ts
import type { Example } from './example';
```

## License Metadata

The repository-level `LICENSE`, `composer.json`, and future `package.json` license fields are the source of truth for licensing.

SPDX file headers are allowed only when a future distribution or compliance need requires them. If adopted, they should be introduced consistently through tooling instead of manually added case by case.

Example if required later:

```php
<?php

declare(strict_types=1);

// SPDX-License-Identifier: MIT

namespace Nene2\Example;
```

SPDX headers are not required for the current foundation phase.

## AI Readability

Documentation comments should help humans and AI agents understand:

- why a boundary exists
- how framework users are expected to extend it
- whether behavior is a public contract
- what is intentionally not handled by the component

Comments should not compensate for unclear names, oversized classes, or hidden control flow.
