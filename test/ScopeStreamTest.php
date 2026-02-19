<?php

namespace League\Fractal\Test;

use League\Fractal\Manager;
use League\Fractal\Resource\Collection;
use League\Fractal\Resource\Item;
use League\Fractal\Scope;
use League\Fractal\Serializer\ArraySerializer;
use League\Fractal\TransformerAbstract;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Scope::toStreamedArray() and Scope::toStream().
 *
 * @covers \League\Fractal\Scope::toStreamedArray
 * @covers \League\Fractal\Scope::toStream
 */
class ScopeStreamTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function makeManager(): Manager
    {
        $manager = new Manager();
        $manager->setSerializer(new ArraySerializer());
        return $manager;
    }

    /** Trivial transformer — no includes. */
    private function simpleTransformer(): TransformerAbstract
    {
        return new class extends TransformerAbstract {
            public function transform(array $item): array
            {
                return ['id' => $item['id'], 'name' => $item['name']];
            }
        };
    }

    /**
     * Transformer with a defaultInclude that returns a *nested Collection*,
     * simulating the deep chain: ClientUser → account → translations.
     */
    private function transformerWithCollectionInclude(): TransformerAbstract
    {
        return new class extends TransformerAbstract {
            protected array $defaultIncludes = ['tags'];

            public function transform(array $item): array
            {
                return ['id' => $item['id']];
            }

            public function includeTags(array $item): Collection
            {
                $tags = $item['tags'] ?? [];
                return new Collection($tags, function (array $tag): array {
                    return ['label' => $tag['label']];
                });
            }
        };
    }

    // -----------------------------------------------------------------------
    // toStreamedArray tests
    // -----------------------------------------------------------------------

    public function testStreamedArrayMatchesToArrayForFlatCollection(): void
    {
        $data = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $manager  = $this->makeManager();
        $resource = new Collection($data, $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $this->assertSame($scope->toArray(), $scope->toStreamedArray());
    }

    public function testStreamedArrayMatchesToArrayWithNestedCollectionInclude(): void
    {
        $data = [
            ['id' => 1, 'tags' => [['label' => 'php'], ['label' => 'fractal']]],
            ['id' => 2, 'tags' => [['label' => 'memory']]],
        ];

        $manager  = $this->makeManager();
        $resource = new Collection($data, $this->transformerWithCollectionInclude());
        $scope    = $manager->createData($resource);

        // toStreamedArray must produce the same structure as toArray
        $this->assertSame($scope->toArray(), $scope->toStreamedArray());
    }

    public function testStreamedArrayOnItemDelegatesToToArray(): void
    {
        $manager  = $this->makeManager();
        $resource = new Item(['id' => 5, 'name' => 'Eve'], $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $this->assertSame($scope->toArray(), $scope->toStreamedArray());
    }

    public function testStreamedArrayOnEmptyCollectionReturnsEmptyDataKey(): void
    {
        $manager  = $this->makeManager();
        $resource = new Collection([], $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $result = $scope->toStreamedArray();
        $this->assertIsArray($result);
        $this->assertEmpty($result['data']);
    }

    // -----------------------------------------------------------------------
    // toStream tests
    // -----------------------------------------------------------------------

    public function testStreamYieldsSameItemsAsToArray(): void
    {
        $data = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
            ['id' => 3, 'name' => 'Carol'],
        ];

        $manager  = $this->makeManager();
        $resource = new Collection($data, $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $streamed = [];
        $scope->toStream(function (array $item) use (&$streamed): void {
            $streamed[] = $item;
        });

        $this->assertSame($scope->toArray()['data'], $streamed);
    }

    public function testStreamCallbackIsCalledOncePerItem(): void
    {
        $data = array_map(fn(int $i) => ['id' => $i, 'name' => "User $i"], range(1, 50));

        $manager  = $this->makeManager();
        $resource = new Collection($data, $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $callCount = 0;
        $scope->toStream(function (array $item) use (&$callCount): void {
            $callCount++;
        });

        $this->assertSame(50, $callCount);
    }

    public function testStreamWorksWithNestedCollectionDefaultInclude(): void
    {
        $data = [
            ['id' => 1, 'tags' => [['label' => 'php'], ['label' => 'fractal']]],
            ['id' => 2, 'tags' => [['label' => 'memory']]],
        ];

        $manager  = $this->makeManager();
        $resource = new Collection($data, $this->transformerWithCollectionInclude());
        $scope    = $manager->createData($resource);

        $streamed = [];
        $scope->toStream(function (array $item) use (&$streamed): void {
            $streamed[] = $item;
        });

        // Each item must have the nested 'tags' collection
        $this->assertArrayHasKey('tags', $streamed[0]);
        $this->assertCount(2, $streamed[0]['tags']['data']);
        $this->assertSame('php', $streamed[0]['tags']['data'][0]['label']);

        $this->assertArrayHasKey('tags', $streamed[1]);
        $this->assertCount(1, $streamed[1]['tags']['data']);
    }

    public function testStreamOnEmptyCollectionCallsCallbackZeroTimes(): void
    {
        $manager  = $this->makeManager();
        $resource = new Collection([], $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $callCount = 0;
        $scope->toStream(function (array $item) use (&$callCount): void {
            $callCount++;
        });

        $this->assertSame(0, $callCount);
    }

    public function testStreamOnItemResourceCallsCallbackOnce(): void
    {
        $manager  = $this->makeManager();
        $resource = new Item(['id' => 7, 'name' => 'Dave'], $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $calls = [];
        $scope->toStream(function (array $item) use (&$calls): void {
            $calls[] = $item;
        });

        $this->assertCount(1, $calls);
        $this->assertSame(['id' => 7, 'name' => 'Dave'], $calls[0]);
    }
}
