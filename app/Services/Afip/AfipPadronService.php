<?php

namespace App\Services\Afip;

use App\Models\CompanyAfipCertificate;
use Illuminate\Support\Facades\Log;

class AfipPadronService
{
    private const PADRON_A5_WSDL_PRODUCTION = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5?WSDL';
    private const PADRON_A13_WSDL_PRODUCTION = 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL';
    
    private CompanyAfipCertificate $certificate;
    private AfipWebServiceClient $wsClient;
    private bool $mockMode;

    public function __construct(CompanyAfipCertificate $certificate, bool $mockMode = null)
    {
        $this->certificate = $certificate;
        $this->wsClient = new AfipWebServiceClient($certificate, 'ws_sr_padron_a5');
        
        // Auto-detect mock mode: use mock if not in production environment
        $this->mockMode = $mockMode ?? ($certificate->environment !== 'production');
    }

    /**
     * Get taxpayer data by CUIT/CUIL
     */
    public function getTaxpayerData(string $cuit): array
    {
        $cuit = $this->cleanCuit($cuit);
        
        if (!$this->isValidCuitFormat($cuit)) {
            throw new \Exception('Formato de CUIT/CUIL inválido');
        }

        // Mock mode for testing (Padron only works in production)
        if ($this->mockMode) {
            return $this->getMockTaxpayerData($cuit);
        }

        try {
            $auth = $this->wsClient->getAuthArray();
            $client = $this->getPadronClient();
            
            $params = [
                'token' => $auth['Token'],
                'sign' => $auth['Sign'],
                'cuitRepresentada' => $auth['Cuit'],
                'idPersona' => $cuit,
            ];

            $response = $client->getPersona($params);
            
            if (!isset($response->personaReturn)) {
                throw new \Exception('No se encontraron datos para el CUIT ingresado');
            }

            $persona = $response->personaReturn;
            
            return $this->parseAfipResponse($persona);
            
        } catch (\SoapFault $e) {
            Log::error('AFIP Padron SOAP error', [
                'cuit' => $cuit,
                'error' => $e->getMessage(),
            ]);
            
            if (str_contains($e->getMessage(), 'No existe persona')) {
                throw new \Exception('No se encontró información para el CUIT/CUIL ingresado en AFIP');
            }
            
            throw new \Exception('Error consultando AFIP: ' . $e->getMessage());
        }
    }

    /**
     * Get own company fiscal data (from certificate CUIT)
     */
    public function getOwnFiscalData(): array
    {
        $companyCuit = preg_replace('/[^0-9]/', '', $this->certificate->company->national_id);
        return $this->getTaxpayerData($companyCuit);
    }

    /**
     * Parse AFIP response to standardized format
     */
    private function parseAfipResponse($persona): array
    {
        $data = [
            'cuit' => (string) $persona->idPersona,
            'person_type' => $this->getPersonType($persona->tipoClave ?? null),
            'tax_condition' => $this->getTaxCondition($persona->tipoPersona ?? null),
            'name' => null,
            'business_name' => null,
            'address' => null,
            'province' => null,
            'city' => null,
            'postal_code' => null,
            'activities' => [],
            'taxes' => [],
        ];

        // Physical person
        if (isset($persona->nombre)) {
            $data['name'] = trim(
                ($persona->nombre ?? '') . ' ' . ($persona->apellido ?? '')
            );
        }

        // Legal entity
        if (isset($persona->razonSocial)) {
            $data['business_name'] = (string) $persona->razonSocial;
        }

        // Address
        if (isset($persona->domicilioFiscal)) {
            $dom = $persona->domicilioFiscal;
            
            $addressParts = array_filter([
                $dom->direccion ?? null,
                $dom->localidad ?? null,
                $dom->descripcionProvincia ?? null,
            ]);
            
            $data['address'] = implode(', ', $addressParts);
            $data['province'] = $dom->descripcionProvincia ?? null;
            $data['city'] = $dom->localidad ?? null;
            $data['postal_code'] = $dom->codPostal ?? null;
        }

        // Activities
        if (isset($persona->actividades) && is_array($persona->actividades)) {
            $data['activities'] = array_map(function($act) {
                return [
                    'code' => $act->idActividad ?? null,
                    'description' => $act->descripcionActividad ?? null,
                ];
            }, $persona->actividades);
        }

        // Taxes (impuestos)
        if (isset($persona->impuestos) && is_array($persona->impuestos)) {
            $data['taxes'] = array_map(function($imp) {
                return [
                    'code' => $imp->idImpuesto ?? null,
                    'description' => $imp->descripcionImpuesto ?? null,
                ];
            }, $persona->impuestos);
        }

        return $data;
    }

    /**
     * Get SOAP client for Padron service
     */
    private function getPadronClient(): \SoapClient
    {
        return new \SoapClient(self::PADRON_A5_WSDL_PRODUCTION, [
            'soap_version' => SOAP_1_2,
            'trace' => 1,
            'exceptions' => true,
        ]);
    }

    /**
     * Mock data for testing (Padron only works in production)
     */
    private function getMockTaxpayerData(string $cuit): array
    {
        // Simulate different scenarios based on CUIT
        $lastDigit = (int) substr($cuit, -1);
        
        $mockData = [
            'cuit' => $cuit,
            'person_type' => $lastDigit < 5 ? 'physical' : 'legal',
            'tax_condition' => $this->getMockTaxCondition($lastDigit),
            'name' => null,
            'business_name' => null,
            'address' => 'Av. Corrientes 1234, CABA, Buenos Aires',
            'province' => 'Buenos Aires',
            'city' => 'CABA',
            'postal_code' => '1043',
            'activities' => [
                [
                    'code' => '620100',
                    'description' => 'Servicios de consultoría en informática',
                ],
            ],
            'taxes' => [
                [
                    'code' => '30',
                    'description' => 'IVA',
                ],
            ],
        ];

        if ($mockData['person_type'] === 'physical') {
            $mockData['name'] = 'Juan Pérez';
        } else {
            $mockData['business_name'] = 'Empresa de Prueba S.A.';
        }

        return $mockData;
    }

    /**
     * Get mock tax condition based on last digit
     */
    private function getMockTaxCondition(int $lastDigit): string
    {
        $conditions = [
            'responsable_inscripto',
            'monotributo',
            'exento',
            'consumidor_final',
        ];
        
        return $conditions[$lastDigit % 4];
    }

    /**
     * Determine person type from AFIP data
     */
    private function getPersonType(?string $tipoClave): string
    {
        if (!$tipoClave) {
            return 'unknown';
        }
        
        // CUIT starts with 30, 33, 34 = Legal entity
        // CUIL starts with 20, 23, 24, 27 = Physical person
        $prefix = substr($tipoClave, 0, 2);
        
        if (in_array($prefix, ['30', '33', '34'])) {
            return 'legal';
        }
        
        if (in_array($prefix, ['20', '23', '24', '27'])) {
            return 'physical';
        }
        
        return 'unknown';
    }

    /**
     * Map AFIP tax type to our system
     */
    private function getTaxCondition(?string $tipoPersona): string
    {
        // This mapping depends on AFIP's response structure
        // Adjust based on actual AFIP data
        return match($tipoPersona) {
            'FISICA' => 'monotributo',
            'JURIDICA' => 'responsable_inscripto',
            default => 'consumidor_final',
        };
    }

    /**
     * Clean CUIT (remove hyphens and spaces)
     */
    private function cleanCuit(string $cuit): string
    {
        return preg_replace('/[^0-9]/', '', $cuit);
    }

    /**
     * Validate CUIT format and checksum
     */
    private function isValidCuitFormat(string $cuit): bool
    {
        if (strlen($cuit) !== 11 || !ctype_digit($cuit)) {
            return false;
        }
        
        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        
        for ($i = 0; $i < 10; $i++) {
            $sum += intval($cuit[$i]) * $multipliers[$i];
        }
        
        $remainder = $sum % 11;
        $checkDigit = $remainder === 0 ? 0 : 11 - $remainder;
        
        return intval($cuit[10]) === $checkDigit;
    }
}
