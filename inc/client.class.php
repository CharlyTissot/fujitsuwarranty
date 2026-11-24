<?php
/** Read-only Fujitsu warranty API client for GLPI 10. */
class PluginFujitsuwarrantyClient {
    public static function check($serial) {
        $serial = strtoupper(trim((string)$serial));
        if (!preg_match('/^[A-Z0-9-]{4,40}$/D', $serial)) {
            throw new InvalidArgumentException('Numéro de série invalide.');
        }
        $url = 'https://support.ts.fujitsu.com/api/warranty/getWarrantyToProduct?' .
            http_build_query(['region' => 'Europe', 'serialNr' => $serial], '', '&', PHP_QUERY_RFC3986);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'x-blazor-client: true',
                'Referer: https://support.ts.fujitsu.com/warranty/warranty-support',
            ],
            CURLOPT_USERAGENT => 'GLPI FujitsuWarranty/0.3.2',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $result = ['serial' => $serial, 'http_status' => $status, 'result' => ''];
        if ($body === false) {
            $result['result'] = 'Erreur réseau : ' . $error;
            return $result;
        }
        if ($status !== 200) {
            $result['result'] = 'Erreur HTTP ' . $status . ' (aucune mise à jour GLPI)';
            return $result;
        }
        if (stripos($contentType, 'application/json') === false) {
            $result['result'] = 'Réponse non JSON (aucune mise à jour GLPI)';
            return $result;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || json_last_error() !== JSON_ERROR_NONE) {
            $result['result'] = 'JSON invalide';
            return $result;
        }
        $result['model'] = self::value($json, 'productDescription');
        $result['product_order'] = self::value($json, 'productOrderNr');
        $result['start'] = self::dateValue($json['warrantyStartDate'] ?? null);
        $result['end'] = self::dateValue($json['warrantyEndDate'] ?? null);
        $result['end_of_support'] = self::dateValue($json['endOfProductSupport'] ?? null);
        $result['warranty_state'] = isset($json['warrantyState']) && is_bool($json['warrantyState'])
            ? ($json['warrantyState'] ? 'Active (selon Fujitsu)' : 'Inactive (selon Fujitsu)') : null;
        $result['support_code'] = self::value($json, 'supportCode');
        $result['warranty_number'] = self::value($json, 'warrantyNumber');
        $result['result'] = ($result['start'] && $result['end'])
            ? 'Garantie trouvée — mode lecture seule, aucune donnée GLPI modifiée'
            : 'Aucune période de garantie exploitable';
        return $result;
    }
    private static function value(array $data, $key) {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
    }
    private static function dateValue($value) {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T|$)/', $value, $m)) return null;
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
}
