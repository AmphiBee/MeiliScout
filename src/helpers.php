<?php

declare(strict_types=1);

namespace Pollora\MeiliScout;

use Pollora\MeiliScout\Utils\Template;

/**
 * Loads and displays a template part with data.
 *
 * @param string $slug    The slug name for the template
 * @param array  $datas   Optional. Data to pass to the template
 * @param bool   $display Optional. Whether to display or return the template output
 * @return string|void Template output if $display is false, void otherwise
 */
function get_template_part($slug, $datas = [], $display = true)
{
    // Create a new Template instance and set the template data.
    $template = (new Template)->setTemplateData($datas);

    // If $display is false, start output buffering.
    if (! $display) {
        ob_start();
    }

    // Get the template part.
    $template->getTemplatePart($slug);

    // If $display is false, end output buffering and return the contents.
    if (! $display) {
        return ob_get_clean();
    }
}

function clean_recursive(array $array): array
{
    $cleaned = [];

    foreach ($array as $key => $value) {
        // Nettoyage récursif si tableau
        if (is_array($value)) {
            $value = clean_recursive($value);
        }

        // On garde :
        // - les booléens (même false)
        // - les entiers / floats
        // - les chaînes non vides
        // - les tableaux non vides
        // - les objets non null

        $shouldKeep = match (true) {
            is_bool($value) => true,
            is_int($value), is_float($value) => true,
            is_string($value) => trim($value) !== '',
            is_array($value) => !empty($value),
            is_object($value) => true, // tu peux adapter selon ton besoin
            default => false,
        };

        if ($shouldKeep) {
            $cleaned[$key] = $value;
        }
    }

    return $cleaned;
}
