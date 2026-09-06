<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\StockControl;

/**
 * StockControl::groupCapacity() — folding per-hypervisor fits into one group number.
 *
 * Two production incidents are pinned here:
 *
 *  1. capFor() returns PHP_INT_MAX for an unlimited quota (max = 0), and cpuCores.max = 0
 *     is ordinary in the wild. A hypervisor unlimited on memory, CPU *and* storage made
 *     the running total overflow to float, which this int-typed method cannot return —
 *     PHP threw a TypeError that recalculateForProduct() swallowed, leaving the product's
 *     qty silently unmanaged from then on.
 *  2. IPv4 is a group-wide pool reported identically on every hypervisor, so summing it
 *     would multiply the pool by the node count.
 */
#[CoversClass(StockControl::class)]
final class GroupCapacityTest extends TestCase
{
    private const SSD = 1;

    /** @return array{0:int,1:array} The group capacity and the per-hypervisor breakdown. */
    private function capacity(array $response, array $package, int $ipv4Required = 1, float $buffer = 0.0): array
    {
        $breakdown = [];
        $total = $this->callRef(
            $this->bare(StockControl::class),
            'groupCapacity',
            [$response, $package, $ipv4Required, $buffer],
            $breakdown,
        );

        return [$total, $breakdown];
    }

    private function package(int $memory = 2048, int $cpu = 2, int $storage = 40, int $profile = self::SSD): array
    {
        return ['memory' => $memory, 'cpuCores' => $cpu, 'primaryStorage' => $storage, 'primaryStorageProfile' => $profile];
    }

    /** A hypervisor with no quota on anything — the shape that used to overflow. */
    private function unlimitedNode(int $id, ?int $ipv4 = null): array
    {
        $resources = [
            'memory' => ['max' => 0, 'free' => 0],
            'cpuCores' => ['max' => 0, 'free' => 0],
            'localStorage' => ['enabled' => 1, 'storageType' => self::SSD, 'max' => 0, 'free' => -5],
            'otherStorage' => [],
        ];

        if ($ipv4 !== null) {
            $resources['network'] = ['total' => ['ipv4' => ['free' => $ipv4]]];
        }

        return [
            'hypervisor' => ['id' => $id, 'name' => "zfs-$id", 'enabled' => true, 'commissioned' => true, 'prohibit' => false],
            'resources' => $resources,
        ];
    }

    private function node(int $id, int $memFree, int $storageFree, int $ipv4 = 96, array $overrides = []): array
    {
        return [
            // array_merge, not `+`: the union operator keeps the left-hand key and would
            // silently discard every override.
            'hypervisor' => array_merge(
                ['id' => $id, 'name' => "hv-$id", 'enabled' => true, 'commissioned' => true, 'prohibit' => false],
                $overrides,
            ),
            'resources' => [
                'memory' => ['max' => 100000, 'free' => $memFree],
                'cpuCores' => ['max' => 64, 'free' => 64],
                'localStorage' => ['enabled' => 1, 'storageType' => self::SSD, 'max' => 10000, 'free' => $storageFree],
                'otherStorage' => [],
                'network' => ['total' => ['ipv4' => ['free' => $ipv4]]],
            ],
        ];
    }

    #[Test]
    public function unlimited_hypervisors_do_not_overflow_into_a_type_error(): void
    {
        // Before the saturation guard this threw:
        //   "groupCapacity(): Return value must be of type int, float returned"
        [$total] = $this->capacity(
            ['data' => [$this->unlimitedNode(1), $this->unlimitedNode(2)]],
            $this->package(),
        );

        $this->assertSame(PHP_INT_MAX, $total, 'an unbounded group reports the sentinel, not a float');
    }

    #[Test]
    public function an_unlimited_hypervisor_is_still_capped_by_the_ipv4_pool(): void
    {
        // Regression guard: clamping unlimited fits to zero (rather than saturating)
        // would return 0 here and take a healthy thin-provisioned node out of stock.
        [$total] = $this->capacity(
            ['data' => [$this->unlimitedNode(1, ipv4: 96), $this->unlimitedNode(2, ipv4: 96)]],
            $this->package(),
        );

        $this->assertSame(96, $total);
    }

    #[Test]
    public function the_ipv4_pool_is_taken_as_the_group_maximum_not_summed(): void
    {
        // Both nodes report the same group-wide pool of 3 addresses. Summing would say 6.
        [$total] = $this->capacity(
            ['data' => [$this->node(1, 100000, 10000, ipv4: 3), $this->node(2, 100000, 10000, ipv4: 3)]],
            $this->package(),
        );

        $this->assertSame(3, $total);
    }

    #[Test]
    public function each_requested_ipv4_address_divides_the_pool(): void
    {
        [$total] = $this->capacity(
            ['data' => [$this->node(1, 100000, 10000, ipv4: 9)]],
            $this->package(),
            ipv4Required: 3,
        );

        $this->assertSame(3, $total);
    }

    #[Test]
    public function an_exhausted_ipv4_pool_zeroes_the_group(): void
    {
        [$total] = $this->capacity(['data' => [$this->node(1, 100000, 10000, ipv4: 0)]], $this->package());

        $this->assertSame(0, $total);
    }

    #[Test]
    public function ineligible_hypervisors_contribute_nothing_and_say_why(): void
    {
        $response = ['data' => [
            $this->node(1, 100000, 10000, overrides: ['enabled' => false]),
            $this->node(2, 100000, 10000, overrides: ['commissioned' => false]),
            $this->node(3, 100000, 10000, overrides: ['prohibit' => true]),
        ]];

        [$total, $breakdown] = $this->capacity($response, $this->package());

        $this->assertSame(0, $total);
        $this->assertCount(3, $breakdown);
        foreach ($breakdown as $entry) {
            $this->assertIsString($entry, 'a skipped hypervisor is recorded with its reason');
            $this->assertStringContainsString('skipped', $entry);
        }
    }

    #[Test]
    public function an_empty_group_has_no_capacity(): void
    {
        [$total] = $this->capacity(['data' => []], $this->package());

        $this->assertSame(0, $total);
    }

    #[Test]
    public function the_breakdown_names_the_binding_axis(): void
    {
        // Storage is plentiful, memory is nearly gone: memory must be what limits the node.
        [$total, $breakdown] = $this->capacity(
            ['data' => [$this->node(7, 4096, 10000)]],
            $this->package(),
        );

        $this->assertSame(2, $total);
        $this->assertSame(
            ['memory' => 2, 'cpu' => 32, 'storage' => 250, 'ipv4Free' => 96, 'fits' => 2],
            $breakdown['7:hv-7'],
        );
    }

    #[Test]
    public function the_breakdown_reports_unlimited_axes_as_words_not_integers(): void
    {
        [, $breakdown] = $this->capacity(['data' => [$this->unlimitedNode(4, ipv4: 96)]], $this->package());

        $this->assertSame('unlimited', $breakdown['4:zfs-4']['cpu']);
        $this->assertSame('unlimited', $breakdown['4:zfs-4']['memory']);
        $this->assertSame('unlimited', $breakdown['4:zfs-4']['fits']);
    }

    /**
     * The live capture. Both nodes sit above 96% memory, so with the default 10% safety
     * buffer — which reserves a share of each resource's `max`, not of what is free —
     * memory is the binding axis and the correct answer is zero. Drop the buffer and the
     * true figure appears. This is the behaviour operators mistake for the storage bug.
     */
    #[Test]
    public function the_live_capture_is_memory_bound_at_the_default_buffer(): void
    {
        $capture = json_decode(file_get_contents(__DIR__ . '/Fixtures/group-resources-mirrored-storage-types.json'), true);

        foreach ([1, 2] as $storageProfile) {
            $package = $this->package(profile: $storageProfile);

            [$buffered] = $this->capacity($capture, $package, buffer: 10.0);
            [$unbuffered] = $this->capacity($capture, $package, buffer: 0.0);

            $this->assertSame(0, $buffered, "profile $storageProfile is memory-bound at a 10% buffer");
            $this->assertSame(2, $unbuffered, "profile $storageProfile has real headroom at a 0% buffer");
        }
    }
}
