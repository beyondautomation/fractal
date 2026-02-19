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
 * Tests for Scope::toStream() — the memory-efficient streaming path.
 *
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

    /** A trivial transformer with no includes. */
    private function simpleTransformer(): TransformerAbstract
    {
        return new class extends TransformerAbstract {
            public function transform(array $item): array
            {
                return ['id' => $item['id'], 'name' => $item['name']];
            }
        };
    }

    /** A transformer with a defaultInclude (simulates deep nesting). */
    private function transformerWithDefaultInclude(): TransformerAbstract
    {
        return new class extends TransformerAbstract {
            protected array $defaultIncludes = ['meta'];

            public function transform(array $item): array
            {
                return ['id' => $item['id']];
            }

            public function includeMeta(array $item): Item
            {
                return new Item(['value' => $item['id'] * 10], function (array $d): array {
                    return $d;
                });
            }
        };
    }

    // -----------------------------------------------------------------------
    // Tests
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

        // Collect via toStream
        $streamed = [];
        $scope->toStream(function (array $item) use (&$streamed): void {
            $streamed[] = $item;
        });

        // Collect via toArray
        $fromArray = $scope->toArray()['data'];

        $this->assertSame($fromArray, $streamed);
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

    public function testStreamWorksWithDefaultIncludes(): void
    {
        $data = [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ];

        $manager  = $this->makeManager();
        $resource = new Collection($data, $this->transformerWithDefaultInclude());
        $scope    = $manager->createData($resource);

        $streamed = [];
        $scope->toStream(function (array $item) use (&$streamed): void {
            $streamed[] = $item;
        });

        // Each item should have the nested 'meta' include merged in.
        $this->assertArrayHasKey('meta', $streamed[0]);
        $this->assertSame(['value' => 10], $streamed[0]['meta']);
        $this->assertSame(['value' => 20], $streamed[1]['meta']);
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

    public function testStreamItemsAreIndependent(): void
    {
        // Ensures that items processed in one iteration don't bleed into the next
        $data = [
            ['id' => 1, 'name' => 'Alpha'],
            ['id' => 2, 'name' => 'Beta'],
        ];

        $manager  = $this->makeManager();
        $resource = new Collection($data, $this->simpleTransformer());
        $scope    = $manager->createData($resource);

        $items = [];
        $scope->toStream(function (array $item) use (&$items): void {
            $items[] = $item;
        });

        $this->assertSame(['id' => 1, 'name' => 'Alpha'], $items[0]);
        $this->assertSame(['id' => 2, 'name' => 'Beta'],  $items[1]);
    }
}
