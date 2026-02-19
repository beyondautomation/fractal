# Streaming Collections — beyondautomation/fractal

## The problem

`Scope::toArray()` builds the **entire** nested result structure in memory before
returning.  With deep `defaultIncludes` chains on large collections PHP exhausts its
memory limit because all entities and their rendered arrays accumulate simultaneously.

The OOM crash has **two interacting causes**:

1. The top-level controller calls `->toArray()` on the root `Collection` scope, which
   calls `executeResourceTransformers()` and accumulates every transformed item before
   returning.

2. Fractal's own include pipeline (`TransformerAbstract::includeResourceIfAvailable`)
   calls `$childScope->toArray()` for every nested include — even when that include is
   itself a large `Collection`.  This means the fix must reach inside the library, not
   just the controller.

Stack trace that exposed cause #2:

```
SerializerAbstract::mergeIncludes          ← OOM here, merging huge include arrays
Scope::fireTransformer                     ← processing nested Collection include
Scope::executeResourceTransformers
Scope::toArray                             ← called by TransformerAbstract for child scope
TransformerAbstract::includeResourceIfAvailable
TransformerAbstract::processIncludedResources
Scope::fireIncludedTransformers
Scope::fireTransformer
Scope::executeResourceTransformers
Scope::toArray                             ← top-level controller call
ClientUser::getUserList                    ← controller
```

## The fix — two-part, fully internal

### Part 1: `Scope::toStreamedArray()`

A new method that produces **identical output** to `toArray()` but transforms
Collection items one at a time, `unset()`-ing each before moving to the next.
Peak memory is O(1 item) instead of O(N items).

### Part 2: `TransformerAbstract::includeResourceIfAvailable()` patched

The single line `$childScope->toArray()` is replaced with
`$childScope->toStreamedArray()`.  Because `toStreamedArray()` delegates to
`toArray()` for non-Collection resources, this change is transparent for
`Item`, `NullResource` and `Primitive` includes — it only activates streaming
for `Collection` includes, which is exactly where the memory accumulates.

This means **streaming propagates automatically through every level of nesting**
with no changes required in your transformers.

---

## Migration — what you need to change in your application

Only the **controller** needs updating.  Switch from `toArray()` to `toStream()`:

```php
// BEFORE — OOM on large collections
$scope = $manager->createData(new Collection($users, new ClientUserTransformer()));
$data  = $scope->toArray();              // explodes at 128 MB
return $response->withJson($data);

// AFTER — streaming JSON response
$scope = $manager->createData(new Collection($users, new ClientUserTransformer()));

$body = $response->getBody();
$body->write('[');
$first = true;
$scope->toStream(function (array $item) use ($body, &$first): void {
    $body->write(($first ? '' : ',') . json_encode($item));
    $first = false;
});
$body->write(']');

return $response->withHeader('Content-Type', 'application/json');
```

If your client expects the standard `{ "data": [...] }` envelope, wrap it:

```php
$body->write('{"data":[');
$first = true;
$scope->toStream(function (array $item) use ($body, &$first): void {
    $body->write(($first ? '' : ',') . json_encode($item));
    $first = false;
});
$body->write(']}');
```

### If you can't change the response format yet

You can still benefit from the memory reduction without streaming the HTTP
response by collecting via `toStreamedArray()`:

```php
// Memory-safe equivalent of ->toArray() — same return value, lower peak memory
$data = $scope->toStreamedArray();
return $response->withJson($data);
```

This alone fixes the OOM because the nested include pipeline now uses
`toStreamedArray()` internally at every level — so even though the final array
is assembled in memory, the deep `CountryTranslation` objects are freed
between items instead of all held simultaneously.

---

## Behaviour notes

| Scenario | `toArray()` | `toStreamedArray()` | `toStream()` |
|---|---|---|---|
| `Collection` resource | All items in memory at once | Items freed between iterations | Items streamed to callback |
| `Item` / `NullResource` | Normal | Delegates to `toArray()` | Calls callback once |
| Nested `Collection` includes | All sub-items in memory | Sub-items freed between iterations ✓ | Sub-items freed ✓ |
| Pagination / cursor / meta | ✓ | ✓ | Not emitted — add manually |
| Side-loading serialisers (JSON:API) | ✓ | ✓ | Per-item side-load |
| Backward compatibility | — | 100% identical output | New API |

---

## Installing the fork

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/beyondautomation/fractal"
        }
    ],
    "require": {
        "beyondautomation/fractal": "^0.20"
    }
}
```

The package declares `"replace": {"league/fractal": "*"}` so Composer will satisfy
any existing `league/fractal` constraint in your dependencies without modification.
