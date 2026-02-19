# Streaming Collections — beyondautomation/fractal

## The problem this solves

`Scope::toArray()` builds the **entire** nested result structure in memory before
returning.  With deep `defaultIncludes` chains on large collections PHP exhausts
its memory limit because all entities and their rendered arrays accumulate
simultaneously before a single byte is written.

Example chain that exposed this:

```
ClientUser (100×)
  → profile (defaultInclude)
    → country + nationality (defaultIncludes)
      → translations (defaultInclude, ~200 rows each)
```

That's ~40 000 `CountryTranslation` objects all alive at once — before `json_encode`
is even called.

## The fix — `Scope::toStream(callable $callback)`

The new `toStream()` method transforms **one item at a time**, calls your callback
with the fully-serialised item array, then unsets the item before moving to the
next one.  `toArray()` is untouched; this is 100 % additive.

---

## Usage

### Streaming a JSON array response (recommended)

```php
use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Serializer\ArraySerializer;

$manager = new Manager();
$manager->setSerializer(new ArraySerializer());

$scope = $manager->createData(new Collection($users, new ClientUserTransformer()));

header('Content-Type: application/json');
echo '[';
$first = true;
$scope->toStream(function (array $item) use (&$first): void {
    echo ($first ? '' : ',') . json_encode($item);
    $first = false;
    ob_flush();
    flush();
});
echo ']';
```

### Collecting into an array (backward-compatible, useful for tests)

```php
$items = [];
$scope->toStream(function (array $item) use (&$items): void {
    $items[] = $item;
});
// $items now contains the same data as $scope->toArray()['data']
```

### Laravel / Symfony streaming response

```php
// Laravel
return response()->stream(function () use ($scope) {
    echo '[';
    $first = true;
    $scope->toStream(function (array $item) use (&$first) {
        echo ($first ? '' : ',') . json_encode($item);
        $first = false;
        ob_flush(); flush();
    });
    echo ']';
}, 200, ['Content-Type' => 'application/json']);
```

---

## Behaviour notes

| Scenario | Behaviour |
|---|---|
| Resource is a `Collection` | Items are transformed and yielded one by one |
| Resource is an `Item` or `NullResource` | `toArray()` is called once and the result is passed to `$callback` |
| Serialiser has `sideloadIncludes()` (e.g. JSON:API) | Side-loaded includes are merged per-item — note that deduplication across the full collection is **not** performed in streaming mode |
| `defaultIncludes` / `availableIncludes` | Fully supported — they run per-item exactly as in `toArray()` |
| Pagination / cursor metadata | Not emitted by `toStream()` — add it yourself around the loop if needed |

---

## Migration

No existing code needs to change.  Only the handful of list endpoints hitting
memory limits need to switch from:

```php
// Before
$data = $fractal->createData($resource)->toArray();
return response()->json($data);

// After — streaming
return response()->stream(function () use ($fractal, $resource) {
    echo '[';
    $first = true;
    $fractal->createData($resource)->toStream(function (array $item) use (&$first) {
        echo ($first ? '' : ',') . json_encode($item);
        $first = false;
        ob_flush(); flush();
    });
    echo ']';
}, 200, ['Content-Type' => 'application/json']);
```

---

## Installing the fork

In your project's `composer.json`:

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

The package declares `"replace": {"league/fractal": "*"}`, so Composer will
satisfy any existing `league/fractal` constraint in your dependencies without
modification.
