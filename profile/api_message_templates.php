<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/message_template_service.php';

$currentUser = require_login($pdo);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function message_templates_api_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? 'list')));
$userId = (int) $currentUser['id'];

try {
    if ($method === 'GET' || $action === 'list') {
        message_templates_api_response([
            'ok' => true,
            'templates' => message_templates_list($pdo, $userId),
        ]);
    }

    verify_csrf();

    if ($action === 'save') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $template = message_templates_save(
            $pdo,
            $userId,
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['content'] ?? ''),
            $templateId > 0 ? $templateId : null
        );

        message_templates_api_response([
            'ok' => true,
            'message' => 'Template saved.',
            'template' => $template,
        ]);
    }

    if ($action === 'delete') {
        $templateId = (int) ($_POST['template_id'] ?? 0);
        if ($templateId <= 0) {
            message_templates_api_response([
                'ok' => false,
                'error' => 'Template not found.',
            ], 400);
        }

        if (!message_templates_delete($pdo, $userId, $templateId)) {
            message_templates_api_response([
                'ok' => false,
                'error' => 'Template not found.',
            ], 404);
        }

        message_templates_api_response([
            'ok' => true,
            'message' => 'Template deleted.',
        ]);
    }

    message_templates_api_response([
        'ok' => false,
        'error' => 'Unsupported action.',
    ], 400);
} catch (InvalidArgumentException $exception) {
    message_templates_api_response([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], 422);
} catch (Throwable $throwable) {
    message_templates_api_response([
        'ok' => false,
        'error' => $throwable->getMessage(),
    ], 500);
}
