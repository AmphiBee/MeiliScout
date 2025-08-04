<?php

declare(strict_types=1);

/**
 * Template Loader
 *
 * Originally based on functions in Easy Digital Downloads (thanks Pippin!).
 * When using in a plugin, create a new class that extends this one and just overrides the properties.
 */

namespace Pollora\MeiliScout\Utils;

class Template
{
    /**
     * Name of the directory containing templates within this plugin.
     */
    protected string $pluginTplDirectory = 'resources/views';

    /**
     * Holds the paths of located templates.
     *
     * @var array<string>
     */
    private array $tplPathCache = [];

    /**
     * Stores variable names for template data.
     *
     * @var array<string, mixed>
     */
    private array $tplData = [];

    /**
     * Clear template data.
     */
    public function __destruct()
    {
        $this->unsetTemplateData();
    }

    /**
     * Get a template part.
     */
    public function getTemplatePart(string $slug, ?string $name = null, bool $load = true): string
    {
        do_action("meiliscout/get_template_part_{$slug}", $slug, $name);

        $templateFilenames = $this->getTemplateFileNames($slug, $name);

        return $this->locateTemplate($templateFilenames, $load, false);
    }

    /**
     * Provide custom data to the template.
     */
    public function setTemplateData(array $data): self
    {
        $this->tplData = array_merge($this->tplData, $data);

        return $this;
    }

    /**
     * Deny access to custom data in the template.
     */
    public function unsetTemplateData(): self
    {
        $this->tplData = [];

        return $this;
    }

    /**
     * Generate template file names based on a slug and optional name.
     */
    protected function getTemplateFileNames(string $slug, ?string $name): array
    {
        $templates = isset($name) ? ["{$slug}-{$name}.php", "{$slug}.php"] : ["{$slug}.php"];

        return apply_filters('meiliscout/get_template_part', $templates, $slug, $name);
    }

    /**
     * Find the highest priority template file that exists.
     */
    public function locateTemplate(string|array $tplNames, bool $load = false, bool $loadOnce = true): string
    {
        $cacheKey = is_array($tplNames) ? reset($tplNames) : $tplNames;

        if (isset($this->tplPathCache[$cacheKey])) {
            $located = $this->tplPathCache[$cacheKey];
        } else {
            $tplNames = array_filter((array) $tplNames);
            $tplPaths = $this->getTemplatePaths();

            $possiblePaths = [];
            foreach ($tplNames as $tplName) {
                $tplName = ltrim($tplName, '/');
                foreach ($tplPaths as $tplPath) {
                    $possiblePaths[] = $tplPath.$tplName;
                }
            }

            $located = array_filter($possiblePaths, fn ($path) => file_exists($path))[0] ?? '';

            if ($located) {
                $this->tplPathCache[$cacheKey] = $located;
            }
        }

        if ($load && $located) {
            extract($this->tplData);
            require $located;
        }

        return $located;
    }

    /**
     * Generate a list of paths for template locations.
     */
    protected function getTemplatePaths(): array
    {
        $filePaths = apply_filters('meiliscout/template_paths', [
            $this->getTemplatesDir(),
        ]);

        ksort($filePaths, SORT_NUMERIC);

        return array_map('trailingslashit', $filePaths);
    }

    /**
     * Retrieve the path to the templates directory within this plugin.
     */
    protected function getTemplatesDir(): string
    {
        return trailingslashit(MEILISCOUT_DIR_PATH).$this->pluginTplDirectory;
    }
}
