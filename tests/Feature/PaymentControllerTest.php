<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Company $company;
    private Supplier $supplier;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
        $this->invoice = Invoice::factory()->create([
            'issuer_company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
        ]);
        
        // Add user to company
        $this->company->members()->create([
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);
    }

    public function test_index_returns_payments()
    {
        Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/companies/{$this->company->id}/payments");

        expect($response->status())->toBe(200);
        expect($response->json())->toHaveCount(1);
    }

    public function test_index_filters_by_status()
    {
        Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'status' => 'confirmed',
        ]);
        
        Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/companies/{$this->company->id}/payments?status=confirmed");

        expect($response->status())->toBe(200);
        expect($response->json())->toHaveCount(1);
    }

    public function test_store_creates_payment()
    {
        $data = [
            'invoice_id' => $this->invoice->id,
            'amount' => 100,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'transfer',
            'reference_number' => 'REF123',
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/companies/{$this->company->id}/payments", $data);

        expect($response->status())->toBe(201);
        expect(Payment::where('company_id', $this->company->id)->count())->toBe(1);
    }

    public function test_update_payment()
    {
        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'amount' => 100,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/companies/{$this->company->id}/payments/{$payment->id}", [
                'amount' => 150,
            ]);

        expect($response->status())->toBe(200);
        expect(Payment::find($payment->id)->amount)->toBe(150);
    }

    public function test_destroy_deletes_payment()
    {
        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/companies/{$this->company->id}/payments/{$payment->id}");

        expect($response->status())->toBe(200);
        expect(Payment::find($payment->id))->toBeNull();
    }

    public function test_destroy_cannot_delete_confirmed_payment()
    {
        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/companies/{$this->company->id}/payments/{$payment->id}");

        expect($response->status())->toBe(400);
        expect(Payment::find($payment->id))->not->toBeNull();
    }

    public function test_confirm_payment()
    {
        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/companies/{$this->company->id}/payments/{$payment->id}/confirm");

        expect($response->status())->toBe(200);
        expect(Payment::find($payment->id)->status)->toBe('confirmed');
    }

    public function test_confirm_already_confirmed_payment_returns_error()
    {
        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'invoice_id' => $this->invoice->id,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/companies/{$this->company->id}/payments/{$payment->id}/confirm");

        expect($response->status())->toBe(400);
    }
}
