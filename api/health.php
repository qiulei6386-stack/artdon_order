<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Singapore');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'ok', 'service' => 'Artdon Procurement Platform', 'version' => 'V1.0', 'time' => date(DATE_ATOM)]);
