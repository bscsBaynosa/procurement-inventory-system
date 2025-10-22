<?php

namespace App\Controllers;

/**
 * Minimal base controller with a simple render helper.
 *
 * This implementation includes the target template directly. Our current
 * templates (e.g., auth/login.php, auth/landing.php, dashboard/*) are full
 * HTML documents, so we avoid wrapping with a layout here to prevent double
 * <html>/<body> output. If you later introduce partial views, you can extend
 * this to support a layout wrapper.
 */
abstract class BaseController
{
    /**
     * Render a PHP template from the templates/ directory.
     *
     * @param string $template Relative path under templates/ (e.g., 'auth/login.php')
     * @param array $data      Variables to extract for the template
     */
    protected function render(string $template, array $data = []): void
    {
        // Build absolute path to the template file
        $relative = ltrim($template, '/');
        // Ensure .php extension if omitted
        if (substr($relative, -4) !== '.php') {
            $relative .= '.php';
        }
        $path = __DIR__ . '/../../templates/' . $relative;

        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo 'Template not found: ' . $relative;
            return;
        }

        // Make $data entries available as variables in the template scope
        if (!empty($data)) {
            extract($data, EXTR_SKIP);
        }

        include $path;
    }
}

?>
