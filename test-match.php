<?php

$csr = <<<CSR
-----BEGIN CERTIFICATE REQUEST-----
MIICyTCCAbECAQAwgYMxCzAJBgNVBAYTAkFSMRUwEwYDVQQIDAxCdWVub3MgQWly
ZXMxDTALBgNVBAcMBENBQkExHTAbBgNVBAoMFExldGljaWEgQW5kcmVhIE1hdGVq
MRQwEgYDVQQDDAsyNzIxNDM4Mzc5NDEZMBcGA1UEBRMQQ1VJVCAyNzIxNDM4Mzc5
NDCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAJjLWFrj1WUAODTXDiAL
JG4bUxmCUfMtZstmLVpje1ow26zzSb1rdE0PZo/8VM5clSTAQ0wROlZ1z0qvdI+H
EvIWi45O1zlfnslSW6aQ6K7QoFk5CP1Q0Q1oWtbtDHuX9IYBF3JwPgvBVywWUUQC
9+N+KwZtVzdaYe+dNaPlvrbGfBEnMeE7KMUL/xSMwAckBrfc2iXCs9BU7eGvIzML
3uh6X2PndDfUEmP9dkKggedve/K6BfVSl8wzbM/7D6EuIv+HiejADOHmY+nPskM8
Uc4jel9O0+xqf/IPdBNNmlY+rHzJ8KWOGHBbJ5C1t6PQ35a4S79zy+fG1JS8Rz98
S90CAwEAAaAAMA0GCSqGSIb3DQEBCwUAA4IBAQBzvD0n2EiA1im74fvy2lt5Hqvb
8HtZ/EwEelYuZ2PDRnEMKGrn9RN7GwCXfyjynAiWYZ2wGoZCOBFkgwZ5h/bsjWsh
U28s20vsBkkQAAKQGzK4G8u2t3osxlR2RAwY18kDRKlXZ26oaeK3cXW5eABoi7qs
wTBKW4mEVYu9CBEO7UlYlQ+3LdRNF7QJQWR/sOOYaPBnOAT8lrpxihNcMYjshFgm
FSYIx9VV1JHA3zGaIgXs77pk/iHfTxHXDkXTufEhAaURNt1RRYqWhGEkOXdkpCcz
nUMuhu/KNebl+uen1tynnmHwAxBk38tJjhtMpphVgkPt9oNVqNTG7dVc5Idl
-----END CERTIFICATE REQUEST-----
CSR;

$cert = <<<CERT
-----BEGIN CERTIFICATE-----
MIIDRzCCAi+gAwIBAgIIU/CPXUiG+04wDQYJKoZIhvcNAQENBQAwODEaMBgGA1UEAwwRQ29tcHV0
YWRvcmVzIFRlc3QxDTALBgNVBAoMBEFGSVAxCzAJBgNVBAYTAkFSMB4XDTI1MTAxNzA0MTEyNFoX
DTI3MTAxNzA0MTEyNFowLTEQMA4GA1UEAwwHVGVzdGluZzEZMBcGA1UEBRMQQ1VJVCAyNzIxNDM4
Mzc5NDCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAJjLWFrj1WUAODTXDiALJG4bUxmC
UfMtZstmLVpje1ow26zzSb1rdE0PZo/8VM5clSTAQ0wROlZ1z0qvdI+HEvIWi45O1zlfnslSW6aQ
6K7QoFk5CP1Q0Q1oWtbtDHuX9IYBF3JwPgvBVywWUUQC9+N+KwZtVzdaYe+dNaPlvrbGfBEnMeE7
KMUL/xSMwAckBrfc2iXCs9BU7eGvIzML3uh6X2PndDfUEmP9dkKggedve/K6BfVSl8wzbM/7D6Eu
Iv+HiejADOHmY+nPskM8Uc4jel9O0+xqf/IPdBNNmlY+rHzJ8KWOGHBbJ5C1t6PQ35a4S79zy+fG
1JS8Rz98S90CAwEAAaNgMF4wDAYDVR0TAQH/BAIwADAfBgNVHSMEGDAWgBSzstP//em63t6NrxEh
nNYgffJPbzAdBgNVHQ4EFgQUhP5C7UQV8FSvA3RQhFFKd7tJuGkwDgYDVR0PAQH/BAQDAgXgMA0G
CSqGSIb3DQEBDQUAA4IBAQB0B9Sr8rp45gvaedS1S/qK2aDKmU4lRpA6r/OQAf7Yk/OeCq9tHuh4
WM8hQFlFdOtokEQYrqAK9+xSKEJ4yTXMGvxwhR5NXPqlFwCw7gaqdOyGEtuQ/hm5Wu6ShH+miQ4+
600EM838URcGDnlicv2ve49wG4p7TWN8M5hKbo03rIOIjFngJIhge5HIKUgnGrT8ZRcztHnNZwe9
m41dnrIq3uNEuhZsWN3Aqwkec9TGFpiPTczw9Jzxv5Ez6NdneFKs0RC3d36JnNqY4rU6pa+bMte9
DwsCkflSPanIwOC/WrRtCyPT6aP7uYrBkm2sFm2Tmv5OZuXRwDvL1+KUAoem
-----END CERTIFICATE-----
CERT;

echo "Extrayendo clave pública del CSR...\n";
$csrResource = openssl_csr_get_public_key($csr);
if (!$csrResource) {
    die("Error: No se pudo extraer clave pública del CSR\n");
}
$csrDetails = openssl_pkey_get_details($csrResource);

echo "Extrayendo clave pública del certificado...\n";
$certPubKey = openssl_pkey_get_public($cert);
if (!$certPubKey) {
    die("Error: No se pudo extraer clave pública del certificado\n");
}
$certDetails = openssl_pkey_get_details($certPubKey);

echo "\nCSR - Módulo RSA (primeros 100 chars):\n";
echo substr(bin2hex($csrDetails['rsa']['n']), 0, 100) . "...\n";

echo "\nCertificado - Módulo RSA (primeros 100 chars):\n";
echo substr(bin2hex($certDetails['rsa']['n']), 0, 100) . "...\n";

echo "\n¿Coinciden? ";
if ($csrDetails['rsa']['n'] === $certDetails['rsa']['n']) {
    echo "✅ SÍ - Las claves coinciden\n";
} else {
    echo "❌ NO - Las claves NO coinciden\n";
}
