<?php
/**
 * Argora Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Taras Kondratyuk <help@argora.org>
 * @copyright  Copyright (c) 2025 Argora
 * @license    MIT License
 * @link       https://github.com/getargora/foundry
 */

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class SparkController extends Controller
{
    public function listOrders(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = $this->container->get('db');

        // Map fields to fully qualified columns for filtering/sorting
        // Adjust field names if needed
        $allowedFieldsMap = [
            'user_id' => 'o.id',
            'user_id' => 'o.user_id',
            'service_type' => 'o.service_type',
            'status' => 'o.status',
            'amount_due' => 'o.amount_due',
            'currency' => 'o.currency',
            'created_at' => 'o.created_at',
            'paid_at' => 'o.paid_at',
            'invoice_id' => 'o.invoice_id',
            'username' => 'u.username'
        ];

        // --- SORTING ---
        $sortField = 'o.created_at'; // default sort by date
        $sortDir = 'desc';
        if (!empty($params['order'])) {
            $orderParts = explode(',', $params['order']);
            if (count($orderParts) === 2) {
                $fieldCandidate = preg_replace('/[^a-zA-Z0-9_]/', '', $orderParts[0]);
                if (array_key_exists($fieldCandidate, $allowedFieldsMap)) {
                    $sortField = $allowedFieldsMap[$fieldCandidate];
                }
                $sortDir = strtolower($orderParts[1]) === 'asc' ? 'asc' : 'desc';
            }
        }

        // --- PAGINATION ---
        $page = 1;
        $size = 10;
        if (!empty($params['page'])) {
            $pageParts = explode(',', $params['page']);
            if (count($pageParts) === 2) {
                $pageNum = (int)$pageParts[0];
                $pageSize = (int)$pageParts[1];
                if ($pageNum > 0) {
                    $page = $pageNum;
                }
                if ($pageSize > 0) {
                    $size = $pageSize;
                }
            }
        }
        $offset = ($page - 1) * $size;

        // --- FILTERING ---
        $whereClauses = [];
        $bindParams = [];
        foreach ($params as $key => $value) {
            if (preg_match('/^filter\d+$/', $key)) {
                $fParts = explode(',', $value);
                if (count($fParts) === 3) {
                    list($fField, $fOp, $fVal) = $fParts;
                    $fField = preg_replace('/[^a-zA-Z0-9_]/', '', $fField);

                    // Ensure the field is allowed and fully qualify it
                    if (!array_key_exists($fField, $allowedFieldsMap)) {
                        // Skip unknown fields
                        continue;
                    }
                    $column = $allowedFieldsMap[$fField];

                    switch ($fOp) {
                        case 'eq':
                            $whereClauses[] = "$column = :f_{$key}";
                            $bindParams["f_{$key}"] = $fVal;
                            break;
                        case 'cs':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal%";
                            break;
                        case 'sw':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "$fVal%";
                            break;
                        case 'ew':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal";
                            break;
                        // Add other cases if needed
                    }
                }
            }
        }

        // Check admin status and apply user filter if needed
        $userCondition = '';
        if ($_SESSION['auth_roles'] !== 0) { // not admin
            $userId = $_SESSION['auth_user_id'];
            $userCondition = "o.user_id = :userId";
            $bindParams["userId"] = $userId;
        }

        // Base SQL
        $sqlBase = "
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
        ";

        // Combine user condition and search filters
        if (!empty($whereClauses)) {
            // We have search conditions
            $filtersCombined = "(" . implode(" OR ", $whereClauses) . ")";
            if ($userCondition) {
                // If userCondition exists and we have filters
                // we do userCondition AND (filters OR...)
                $sqlWhere = "WHERE $userCondition AND $filtersCombined";
            } else {
                // No user restriction, just the filters
                $sqlWhere = "WHERE $filtersCombined";
            }
        } else {
            // No search filters
            if ($userCondition) {
                // Only user condition
                $sqlWhere = "WHERE $userCondition";
            } else {
                // No filters, no user condition
                $sqlWhere = '';
            }
        }

        // Count total results
        $totalSql = "SELECT COUNT(DISTINCT o.id) AS total $sqlBase $sqlWhere";
        $totalCount = $db->selectValue($totalSql, $bindParams);

        // Data query
        $selectFields = "
            o.id,
            o.user_id,
            o.service_type,
            o.status,
            o.amount_due,
            o.currency,
            o.created_at,
            o.paid_at,
            o.invoice_id,
            u.username
        ";

        $dataSql = "
            SELECT $selectFields
            $sqlBase
            $sqlWhere
            ORDER BY $sortField $sortDir
            " . $this->limitClause($offset, $size) . "
        ";

        $records = $db->select($dataSql, $bindParams);

        // Ensure records is always an array
        if (!$records) {
            $records = [];
        }

        $payload = [
            'records' => $records,
            'results' => $totalCount
        ];

        $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function listTransactions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $db = $this->container->get('db');

        // Map fields to fully qualified columns for filtering/sorting
        $allowedFieldsMap = [
            'user_id' => 'tr.user_id',
            'type' => 'tr.type',
            'category' => 'tr.category',
            'description' => 'tr.description',
            'amount' => 'tr.amount',
            'currency' => 'tr.currency',
            'status' => 'tr.status',
            'created_at' => 'tr.created_at',
            'username' => 'u.username'
        ];

        // --- SORTING ---
        $sortField = 'tr.created_at'; // default sort by date
        $sortDir = 'desc';
        if (!empty($params['order'])) {
            $orderParts = explode(',', $params['order']);
            if (count($orderParts) === 2) {
                $fieldCandidate = preg_replace('/[^a-zA-Z0-9_]/', '', $orderParts[0]);
                if (array_key_exists($fieldCandidate, $allowedFieldsMap)) {
                    $sortField = $allowedFieldsMap[$fieldCandidate];
                }
                $sortDir = strtolower($orderParts[1]) === 'asc' ? 'asc' : 'desc';
            }
        }

        // --- PAGINATION ---
        $page = 1;
        $size = 10;
        if (!empty($params['page'])) {
            $pageParts = explode(',', $params['page']);
            if (count($pageParts) === 2) {
                $pageNum = (int)$pageParts[0];
                $pageSize = (int)$pageParts[1];
                if ($pageNum > 0) {
                    $page = $pageNum;
                }
                if ($pageSize > 0) {
                    $size = $pageSize;
                }
            }
        }
        $offset = ($page - 1) * $size;

        // --- FILTERING ---
        $whereClauses = [];
        $bindParams = [];
        foreach ($params as $key => $value) {
            if (preg_match('/^filter\d+$/', $key)) {
                $fParts = explode(',', $value);
                if (count($fParts) === 3) {
                    list($fField, $fOp, $fVal) = $fParts;
                    $fField = preg_replace('/[^a-zA-Z0-9_]/', '', $fField);

                    // Ensure the field is allowed and fully qualify it
                    if (!array_key_exists($fField, $allowedFieldsMap)) {
                        // Skip unknown fields
                        continue;
                    }
                    $column = $allowedFieldsMap[$fField];

                    switch ($fOp) {
                        case 'eq':
                            $whereClauses[] = "$column = :f_{$key}";
                            $bindParams["f_{$key}"] = $fVal;
                            break;
                        case 'cs':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal%";
                            break;
                        case 'sw':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "$fVal%";
                            break;
                        case 'ew':
                            $whereClauses[] = "$column LIKE :f_{$key}";
                            $bindParams["f_{$key}"] = "%$fVal";
                            break;
                        // Add other cases if needed
                    }
                }
            }
        }

        // Check admin status and apply user filter if needed
        $userCondition = '';
        if ($_SESSION['auth_roles'] !== 0) { // not admin
            $userId = $_SESSION['auth_user_id'];
            $userCondition = "tr.user_id = :userId";
            $bindParams["userId"] = $userId;
        }

        // Base SQL
        $sqlBase = "
            FROM transactions tr
            LEFT JOIN users u ON tr.user_id = u.id
        ";

        // Combine user condition and search filters
        if (!empty($whereClauses)) {
            // We have search conditions
            $filtersCombined = "(" . implode(" OR ", $whereClauses) . ")";
            if ($userCondition) {
                // If userCondition exists and we have filters
                // we do userCondition AND (filters OR...)
                $sqlWhere = "WHERE $userCondition AND $filtersCombined";
            } else {
                // No user restriction, just the filters
                $sqlWhere = "WHERE $filtersCombined";
            }
        } else {
            // No search filters
            if ($userCondition) {
                // Only user condition
                $sqlWhere = "WHERE $userCondition";
            } else {
                // No filters, no user condition
                $sqlWhere = '';
            }
        }

        // Count total results
        $totalSql = "SELECT COUNT(DISTINCT tr.id) AS total $sqlBase $sqlWhere";
        $totalCount = $db->selectValue($totalSql, $bindParams);

        // Data query
        $selectFields = "
            tr.user_id,
            tr.type,
            tr.category,
            tr.description,
            tr.amount,
            tr.currency,
            tr.status,
            tr.created_at,
            u.username
        ";

        $dataSql = "
            SELECT $selectFields
            $sqlBase
            $sqlWhere
            ORDER BY $sortField $sortDir
            " . $this->limitClause($offset, $size) . "
        ";

        $records = $db->select($dataSql, $bindParams);

        // Ensure records is always an array
        if (!$records) {
            $records = [];
        }

        $payload = [
            'records' => $records,
            'results' => $totalCount
        ];

        $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response;
    }

    public function domainCheck(Request $request, Response $response): Response
    {
        $db = $this->container->get('db');

        if ($request->getMethod() !== 'POST') {
            return $response
                ->withHeader('Location', '/')
                ->withStatus(302);
        }

        $limit = 10;
        $window = 60;

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $bucket = intdiv(time(), $window);
        $key = 'domain-check:' . hash('sha256', $ip . ':' . $bucket);

        apcu_add($key, 0, $window * 2);
        $count = apcu_inc($key);

        if ($count > $limit) {
            $retryAfter = $window - (time() % $window);

            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Too many domain checks. Please try again shortly.',
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json; charset=UTF-8')
                ->withHeader('Retry-After', (string) $retryAfter)
                ->withStatus(429);
        }

        $params = (array) $request->getParsedBody();
        $requestedDomains = $params['domains'] ?? null;

        if (
            !is_array($requestedDomains) ||
            !array_is_list($requestedDomains) ||
            count($requestedDomains) !== 1 ||
            !is_string($requestedDomains[0]) ||
            trim($requestedDomains[0]) === ''
        ) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Exactly one domain is required.',
            ], JSON_UNESCAPED_UNICODE));

            return $response
                ->withHeader('Content-Type', 'application/json; charset=UTF-8')
                ->withStatus(422);
        }

        $domain = mb_strtolower(trim($requestedDomains[0]), 'UTF-8');
        $asciiDomain = idn_to_ascii(
            $domain,
            IDNA_DEFAULT,
            INTL_IDNA_VARIANT_UTS46
        );

        $domains = [$asciiDomain ?: $domain];
        $domainData = getDomainConfig($domains, $db);

        if (empty($domainData) || !isset($domainData[0]['tld'])) {
            $payload = [
                'success' => false,
                'message' => 'Error checking domain.'
            ];

            $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
            $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
            return $response;
        }

        $registryType = getRegistryExtensionByTld('.'.$domainData[0]['tld']);

        try {
            $epp = connectEpp(
                $registryType,
                $domainData[0]['host'],
                $domainData[0]['port'],
                $domainData[0]['cafile'] ?? '',
                $domainData[0]['cert_file'],
                $domainData[0]['key_file'],
                $domainData[0]['passphrase'] ?? '',
                $domainData[0]['username'],
                $domainData[0]['password']
            );

            $domainCheck = $epp->domainCheck(['domains' => $domains]);

            if (isset($domainCheck['error'])) {
                $payload = [
                    'success' => false,
                    'message' => $domainCheck['error']
                ];
            } else {
                $results = [];
                $x = 1;
                foreach ($domainCheck['domains'] as $domain) {
                    $reason = $domain['avail'] ? null : ($domain['reason'] ?? null);

                    $transferable = (
                        !$domain['avail']
                        && is_string($reason)
                        && in_array(strtolower(trim($reason)), ['in use', 'object exists'], true)
                    );

                    $fqdn = $domain['name'];
                    $ownedServiceId = $db->selectValue(
                        'SELECT `id` FROM `services` WHERE `type` = ? AND `service_name` = ? LIMIT 1',
                        [ 'domain', $fqdn ]
                    );

                    if ($ownedServiceId !== null) {
                        $transferable = false;
                    }

                    $results[] = [
                        'index'     => $x++,
                        'name'      => $domain['name'],
                        'available' => $domain['avail'],
                        'reason'    => $reason,
                    ] + ($transferable ? ['transferable' => true] : []);
                }

                $payload = [
                    'success' => true,
                    'results' => count($results),
                    'domains' => $results
                ];
            }

            $epp->logout();
        } catch (\Throwable $e) {
            $payload = [
                'success' => false,
                'message' => 'EPP error: ' . $e->getMessage()
            ];
        }

        $response = $response->withHeader('Content-Type', 'application/json; charset=UTF-8');
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(200);
    }

    private function limitClause(int $offset, int $size): string
    {
        // harden numbers
        $offset = max(0, (int)$offset);
        $size   = max(1, (int)$size);

        switch (envi('DB_DRIVER')) {
            case 'mysql':
                // MySQL/MariaDB
                return "LIMIT {$offset}, {$size}";
            case 'pgsql':
            case 'sqlite':
            default:
                // PostgreSQL & SQLite
                return "LIMIT {$size} OFFSET {$offset}";
        }
    }

}