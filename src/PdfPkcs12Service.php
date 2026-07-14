<?php
declare(strict_types=1);

/**
 * [EN]    PAdES (PDF) signing service — PKCS#12 certificate pre-imported into SolidSign cache.
 *         Import once via POST /solidsign/dsig/certificates/pkcs12/import, then set SOLIDSIGN_CERT_ID.
 * [PT-BR] Serviço de assinatura PAdES (PDF) — certificado PKCS#12 pré-importado na cache do SolidSign.
 *         Importe uma vez via POST /solidsign/dsig/certificates/pkcs12/import, depois configure SOLIDSIGN_CERT_ID.
 */

namespace SolidSign;

use GuzzleHttp\Client;
use ZipArchive;


/**
 * [EN]    Rewrites visual-signature config entries in a Guzzle multipart array to the INDEXED
 *         form the API expects: signatureFieldConfig[0]={...} per document (NOT a single
 *         signatureFieldConfig=[{...}], which the API ignores, hiding the visual stamp).
 * [PT-BR] Reescreve as entradas de config de assinatura visual no multipart do Guzzle para a
 *         forma INDEXADA que a API espera: signatureFieldConfig[0]={...} por documento (e NÃO
 *         um único signatureFieldConfig=[{...}], que a API ignora e esconde o carimbo).
 */
function indexFieldConfigs(array $multipart): array
{
    $keys = ['signatureFieldConfig', 'signatureTextConfig', 'signatureQrCodeConfig'];
    $out = [];
    foreach ($multipart as $part) {
        if (!isset($part['name']) || !in_array($part['name'], $keys, true)) { $out[] = $part; continue; }
        $raw = $part['contents'] ?? '';
        if ($raw === '' || $raw === null) { continue; }
        $parsed = json_decode($raw, true);
        if (is_array($parsed) && array_is_list($parsed)) {
            foreach ($parsed as $i => $item) {
                $out[] = ['name' => $part['name'] . "[$i]", 'contents' => is_string($item) ? $item : json_encode($item)];
            }
        } elseif ($parsed !== null) {
            $out[] = ['name' => $part['name'] . '[0]', 'contents' => is_string($parsed) ? $parsed : json_encode($parsed)];
        } else {
            $out[] = ['name' => $part['name'] . '[0]', 'contents' => $raw];
        }
    }
    return $out;
}

class PdfPkcs12Service
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client(['http_errors' => false]);
    }

    // ── Batch (local files) ───────────────────────────────────────────────────

    public function signBatch(string $inputPath, string $outputPath): ?string
    {
        $pdfFiles = glob(rtrim($inputPath, '/') . '/*.pdf') ?: [];
        if (empty($pdfFiles)) {
            error_log("No PDF files found in $inputPath");
            return null;
        }

        $multipart = $this->buildFileMultipart($pdfFiles);

        $imagePaths = array_filter(array_map('trim', explode(',', $_ENV['SOLIDSIGN_SIGNATURE_IMAGE_PATHS'] ?? '')));
        foreach (array_values($imagePaths) as $i => $imgPath) {
            if (file_exists($imgPath)) {
                $multipart[] = ['name' => "signatureImage[$i]", 'contents' => fopen($imgPath, 'r'), 'filename' => basename($imgPath)];
            }
        }

        $multipart[] = ['name' => 'pfxCode',                 'contents' => $_ENV['SOLIDSIGN_CERT_ID'] ?? ''];
        $multipart[] = ['name' => 'profile',                 'contents' => $_ENV['SOLIDSIGN_PROFILE'] ?? 'ADRB'];
        $multipart[] = ['name' => 'hashAlgorithm',           'contents' => $_ENV['SOLIDSIGN_HASH_ALGORITHM'] ?? 'SHA256'];
        $multipart[] = ['name' => 'sigFieldMeasurementUnit', 'contents' => $_ENV['SOLIDSIGN_SIG_FIELD_MEASUREMENT_UNIT'] ?? 'PIXELS'];
        $multipart[] = ['name' => 'signatureFieldConfig',    'contents' => $_ENV['SOLIDSIGN_SIGNATURE_FIELD_CONFIG'] ?? ''];
        $multipart[] = ['name' => 'reason',                  'contents' => $_ENV['SOLIDSIGN_REASON'] ?? ''];
        $multipart[] = ['name' => 'location',                'contents' => $_ENV['SOLIDSIGN_LOCATION'] ?? ''];
        $multipart[] = ['name' => 'contact',                 'contents' => $_ENV['SOLIDSIGN_CONTACT'] ?? ''];
        if (!empty($_ENV['SOLIDSIGN_POLICY_VERSION'])) {
            $multipart[] = ['name' => 'policyVersion', 'contents' => $_ENV['SOLIDSIGN_POLICY_VERSION']];
        }

        $baseUrl = rtrim($_ENV['SOLIDSIGN_BASE_URL'] ?? 'http://localhost:8080', '/');
        $auth    = $_ENV['SOLIDSIGN_AUTHORIZATION'] ?? '';

        $multipart = indexFieldConfigs($multipart);
        $response = $this->client->post("$baseUrl/solidsign/dsig/pdf/sign-pkcs12", [
            'headers'   => ['Authorization' => $auth],
            'multipart' => $multipart,
        ]);

        if ($response->getStatusCode() >= 400) {
            error_log("SolidSign error {$response->getStatusCode()}: " . $response->getBody());
            return null;
        }

        $signResp  = json_decode((string) $response->getBody(), true);
        $origNames = array_map('basename', $pdfFiles);
        return $this->downloadAndZip($signResp, $origNames, $auth, $outputPath, 'signed_pdf_pkcs12');
    }

    // ── Form (uploaded files) ─────────────────────────────────────────────────

    /**
     * @param array $params      Associative array of text fields from the multipart form.
     * @param array $files       ['document' => [['content'=>string,'filename'=>string],...], 'signatureImage' => [...]]
     */
    public function signForm(array $params, array $files): ?string
    {
        $multipart = [];
        foreach ($files['document'] ?? [] as $i => $f) {
            $multipart[] = ['name' => "document[$i]", 'contents' => $f['content'], 'filename' => $f['filename']];
        }
        foreach ($files['signatureImage'] ?? [] as $i => $f) {
            $multipart[] = ['name' => "signatureImage[$i]", 'contents' => $f['content'], 'filename' => $f['filename']];
        }

        $textFields = ['pfxCode', 'profile', 'hashAlgorithm', 'policyVersion', 'sigFieldMeasurementUnit',
                       'signatureFieldConfig', 'reason', 'location', 'contact',
                       'signatureFieldName', 'signatureTextConfig', 'mdpPermissionLevel',
                       'passwordsForDecryption', 'documentInfoMetadata', 'signatureQrCodeConfig'];
        foreach ($textFields as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $multipart[] = ['name' => $field, 'contents' => $params[$field]];
            }
        }

        $baseUrl = rtrim($params['baseUrl'] ?? '', '/');
        $auth    = $params['authorization'] ?? '';

        $multipart = indexFieldConfigs($multipart);
        $response = $this->client->post("$baseUrl/solidsign/dsig/pdf/sign-pkcs12", [
            'headers'   => ['Authorization' => $auth],
            'multipart' => $multipart,
        ]);

        if ($response->getStatusCode() >= 400) {
            error_log("SolidSign error {$response->getStatusCode()}: " . $response->getBody());
            return null;
        }

        $signResp  = json_decode((string) $response->getBody(), true);
        $origNames = array_column($files['document'] ?? [], 'filename');
        $tmpDir    = sys_get_temp_dir() . '/solidsign_out_' . uniqid();
        return $this->downloadAndZip($signResp, $origNames, $auth, $tmpDir, 'signed_pdf');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildFileMultipart(array $filePaths): array
    {
        $multipart = [];
        foreach ($filePaths as $i => $path) {
            $multipart[] = ['name' => "document[$i]", 'contents' => fopen($path, 'r'), 'filename' => basename($path)];
        }
        return $multipart;
    }

    private function downloadAndZip(array $signResp, array $origNames, string $auth, string $outputDir, string $prefix): ?string
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        $tmpDir = sys_get_temp_dir() . '/solidsign_dl_' . uniqid();
        mkdir($tmpDir, 0777, true);

        foreach ($signResp['documents'] ?? [] as $i => $doc) {
            $selfHref = $doc['_links']['self']['href'] ?? null;
            if ($selfHref === null) {
                foreach ($doc['links'] ?? [] as $link) {
                    if ($link['rel'] === 'self') { $selfHref = $link['href']; break; }
                }
            }
            if ($selfHref === null) continue;

            $dlResp = $this->client->get($selfHref, ['headers' => ['Authorization' => $auth]]);
            if ($dlResp->getStatusCode() >= 400) continue;
            $origName = $origNames[$i] ?? "document_$i";
            file_put_contents("$tmpDir/signed_$origName", (string) $dlResp->getBody());
        }

        $zipPath = "$outputDir/{$prefix}_" . time() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (glob("$tmpDir/*") ?: [] as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();

        foreach (glob("$tmpDir/*") ?: [] as $file) { unlink($file); }
        rmdir($tmpDir);

        echo "PAdES PKCS12 signing complete. Output: $zipPath\n";
        return $zipPath;
    }
}
