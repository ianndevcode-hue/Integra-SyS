<?php
declare(strict_types=1);

/**
 * API helpers: JSON I/O, validation and a schema-driven CRUD engine.
 */

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_out(['error' => $message] + $extra, $status);
}

function input(): array
{
    static $data = null;
    if ($data !== null) return $data;
    $raw = file_get_contents('php://input');
    $data = $raw ? (json_decode($raw, true) ?? []) : [];
    if (!$data && $_POST) $data = $_POST;
    return is_array($data) ? $data : [];
}

function require_user(string $area): array
{
    $user = current_user();
    if (!$user) json_error('Sessão expirada. Faça login novamente.', 401);
    if (!can($area, $user)) json_error('Você não tem permissão para acessar esta área.', 403);
    return $user;
}

/**
 * Validate and cast payload against field definitions.
 * Field def: ['type' => string|text|int|decimal|date|datetime|bool|enum|email, 'required' => bool, 'max' => int, 'values' => [...]]
 */
function validate_fields(array $defs, array $in, bool $partial = false): array
{
    $out = [];
    $errors = [];
    foreach ($defs as $name => $def) {
        $present = array_key_exists($name, $in);
        if ($partial && !$present) continue;
        $value = $present ? $in[$name] : null;
        if (is_string($value)) $value = trim($value);
        $empty = $value === null || $value === '';
        if ($empty) {
            if (!empty($def['required'])) $errors[$name] = 'Campo obrigatório.';
            elseif (($def['type'] ?? '') === 'bool') $out[$name] = !$present && array_key_exists('default', $def) ? (int)$def['default'] : 0;
            else $out[$name] = array_key_exists('default', $def) ? $def['default'] : null;
            continue;
        }
        switch ($def['type'] ?? 'string') {
            case 'int':
                if (!is_numeric($value)) { $errors[$name] = 'Número inválido.'; break; }
                $out[$name] = (int)$value;
                break;
            case 'decimal':
                // accept "1.234,56" (pt-BR) as well as "1234.56"
                if (is_string($value) && strpos($value, ',') !== false) $value = str_replace(['.', ','], ['', '.'], $value);
                if (!is_numeric($value)) { $errors[$name] = 'Valor inválido.'; break; }
                $out[$name] = round((float)$value, 2);
                break;
            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) { $errors[$name] = 'Data inválida.'; break; }
                $out[$name] = $value;
                break;
            case 'datetime':
                $ts = strtotime((string)$value);
                if (!$ts) { $errors[$name] = 'Data/hora inválida.'; break; }
                $out[$name] = date('Y-m-d H:i:s', $ts);
                break;
            case 'bool':
                $out[$name] = filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                break;
            case 'enum':
                if (!in_array($value, $def['values'], true)) { $errors[$name] = 'Opção inválida.'; break; }
                $out[$name] = $value;
                break;
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) { $errors[$name] = 'E-mail inválido.'; break; }
                $out[$name] = mb_strtolower((string)$value);
                break;
            case 'text':
                $out[$name] = (string)$value;
                break;
            default:
                $value = (string)$value;
                $max = $def['max'] ?? 255;
                if (mb_strlen($value) > $max) { $errors[$name] = "Máximo de $max caracteres."; break; }
                $out[$name] = $value;
        }
    }
    if ($errors) json_error('Verifique os campos destacados.', 422, ['fields' => $errors]);
    return $out;
}

/**
 * Generic list endpoint: search, filters, sorting and pagination.
 */
function crud_list(array $res): void
{
    $alias = $res['alias'] ?? 't';
    $from = $res['from'] ?? ($res['table'] . " $alias");
    $select = $res['select'] ?? "$alias.*";
    $where = [];
    $params = [];

    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '' && !empty($res['search'])) {
        $likes = [];
        foreach ($res['search'] as $col) {
            $likes[] = "$col LIKE ?";
            $params[] = "%$q%";
        }
        $where[] = '(' . implode(' OR ', $likes) . ')';
    }
    foreach (($res['filters'] ?? []) as $param => $col) {
        if (is_int($param)) { $param = $col; $col = "$alias.$col"; }
        $val = $_GET[$param] ?? '';
        if ($val === '' || $val === null) continue;
        $where[] = "$col = ?";
        $params[] = $val;
    }
    if (isset($res['scope'])) {
        [$sql, $scopeParams] = $res['scope']();
        if ($sql) { $where[] = $sql; $params = array_merge($params, $scopeParams); }
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $sortable = $res['sort'] ?? ['id'];
    $sort = in_array($_GET['sort'] ?? '', $sortable, true) ? $_GET['sort'] : ($res['default_sort'] ?? $sortable[0]);
    $dir = strtolower($_GET['dir'] ?? ($res['default_dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $sortCol = strpos($sort, '.') === false && empty($res['sort_raw']) ? "$alias.$sort" : $sort;

    $perPage = min(500, max(1, (int)($_GET['per_page'] ?? 25)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)db_value("SELECT COUNT(*) FROM $from$whereSql", $params);
    $rows = db_all("SELECT $select FROM $from$whereSql ORDER BY $sortCol $dir LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);

    if (isset($res['transform'])) $rows = array_map($res['transform'], $rows);
    json_out(['data' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function crud_get(array $res, int $id): array
{
    $alias = $res['alias'] ?? 't';
    $from = $res['from'] ?? ($res['table'] . " $alias");
    $select = $res['select'] ?? "$alias.*";
    $row = db_one("SELECT $select FROM $from WHERE $alias.id = ?", [$id]);
    if (!$row) json_error('Registro não encontrado.', 404);
    if (isset($res['transform'])) $row = $res['transform']($row);
    return $row;
}

function crud_create(array $res): array
{
    $data = validate_fields($res['fields'], input());
    if (isset($res['before_save'])) $data = $res['before_save']($data, null);
    if (!empty($res['timestamps'])) $data['created_at'] = $data['updated_at'] = now();
    elseif (!empty($res['created_at'])) $data['created_at'] = now();
    $id = db_insert($res['table'], $data);
    if (isset($res['after_create'])) $res['after_create']($id, $data);
    audit('create', $res['table'], $id);
    return crud_get($res, $id);
}

function crud_update(array $res, int $id): array
{
    $existing = db_find($res['table'], $id);
    if (!$existing) json_error('Registro não encontrado.', 404);
    $data = validate_fields($res['fields'], input(), true);
    if (isset($res['before_save'])) $data = $res['before_save']($data, $existing);
    if (!empty($res['timestamps'])) $data['updated_at'] = now();
    db_update($res['table'], $id, $data);
    audit('update', $res['table'], $id, array_keys($data));
    return crud_get($res, $id);
}

function crud_delete(array $res, int $id): void
{
    if (!db_find($res['table'], $id)) json_error('Registro não encontrado.', 404);
    if (isset($res['before_delete'])) $res['before_delete']($id);
    db_exec("DELETE FROM {$res['table']} WHERE id = ?", [$id]);
    audit('delete', $res['table'], $id);
}

function csv_out(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF"); // BOM so Excel opens UTF-8 correctly
    fputcsv($fh, array_values($headers), ';', '"', '\\');
    foreach ($rows as $row) {
        $line = [];
        foreach (array_keys($headers) as $key) {
            $v = $row[$key] ?? '';
            if (is_float($v) || (is_string($v) && preg_match('/^-?\d+\.\d{2}$/', $v))) $v = str_replace('.', ',', (string)$v);
            $line[] = $v;
        }
        fputcsv($fh, $line, ';', '"', '\\');
    }
    fclose($fh);
    exit;
}
