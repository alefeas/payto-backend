<?php

namespace App\Services\Afip;

use App\Models\Company;
use App\Models\CompanyAfipCertificate;

class AfipVerificationService
{
    private $certificate;
    private $wsClient;

    public function __construct(CompanyAfipCertificate $certificate)
    {
        $this->certificate = $certificate;
        $this->wsClient = new AfipWebServiceClient($certificate);
    }

    public function verifyConnection(): array
    {
        try {
            $token = $this->wsClient->getToken();
            return [
                'success' => true,
                'message' => 'Conexión exitosa con AFIP',
                'token_expires_at' => $this->certificate->token_expires_at
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function getTaxCondition(): string
    {
        $company = $this->certificate->company;
        $cuit = preg_replace('/[^0-9]/', '', $company->national_id);
        
        // Consultar ws_sr_padron_a5 para obtener condición fiscal
        $client = new \SoapClient(
            $this->certificate->environment === 'production'
                ? 'https://aws.afip.gov.ar/sr-padron/webservices/personaServiceA5?WSDL'
                : 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA5?WSDL',
            ['soap_version' => SOAP_1_1, 'trace' => 1]
        );

        $token = $this->wsClient->getToken();
        $sign = $this->wsClient->getSign();

        $params = [
            'token' => $token,
            'sign' => $sign,
            'cuitRepresentada' => $cuit,
            'idPersona' => $cuit
        ];

        $response = $client->getPersona($params);
        
        if (!isset($response->personaReturn)) {
            throw new \Exception('No se pudo obtener información del contribuyente');
        }

        $persona = $response->personaReturn;
        
        // Mapear impuesto IVA a tax_condition
        if (isset($persona->datosGenerales->tipoPersona)) {
            $tipoPersona = $persona->datosGenerales->tipoPersona;
            
            // Buscar impuesto IVA
            if (isset($persona->datosRegimenGeneral->impuesto)) {
                $impuestos = is_array($persona->datosRegimenGeneral->impuesto) 
                    ? $persona->datosRegimenGeneral->impuesto 
                    : [$persona->datosRegimenGeneral->impuesto];
                
                foreach ($impuestos as $impuesto) {
                    if ($impuesto->idImpuesto == 30) { // IVA
                        // 1 = Responsable Inscripto
                        return 'registered_taxpayer';
                    }
                    if ($impuesto->idImpuesto == 20) { // Monotributo
                        return 'monotax';
                    }
                }
            }
        }
        
        // Default para empresas (no pueden ser consumidor final)
        return 'registered_taxpayer';
    }

    public function getContribuyenteData(string $cuit, Company $company): ?array
    {
        // Placeholder - implementar consulta real a AFIP ws_sr_padron_a5
        return null;
    }
}
