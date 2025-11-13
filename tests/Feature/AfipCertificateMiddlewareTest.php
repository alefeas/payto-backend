<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanyAfipCertificate;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AfipCertificateMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private $user;
    private $company;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->company = Company::factory()->create();
        
        // Asociar usuario con empresa
        $this->company->members()->attach($this->user->id, ['role' => 'owner']);
    }

    /** @test */
    public function it_blocks_afip_routes_without_certificate()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/companies/{$this->company->id}/afip/search-cuit", [
                'cuit' => '20123456789'
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Certificado AFIP requerido. Debe configurar un certificado AFIP activo para usar esta funcionalidad.',
                'error_code' => 'AFIP_CERTIFICATE_REQUIRED'
            ]);
    }

    /** @test */
    public function it_blocks_afip_routes_with_inactive_certificate()
    {
        // Crear certificado inactivo
        CompanyAfipCertificate::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => false
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/companies/{$this->company->id}/afip/search-cuit", [
                'cuit' => '20123456789'
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function it_blocks_afip_routes_with_expired_certificate()
    {
        // Crear certificado expirado
        CompanyAfipCertificate::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
            'expires_at' => now()->subDay()
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/companies/{$this->company->id}/afip/search-cuit", [
                'cuit' => '20123456789'
            ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'El certificado AFIP ha expirado. Debe renovar su certificado para continuar.',
                'error_code' => 'AFIP_CERTIFICATE_EXPIRED'
            ]);
    }

    /** @test */
    public function it_allows_afip_routes_with_valid_certificate()
    {
        // Crear certificado válido
        CompanyAfipCertificate::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
            'expires_at' => now()->addMonth()
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/companies/{$this->company->id}/afip/search-cuit", [
                'cuit' => '20123456789'
            ]);

        // No debe ser 403 (puede ser otro error por lógica de negocio, pero no por certificado)
        $response->assertStatus(200); // O el status que corresponda según la implementación
    }

    /** @test */
    public function it_allows_certificate_management_routes_without_certificate()
    {
        // Las rutas de gestión de certificados NO deben estar protegidas
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/companies/{$this->company->id}/afip/certificate");

        // No debe ser 403 por falta de certificado
        $response->assertStatus(200); // O 404 si no existe, pero no 403
    }

    /** @test */
    public function it_provides_correct_redirect_url_in_error_response()
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/companies/{$this->company->id}/afip/search-cuit", [
                'cuit' => '20123456789'
            ]);

        $response->assertStatus(403)
            ->assertJsonFragment([
                'redirect_url' => "/company/{$this->company->id}/verify"
            ]);
    }
}