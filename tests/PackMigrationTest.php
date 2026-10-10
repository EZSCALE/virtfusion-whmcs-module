<?php

declare(strict_types=1);

namespace VirtFusionDirect\Tests;

use Illuminate\Database\Capsule\Manager as DB;
use PHPUnit\Framework\Attributes\Test;
use WHMCS\Authentication\CurrentUser;
use WHMCS\Module\Server\VirtFusionDirect\PackMigration;

final class PackMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->setAsGlobal();
        $db->bootEloquent();
        DB::schema()->create('tblproducts', function ($t) {
            $t->integer('id');
            $t->string('servertype');
            for ($i = 1; $i <= 12; $i++) {
                $t->string('configoption' . $i)->nullable();
            }
        });
        DB::schema()->create('tblhosting', function ($t) {
            $t->integer('id');
            $t->integer('packageid');
        });
        DB::schema()->create('mod_virtfusion_res_pks', function ($t) {
            $t->integer('service_id');
            $t->integer('pack_id');
        });
        DB::table('tblproducts')->insert(['id' => 1, 'servertype' => 'VirtFusionResourcePacks', 'configoption1' => '7', 'configoption2' => '90']);
        CurrentUser::$admin = new class
        {
            public int $id = 5;

            public int $roleid = 1;

            public function hasPermission($p)
            {
                return true;
            }
        };
        $_SESSION = [];
    }

    private function fields(): array
    {
        return ['sourceModule' => 'VirtFusionResourcePacks', 'products' => [['productId' => 1, 'profileId' => 7, 'deletionDelay' => 90, 'terminationBehavior' => 'Delete Servers']]];
    }

    #[Test]
    public function dry_run_does_not_switch_products_and_apply_preserves_explicit_behavior_and_snapshot(): void
    {
        $preview = PackMigration::preview($this->fields());
        $this->assertSame('VirtFusionResourcePacks', DB::table('tblproducts')->first()->servertype);
        $this->assertTrue($preview['dryRun']);
        $this->assertSame('Delete Servers', $preview['changes'][0]['targetSettings']['configoption12']);
        PackMigration::apply($this->fields() + ['digest' => $preview['digest'], 'migrationConfirmed' => true]);
        $product = DB::table('tblproducts')->first();
        $this->assertSame('VirtFusionDirect', $product->servertype);
        $this->assertSame('Resource Pack', $product->configoption9);
        $this->assertSame('7', $product->configoption10);
        $this->assertSame('90', $product->configoption11);
        $this->assertSame('Delete Servers', $product->configoption12);
        $this->assertTrue(DB::schema()->hasTable('mod_virtfusion_res_pks'));
        $snapshot = json_decode(DB::table('mod_virtfusion_direct_migrations')->first()->snapshot, true);
        $this->assertSame('7', $snapshot['changes'][0]['sourceSettings']['configoption1']);
    }

    #[Test]
    public function apply_without_preview_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        PackMigration::apply($this->fields() + ['digest' => str_repeat('a', 64), 'migrationConfirmed' => true]);
    }

    #[Test]
    public function changed_source_and_a_different_admin_cannot_apply_a_saved_preview(): void
    {
        $preview = PackMigration::preview($this->fields());
        CurrentUser::$admin->id = 6;

        try {
            PackMigration::apply($this->fields() + ['digest' => $preview['digest'], 'migrationConfirmed' => true]);
            $this->fail('Another administrator applied a saved session preview.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        CurrentUser::$admin->id = 5;
        DB::table('tblproducts')->update(['configoption2' => '120']);

        try {
            PackMigration::apply($this->fields() + ['digest' => $preview['digest'], 'migrationConfirmed' => true]);
            $this->fail('Changed source was migrated.');
        } catch (\RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }
        $this->assertSame('VirtFusionResourcePacks', DB::table('tblproducts')->first()->servertype);
    }

    #[Test]
    public function changed_owner_product_or_source_pack_invalidates_pinned_binding(): void
    {
        $binding = ['serviceId' => 1, 'clientId' => 10, 'sourceProductId' => 2, 'oldPanel' => 3, 'status' => 'Active', 'packId' => 50];
        $service = (object) ['id' => 1, 'userid' => 10, 'packageid' => 2, 'server' => 3, 'domainstatus' => 'Active'];
        $source = (object) ['service_id' => 1, 'pack_id' => 50];
        PackMigration::verifySourceBinding($service, $source, $binding);
        foreach (['userid', 'packageid', 'server', 'domainstatus', 'pack_id', 'service_id', 'missing'] as $changed) {
            $current = clone $service;
            $currentSource = clone $source;
            if ($changed === 'missing') {
                $currentSource = null;
            } elseif (in_array($changed, ['pack_id', 'service_id'], true)) {
                $currentSource->{$changed} = 99;
            } else {
                $current->{$changed} = $changed === 'domainstatus' ? 'Suspended' : 99;
            }

            try {
                PackMigration::verifySourceBinding($current, $currentSource, $binding);
                $this->fail('Changed source binding passed migration.');
            } catch (\RuntimeException $e) {
                $this->assertSame(409, $e->getCode());
            }
        }
        $this->assertSame(50, $source->pack_id);
        $this->assertSame(10, $service->userid);
    }
}
