<?php

/*
 * This file is part of the League\Fractal package.
 *
 * (c) Phil Sturgeon <me@philsturgeon.uk>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 **/

namespace League\Fractal;

use InvalidArgumentException;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\Primitive;
use League\Fractal\Resource\NullResource;
use League\Fractal\Resource\ResourceInterface;
use League\Fractal\Serializer\Serializer;

/**
 * Scope
 *
 * The scope class acts as a tracker, relating a specific resource in a specific
 * context. For example, the same resource could be attached to multiple scopes.
 * There are root scopes, parent scopes and child scopes.
 */
class Scope implements \JsonSerializable
{
    protected array $availableIncludes = [];

    protected ?string $scopeIdentifier;

    protected Manager $manager;

    protected ResourceInterface $resource;

    protected array $parentScopes = [];

    public function __construct(Manager $manager, ResourceInterface $resource, ?string $scopeIdentifier = null)
    {
        $this->manager = $manager;
        $this->resource = $resource;
        $this->scopeIdentifier = $scopeIdentifier;
    }

    /**
     * Embed a scope as a child of the current scope.
     *
     * @internal
     */
    public function embedChildScope(string $scopeIdentifier, ResourceInterface $resource): Scope
    {
        return $this->manager->createData($resource, $scopeIdentifier, $this);
    }

    /**
     * Get the current identifier.
     */
    public function getScopeIdentifier(): ?string
    {
        return $this->scopeIdentifier;
    }

    /**
     * Get the unique identifier for this scope.
     */
    public function getIdentifier(?string $appendIdentifier = null): string
    {
        $identifierParts = array_merge($this->parentScopes, [$this->scopeIdentifier, $appendIdentifier]);

        return implode('.', array_filter($identifierParts));
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function getParentScopes()
    {
        return $this->parentScopes;
    }

    public function getResource(): ResourceInterface
    {
        return $this->resource;
    }

    public function getManager(): Manager
    {
        return $this->manager;
    }

    /**
     * Check if - in relation to the current scope - this specific segment is allowed.
     * That means, if a.b.c is requested and the current scope is a.b, then c is allowed. If the current
     * scope is a then c is not allowed, even if it is there and potentially transformable.
     *
     * @internal
     *
     * @return bool Returns the new number of elements in the array.
     */
    public function isRequested(string $checkScopeSegment): bool
    {
        if ($this->parentScopes) {
            $scopeArray = array_slice($this->parentScopes, 1);
            array_push($scopeArray, $this->scopeIdentifier, $checkScopeSegment);
        } else {
            $scopeArray = [$checkScopeSegment];
        }

        $scopeString = implode('.', $scopeArray);

        return in_array($scopeString, $this->manager->getRequestedIncludes());
    }

    /**
     * Check if - in relation to the current scope - this specific segment should
     * be excluded. That means, if a.b.c is excluded and the current scope is a.b,
     * then c will not be allowed in the transformation whether it appears in
     * the list of default or available, requested includes.
     *
     * @internal
     */
    public function isExcluded(string $checkScopeSegment): bool
    {
        if ($this->parentScopes) {
            $scopeArray = array_slice($this->parentScopes, 1);
            array_push($scopeArray, $this->scopeIdentifier, $checkScopeSegment);
        } else {
            $scopeArray = [$checkScopeSegment];
        }

        $scopeString = implode('.', $scopeArray);

        return in_array($scopeString, $this->manager->getRequestedExcludes());
    }

    /**
     * Push Parent Scope.
     *
     * Push a scope identifier into parentScopes
     *
     * @internal
     *
     * @return int Returns the new number of elements in the array.
     */
    public function pushParentScope(string $identifierSegment): int
    {
        return array_push($this->parentScopes, $identifierSegment);
    }

    /**
     * Set parent scopes.
     *
     * @internal
     *
     * @param string[] $parentScopes Value to set.
     */
    public function setParentScopes(array $parentScopes): self
    {
        $this->parentScopes = $parentScopes;

        return $this;
    }

    /**
     * Convert the current data for this scope to an array.
     */
    public function toArray(): ?array
    {
        list($rawData, $rawIncludedData) = $this->executeResourceTransformers();

        $serializer = $this->manager->getSerializer();

        $data = $this->serializeResource($serializer, $rawData);

        // If the serializer wants the includes to be side-loaded then we'll
        // serialize the included data and merge it with the data.
        if ($serializer->sideloadIncludes()) {
            //Filter out any relation that wasn't requested
            $rawIncludedData = array_map([$this, 'filterFieldsets'], $rawIncludedData);

            $includedData = $serializer->includedData($this->resource, $rawIncludedData);

            // If the serializer wants to inject additional information
            // about the included resources, it can do so now.
            $data = $serializer->injectData($data, $rawIncludedData);

            if ($this->isRootScope()) {
                // If the serializer wants to have a final word about all
                // the objects that are sideloaded, it can do so now.
                $includedData = $serializer->filterIncludes(
                    $includedData,
                    $data,
                );
            }

            $data = $data + $includedData;
        }

        if (!empty($this->availableIncludes)) {
            $data = $serializer->injectAvailableIncludeData($data, $this->availableIncludes);
        }

        if ($this->resource instanceof Collection) {
            if ($this->resource->hasCursor()) {
                $pagination = $serializer->cursor($this->resource->getCursor());
            } elseif ($this->resource->hasPaginator()) {
                $pagination = $serializer->paginator($this->resource->getPaginator());
            }

            if (! empty($pagination)) {
                $this->resource->setMetaValue(key($pagination), current($pagination));
            }
        }

        // Pull out all of OUR metadata and any custom meta data to merge with the main level data
        $meta = $serializer->meta($this->resource->getMeta());

        // in case of returning NullResource we should return null and not to go with array_merge
        if (is_null($data)) {
            if (!empty($meta)) {
                return $meta;
            }
            return null;
        }

        return $data + $meta;
    }

    /**
     * Memory-efficient equivalent of toArray() for Collection resources.
     *
     * Produces an identical return value to toArray() but transforms items
     * one at a time and unsets each one before processing the next.  This
     * keeps peak memory proportional to a single item's include chain rather
     * than the entire collection.
     *
     * Called automatically by TransformerAbstract when resolving nested
     * Collection includes, so the streaming behaviour propagates through all
     * levels of nesting without any changes to transformer or controller code
     * (other than the top-level toStream() call).
     *
     * Non-Collection resources delegate directly to toArray().
     *
     * @internal  Use toStream() at the top level; this is called internally
     *            by the include pipeline.
     */
    public function toStreamedArray(): ?array
    {
        if (!$this->resource instanceof Collection) {
            // Item / NullResource — nothing to stream, delegate normally.
            return $this->toArray();
        }

        $transformer = $this->resource->getTransformer();
        $serializer  = $this->manager->getSerializer();

        $transformedItems = [];

        foreach ($this->resource->getData() as $value) {
            [$transformedData, $includedData] = $this->fireTransformer($transformer, $value);

            if ($serializer->sideloadIncludes()) {
                // Side-loading (e.g. JSON:API) — merge includes into item and collect.
                $filteredIncludes  = array_map([$this, 'filterFieldsets'], [$includedData]);
                $includedResult    = $serializer->includedData($this->resource, $filteredIncludes);
                $itemArray         = $serializer->item($this->resource->getResourceKey() ?: null, $transformedData);
                $itemArray         = $serializer->injectData($itemArray, $filteredIncludes);
                $itemArray         = $itemArray + $includedResult;
                $transformedItems[] = $itemArray;
            } else {
                $transformedItems[] = $transformedData;
            }

            // Release per-item memory before processing the next item.
            unset($value, $transformedData, $includedData);
        }

        // Now finish the same way toArray() does: serialize the full collection,
        // handle pagination/cursors, meta, etc.
        $data = $this->serializeResource($serializer, $transformedItems);
        unset($transformedItems);

        if (!empty($this->availableIncludes)) {
            $data = $serializer->injectAvailableIncludeData($data, $this->availableIncludes);
        }

        if ($this->resource->hasCursor()) {
            $pagination = $serializer->cursor($this->resource->getCursor());
        } elseif ($this->resource->hasPaginator()) {
            $pagination = $serializer->paginator($this->resource->getPaginator());
        }

        if (!empty($pagination)) {
            $this->resource->setMetaValue(key($pagination), current($pagination));
        }

        $meta = $serializer->meta($this->resource->getMeta());

        if (is_null($data)) {
            return !empty($meta) ? $meta : null;
        }

        return $data + $meta;
    }

    /**
     * Stream a Collection resource one item at a time to avoid memory exhaustion.
     *
     * The callback receives a single fully-transformed item array on each invocation.
     * After the callback returns the item is freed from memory before the next one
     * is processed — which is the key difference from toArray().
     *
     * Usage in a controller (streaming JSON response):
     *
     *   $scope = $manager->createData(new Collection($users, new UserTransformer));
     *
     *   header('Content-Type: application/json');
     *   echo '[';
     *   $first = true;
     *   $scope->toStream(function (array $item) use (&$first) {
     *       echo ($first ? '' : ',') . json_encode($item);
     *       $first = false;
     *       ob_flush(); flush();
     *   });
     *   echo ']';
     *
     * Or collect results (same peak memory as toArray() — useful for tests):
     *
     *   $items = [];
     *   $scope->toStream(function (array $item) use (&$items) { $items[] = $item; });
     *
     * Non-Collection resources fall back to calling $callback once with the full toArray() result.
     *
     * @param callable(array $item): void $callback
     */
    public function toStream(callable $callback): void
    {
        if (!$this->resource instanceof Collection) {
            // For Item / NullResource there is nothing to stream — just call back once.
            $result = $this->toArray();
            if ($result !== null) {
                $callback($result);
            }
            return;
        }

        $transformer = $this->resource->getTransformer();
        $serializer  = $this->manager->getSerializer();

        foreach ($this->resource->getData() as $value) {
            // Transform the single item (including all nested defaultIncludes).
            [$transformedData, $includedData] = $this->fireTransformer($transformer, $value);

            // Serialise to an item-shaped array.
            $resourceKey = $this->resource->getResourceKey();
            $itemArray   = $serializer->item($resourceKey ?: null, $transformedData);

            // Side-load includes if the serialiser requires it (e.g. JSON API).
            if ($serializer->sideloadIncludes()) {
                $filteredIncludes = array_map([$this, 'filterFieldsets'], [$includedData]);
                $includedResult   = $serializer->includedData($this->resource, $filteredIncludes);
                $itemArray        = $serializer->injectData($itemArray, $filteredIncludes);
                $itemArray        = $itemArray + $includedResult;
            }

            // Deliver to the caller.
            $callback($itemArray);

            // Explicitly free memory before the next iteration.
            unset($value, $transformedData, $includedData, $itemArray);
        }
    }

    /**
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * Convert the current data for this scope to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return \json_encode($this, $options);
    }

    /**
     * Transformer a primitive resource
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function transformPrimitiveResource()
    {
        if (! ($this->resource instanceof Primitive)) {
            throw new InvalidArgumentException(
                'Argument $resource should be an instance of League\Fractal\Resource\Primitive',
            );
        }

        $transformer = $this->resource->getTransformer();
        $data = $this->resource->getData();

        $this->setScope($transformer);
        try {
            if (null === $transformer) {
                $transformedData = $data;
            } elseif (is_callable($transformer)) {
                $transformedData = call_user_func($transformer, $data);
            } else {
                $transformedData = $transformer->transform($data);
            }
        } finally {
            $this->unsetScope($transformer);
        }

        return $transformedData;
    }

    /**
     * Execute the resources transformer and return the data and included data.
     *
     * @internal
     */
    protected function executeResourceTransformers(): array
    {
        $transformer = $this->resource->getTransformer();
        $data = $this->resource->getData();

        $transformedData = $includedData = [];

        if ($this->resource instanceof Item) {
            list($transformedData, $includedData[]) = $this->fireTransformer($transformer, $data);
        } elseif ($this->resource instanceof Collection) {
            foreach ($data as $value) {
                list($transformedData[], $includedData[]) = $this->fireTransformer($transformer, $value);
            }
        } elseif ($this->resource instanceof NullResource) {
            $transformedData = null;
            $includedData = [];
        } else {
            throw new InvalidArgumentException(
                'Argument $resource should be an instance of League\Fractal\Resource\Item'
                . ' or League\Fractal\Resource\Collection',
            );
        }

        return [$transformedData, $includedData];
    }

    /**
     * Serialize a resource
     *
     * @internal
     *
     * @param mixed $data
     */
    protected function serializeResource(Serializer $serializer, $data): ?array
    {
        $resourceKey = $this->resource->getResourceKey();

        if ($this->resource instanceof Collection) {
            if (!empty($resourceKey)) {
                return $serializer->collection($resourceKey, $data);
            }

            return $serializer->collection(null, $data);
        }

        if ($this->resource instanceof Item) {
            // this is where it breaks now.
            if (!empty($resourceKey)) {
                return $serializer->item($resourceKey, $data);
            }

            return $serializer->item(null, $data);
        }

        return $serializer->null();
    }

    /**
     * Fire the main transformer.
     *
     * @internal
     *
     * @param TransformerAbstract|callable $transformer
     * @param mixed                        $data
     */
    protected function fireTransformer($transformer, $data): array
    {
        $includedData = [];

        $this->setScope($transformer);
        try {
            if (is_callable($transformer)) {
                $transformedData = call_user_func($transformer, $data);
            } else {
                $transformedData = $transformer->transform($data);
            }

            if ($this->transformerHasIncludes($transformer)) {
                $includedData = $this->fireIncludedTransformers($transformer, $data);
                $transformedData = $this->manager->getSerializer()->mergeIncludes($transformedData, $includedData);
            }

            //Stick only with requested fields
            $transformedData = $this->filterFieldsets($transformedData);
        } finally {
            $this->unsetScope($transformer);
        }
        return [$transformedData, $includedData];
    }

    /**
     * Fire the included transformers.
     *
     * @internal
     *
     * @param \League\Fractal\TransformerAbstract $transformer
     * @param mixed                               $data
     */
    protected function fireIncludedTransformers($transformer, $data): array
    {
        $this->availableIncludes = $transformer->getAvailableIncludes();

        return $transformer->processIncludedResources($this, $data) ?: [];
    }

    /**
     * Determine if a transformer has any available includes.
     *
     * @internal
     *
     * @param TransformerAbstract|callable $transformer
     */
    protected function transformerHasIncludes($transformer): bool
    {
        if (! $transformer instanceof TransformerAbstract) {
            return false;
        }

        $defaultIncludes = $transformer->getDefaultIncludes();
        $availableIncludes = $transformer->getAvailableIncludes();

        return ! empty($defaultIncludes) || ! empty($availableIncludes);
    }

    /**
     * Check, if this is the root scope.
     */
    protected function isRootScope(): bool
    {
        return empty($this->parentScopes);
    }

    /**
     * Filter the provided data with the requested filter fieldset for
     * the scope resource
     *
     * @internal
     */
    protected function filterFieldsets(array $data): array
    {
        if (!$this->hasFilterFieldset()) {
            return $data;
        }
        $serializer = $this->manager->getSerializer();
        $requestedFieldset = iterator_to_array($this->getFilterFieldset());
        //Build the array of requested fieldsets with the mandatory serializer fields
        $filterFieldset = array_flip(
            array_merge(
                $serializer->getMandatoryFields(),
                $requestedFieldset,
            ),
        );
        return array_intersect_key($data, $filterFieldset);
    }

    /**
     * Return the requested filter fieldset for the scope resource
     *
     * @internal
     */
    protected function getFilterFieldset(): ?ParamBag
    {
        return $this->manager->getFieldset($this->getResourceType());
    }

    /**
     * Check if there are requested filter fieldsets for the scope resource.
     *
     * @internal
     */
    protected function hasFilterFieldset(): bool
    {
        return $this->getFilterFieldset() !== null;
    }

    /**
     * Return the scope resource type.
     *
     * @internal
     */
    protected function getResourceType(): string
    {
        return $this->resource->getResourceKey();
    }

    protected function setScope($transformer): void
    {
        if ($transformer instanceof TransformerAbstract) {
            $transformer->setCurrentScope($this);
        }
    }

    protected function unsetScope($transformer): void
    {
        if ($transformer instanceof TransformerAbstract) {
            $transformer->unsetCurrentScope();
        }
    }
}
