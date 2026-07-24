<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/header.php';
$templateFile = __DIR__ . '/templates/' . $template . '.php';
if (!is_file($templateFile)) {
    $templateFile = __DIR__ . '/templates/page.php';
}
require $templateFile;
require __DIR__ . '/includes/footer.php';
