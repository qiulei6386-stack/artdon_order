<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/lighting_report.php';

api_require_method('GET', 'POST');
$requestId = api_request_id();

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'POST') {
        api_rate_limit('lighting-project-write', 30, 60);
    }
    $pdo = artdon_db_open_ready();
    if ($method === 'GET') {
        $publicId = strtoupper(trim((string) ($_GET['id'] ?? '')));
        $project = artdon_lighting_find_project($publicId, artdon_lighting_session_key_hash(), $pdo);
        if ($project === null) {
            api_respond(404, [
                'success' => false,
                'request_id' => $requestId,
                'message' => 'The simulation project was not found in this session.',
            ]);
        }
        api_respond(200, [
            'success' => true,
            'request_id' => $requestId,
            'project' => artdon_lighting_public_project($project),
        ]);
    }

    $input = api_json_body(65_536);
    api_verify_csrf($input);
    $token = strtoupper(trim((string) ($input['simulation_token'] ?? '')));

    $savedTokens = is_array($_SESSION['lighting_saved_simulation_tokens'] ?? null)
        ? $_SESSION['lighting_saved_simulation_tokens']
        : [];
    $savedProjectId = (string) ($savedTokens[$token] ?? '');
    if ($savedProjectId !== '') {
        $savedProject = artdon_lighting_find_project(
            $savedProjectId,
            artdon_lighting_session_key_hash(),
            $pdo
        );
        if ($savedProject !== null) {
            artdon_lighting_ensure_report($savedProject, $pdo);
            $savedProject = artdon_lighting_find_project(
                $savedProjectId,
                artdon_lighting_session_key_hash(),
                $pdo
            );
            if ($savedProject === null) {
                throw new RuntimeException('The saved simulation project could not be reloaded.');
            }
            api_respond(200, [
                'success' => true,
                'duplicate' => true,
                'request_id' => $requestId,
                'project' => artdon_lighting_public_project($savedProject),
            ]);
        }
    }

    $pending = artdon_lighting_pending($token);
    if ($pending === null) {
        api_respond(404, [
            'success' => false,
            'request_id' => $requestId,
            'message' => 'The pending simulation expired or does not belong to this session. Run it again.',
        ]);
    }
    $projectName = array_key_exists('project_name', $input)
        ? (string) $input['project_name']
        : (string) ($pending['input']['project_name'] ?? '');
    $project = artdon_lighting_create_project($pending, $projectName, $pdo);

    // Remember the project before report generation so a transient filesystem
    // failure can be retried idempotently instead of creating another project.
    $savedTokens[$token] = (string) $project['public_id'];
    if (count($savedTokens) > 20) {
        $savedTokens = array_slice($savedTokens, -20, null, true);
    }
    $_SESSION['lighting_saved_simulation_tokens'] = $savedTokens;

    artdon_lighting_ensure_report($project, $pdo);
    $project = artdon_lighting_find_project(
        (string) $project['public_id'],
        artdon_lighting_session_key_hash(),
        $pdo
    );
    if ($project === null) {
        throw new RuntimeException('The saved simulation project could not be reloaded.');
    }
    artdon_lighting_pending($token, true);

    api_respond(201, [
        'success' => true,
        'request_id' => $requestId,
        'project' => artdon_lighting_public_project($project),
    ]);
} catch (ArtdonDatabaseUnavailable $error) {
    error_log(sprintf('[lighting-project:%s] Database is not ready: %s', $requestId, $error->getMessage()));
    if (!headers_sent()) {
        header('Retry-After: 5');
    }
    api_respond(503, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'The simulation project service is temporarily unavailable.',
    ]);
} catch (InvalidArgumentException $error) {
    api_respond(422, [
        'success' => false,
        'request_id' => $requestId,
        'message' => $error->getMessage(),
    ]);
} catch (Throwable $error) {
    error_log(sprintf('[lighting-project:%s] %s', $requestId, $error->getMessage()));
    api_respond(500, [
        'success' => false,
        'request_id' => $requestId,
        'message' => 'The simulation project could not be saved or loaded.',
    ]);
}
