<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\StockControl;

/**
 * StockControl::capForStorage() — which storage pool a package can be placed on,
 * and how many VPSes fit into it.
 *
 * The regression this file exists for: `localStorage` (the hypervisor's default
 * mountpoint) carries the same `storageType` code as every `otherStorage[]` entry,
 * but was excluded from the search whenever the package named a storage profile.
 * Every hypervisor serving its VPS storage from the default mountpoint therefore
 * reported zero capacity, and because per-hypervisor capacity is
 * min(memory, cpu, storage) that silently zeroed the whole node.
 */
#[CoversClass(StockControl::class)]
final class StorageCapacityTest extends TestCase
{
    private const SSD = 1;

    private const NVME = 2;

    /** @return int Capacity in VPS units for one hypervisor's resources block. */
    private function cap(array $resources, int $storageType, int $needGb = 40, float $buffer = 0.0): int
    {
        return $this->call(StockControl::class, 'capForStorage', [$resources, $storageType, $needGb, $buffer]);
    }

    private function pool(int $type, int $max, int $free, int $enabled = 1): array
    {
        return ['enabled' => $enabled, 'storageType' => $type, 'max' => $max, 'free' => $free];
    }

    #[Test]
    public function it_counts_storage_served_from_the_default_mountpoint(): void
    {
        // The reported bug: NVMe is this node's default mountpoint, with no extra pools.
        $node = [
            'localStorage' => $this->pool(self::NVME, 4000, 4000) + ['name' => 'Local (Default mountpoint)'],
            'otherStorage' => [],
        ];

        $this->assertSame(100, $this->cap($node, self::NVME), 'default mountpoint must be a placement candidate');
    }

    #[Test]
    public function it_still_counts_storage_served_from_an_additional_pool(): void
    {
        $node = [
            'localStorage' => $this->pool(self::SSD, 200, 200),
            'otherStorage' => [$this->pool(self::NVME, 4000, 4000) + ['id' => 7, 'name' => 'nvme']],
        ];

        $this->assertSame(100, $this->cap($node, self::NVME));
    }

    #[Test]
    public function a_hypervisor_with_no_pool_of_the_requested_type_cannot_place_the_vm(): void
    {
        $node = ['localStorage' => $this->pool(self::SSD, 4000, 4000), 'otherStorage' => []];

        $this->assertSame(0, $this->cap($node, self::NVME));
    }

    #[Test]
    public function a_disabled_pool_contributes_nothing(): void
    {
        $node = ['localStorage' => $this->pool(self::NVME, 4000, 4000, enabled: 0), 'otherStorage' => []];

        $this->assertSame(0, $this->cap($node, self::NVME));
    }

    #[Test]
    public function a_disabled_pool_does_not_veto_an_enabled_peer_of_the_same_type(): void
    {
        $node = [
            'localStorage' => $this->pool(self::NVME, 99, 99, enabled: 0),
            'otherStorage' => [$this->pool(self::NVME, 800, 800)],
        ];

        $this->assertSame(20, $this->cap($node, self::NVME));
    }

    #[Test]
    public function the_largest_fitting_pool_wins_across_local_and_additional_storage(): void
    {
        $node = [
            'localStorage' => $this->pool(self::NVME, 400, 400),
            'otherStorage' => [$this->pool(self::NVME, 4000, 4000)],
        ];

        $this->assertSame(100, $this->cap($node, self::NVME));
    }

    #[Test]
    public function a_package_naming_no_profile_falls_back_to_the_default_mountpoint(): void
    {
        $node = [
            'localStorage' => $this->pool(self::SSD, 200, 200),
            'otherStorage' => [$this->pool(self::NVME, 4000, 4000)],
        ];

        $this->assertSame(5, $this->cap($node, 0), 'profile <= 0 uses localStorage regardless of its type');
    }

    #[Test]
    public function a_package_needing_no_storage_is_unconstrained(): void
    {
        $node = ['localStorage' => $this->pool(self::NVME, 400, 400), 'otherStorage' => []];

        $this->assertSame(PHP_INT_MAX, $this->cap($node, self::NVME, needGb: 0));
    }

    #[Test]
    public function an_unlimited_quota_is_unconstrained_even_when_free_is_negative(): void
    {
        // Thin-provisioned pools report max = 0 and can run free negative; that is a
        // quota of "unlimited", not an exhausted pool.
        $node = ['localStorage' => $this->pool(self::NVME, 0, -5), 'otherStorage' => []];

        $this->assertSame(PHP_INT_MAX, $this->cap($node, self::NVME));
    }

    #[Test]
    public function the_safety_buffer_is_reserved_from_max_not_from_free(): void
    {
        $node = ['localStorage' => $this->pool(self::NVME, 4000, 4000), 'otherStorage' => []];

        // 10% of max (400 GB) held back, leaving 3600 GB for 40 GB VMs.
        $this->assertSame(90, $this->cap($node, self::NVME, buffer: 10.0));
    }

    #[Test]
    public function a_node_already_inside_its_buffer_reports_no_capacity(): void
    {
        // 10% of 4000 is 400, but only 300 is free — the node is past its headroom.
        $node = ['localStorage' => $this->pool(self::NVME, 4000, 300), 'otherStorage' => []];

        $this->assertSame(0, $this->cap($node, self::NVME, buffer: 10.0));
    }

    #[Test]
    public function a_missing_local_storage_key_is_not_fatal(): void
    {
        $node = ['otherStorage' => [$this->pool(self::NVME, 400, 400)]];

        $this->assertSame(10, $this->cap($node, self::NVME));
    }

    #[Test]
    public function malformed_pool_entries_are_skipped_rather_than_throwing(): void
    {
        $node = [
            'localStorage' => 'not-an-array',
            'otherStorage' => ['also-not-an-array', $this->pool(self::NVME, 400, 400)],
        ];

        $this->assertSame(10, $this->cap($node, self::NVME));
    }

    /**
     * The live capture that settled what `primaryStorageProfile` means: two sibling
     * nodes where the same medium is the default mountpoint on one and an additional
     * pool on the other. `storageType` tracks the medium, not the attachment.
     */
    #[Test]
    #[DataProvider('mirroredNodes')]
    public function mirrored_real_world_nodes_both_report_capacity(int $index, int $storageType, int $expected): void
    {
        $capture = json_decode(file_get_contents(__DIR__ . '/Fixtures/group-resources-mirrored-storage-types.json'), true);
        $resources = $capture['data'][$index]['resources'];

        $this->assertSame($expected, $this->cap($resources, $storageType));
    }

    public static function mirroredNodes(): array
    {
        return [
            // node 0: localStorage type 1 (1430 GB free), otherStorage NVMe type 2 (110 GB free)
            'node A, SSD from its default mountpoint' => [0, self::SSD, 35],
            'node A, NVMe from its additional pool' => [0, self::NVME, 2],
            // node 1: localStorage type 2 (450 GB free), otherStorage SSD type 1 (975 GB free)
            'node B, NVMe from its default mountpoint' => [1, self::NVME, 11],
            'node B, SSD from its additional pool' => [1, self::SSD, 24],
        ];
    }
}
