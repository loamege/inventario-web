<?php
/**
 * marketplace.php - Módulo reutilizable de integración con marketplaces
 * Extraído de BodegaPro (Jun 2026)
 *
 * Soporta: WooCommerce, MercadoLibre, Falabella, Walmart
 *
 * USO:
 *   require_once 'marketplace.php';
 *
 *   El sistema destino debe implementar estas funciones callback:
 *     mp_config()              → array con configs de cada marketplace
 *     mp_get_productos()       → array de productos
 *     mp_get_inventario()      → array de inventario
 *     mp_get_movimientos()     → array de movimientos
 *     mp_save_inventario($arr) → guardar inventario
 *     mp_save_movimientos($arr)→ guardar movimientos
 *     mp_save_config($k, $v)   → guardar config (tokens, etc.)
 *     mp_get_config($k)        → leer config
 *     mp_log($sis,$ac,$st,$d,$o)→ logging opcional
 *
 * Requiere: PHP 7.4+, ext-curl
 */

if (!extension_loaded('curl')) {
    trigger_error('marketplace.php requiere ext-curl', E_USER_ERROR);
}

// ============================================================
// 1. PRODUCT FLAGS (resolución de SKU y flags por canal)
// ============================================================

function mpFlagDefaultPublicado($prod, $canal) {
    if (!is_array($prod)) return false;
    if ($canal === 'ml') return trim((string)($prod['mlItemId'] ?? '')) !== '';
    if ($canal === 'fal') return trim((string)($prod['falabellaSku'] ?? '')) !== '';
    if ($canal === 'wal') return trim((string)($prod['walmartSku'] ?? ($prod['walmartId'] ?? ''))) !== '';
    if ($canal === 'wc') return trim((string)($prod['wcSku'] ?? ($prod['woocommerceSku'] ?? ($prod['wooSku'] ?? ($prod['skuWoo'] ?? ($prod['sku_wc'] ?? ($prod['sku'] ?? ''))))))) !== '';
    return false;
}

function mpFlagBool($prod, $key, $default) {
    if (!is_array($prod) || !array_key_exists($key, $prod) || $prod[$key] === null || $prod[$key] === '') return (bool)$default;
    $v = $prod[$key];
    if (is_bool($v)) return $v;
    if (is_numeric($v)) return ((int)$v) === 1;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','si','sí','yes','on'], true);
}

function mpProductoPublicado($prod, $canal) {
    $map = ['ml'=>'mp_ml_publicado','fal'=>'mp_fal_publicado','wal'=>'mp_wal_publicado','wc'=>'mp_wc_publicado'];
    if (!isset($map[$canal])) return false;
    return mpFlagBool($prod, $map[$canal], mpFlagDefaultPublicado($prod, $canal));
}

function mpProductoSyncActivo($prod, $canal) {
    $map = ['ml'=>'mp_ml_sync','fal'=>'mp_fal_sync','wal'=>'mp_wal_sync','wc'=>'mp_wc_sync'];
    if (!isset($map[$canal])) return false;
    return mpProductoPublicado($prod, $canal) && mpFlagBool($prod, $map[$canal], true);
}

function mpProductoSkuCanal($prod, $canal) {
    if (!is_array($prod)) return '';
    if ($canal === 'wal') return trim((string)($prod['walmartSku'] ?? ($prod['walmartId'] ?? '')));
    if ($canal === 'fal') return trim((string)($prod['falabellaSku'] ?? ''));
    if ($canal === 'wc') return trim((string)($prod['wcSku'] ?? ($prod['woocommerceSku'] ?? ($prod['wooSku'] ?? ($prod['skuWoo'] ?? ($prod['sku_wc'] ?? ($prod['sku'] ?? '')))))));
    if ($canal === 'ml') return trim((string)($prod['mlItemId'] ?? ($prod['sku'] ?? '')));
    return trim((string)($prod['sku'] ?? ''));
}

function mpLocalProductBySku($productos, $sku) {
    $sku = (string)$sku;
    if ($sku === '') return null;
    foreach ($productos as $p) {
        if (strcasecmp((string)($p['sku'] ?? ''), $sku) === 0) return $p;
        if (!empty($p['falabellaSku']) && strcasecmp((string)$p['falabellaSku'], $sku) === 0) return $p;
        if (!empty($p['mlItemId']) && strcasecmp((string)$p['mlItemId'], $sku) === 0) return $p;
        if (!empty($p['walmartSku']) && strcasecmp((string)$p['walmartSku'], $sku) === 0) return $p;
        if (!empty($p['walmartId']) && strcasecmp((string)$p['walmartId'], $sku) === 0) return $p;
    }
    return null;
}

// ============================================================
// 2. HELPERS
// ============================================================

function mpMoney($value) {
    if (is_array($value)) return 0;
    return (float)str_replace(',', '.', preg_replace('/[^0-9,\.\-]/', '', (string)$value));
}

function mpAsList($value) {
    if (!is_array($value)) return [];
    if (isset($value[0])) return $value;
    return [$value];
}

function mpFindInvIndex($inventario, $productoId, $bodegaId, $ubicacion) {
    foreach ($inventario as $i => $inv) {
        if ((int)($inv['productoId'] ?? 0) === (int)$productoId && (int)($inv['bodegaId'] ?? 0) === (int)$bodegaId && (($inv['ubicacion'] ?? 'a Piso') === $ubicacion)) return $i;
    }
    return null;
}

function mpNextNumericId($arr) {
    $max = 0;
    foreach ($arr as $x) $max = max($max, (int)($x['id'] ?? 0));
    return $max + 1;
}

function mpMovExists($movimientos, $referencia) {
    foreach ($movimientos as $m) if (($m['referencia'] ?? '') === $referencia) return true;
    return false;
}

function mpLog($sistema, $accion, $status, $detalle = '', $ordenId = '') {
    if (function_exists('mp_log_callback')) {
        mp_log_callback($sistema, $accion, $status, $detalle, $ordenId);
    }
}

function mpDebug($msg) {
    $logFile = __DIR__ . '/marketplace_debug.log';
    if (file_exists($logFile) && filesize($logFile) > 10 * 1024 * 1024) {
        @rename($logFile, __DIR__ . '/marketplace_debug_' . date('Ymd_His') . '.log');
    }
    @file_put_contents($logFile, date('c') . " $msg\n", FILE_APPEND);
}

// ============================================================
// 3. STATUS NORMALIZATION
// ============================================================

function mpNormalizeOrderStatus($status, $sistema = '') {
    $raw = mb_strtolower(trim((string)$status));
    if ($raw === '') return 'Creado';

    if (strpos($raw, 'cancel') !== false || strpos($raw, 'canceled') !== false || strpos($raw, 'cancelled') !== false || strpos($raw, 'failed') !== false || strpos($raw, 'fail') !== false || strpos($raw, 'rechaz') !== false || strpos($raw, 'refunded') !== false || strpos($raw, 'refund') !== false || strpos($raw, 'returned') !== false || strpos($raw, 'return') !== false) return 'Cancelado';
    if (strpos($raw, 'deliver') !== false || strpos($raw, 'delivered') !== false || strpos($raw, 'entreg') !== false || strpos($raw, 'completed') !== false || strpos($raw, 'complete') !== false) return 'Entregado';
    if (strpos($raw, 'shipped') !== false || strpos($raw, 'ship') !== false || strpos($raw, 'dispatch') !== false || strpos($raw, 'despach') !== false || strpos($raw, 'enviado') !== false) return 'Despachado';

    if (strpos($raw, 'paid') !== false || strpos($raw, 'confirmed') !== false || strpos($raw, 'ready_to_ship') !== false || strpos($raw, 'packed') !== false || strpos($raw, 'handling') !== false || strpos($raw, 'pending') !== false || strpos($raw, 'processing') !== false || strpos($raw, 'on-hold') !== false || strpos($raw, 'on_hold') !== false || strpos($raw, 'created') !== false || strpos($raw, 'creado') !== false) return 'Creado';

    return 'Creado';
}

function mpStatusPriority($status) {
    $norm = mpNormalizeOrderStatus($status, '');
    if ($norm === 'Cancelado') return 4;
    if ($norm === 'Entregado') return 3;
    if ($norm === 'Despachado') return 2;
    if ($norm === 'Creado') return 1;
    return 0;
}

function mpPickMostAdvancedStatus($statuses) {
    $statuses = array_values(array_filter(array_map(function($x){ return trim((string)$x); }, is_array($statuses) ? $statuses : [$statuses])));
    if (empty($statuses)) return '';
    $best = $statuses[0];
    $bestScore = mpStatusPriority($best);
    foreach ($statuses as $st) {
        $score = mpStatusPriority($st);
        if ($score > $bestScore) { $best = $st; $bestScore = $score; }
    }
    return $best;
}

function mpExtractOriginalOrderStatus($sistema, $order, $item = null) {
    $sistema = strtolower((string)$sistema);
    $order = is_array($order) ? $order : [];
    $item = is_array($item) ? $item : [];

    if ($sistema === 'mercadolibre') return mpPickMostAdvancedStatus(mpExtractMercadoLibreStatuses($order));
    if ($sistema === 'falabella') return mpPickMostAdvancedStatus(mpExtractFalabellaStatuses($order, $item));
    if ($sistema === 'woocommerce') return mpPickMostAdvancedStatus(mpExtractWooCommerceStatuses($order));
    return (string)($order['status'] ?? $item['Status'] ?? '');
}

function mpExtractWooCommerceStatuses($order = []) {
    $order = is_array($order) ? $order : [];
    $statuses = [];
    foreach (['status','payment_status','shipping_status'] as $k) {
        if (!empty($order[$k])) $statuses[] = $order[$k];
    }
    return array_values(array_unique(array_map('strval', $statuses)));
}

function mpExtractMercadoLibreStatuses($order = [], $shipment = []) {
    $statuses = [];
    $order = is_array($order) ? $order : [];
    $shipment = is_array($shipment) ? $shipment : [];

    foreach ([$shipment, $order['shipping'] ?? []] as $node) {
        if (!is_array($node)) continue;
        foreach (['status','substatus','logistic_type','mode'] as $k) {
            if (!empty($node[$k]) && in_array($k, ['status','substatus'], true)) $statuses[] = $node[$k];
        }
        if (!empty($node['status_history']) && is_array($node['status_history'])) {
            foreach ($node['status_history'] as $k => $v) {
                if (!empty($v) && is_string($k)) $statuses[] = $k;
            }
        }
    }

    foreach (['status','order_status','status_detail'] as $k) {
        if (!empty($order[$k])) $statuses[] = $order[$k];
    }

    return array_values(array_unique(array_map('strval', $statuses)));
}

function mpExtractFalabellaStatuses($order = [], $item = []) {
    $statuses = [];
    $order = is_array($order) ? $order : [];
    $item = is_array($item) ? $item : [];

    foreach (['Status','status','OrderItemStatus','ItemStatus','StatusName'] as $k) {
        if (isset($item[$k]) && $item[$k] !== '') $statuses[] = $item[$k];
    }

    if (isset($order['Statuses'])) {
        $sts = $order['Statuses'];
        if (is_array($sts)) {
            if (isset($sts['Status']) && !is_array($sts['Status'])) $statuses[] = $sts['Status'];
            foreach (mpAsList($sts) as $x) {
                if (is_array($x)) {
                    foreach (['Status','status','Name','name'] as $k) {
                        if (isset($x[$k]) && $x[$k] !== '') $statuses[] = $x[$k];
                    }
                } elseif ($x !== '') {
                    $statuses[] = $x;
                }
            }
        } elseif ($sts !== '') {
            $statuses[] = $sts;
        }
    }

    foreach (['Status','status','OrderStatus','orderStatus'] as $k) {
        if (isset($order[$k]) && $order[$k] !== '') $statuses[] = $order[$k];
    }

    return array_values(array_unique(array_map('strval', $statuses)));
}

// ============================================================
// 4. WOOCOMMERCE API CLIENT
// ============================================================

function mpWcGet($url, $ck, $cs) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => "$ck:$cs",
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $e = curl_error($ch);
    curl_close($ch);
    return ['body' => $r, 'http' => $h, 'error' => $e];
}

// ============================================================
// 5. MERCADOLIBRE API CLIENT
// ============================================================

function mpMlApi($endpoint, $accessToken, $method = 'GET', $body = null) {
    $url = 'https://api.mercadolibre.com' . $endpoint;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ];
    if ($method === 'POST') $opts[CURLOPT_POST] = true;
    if (in_array($method, ['PUT','DELETE'])) $opts[CURLOPT_CUSTOMREQUEST] = $method;
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $e = curl_error($ch);
    curl_close($ch);
    $data = json_decode($r, true);
    return ['body' => $r, 'http' => $h, 'error' => $e, 'data' => $data];
}

function mpMlGetShipmentStatus($accessToken, $shipmentId) {
    $shipmentId = trim((string)$shipmentId);
    if ($shipmentId === '') return [];
    $res = mpMlApi('/shipments/' . rawurlencode($shipmentId), $accessToken);
    if ($res['error'] || $res['http'] < 200 || $res['http'] >= 300 || !is_array($res['data'])) return [];
    return $res['data'];
}

// ============================================================
// 6. FALABELLA API CLIENT
// ============================================================

function mpFaSign($params, $apiKey) {
    ksort($params);
    $encoded = [];
    foreach ($params as $k => $v) {
        $encoded[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    $concatenated = implode('&', $encoded);
    return rawurlencode(hash_hmac('sha256', $concatenated, $apiKey, false));
}

function mpFaApiCall($action, $extraParams, $userID, $apiKey, $format = 'JSON', $method = 'GET') {
    $baseUrl = 'https://sellercenter-api.falabella.com/';
    $params = array_merge([
        'Action' => $action,
        'Format' => $format,
        'Timestamp' => date('c'),
        'UserID' => $userID,
        'Version' => '1.0',
    ], $extraParams);
    $params['Signature'] = mpFaSign($params, $apiKey);
    $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $baseUrl . '?' . $qs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['User-Agent: MarketplaceModule/PHP', 'Accept: application/json'],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
    }
    curl_setopt_array($ch, $opts);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $e = curl_error($ch);
    curl_close($ch);
    return ['body' => $r, 'http' => $h, 'error' => $e];
}

function mpFaApiCallUpdateStock($xmlBody, $userID, $apiKey) {
    $params = [
        'Action' => 'UpdateStock',
        'Format' => 'XML',
        'Timestamp' => date('c'),
        'UserID' => $userID,
        'Version' => '1.0',
    ];
    $params['Signature'] = mpFaSign($params, $apiKey);
    $qs = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://sellercenter-api.falabella.com/?' . $qs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xmlBody,
        CURLOPT_HTTPHEADER => ['Content-Type: application/xml', 'User-Agent: MarketplaceModule/PHP'],
        CURLOPT_TIMEOUT => 30,
    ]);
    $r = curl_exec($ch);
    $h = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $e = curl_error($ch);
    curl_close($ch);
    return ['body' => $r, 'http' => $h, 'error' => $e];
}

function mpFaExtractOrders($data) {
    if (!is_array($data)) return [];
    $paths = [
        ['SuccessResponse','Body','Orders','Order'],
        ['SuccessResponse','Body','Order'],
        ['SuccessResponse','Body','Orders'],
        ['SuccessResponse','Body'],
        ['Body','Orders','Order'],
        ['Body','Order'],
        ['Body','Orders'],
        ['Orders','Order'],
        ['Orders'],
        ['Order'],
        ['SuccessResponse','Body','Data'],
        ['Data'],
    ];
    foreach ($paths as $path) {
        $ptr = $data;
        foreach ($path as $k) {
            if (is_array($ptr) && array_key_exists($k, $ptr)) $ptr = $ptr[$k];
            else { $ptr = null; break; }
        }
        if ($ptr === null || $ptr === [] || $ptr === '') continue;
        $list = mpAsList($ptr);
        $orders = [];
        foreach ($list as $x) {
            if (!is_array($x)) continue;
            if (isset($x['Order']) && is_array($x['Order'])) {
                foreach (mpAsList($x['Order']) as $o) if (is_array($o)) $orders[] = $o;
            } else {
                $orders[] = $x;
            }
        }
        $orders = array_values(array_filter($orders, 'is_array'));
        if (!empty($orders)) return $orders;
    }
    return [];
}

function mpFaExtractOrderItems($data) {
    $items = [];
    $walk = function($node, $ctx = []) use (&$walk, &$items) {
        if (!is_array($node)) return;

        $looksItem = isset($node['SellerSku']) || isset($node['ShopSku']) || isset($node['OrderItemId']) || isset($node['OrderItemID']) || isset($node['Sku']) || isset($node['SKU']);
        if ($looksItem) {
            $it = $node;
            if (empty($it['OrderId']) && !empty($ctx['OrderId'])) $it['OrderId'] = $ctx['OrderId'];
            if (empty($it['OrderNumber']) && !empty($ctx['OrderNumber'])) $it['OrderNumber'] = $ctx['OrderNumber'];
            $items[] = $it;
            return;
        }

        $nextCtx = $ctx;
        foreach (['OrderId','OrderID','Id'] as $k) {
            if (!empty($node[$k])) { $nextCtx['OrderId'] = $node[$k]; break; }
        }
        foreach (['OrderNumber','OrderNr','orderNumber'] as $k) {
            if (!empty($node[$k])) { $nextCtx['OrderNumber'] = $node[$k]; break; }
        }

        foreach (['OrderItems','Items'] as $grp) {
            if (isset($node[$grp])) {
                $sub = $node[$grp]['OrderItem'] ?? $node[$grp]['Item'] ?? $node[$grp];
                foreach (mpAsList($sub) as $x) $walk($x, $nextCtx);
                return;
            }
        }
        if (isset($node['OrderItem'])) {
            foreach (mpAsList($node['OrderItem']) as $x) $walk($x, $nextCtx);
            return;
        }
        if (isset($node['Orders'])) { $walk($node['Orders'], $nextCtx); return; }
        if (isset($node['Order'])) { foreach (mpAsList($node['Order']) as $x) $walk($x, $nextCtx); return; }

        foreach ($node as $v) {
            if (is_array($v)) $walk($v, $nextCtx);
        }
    };
    $walk($data, []);

    $out = []; $seen = [];
    foreach ($items as $it) {
        $key = (string)($it['OrderItemId'] ?? $it['OrderItemID'] ?? '') . '|' . (string)($it['OrderId'] ?? '') . '|' . (string)($it['SellerSku'] ?? $it['ShopSku'] ?? $it['Sku'] ?? $it['SKU'] ?? '');
        if ($key === '||') $key = md5(json_encode($it));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $it;
    }
    return $out;
}

// ============================================================
// 7. WALMART API CLIENT
// ============================================================

function mpWalmartBaseUrl($cfg) {
    return 'https://marketplace.walmartapis.com';
}

function mpWalmartConfigIsReady($cfg) {
    return !empty($cfg['client_id']) && !empty($cfg['client_secret']);
}

function mpWalmartHeaders($cfg, $token = '', $forToken = false) {
    $cid = $cfg['client_id'] ?? '';
    $sec = $cfg['client_secret'] ?? '';
    $headers = [
        'WM_MARKET: cl',
        'WM_SVC.NAME: Walmart Marketplace',
        'WM_QOS.CORRELATION_ID: mp-' . bin2hex(random_bytes(8)),
        'Accept: application/json',
        'Content-Type: application/json'
    ];
    if ($forToken) {
        $headers[] = 'Authorization: Basic ' . base64_encode($cid . ':' . $sec);
    } elseif ($token) {
        $headers[] = 'WM_SEC.ACCESS_TOKEN: ' . $token;
    }
    if (!empty($cfg['partner_id'])) $headers[] = 'WM_PARTNER.ID: ' . $cfg['partner_id'];
    return $headers;
}

function mpWalmartGetToken($cfg, $force = false) {
    if (!$force && function_exists('mp_get_config')) {
        $cached = mp_get_config('walmart_token');
        if (!empty($cached['access_token']) && !empty($cached['expires_at']) && ((int)$cached['expires_at'] - 60) > time()) return $cached;
    }
    if (!mpWalmartConfigIsReady($cfg)) return ['error' => 'Walmart no configurado: falta Client ID o Client Secret'];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => mpWalmartBaseUrl($cfg) . '/v3/token',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(array_filter(mpWalmartHeaders($cfg, '', true), function($h){ return stripos($h, 'Content-Type:') !== 0; }), ['Content-Type: application/x-www-form-urlencoded']),
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    if ($err || $http < 200 || $http >= 300 || empty($data['access_token'])) {
        return ['error' => $err ?: ($data['error_description'] ?? $data['error'] ?? "HTTP $http"), 'http' => $http, 'body' => $body];
    }
    $data['expires_at'] = time() + (int)($data['expires_in'] ?? 900);
    if (function_exists('mp_save_config')) {
        mp_save_config('walmart_token', $data);
    }
    return $data;
}

function mpWalmartApi($cfg, $method, $path, $payload = null, $query = []) {
    $tok = mpWalmartGetToken($cfg);
    if (!empty($tok['error'])) return ['success'=>false, 'error'=>$tok['error'], 'http'=>$tok['http'] ?? 0, 'body'=>$tok['body'] ?? ''];
    $url = mpWalmartBaseUrl($cfg) . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $headers = mpWalmartHeaders($cfg, $tok['access_token']);
    $ch = curl_init();
    $opts = [CURLOPT_URL=>$url, CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$headers, CURLOPT_TIMEOUT=>30];
    if ($method === 'POST') $opts[CURLOPT_POST] = true;
    if (in_array($method, ['PUT','DELETE'])) $opts[CURLOPT_CUSTOMREQUEST] = $method;
    if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return ['success'=>(!$err && $http >= 200 && $http < 300), 'http'=>$http, 'error'=>$err ?: null, 'data'=>$data, 'body'=>$body];
}

function mpWalmartNormalizeDateParam($value, $isEnd = false) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value . ($isEnd ? 'T23:59:59Z' : 'T00:00:00Z');
    return $value;
}

function mpWalmartStatusOriginal($line) {
    $statuses = $line['orderLineStatuses']['orderLineStatus'] ?? [];
    if (isset($statuses['status'])) $statuses = [$statuses];
    $priority = ['Delivered'=>4, 'Shipped'=>3, 'Acknowledged'=>2, 'Created'=>1, 'Cancelled'=>5, 'Canceled'=>5];
    $best = '';
    $bestScore = -1;
    foreach ($statuses as $st) {
        $raw = (string)($st['status'] ?? '');
        if ($raw === '') continue;
        $score = $priority[$raw] ?? 0;
        if ($score > $bestScore) { $best = $raw; $bestScore = $score; }
    }
    return $best;
}

function mpWalmartNormalizeStatus($status) {
    $raw = strtolower(trim((string)$status));
    if ($raw === 'delivered') return 'Entregado';
    if ($raw === 'shipped' || $raw === 'acknowledged') return 'Despachado';
    if ($raw === 'cancelled' || $raw === 'canceled') return 'Cancelado';
    if ($raw === 'created') return 'Creado';
    return mpNormalizeOrderStatus($status, 'Walmart');
}

function mpWalmartProductCharge($line) {
    $charges = $line['charges']['charge'] ?? [];
    if (isset($charges['chargeType'])) $charges = [$charges];
    $total = 0;
    foreach ($charges as $c) {
        if (strtoupper((string)($c['chargeType'] ?? '')) === 'PRODUCT') {
            $total += mpMoney($c['chargeAmount']['amount'] ?? 0);
        }
    }
    return $total;
}

function mpWalmartDateFromMs($value) {
    if (!$value) return '';
    $n = (float)$value;
    if ($n > 100000000000) $n = $n / 1000;
    return date('Y-m-d H:i:s', (int)$n);
}

// ============================================================
// 8. STOCK SYNC
// ============================================================

/**
 * Calcular stock total de un producto filtrado por bodega/ubicación
 */
function mpCalcStock($productoId, $inventario, $bodegaId = 0, $ubicacion = '') {
    $total = 0;
    foreach ($inventario as $inv) {
        if ($inv['productoId'] != $productoId) continue;
        if ($bodegaId > 0 && (int)$inv['bodegaId'] !== $bodegaId) continue;
        if ($ubicacion && ($inv['ubicacion'] ?? '') !== $ubicacion) continue;
        $total += (float)$inv['cantidad'];
    }
    return max(0, $total);
}

/**
 * Sync stock a WooCommerce
 */
function mpSyncWc($productos, $inventario, $wcUrl, $ck, $cs, $bodegaId, $ubicacion) {
    $synced = 0; $errors = 0; $notfound = 0; $skipped = 0; $detalles = [];
    foreach ($productos as $prod) {
        $skuProd = $prod['sku'] ?? '';
        if (!mpProductoSyncActivo($prod, 'wc')) { $skipped++; $detalles[] = "$skuProd=skip"; continue; }
        $sku = mpProductoSkuCanal($prod, 'wc');
        if (!$sku) { $skipped++; $detalles[] = "$skuProd=sin_sku_wc"; continue; }
        $total = mpCalcStock($prod['id'], $inventario, $bodegaId, $ubicacion);

        // Buscar producto en WC por SKU
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => rtrim($wcUrl, '/') . "/wp-json/wc/v3/products?sku=" . urlencode($sku) . "&_fields=id,sku,type", CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "$ck:$cs", CURLOPT_TIMEOUT => 10]);
        $r = curl_exec($ch); $h = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($h === 200) {
            $prods = json_decode($r, true);
            if (!empty($prods) && !isset($prods['code'])) {
                $wcId = $prods[0]['id'];
                $ch = curl_init();
                curl_setopt_array($ch, [CURLOPT_URL => rtrim($wcUrl, '/') . "/wp-json/wc/v3/products/$wcId", CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => json_encode(['stock_quantity' => $total, 'manage_stock' => true]), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_USERPWD => "$ck:$cs", CURLOPT_TIMEOUT => 10]);
                curl_exec($ch); $h2 = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                if ($h2 === 200) { $synced++; $detalles[] = "$skuProd=OK($total)"; } else { $errors++; $detalles[] = "$skuProd=ERR(HTTP $h2)"; }
                continue;
            }
        }
        // Fallback: buscar en variaciones
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => rtrim($wcUrl, '/') . "/wp-json/wc/v3/products?_fields=id&per_page=100", CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "$ck:$cs", CURLOPT_TIMEOUT => 10]);
        $r = curl_exec($ch); $h = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($h === 200) {
            $allProds = json_decode($r, true);
            if (!empty($allProds)) {
                foreach ($allProds as $parent) {
                    $ch = curl_init();
                    curl_setopt_array($ch, [CURLOPT_URL => rtrim($wcUrl, '/') . "/wp-json/wc/v3/products/{$parent['id']}/variations?sku=" . urlencode($sku) . "&_fields=id,sku", CURLOPT_RETURNTRANSFER => true, CURLOPT_USERPWD => "$ck:$cs", CURLOPT_TIMEOUT => 10]);
                    $r = curl_exec($ch); $h = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                    if ($h === 200) {
                        $vars = json_decode($r, true);
                        if (!empty($vars) && !isset($vars['code'])) {
                            $wcId = $vars[0]['id'];
                            $ch = curl_init();
                            curl_setopt_array($ch, [CURLOPT_URL => rtrim($wcUrl, '/') . "/wp-json/wc/v3/products/{$parent['id']}/variations/$wcId", CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => json_encode(['stock_quantity' => $total, 'manage_stock' => true]), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_USERPWD => "$ck:$cs", CURLOPT_TIMEOUT => 10]);
                            curl_exec($ch); curl_close($ch);
                            $synced++; $detalles[] = "$skuProd=OK(var,$total)"; continue 2;
                        }
                    }
                }
            }
        }
        $notfound++; $detalles[] = "$skuProd=NOTFOUND";
    }
    return ['synced' => $synced, 'errors' => $errors, 'notfound' => $notfound, 'skipped' => $skipped, 'detalles' => $detalles];
}

/**
 * Sync stock a MercadoLibre
 */
function mpSyncMl($productos, $inventario, $accessToken, $bodegaId, $ubicacion) {
    $synced = 0; $errors = 0; $skipped = 0; $detalles = [];
    foreach ($productos as $prod) {
        $skuProd = $prod['sku'] ?? '';
        if (!mpProductoSyncActivo($prod, 'ml')) { $skipped++; $detalles[] = "$skuProd=skip"; continue; }
        $mlItemId = $prod['mlItemId'] ?? '';
        $mlVariationId = $prod['mlVariationId'] ?? '';
        if (!$mlItemId) { $skipped++; $detalles[] = "$skuProd=sin_mlItemId"; continue; }
        $total = mpCalcStock($prod['id'], $inventario, $bodegaId, $ubicacion);
        $total = max(0, (int)$total);
        $url = $mlVariationId ? "https://api.mercadolibre.com/items/$mlItemId/variations/$mlVariationId" : "https://api.mercadolibre.com/items/$mlItemId";
        $payload = json_encode(['available_quantity' => $total]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 10,
        ]);
        $r = curl_exec($ch); $h = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $resp = json_decode($r, true);
        if ($h === 200 && !isset($resp['error'])) { $synced++; $detalles[] = "$skuProd=OK($total)"; } else { $errors++; $detalles[] = "$skuProd=ERR(HTTP $h)"; }
    }
    return ['synced' => $synced, 'errors' => $errors, 'skipped' => $skipped, 'detalles' => $detalles];
}

/**
 * Sync stock a Falabella
 */
function mpSyncFa($productos, $inventario, $userID, $apiKeyFa, $bodegaId, $ubicacion, $gscFacilityId = '', $sellerWarehousesId = '') {
    $synced = 0; $errors = 0; $skipped = 0; $detalles = [];
    $stocks = [];
    foreach ($productos as $prod) {
        $skuProd = $prod['sku'] ?? '';
        if (!mpProductoSyncActivo($prod, 'fal')) { $skipped++; $detalles[] = "$skuProd=skip"; continue; }
        $sku = $prod['sku'] ?? '';
        if (!$sku) { $skipped++; $detalles[] = "sin_sku=skip"; continue; }
        $falabellaSku = mpProductoSkuCanal($prod, 'fal');
        if (!$falabellaSku) { $skipped++; $detalles[] = "$skuProd=sin_falabellaSku"; continue; }
        $total = mpCalcStock($prod['id'], $inventario, $bodegaId, $ubicacion);
        $total = max(0, (int)$total);
        $stocks[] = ['sku' => $skuProd, 'falSku' => $falabellaSku, 'qty' => $total];
    }
    if (empty($stocks)) {
        return ['synced' => 0, 'errors' => 0, 'skipped' => $skipped, 'detalles' => $detalles];
    }
    $xmlItems = '';
    foreach ($stocks as $s) {
        $swId = $sellerWarehousesId ?: 'sellerWarehouseId1';
        $xmlItems .= '<Stock><GSCFacilityId>' . htmlspecialchars($gscFacilityId) . '</GSCFacilityId><SellerWarehousesId>' . htmlspecialchars($swId) . '</SellerWarehousesId><SellerSku>' . htmlspecialchars($s['falSku']) . '</SellerSku><Quantity>' . (int)$s['qty'] . '</Quantity></Stock>';
    }
    $xmlBody = '<?xml version="1.0" encoding="UTF-8"?><Request><Warehouse>' . $xmlItems . '</Warehouse></Request>';
    $res = mpFaApiCallUpdateStock($xmlBody, $userID, $apiKeyFa);
    $http = (int)$res['http'];
    if ($http === 200) {
        foreach ($stocks as $s) {
            $synced++; $detalles[] = $s['sku'] . '=OK(' . $s['qty'] . ')';
        }
    } else {
        foreach ($stocks as $s) {
            $errors++; $detalles[] = $s['sku'] . '=ERR(HTTP ' . $http . ')';
        }
    }
    return ['synced' => $synced, 'errors' => $errors, 'skipped' => $skipped, 'detalles' => $detalles];
}

/**
 * Sync stock a Walmart
 */
function mpSyncWal($productos, $inventario, $wmConfig, $bodegaId, $ubicacion) {
    $synced = 0; $errors = 0; $skipped = 0; $notfound = 0; $detalles = [];
    if (!mpWalmartConfigIsReady($wmConfig)) {
        return ['synced' => 0, 'errors' => 1, 'notfound' => 0, 'skipped' => 0, 'error' => 'Walmart no configurado'];
    }
    foreach ($productos as $prod) {
        $skuProd = $prod['sku'] ?? '';
        if (!mpProductoSyncActivo($prod, 'wal')) { $skipped++; $detalles[] = "$skuProd=skip"; continue; }
        $sku = mpProductoSkuCanal($prod, 'wal');
        if (!$sku) { $skipped++; $detalles[] = "$skuProd=sin_sku_wal"; continue; }
        $total = mpCalcStock($prod['id'], $inventario, $bodegaId, $ubicacion);
        $total = max(0, (int)$total);
        $payload = [
            'sku' => $sku,
            'quantity' => ['unit' => 'EACH', 'amount' => $total]
        ];
        $res = mpWalmartApi($wmConfig, 'PUT', '/v3/inventory', $payload, ['sku' => $sku]);
        if (!empty($res['success'])) {
            $synced++; $detalles[] = "$skuProd=OK($total)";
        } else {
            $http = (int)($res['http'] ?? 0);
            if ($http === 404) $notfound++; else $errors++;
            $detalles[] = "$skuProd=" . ($http === 404 ? 'NOTFOUND' : "ERR(HTTP $http)");
        }
    }
    return ['synced' => $synced, 'errors' => $errors, 'notfound' => $notfound, 'skipped' => $skipped, 'detalles' => $detalles];
}

// ============================================================
// 9. POLLING SYNC (pedidos Falabella + Walmart)
// ============================================================

function mpFetchFalabellaRowsForSync($desde, $hasta, $productos, &$errores = []) {
    $ventas = [];
    $faConfig = function_exists('mp_get_config') ? mp_get_config('falabella_config') : [];
    $userID = $faConfig['user_id'] ?? '';
    $apiKeyFa = $faConfig['api_key'] ?? '';
    if (!$userID || !$apiKeyFa) { $errores[] = 'Falabella no configurado'; return $ventas; }

    $faOrders = []; $limit = 100; $offset = 0;
    for ($page = 0; $page < 10; $page++) {
        $res = mpFaApiCall('GetOrders', [
            'CreatedAfter' => $desde . 'T00:00:00+0000',
            'CreatedBefore' => $hasta . 'T23:59:59+0000',
            'Limit' => $limit,
            'Offset' => $offset,
        ], $userID, $apiKeyFa, 'JSON');
        $data = json_decode($res['body'], true);
        if (!($res['http'] >= 200 && $res['http'] < 300) || !is_array($data) || isset($data['ErrorResponse']) || isset($data['Errors'])) {
            $errores[] = 'Falabella GetOrders HTTP ' . $res['http'];
            break;
        }
        $batch = mpFaExtractOrders($data);
        if (empty($batch)) break;
        foreach ($batch as $o) if (is_array($o)) $faOrders[] = $o;
        if (count($batch) < $limit) break;
        $offset += $limit;
    }
    if (empty($faOrders)) return $ventas;

    $orderIds = []; $orderNumbers = [];
    foreach ($faOrders as $o) {
        $oid = (string)($o['OrderId'] ?? $o['OrderID'] ?? $o['Id'] ?? '');
        $on = (string)($o['OrderNumber'] ?? $o['OrderNr'] ?? $o['orderNumber'] ?? '');
        if ($oid !== '') $orderIds[] = $oid;
        if ($on !== '') $orderNumbers[] = $on;
    }
    $orderIds = array_values(array_unique($orderIds));
    $orderNumbers = array_values(array_unique($orderNumbers));

    $allItems = [];
    foreach (array_chunk($orderIds, 50) as $chunk) {
        $resMulti = mpFaApiCall('GetMultipleOrderItems', ['OrderIdList' => json_encode(array_values($chunk))], $userID, $apiKeyFa, 'JSON');
        $multiData = json_decode($resMulti['body'], true);
        if ($resMulti['http'] >= 200 && $resMulti['http'] < 300 && is_array($multiData) && !isset($multiData['ErrorResponse']) && !isset($multiData['Errors'])) {
            foreach (mpFaExtractOrderItems($multiData) as $it) if (is_array($it)) $allItems[] = $it;
        }
    }
    if (empty($allItems) && !empty($orderNumbers)) {
        foreach (array_chunk($orderNumbers, 50) as $chunk) {
            $resMulti = mpFaApiCall('GetMultipleOrderItems', ['OrderNumberList' => json_encode(array_values($chunk))], $userID, $apiKeyFa, 'JSON');
            $multiData = json_decode($resMulti['body'], true);
            if ($resMulti['http'] >= 200 && $resMulti['http'] < 300 && is_array($multiData) && !isset($multiData['ErrorResponse']) && !isset($multiData['Errors'])) {
                foreach (mpFaExtractOrderItems($multiData) as $it) if (is_array($it)) $allItems[] = $it;
            }
        }
    }
    $itemsByOrderKey = [];
    foreach ($allItems as $it) {
        $key = (string)($it['OrderId'] ?? $it['OrderID'] ?? $it['OrderNumber'] ?? $it['OrderNr'] ?? '');
        if ($key === '' && count($faOrders) === 1) $key = (string)($faOrders[0]['OrderId'] ?? $faOrders[0]['OrderNumber'] ?? '');
        if ($key === '') continue;
        $itemsByOrderKey[$key][] = $it;
    }

    foreach ($faOrders as $o) {
        $orderNumber = (string)($o['OrderNumber'] ?? $o['OrderNr'] ?? $o['orderNumber'] ?? '');
        $orderId = (string)($o['OrderId'] ?? $o['OrderID'] ?? $o['Id'] ?? '');
        $orden = $orderNumber ?: $orderId;
        if ($orden === '') continue;
        $estadoOrdenOriginal = mpExtractOriginalOrderStatus('Falabella', $o);
        $fecha = $o['CreatedAt'] ?? $o['CreatedDate'] ?? $o['CreatedOn'] ?? $o['created_at'] ?? $o['Created'] ?? '';
        $items = $itemsByOrderKey[$orderId] ?? $itemsByOrderKey[$orderNumber] ?? [];
        if (empty($items) && ($orderId !== '' || $orderNumber !== '')) {
            $resIt = mpFaApiCall('GetOrderItems', $orderId ? ['OrderId'=>$orderId] : ['OrderNumber'=>$orderNumber], $userID, $apiKeyFa, 'JSON');
            $itData = json_decode($resIt['body'], true);
            if ($resIt['http'] >= 200 && $resIt['http'] < 300 && is_array($itData) && !isset($itData['ErrorResponse']) && !isset($itData['Errors'])) $items = mpFaExtractOrderItems($itData);
        }
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $sku = (string)($it['SellerSku'] ?? $it['Sku'] ?? $it['ShopSku'] ?? $it['SKU'] ?? '');
            $shopSku = (string)($it['ShopSku'] ?? $it['shopSku'] ?? '');
            $qty = (float)($it['Quantity'] ?? $it['quantity'] ?? $it['Qty'] ?? 1);
            $prod = mpLocalProductBySku($productos, $sku) ?: mpLocalProductBySku($productos, $shopSku);
            $estadoOriginal = mpExtractOriginalOrderStatus('Falabella', $o, $it) ?: $estadoOrdenOriginal;
            $ventas[] = [
                'sistema'=>'Falabella', 'orden'=>$orden, 'estado'=>mpNormalizeOrderStatus($estadoOriginal, 'Falabella'), 'estado_original'=>$estadoOriginal,
                'sku'=>$sku ?: $shopSku, 'nombre'=>(string)($it['Name'] ?? $it['name'] ?? $it['ItemName'] ?? ($prod['nombre'] ?? '')),
                'cant'=>$qty, 'fecha'=>$fecha, 'order_id_tecnico'=>$orderId
            ];
        }
    }
    return $ventas;
}

function mpFetchWalmartRowsForSync($desde, $hasta, $productos, &$errores = []) {
    $ventas = [];
    $wmCfg = function_exists('mp_get_config') ? mp_get_config('walmart_config') : [];
    if (!mpWalmartConfigIsReady($wmCfg)) { $errores[] = 'Walmart no configurado'; return $ventas; }
    $query = ['createdStartDate'=>mpWalmartNormalizeDateParam($desde, false), 'createdEndDate'=>mpWalmartNormalizeDateParam($hasta, true), 'limit'=>'50'];
    $cursor = '';
    for ($page = 0; $page < 10; $page++) {
        $q = $query;
        if ($cursor !== '' && $cursor !== '-1') $q['nextCursor'] = $cursor;
        $res = mpWalmartApi($wmCfg, 'GET', '/v3/orders', null, $q);
        if (empty($res['success'])) { $errores[] = 'Walmart ' . ($res['error'] ?: ('HTTP ' . ($res['http'] ?? 0))); break; }
        $orders = $res['data']['list']['elements']['order'] ?? [];
        if (isset($orders['purchaseOrderId'])) $orders = [$orders];
        if (!is_array($orders)) $orders = [];
        foreach ($orders as $o) {
            if (!is_array($o)) continue;
            $orden = (string)($o['purchaseOrderId'] ?? '');
            if ($orden === '') continue;
            $fecha = mpWalmartDateFromMs($o['orderDate'] ?? 0);
            $lines = $o['orderLines']['orderLine'] ?? [];
            if (isset($lines['lineNumber'])) $lines = [$lines];
            if (!is_array($lines)) $lines = [];
            foreach ($lines as $line) {
                if (!is_array($line)) continue;
                $sku = (string)($line['item']['sku'] ?? '');
                $qty = (float)($line['orderLineQuantity']['amount'] ?? 1);
                $prod = mpLocalProductBySku($productos, $sku);
                $estadoOriginal = mpWalmartStatusOriginal($line);
                $ventas[] = [
                    'sistema'=>'Walmart', 'orden'=>$orden, 'estado'=>mpWalmartNormalizeStatus($estadoOriginal), 'estado_original'=>$estadoOriginal,
                    'sku'=>$sku, 'nombre'=>(string)($line['item']['productName'] ?? ($prod['nombre'] ?? '')),
                    'cant'=>$qty, 'fecha'=>$fecha, 'order_id_tecnico'=>$orden
                ];
            }
        }
        $cursor = (string)($res['data']['list']['meta']['nextCursor'] ?? '-1');
        if ($cursor === '' || $cursor === '-1' || empty($orders)) break;
    }
    return $ventas;
}

/**
 * Procesar filas de marketplace → movimientos + inventario
 */
function mpProcessMarketplaceRows($rows) {
    $productos = function_exists('mp_get_productos') ? mp_get_productos() : [];
    $inventario = function_exists('mp_get_inventario') ? mp_get_inventario() : [];
    $movimientos = function_exists('mp_get_movimientos') ? mp_get_movimientos() : [];
    $bodegaId = function_exists('mp_get_config') ? ((mp_get_config('webhook_tienda_config'))['bodega_id'] ?? 999) : 999;
    $ubicacion = function_exists('mp_get_config') ? ((mp_get_config('webhook_tienda_config'))['ubicacion'] ?? 'a Piso') : 'a Piso';
    $nextMovId = mpNextNumericId($movimientos);
    $nextInvId = mpNextNumericId($inventario);
    $stats = ['ventas'=>0, 'reversas'=>0, 'sin_producto'=>0, 'canceladas_sin_venta'=>0, 'errores'=>[]];

    $groups = [];
    foreach ($rows as $r) {
        $sis = (string)($r['sistema'] ?? ''); $orden = (string)($r['orden'] ?? '');
        if ($sis === '' || $orden === '') continue;
        $groups[$sis.'|'.$orden][] = $r;
    }

    foreach ($groups as $key => $items) {
        [$sistema, $orden] = explode('|', $key, 2);
        $refVenta = "Venta $sistema #$orden";
        $refReversa = "Reversa $sistema #$orden";
        $esCancelado = false;
        foreach ($items as $it) if (($it['estado'] ?? '') === 'Cancelado') { $esCancelado = true; break; }

        if ($esCancelado) { $stats['canceladas_sin_venta']++; continue; }

        $procesados = 0;
        foreach ($items as $it) {
            $sku = (string)($it['sku'] ?? ''); $qty = (float)($it['cant'] ?? 1);
            if ($qty <= 0 || $sku === '') continue;
            $prod = mpLocalProductBySku($productos, $sku);
            if (!$prod) { $stats['sin_producto']++; continue; }
            $prodId = (int)$prod['id'];
            $idx = mpFindInvIndex($inventario, $prodId, $bodegaId, $ubicacion);
            if ($idx === null) $inventario[] = ['id'=>$nextInvId++, 'productoId'=>$prodId, 'bodegaId'=>$bodegaId, 'cantidad'=>-$qty, 'ubicacion'=>$ubicacion];
            else $inventario[$idx]['cantidad'] -= $qty;
            $movimientos[] = ['id'=>$nextMovId++, 'tipo'=>'salida', 'cantidad'=>-$qty, 'productoId'=>$prodId, 'bodegaId'=>$bodegaId, 'ubicacion'=>$ubicacion, 'fecha'=>date('c'), 'usuario'=>$sistema, 'referencia'=>$refVenta, 'nota'=>'Venta automática por polling API'];
            $procesados++;
        }
        if ($procesados > 0) {
            $stats['ventas'] += $procesados;
            mpLog($sistema, 'sync-orders', 'ok', "$refVenta items=$procesados", $orden);
        }
    }

    if (function_exists('mp_save_inventario')) mp_save_inventario($inventario);
    if (function_exists('mp_save_movimientos')) mp_save_movimientos($movimientos);
    return $stats;
}

// ============================================================
// 10. SALES REPORT (desde APIs oficiales)
// ============================================================

function mpReporteVentas($desde, $hasta, $sistemaFiltro = '') {
    $ventas = [];
    $errores = [];
    $meta = ['mercadolibre'=>0, 'falabella'=>0, 'woocommerce'=>0, 'walmart'=>0, 'errores'=>[]];

    $productos = function_exists('mp_get_productos') ? mp_get_productos() : [];
    $desdeIso = $desde . 'T00:00:00';
    $hastaIso = $hasta . 'T23:59:59';

    // WooCommerce
    if ($sistemaFiltro === '' || $sistemaFiltro === 'WooCommerce') {
        $wcConfig = function_exists('mp_get_config') ? mp_get_config('woocommerce_api_config') : [];
        $wcUrl = $wcConfig['url'] ?? '';
        $ck = $wcConfig['consumer_key'] ?? '';
        $cs = $wcConfig['consumer_secret'] ?? '';
        if ($wcUrl && $ck && $cs) {
            $url = rtrim($wcUrl, '/') . '/wp-json/wc/v3/orders?' . http_build_query([
                'after' => $desdeIso,
                'before' => $hastaIso,
                'per_page' => 100,
                'orderby' => 'date',
                'order' => 'desc',
            ]);
            $res = mpWcGet($url, $ck, $cs);
            $orders = json_decode($res['body'], true);
            if ($res['http'] >= 200 && $res['http'] < 300 && is_array($orders) && !isset($orders['code'])) {
                foreach ($orders as $o) {
                    $orderCreated = $o['date_created'] ?? $o['date_created_gmt'] ?? $o['date_modified'] ?? '';
                    $estadoOriginal = mpExtractOriginalOrderStatus('WooCommerce', $o);
                    $estado = mpNormalizeOrderStatus($estadoOriginal, 'WooCommerce');
                    foreach (($o['line_items'] ?? []) as $it) {
                        $sku = (string)($it['sku'] ?? '');
                        $qty = (float)($it['quantity'] ?? 1);
                        $prod = mpLocalProductBySku($productos, $sku);
                        $venta = mpMoney($it['total'] ?? 0);
                        if (!$venta && $prod) $venta = $qty * (float)($prod['precioVenta'] ?? 0);
                        $ventas[] = [
                            'fecha' => $orderCreated, 'fecha_creacion_api' => $orderCreated,
                            'sistema' => 'WooCommerce', 'orden' => (string)($o['number'] ?? $o['id'] ?? ''),
                            'estado' => $estado, 'estado_original' => $estadoOriginal,
                            'sku' => $sku, 'nombre' => (string)($it['name'] ?? ($prod['nombre'] ?? '')),
                            'cant' => $qty, 'venta' => $venta, 'order_id_tecnico' => (string)($o['id'] ?? ''),
                        ];
                    }
                }
                $meta['woocommerce'] = count($ventas);
            } else {
                $errores[] = 'WooCommerce: HTTP ' . $res['http'];
            }
        } else {
            $errores[] = 'WooCommerce no configurado';
        }
    }

    // MercadoLibre
    if ($sistemaFiltro === '' || $sistemaFiltro === 'MercadoLibre') {
        $mlToken = function_exists('mp_get_config') ? mp_get_config('mercadolibre_token') : [];
        $accessToken = $mlToken['access_token'] ?? '';
        $sellerId = $mlToken['user_id'] ?? '';
        if ($accessToken && $sellerId) {
            $url = 'https://api.mercadolibre.com/orders/search?' . http_build_query([
                'seller' => $sellerId,
                'order.date_created.from' => $desdeIso . '.000-00:00',
                'order.date_created.to' => $hastaIso . '.000-00:00',
                'sort' => 'date_desc',
                'limit' => 50,
            ]);
            $res = mpMlApi('/orders/search?seller=' . $sellerId . '&order.date_created.from=' . urlencode($desdeIso . '.000-00:00') . '&order.date_created.to=' . urlencode($hastaIso . '.000-00:00') . '&sort=date_desc&limit=50', $accessToken);
            if (!$res['error'] && $res['http'] >= 200 && $res['http'] < 300 && is_array($res['data'])) {
                foreach (($res['data']['results'] ?? []) as $o) {
                    $orderCreated = $o['date_created'] ?? $o['date_closed'] ?? '';
                    $shipmentDetail = [];
                    $shipmentId = $o['shipping']['id'] ?? $o['shipping']['shipment_id'] ?? '';
                    if ($shipmentId) $shipmentDetail = mpMlGetShipmentStatus($accessToken, $shipmentId);
                    $estadoOriginal = mpPickMostAdvancedStatus(mpExtractMercadoLibreStatuses($o, $shipmentDetail));
                    $estado = mpNormalizeOrderStatus($estadoOriginal, 'MercadoLibre');
                    foreach (($o['order_items'] ?? []) as $it) {
                        $item = $it['item'] ?? [];
                        $sku = (string)($item['seller_sku'] ?? $item['seller_custom_field'] ?? $item['id'] ?? '');
                        $qty = (float)($it['quantity'] ?? 1);
                        $unit = mpMoney($it['unit_price'] ?? 0);
                        $prod = mpLocalProductBySku($productos, $sku);
                        $ventas[] = [
                            'fecha' => $orderCreated, 'fecha_creacion_api' => $orderCreated,
                            'sistema' => 'MercadoLibre', 'orden' => (string)($o['id'] ?? ''),
                            'estado' => $estado, 'estado_original' => $estadoOriginal,
                            'sku' => $sku, 'nombre' => (string)($item['title'] ?? ($prod['nombre'] ?? '')),
                            'cant' => $qty, 'venta' => $unit * $qty, 'order_id_tecnico' => (string)($o['id'] ?? ''),
                        ];
                    }
                }
                $meta['mercadolibre'] = count($res['data']['results'] ?? []);
            } else {
                $errores[] = 'MercadoLibre: HTTP ' . $res['http'];
            }
        } else {
            $errores[] = 'MercadoLibre no autorizado/configurado';
        }
    }

    // Falabella
    if ($sistemaFiltro === '' || $sistemaFiltro === 'Falabella') {
        $faConfig = function_exists('mp_get_config') ? mp_get_config('falabella_config') : [];
        $userID = $faConfig['user_id'] ?? '';
        $apiKeyFa = $faConfig['api_key'] ?? '';
        if ($userID && $apiKeyFa) {
            $faOrders = [];
            $limit = 100; $offset = 0;
            for ($page = 0; $page < 20; $page++) {
                $res = mpFaApiCall('GetOrders', [
                    'CreatedAfter' => $desde . 'T00:00:00+0000',
                    'CreatedBefore' => $hasta . 'T23:59:59+0000',
                    'Limit' => $limit, 'Offset' => $offset,
                ], $userID, $apiKeyFa, 'JSON');
                $data = json_decode($res['body'], true);
                if (!($res['http'] >= 200 && $res['http'] < 300) || !is_array($data) || isset($data['ErrorResponse']) || isset($data['Errors'])) break;
                $batch = mpFaExtractOrders($data);
                if (empty($batch)) break;
                foreach ($batch as $ord) if (is_array($ord)) $faOrders[] = $ord;
                if (count($batch) < $limit) break;
                $offset += $limit;
            }

            $orderIds = []; $orderNumbers = [];
            foreach ($faOrders as $o) {
                $orderId = (string)($o['OrderId'] ?? $o['OrderID'] ?? $o['Id'] ?? '');
                $orderNumber = (string)($o['OrderNumber'] ?? $o['OrderNr'] ?? $o['orderNumber'] ?? '');
                if ($orderId !== '') $orderIds[] = $orderId;
                if ($orderNumber !== '') $orderNumbers[] = $orderNumber;
            }
            $orderIds = array_values(array_unique($orderIds));
            $orderNumbers = array_values(array_unique($orderNumbers));

            $allItems = [];
            foreach (array_chunk($orderIds, 50) as $chunk) {
                $resMulti = mpFaApiCall('GetMultipleOrderItems', ['OrderIdList' => json_encode(array_values($chunk))], $userID, $apiKeyFa, 'JSON');
                $multiData = json_decode($resMulti['body'], true);
                if ($resMulti['http'] >= 200 && $resMulti['http'] < 300 && is_array($multiData) && !isset($multiData['ErrorResponse']) && !isset($multiData['Errors'])) {
                    foreach (mpFaExtractOrderItems($multiData) as $entry) if (is_array($entry)) $allItems[] = $entry;
                }
            }
            if (empty($allItems) && !empty($orderNumbers)) {
                foreach (array_chunk($orderNumbers, 50) as $chunk) {
                    $resMulti = mpFaApiCall('GetMultipleOrderItems', ['OrderNumberList' => json_encode(array_values($chunk))], $userID, $apiKeyFa, 'JSON');
                    $multiData = json_decode($resMulti['body'], true);
                    if ($resMulti['http'] >= 200 && $resMulti['http'] < 300 && is_array($multiData) && !isset($multiData['ErrorResponse']) && !isset($multiData['Errors'])) {
                        foreach (mpFaExtractOrderItems($multiData) as $entry) if (is_array($entry)) $allItems[] = $entry;
                    }
                }
            }

            $itemsByOrderKey = [];
            foreach ($allItems as $it) {
                $itemOrderId = (string)($it['OrderId'] ?? $it['OrderID'] ?? '');
                $itemOrderNumber = (string)($it['OrderNumber'] ?? $it['OrderNr'] ?? '');
                $key = $itemOrderId ?: $itemOrderNumber;
                if ($key === '' && count($faOrders) === 1) $key = (string)($faOrders[0]['OrderId'] ?? $faOrders[0]['OrderNumber'] ?? '');
                if ($key === '') continue;
                $itemsByOrderKey[$key][] = $it;
            }

            foreach ($faOrders as $o) {
                $orderNumber = (string)($o['OrderNumber'] ?? $o['OrderNr'] ?? $o['orderNumber'] ?? '');
                $orderId = (string)($o['OrderId'] ?? $o['OrderID'] ?? $o['Id'] ?? '');
                $estadoOrdenOriginal = mpExtractOriginalOrderStatus('Falabella', $o);
                $estadoOrden = mpNormalizeOrderStatus($estadoOrdenOriginal, 'Falabella');
                $fechaCreacionApi = $o['CreatedAt'] ?? $o['CreatedDate'] ?? $o['CreatedOn'] ?? $o['created_at'] ?? $o['Created'] ?? '';
                $items = $itemsByOrderKey[$orderId] ?? $itemsByOrderKey[$orderNumber] ?? [];

                if (empty($items) && ($orderId !== '' || $orderNumber !== '')) {
                    $resIt = mpFaApiCall('GetOrderItems', $orderId ? ['OrderId'=>$orderId] : ['OrderNumber'=>$orderNumber], $userID, $apiKeyFa, 'JSON');
                    $itData = json_decode($resIt['body'], true);
                    if ($resIt['http'] >= 200 && $resIt['http'] < 300 && is_array($itData) && !isset($itData['ErrorResponse']) && !isset($itData['Errors'])) {
                        $items = mpFaExtractOrderItems($itData);
                    }
                }

                if (empty($items)) continue;

                foreach ($items as $it) {
                    if (!is_array($it)) $it = [];
                    $sku = (string)($it['SellerSku'] ?? $it['Sku'] ?? $it['ShopSku'] ?? $it['SKU'] ?? '');
                    $shopSku = (string)($it['ShopSku'] ?? $it['shopSku'] ?? '');
                    $qty = (float)($it['Quantity'] ?? $it['quantity'] ?? $it['Qty'] ?? 1);
                    $prod = mpLocalProductBySku($productos, $sku) ?: mpLocalProductBySku($productos, $shopSku);
                    $unitOrTotal = mpMoney($it['PaidPrice'] ?? $it['ItemPrice'] ?? $it['UnitPrice'] ?? $it['Price'] ?? 0);
                    $venta = $unitOrTotal;
                    if ($venta && $qty > 1 && !isset($it['PaidPrice'])) $venta = $venta * $qty;
                    if (!$venta && $prod) $venta = $qty * (float)($prod['precioVenta'] ?? 0);
                    $estadoItemOriginal = mpExtractOriginalOrderStatus('Falabella', $o, $it) ?: $estadoOrdenOriginal;
                    $estadoItem = mpNormalizeOrderStatus($estadoItemOriginal, 'Falabella');
                    $ventas[] = [
                        'fecha' => $fechaCreacionApi, 'fecha_creacion_api' => $fechaCreacionApi,
                        'sistema' => 'Falabella', 'orden' => $orderNumber ?: $orderId,
                        'estado' => $estadoItem, 'estado_original' => $estadoItemOriginal,
                        'sku' => $sku ?: $shopSku, 'nombre' => (string)($it['Name'] ?? $it['name'] ?? $it['ItemName'] ?? ($prod['nombre'] ?? '')),
                        'cant' => $qty, 'venta' => $venta, 'order_id_tecnico' => $orderId,
                    ];
                }
            }
            $meta['falabella'] = count($faOrders);
        } else {
            $errores[] = 'Falabella no configurado';
        }
    }

    // Walmart
    if ($sistemaFiltro === '' || $sistemaFiltro === 'Walmart') {
        $wmCfg = function_exists('mp_get_config') ? mp_get_config('walmart_config') : [];
        if (mpWalmartConfigIsReady($wmCfg)) {
            $query = ['createdStartDate'=>mpWalmartNormalizeDateParam($desde, false), 'createdEndDate'=>mpWalmartNormalizeDateParam($hasta, true), 'limit'=>'50'];
            $wmOrders = [];
            $cursor = '';
            for ($page = 0; $page < 10; $page++) {
                $q = $query;
                if ($cursor !== '' && $cursor !== '-1') $q['nextCursor'] = $cursor;
                $res = mpWalmartApi($wmCfg, 'GET', '/v3/orders', null, $q);
                if (empty($res['success'])) { $errores[] = 'Walmart: ' . ($res['error'] ?: ('HTTP ' . ($res['http'] ?? 0))); break; }
                $orders = $res['data']['list']['elements']['order'] ?? [];
                if (isset($orders['purchaseOrderId'])) $orders = [$orders];
                if (!is_array($orders)) $orders = [];
                foreach ($orders as $o) if (is_array($o)) $wmOrders[] = $o;
                $cursor = (string)($res['data']['list']['meta']['nextCursor'] ?? '-1');
                if ($cursor === '' || $cursor === '-1' || empty($orders)) break;
            }

            foreach ($wmOrders as $o) {
                $fechaCreacionApi = mpWalmartDateFromMs($o['orderDate'] ?? 0);
                $purchaseOrderId = (string)($o['purchaseOrderId'] ?? '');
                $customerOrderId = (string)($o['customerOrderId'] ?? '');
                $lines = $o['orderLines']['orderLine'] ?? [];
                if (isset($lines['lineNumber'])) $lines = [$lines];
                if (!is_array($lines)) $lines = [];
                foreach ($lines as $line) {
                    if (!is_array($line)) continue;
                    $sku = (string)($line['item']['sku'] ?? '');
                    $qty = (float)($line['orderLineQuantity']['amount'] ?? 1);
                    $prod = mpLocalProductBySku($productos, $sku);
                    $venta = mpWalmartProductCharge($line);
                    if (!$venta && $prod) $venta = $qty * (float)($prod['precioVenta'] ?? 0);
                    $estadoOriginal = mpWalmartStatusOriginal($line);
                    $estado = mpWalmartNormalizeStatus($estadoOriginal);
                    $ventas[] = [
                        'fecha' => $fechaCreacionApi, 'fecha_creacion_api' => $fechaCreacionApi,
                        'sistema' => 'Walmart', 'orden' => $purchaseOrderId,
                        'estado' => $estado, 'estado_original' => $estadoOriginal,
                        'sku' => $sku, 'nombre' => (string)($line['item']['productName'] ?? ($prod['nombre'] ?? '')),
                        'cant' => $qty, 'venta' => $venta, 'order_id_tecnico' => $purchaseOrderId,
                    ];
                }
            }
            $meta['walmart'] = count($wmOrders);
        } else {
            $errores[] = 'Walmart no configurado';
        }
    }

    usort($ventas, function($a, $b) { return strtotime($b['fecha'] ?? '') <=> strtotime($a['fecha'] ?? ''); });
    $meta['errores'] = $errores;
    return ['ventas' => $ventas, 'meta' => $meta];
}

// ============================================================
// 11. WEBHOOK HANDLERS
// ============================================================

/**
 * Procesar webhook de WooCommerce (order.created/updated/refunded/deleted)
 * $payload = json_decode(file_get_contents('php://input'), true)
 */
function mpHandleWooWebhook($payload, $topic = '', $statusOverride = '') {
    if (!is_array($payload)) return ['error' => 'Payload inválido'];
    $productos = function_exists('mp_get_productos') ? mp_get_productos() : [];
    $movimientos = function_exists('mp_get_movimientos') ? mp_get_movimientos() : [];
    $inventario = function_exists('mp_get_inventario') ? mp_get_inventario() : [];
    $config = function_exists('mp_get_config') ? mp_get_config('webhook_tienda_config') : [];
    $bodegaId = $config['bodega_id'] ?? 1;
    $ubicacion = $config['ubicacion'] ?? 'Tienda';

    $orderId = $payload['id'] ?? '?';
    $wcTopic = $topic ?: ($payload['webhook_topic'] ?? $payload['topic'] ?? '');
    $wcStatus = $statusOverride ?: ($payload['status'] ?? '');

    $esReversion = (stripos($wcTopic, 'refund') !== false) || (stripos($wcTopic, 'deleted') !== false) || in_array(strtolower($wcStatus), ['cancelled', 'refunded', 'failed', 'trash']);

    if ($esReversion) {
        $refOrig = "Venta WooCommerce #$orderId";
        $refOrigLegacy = "Pedido WooCommerce #$orderId";
        $refRepoWc = "Reposición WooCommerce #$orderId";
        $ventaWcExiste = mpMovExists($movimientos, $refOrig) || mpMovExists($movimientos, $refOrigLegacy);
        $repoWcExiste = mpMovExists($movimientos, $refRepoWc) || mpMovExists($movimientos, "Anulación WooCommerce #$orderId");

        if (!$ventaWcExiste) return ['success' => true, 'mensaje' => 'Cancelado sin venta previa'];
        if ($repoWcExiste) return ['success' => true, 'mensaje' => 'Reposición ya registrada'];

        $nextId = mpNextNumericId($movimientos);
        $nextInvId = mpNextNumericId($inventario);
        $revertidos = 0;
        $items = $payload['line_items'] ?? [];
        if (empty($items)) {
            foreach ($movimientos as $m) {
                if (($m['referencia'] ?? '') === $refOrig || ($m['referencia'] ?? '') === $refOrigLegacy) {
                    $prodId = $m['productoId']; $qty = abs((float)($m['cantidad'] ?? 0));
                    if ($qty <= 0) continue;
                    $idx = mpFindInvIndex($inventario, $prodId, $bodegaId, $ubicacion);
                    if ($idx === null) $inventario[] = ['id'=>$nextInvId++, 'productoId'=>$prodId, 'bodegaId'=>$bodegaId, 'cantidad'=>$qty, 'ubicacion'=>$ubicacion];
                    else $inventario[$idx]['cantidad'] += $qty;
                    $movimientos[] = ['id'=>$nextId++, 'tipo'=>'entrada', 'cantidad'=>$qty, 'productoId'=>$prodId, 'bodegaId'=>$bodegaId, 'ubicacion'=>$ubicacion, 'fecha'=>date('c'), 'usuario'=>'Tienda Online', 'referencia'=>$refRepoWc, 'nota'=>"Cancelación/Reembolso ($wcStatus)"];
                    $revertidos++;
                }
            }
        } else {
            foreach ($items as $item) {
                $sku = $item['sku'] ?? ''; $qty = (float)($item['quantity'] ?? 1);
                if (!$sku) continue;
                $prod = mpLocalProductBySku($productos, $sku);
                if (!$prod) continue;
                $idx = mpFindInvIndex($inventario, $prod['id'], $bodegaId, $ubicacion);
                if ($idx === null) $inventario[] = ['id'=>$nextInvId++, 'productoId'=>$prod['id'], 'bodegaId'=>$bodegaId, 'cantidad'=>$qty, 'ubicacion'=>$ubicacion];
                else $inventario[$idx]['cantidad'] += $qty;
                $movimientos[] = ['id'=>$nextId++, 'tipo'=>'entrada', 'cantidad'=>$qty, 'productoId'=>$prod['id'], 'bodegaId'=>$bodegaId, 'ubicacion'=>$ubicacion, 'fecha'=>date('c'), 'usuario'=>'Tienda Online', 'referencia'=>$refRepoWc, 'nota'=>"Cancelación/Reembolso ($wcStatus)"];
                $revertidos++;
            }
        }
        if ($revertidos > 0) {
            if (function_exists('mp_save_inventario')) mp_save_inventario($inventario);
            if (function_exists('mp_save_movimientos')) mp_save_movimientos($movimientos);
        }
        return ['success' => true, 'revertidos' => $revertidos];
    }

    // Order created/updated
    if (!isset($payload['line_items'])) return ['error' => 'Sin line_items'];

    $wcStatusNorm = strtolower(trim($wcStatus));
    if ($wcStatusNorm && !in_array($wcStatusNorm, ['processing','completed','on-hold'], true)) {
        return ['success' => true, 'mensaje' => "Estado $wcStatusNorm ignorado"];
    }

    $refWc = "Venta WooCommerce #$orderId";
    if (mpMovExists($movimientos, $refWc) || mpMovExists($movimientos, "Pedido WooCommerce #$orderId")) {
        return ['success' => true, 'mensaje' => 'Ya procesado'];
    }

    $nextId = mpNextNumericId($movimientos);
    $nextInvId = mpNextNumericId($inventario);
    $errores = []; $contador = 0;

    foreach ($payload['line_items'] as $item) {
        $sku = trim($item['sku'] ?? '');
        if (!$sku) { $errores[] = "Item #{$item['id']}: sin SKU"; continue; }
        $prod = mpLocalProductBySku($productos, $sku);
        if (!$prod) { $errores[] = "SKU '$sku' no encontrado"; continue; }
        $qty = (float)($item['quantity'] ?? 1);
        $idx = mpFindInvIndex($inventario, $prod['id'], $bodegaId, $ubicacion);
        if ($idx === null) $inventario[] = ['id'=>$nextInvId++, 'productoId'=>$prod['id'], 'bodegaId'=>$bodegaId, 'cantidad'=>-$qty, 'ubicacion'=>$ubicacion];
        else $inventario[$idx]['cantidad'] -= $qty;
        $movimientos[] = ['id'=>$nextId++, 'tipo'=>'salida', 'cantidad'=>-$qty, 'productoId'=>$prod['id'], 'bodegaId'=>$bodegaId, 'ubicacion'=>$ubicacion, 'fecha'=>date('c'), 'usuario'=>'Tienda Online', 'referencia'=>$refWc, 'nota'=>''];
        $contador++;
    }

    if ($contador > 0) {
        if (function_exists('mp_save_inventario')) mp_save_inventario($inventario);
        if (function_exists('mp_save_movimientos')) mp_save_movimientos($movimientos);
    }

    return ['success' => true, 'procesados' => $contador, 'errores' => $errores];
}

// ============================================================
// 12. CONVENIENCE: sync all stock a un canal
// ============================================================

function mpSyncAllStock($canal) {
    $productos = function_exists('mp_get_productos') ? mp_get_productos() : [];
    $inventario = function_exists('mp_get_inventario') ? mp_get_inventario() : [];
    $config = function_exists('mp_get_config') ? mp_get_config : null;

    switch ($canal) {
        case 'woocommerce':
            $cfg = $config ? $config('woocommerce_api_config') : [];
            $syncCfg = $config ? $config('webhook_tienda_config') : [];
            return mpSyncWc($productos, $inventario, $cfg['url'] ?? '', $cfg['consumer_key'] ?? '', $cfg['consumer_secret'] ?? '', (int)($syncCfg['bodega_id'] ?? 0), $syncCfg['ubicacion'] ?? '');
        case 'mercadolibre':
            $cfg = $config ? $config('mercadolibre_token') : [];
            $syncCfg = $config ? $config('mercadolibre_config') : [];
            return mpSyncMl($productos, $inventario, $cfg['access_token'] ?? '', (int)($syncCfg['sync_bodega_id'] ?? 0), $syncCfg['sync_ubicacion'] ?? '');
        case 'falabella':
            $cfg = $config ? $config('falabella_config') : [];
            return mpSyncFa($productos, $inventario, $cfg['user_id'] ?? '', $cfg['api_key'] ?? '', (int)($cfg['sync_bodega_id'] ?? 0), $cfg['sync_ubicacion'] ?? '', $cfg['sync_gsc_facility_id'] ?? '', $cfg['sync_seller_warehouses_id'] ?? '');
        case 'walmart':
            $cfg = $config ? $config('walmart_config') : [];
            return mpSyncWal($productos, $inventario, $cfg, (int)($cfg['sync_bodega_id'] ?? 0), $cfg['sync_ubicacion'] ?? '');
        default:
            return ['error' => "Canal desconocido: $canal"];
    }
}

/**
 * Sync stock a los 4 canales
 */
function mpSyncAllStockAllChannels() {
    return [
        'woocommerce' => mpSyncAllStock('woocommerce'),
        'mercadolibre' => mpSyncAllStock('mercadolibre'),
        'falabella' => mpSyncAllStock('falabella'),
        'walmart' => mpSyncAllStock('walmart'),
    ];
}

/**
 * Obtener reporte de ventas (wrapper rápido)
 */
function mpSyncMarketplaceOrders($desde = '', $hasta = '') {
    if (!$desde) $desde = date('Y-m-d', strtotime('-1 day'));
    if (!$hasta) $hasta = date('Y-m-d');
    $errores = [];
    $rows = [];
    $rows = array_merge($rows, mpFetchFalabellaRowsForSync($desde, $hasta, function_exists('mp_get_productos') ? mp_get_productos() : [], $errores));
    $rows = array_merge($rows, mpFetchWalmartRowsForSync($desde, $hasta, function_exists('mp_get_productos') ? mp_get_productos() : [], $errores));
    $stats = mpProcessMarketplaceRows($rows);
    $stats['errores'] = array_values(array_merge($stats['errores'] ?? [], $errores));
    return ['desde' => $desde, 'hasta' => $hasta, 'rows' => count($rows), 'stats' => $stats];
}
