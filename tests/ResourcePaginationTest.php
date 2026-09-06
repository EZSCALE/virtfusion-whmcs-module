<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Module\Server\VirtFusionDirect\Module;

/**
 * Module::fetchAllResourcePages() — walking every page of a hypervisor group.
 *
 * The regression: /compute/hypervisors/groups/{id}/resources is paginated and defaults
 * to 20 hypervisors per page. The module sent no `results` parameter and read only the
 * first page, so any group larger than 20 nodes was silently truncated — the hypervisors
 * past the cut contributed nothing and stock was undercounted with no error raised.
 *
 * The paging loop is driven through fetchResourcePage(), the one method that touches the
 * network, so these tests exercise the real merge logic without a socket.
 */
#[CoversClass(Module::class)]
final class ResourcePaginationTest extends TestCase
{
    private function cp(): array
    {
        return ['url' => 'https://panel.example.test/api/v1', 'base_url' => 'https://panel.example.test', 'token' => 'test-token'];
    }

    /** One page of the paginated envelope, carrying $count synthetic hypervisors. */
    private function page(int $current, int $last, int $count, int $idOffset = 0): array
    {
        $data = [];
        for ($i = 1; $i <= $count; $i++) {
            $data[] = ['hypervisor' => ['id' => $idOffset + $i], 'resources' => []];
        }

        return ['current_page' => $current, 'last_page' => $last, 'per_page' => 200, 'total' => $count, 'data' => $data];
    }

    /**
     * A Module whose only network method is replaced by a scripted queue of responses.
     *
     * @param  array<int,array|false|null>  $pages  Keyed by page number.
     */
    private function moduleReturning(array $pages): Module
    {
        $module = new class extends Module
        {
            public array $pages = [];

            public array $requested = [];

            public function __construct() {} // skip Database::schema()

            protected function fetchResourcePage(array $cp, int $groupId, int $page)
            {
                $this->requested[] = $page;

                return $this->pages[$page] ?? null;
            }
        };

        $module->pages = $pages;

        return $module;
    }

    #[Test]
    public function a_single_page_group_is_returned_as_is(): void
    {
        $module = $this->moduleReturning([1 => $this->page(1, 1, 3)]);

        $merged = $this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]);

        $this->assertCount(3, $merged['data']);
        $this->assertSame([1], $module->requested, 'no needless second request');
    }

    #[Test]
    public function every_page_is_fetched_and_merged_in_order(): void
    {
        // 45 hypervisors across three pages — the case that used to lose 25 of them.
        $module = $this->moduleReturning([
            1 => $this->page(1, 3, 20, idOffset: 0),
            2 => $this->page(2, 3, 20, idOffset: 20),
            3 => $this->page(3, 3, 5, idOffset: 40),
        ]);

        $merged = $this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]);

        $this->assertCount(45, $merged['data']);
        $this->assertSame([1, 2, 3], $module->requested);
        $this->assertSame(
            range(1, 45),
            array_column(array_column($merged['data'], 'hypervisor'), 'id'),
            'hypervisors keep their upstream order across the page boundary',
        );
    }

    #[Test]
    public function the_merged_envelope_presents_itself_as_a_single_page(): void
    {
        $module = $this->moduleReturning([
            1 => $this->page(1, 2, 20),
            2 => $this->page(2, 2, 7, idOffset: 20),
        ]);

        $merged = $this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]);

        $this->assertSame(1, $merged['current_page']);
        $this->assertSame(1, $merged['last_page'], 'nothing downstream should think the set is truncated');
        $this->assertSame(27, $merged['per_page']);
        $this->assertSame(27, $merged['total']);
    }

    #[Test]
    public function a_failure_on_a_later_page_discards_the_whole_result(): void
    {
        // A partial fleet undercounts capacity, which is the very failure this paging
        // exists to prevent — so it must degrade to "transient", not to a short list.
        $module = $this->moduleReturning([
            1 => $this->page(1, 3, 20),
            2 => null,
            3 => $this->page(3, 3, 5),
        ]);

        $this->assertNull($this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]));
        $this->assertSame([1, 2], $module->requested, 'paging stops at the first failure');
    }

    #[Test]
    public function a_partial_failure_is_reported_in_the_module_log(): void
    {
        $module = $this->moduleReturning([1 => $this->page(1, 2, 20), 2 => null]);

        $this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]);

        $logged = $this->logsFor('fetchAllResourcePages');
        $this->assertNotEmpty($logged);
        $this->assertStringContainsString('qty is left untouched', $logged[0]['response']);
    }

    #[Test]
    public function a_404_on_the_first_page_reports_a_deleted_group(): void
    {
        $module = $this->moduleReturning([1 => false]);

        $this->assertFalse($this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]));
    }

    #[Test]
    public function a_transient_failure_on_the_first_page_stays_transient(): void
    {
        $module = $this->moduleReturning([1 => null]);

        $this->assertNull($this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]));
    }

    #[Test]
    public function a_404_partway_through_is_treated_as_transient_not_as_a_deleted_group(): void
    {
        // Page 1 proved the group exists; a 404 on page 2 is a mid-flight anomaly, and
        // returning false here would wrongly zero the group's capacity.
        $module = $this->moduleReturning([1 => $this->page(1, 2, 20), 2 => false]);

        $this->assertNull($this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]));
    }

    #[Test]
    public function an_absurd_last_page_is_capped_rather_than_looping_forever(): void
    {
        $pages = [1 => $this->page(1, 999999, 1)];
        for ($i = 2; $i <= 30; $i++) {
            $pages[$i] = $this->page($i, 999999, 1, idOffset: $i);
        }

        $module = $this->moduleReturning($pages);
        $this->call($module, 'fetchAllResourcePages', [$this->cp(), 5]);

        $this->assertCount(25, $module->requested, 'paging stops at RESOURCE_PAGE_LIMIT');
        $this->assertNotEmpty($this->logsFor('fetchAllResourcePages'), 'the cap is logged, not silent');
    }

    #[Test]
    public function the_request_asks_for_the_maximum_page_size(): void
    {
        // The actual fix: without `results`, the endpoint silently serves 20 per page.
        $module = new class extends Module
        {
            public array $urls = [];

            public function __construct() {}

            public function initCurl($token)
            {
                return new class($this->urls)
                {
                    public function __construct(private array &$urls) {}

                    public function get($url = null)
                    {
                        $this->urls[] = $url;

                        return json_encode(['current_page' => 1, 'last_page' => 1, 'data' => []]);
                    }

                    public function getRequestInfo($param = false)
                    {
                        return $param === 'http_code' ? 200 : ['http_code' => 200];
                    }
                };
            }
        };

        $this->call($module, 'fetchResourcePage', [$this->cp(), 42, 1]);

        $this->assertCount(1, $module->urls);
        $this->assertStringContainsString('/compute/hypervisors/groups/42/resources', $module->urls[0]);
        $this->assertStringContainsString('results=200', $module->urls[0]);
        $this->assertStringContainsString('page=1', $module->urls[0]);
    }
}
