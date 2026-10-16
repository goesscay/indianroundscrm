<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\MailHistory;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeMailTransport;
use Tests\TestCase;
use App\Services\Mail\MailTransport;

class SupplierGroupsTest extends TestCase
{
    use RefreshDatabase;

    private function as(User $u): User
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($u, ['*'], 'sanctum');

        return $u;
    }

    public function test_groups_are_created_edited_and_deleted_without_touching_the_suppliers(): void
    {
        $admin = $this->as(User::factory()->admin()->create());
        $a = Supplier::create(['name' => 'Alpha', 'email' => 'a@s.test']);
        $b = Supplier::create(['name' => 'Beta', 'email' => 'b@s.test']);

        $g = $this->postJson('/api/supplier-groups', ['name' => 'Vietnam desk', 'destination' => 'Vietnam', 'supplier_ids' => [(string) $a->id, (string) $b->id, '999999']])->assertOk()->json();
        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $b->id], $g['supplier_ids']);       // the unknown id is ignored
        $this->postJson('/api/supplier-groups', ['name' => 'vietnam DESK'])->assertStatus(400);           // names are unique, ignoring case

        $this->patchJson("/api/supplier-groups/{$g['id']}", ['name' => 'Vietnam desk', 'supplier_ids' => [(string) $a->id]])->assertOk()->assertJsonPath('supplier_ids', [(string) $a->id]);
        $this->assertSame([$g['id']], collect($this->getJson('/api/suppliers')->json())->firstWhere('id', (string) $a->id)['group_ids']);
        $this->assertSame([], collect($this->getJson('/api/suppliers')->json())->firstWhere('id', (string) $b->id)['group_ids']);

        // a supplier can also be put in groups from its own form
        $this->patchJson("/api/suppliers/{$b->id}", ['group_ids' => [$g['id']]])->assertOk();
        $this->assertCount(2, $this->getJson('/api/supplier-groups')->json('0.supplier_ids'));
        $this->patchJson("/api/suppliers/{$b->id}", ['group_ids' => []])->assertOk();
        $this->assertCount(1, $this->getJson('/api/supplier-groups')->json('0.supplier_ids'));

        $this->deleteJson("/api/supplier-groups/{$g['id']}")->assertOk();
        $this->assertSame(2, Supplier::count());
        $this->assertSame([], $this->getJson('/api/supplier-groups')->json());
    }

    public function test_sales_cannot_see_or_manage_groups_but_operations_can(): void
    {
        $sales = $this->as(User::factory()->sales()->create());
        $this->getJson('/api/supplier-groups')->assertStatus(403);
        $this->postJson('/api/supplier-groups', ['name' => 'X'])->assertStatus(403);
        $this->as(User::factory()->operations()->create());
        $this->getJson('/api/supplier-groups')->assertOk();
        $this->postJson('/api/supplier-groups', ['name' => 'X'])->assertOk();               // operations keep the supplier list
    }

    public function test_suppliers_picked_for_cc_and_bcc_are_copied_in_without_a_tracker_row_and_never_repeated(): void
    {
        $fake = new FakeMailTransport;
        $this->app->instance(MailTransport::class, $fake);
        $ops = User::factory()->operations()->create();
        Lead::create(['lead_id' => 'IRMH10023', 'customer_name' => 'Asha', 'phone' => '9876501234', 'destination' => 'Vietnam']);
        $to = Supplier::create(['name' => 'To Co', 'email' => 'to@s.test']);
        $cc = Supplier::create(['name' => 'Cc Co', 'email' => 'cc@s.test']);
        $bcc1 = Supplier::create(['name' => 'Bcc One', 'email' => 'bcc1@s.test']);
        $bcc2 = Supplier::create(['name' => 'Bcc Two', 'email' => 'bcc2@s.test']);
        $this->as($ops);

        $this->postJson('/api/supplier-mail/send', [
            'lead_id' => 'IRMH10023', 'supplier_ids' => [(string) $to->id], 'subject' => 'Enquiry', 'body' => 'Please quote',
            'cc_supplier_ids' => [(string) $cc->id, (string) $to->id],                       // the To supplier is not repeated in CC
            'bcc_supplier_ids' => [(string) $bcc1->id, (string) $bcc2->id, (string) $cc->id],  // nor the CC one in BCC
            'template' => 'plain',
        ])->assertOk();

        $m = $fake->sent[0];
        $this->assertSame(['to@s.test'], $m->to);
        $this->assertSame(['cc@s.test'], $m->cc);
        $this->assertSame(['bcc1@s.test', 'bcc2@s.test'], $m->bcc);
        $this->assertSame(1, \App\Models\LeadSupplier::where('lead_id', 'IRMH10023')->count());   // only the To supplier is tracked
        $this->assertSame(['bcc1@s.test', 'bcc2@s.test'], MailHistory::first()->toApi()['bcc']);
    }

    public function test_a_mail_can_go_out_with_only_cc_or_only_bcc_but_not_with_nobody(): void
    {
        $fake = new FakeMailTransport;
        $this->app->instance(MailTransport::class, $fake);
        $ops = User::factory()->operations()->create();
        Lead::create(['lead_id' => 'IRMH10023', 'customer_name' => 'Asha', 'phone' => '9876501234', 'destination' => 'Vietnam']);
        $a = Supplier::create(['name' => 'Alpha', 'email' => 'a@s.test']);
        $b = Supplier::create(['name' => 'Beta', 'email' => 'b@s.test']);
        $this->as($ops);
        $base = ['lead_id' => 'IRMH10023', 'subject' => 'Enquiry', 'body' => 'Please quote', 'template' => 'plain'];

        $this->postJson('/api/supplier-mail/send', $base + ['supplier_ids' => [], 'bcc_supplier_ids' => [(string) $a->id]])->assertOk();   // BCC only
        $this->postJson('/api/supplier-mail/send', $base + ['supplier_ids' => [], 'cc_supplier_ids' => [(string) $b->id]])->assertOk();    // CC only
        $this->assertSame([[], ['a@s.test']], [$fake->sent[0]->to, $fake->sent[0]->bcc]);
        $this->assertSame([[], ['b@s.test']], [$fake->sent[1]->to, $fake->sent[1]->cc]);
        $this->assertSame(0, \App\Models\LeadSupplier::count());                                  // nobody was asked for a quote, so nobody is tracked

        $this->postJson('/api/supplier-mail/send', $base + ['supplier_ids' => []])->assertStatus(400);
        $this->assertCount(2, $fake->sent);
    }
}
