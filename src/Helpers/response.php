<?php

function jsonResponse(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function success(mixed $data = null, string $message = 'OK', int $status = 200): never {
    jsonResponse(['success' => true, 'message' => $message, 'data' => $data], $status);
}

function error(string $message, int $status = 400, mixed $errors = null): never {
    $body = ['success' => false, 'message' => $message];
    if ($errors !== null) $body['errors'] = $errors;
    jsonResponse($body, $status);
}

function paginate(array $items, int $total, int $page, int $perPage): array {
    return [
        'items'        => $items,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => (int) ceil($total / $perPage),
    ];
}
