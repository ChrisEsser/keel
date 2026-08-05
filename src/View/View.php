<?php

declare(strict_types=1);

namespace Keel\View;

class View
{
    private string $viewPath;

    private string $publicPath;

    /** Values exposed to every template, set once at wiring time. @var array<string,mixed> */
    private array $shared = [];

    /**
     * Both paths are required. This class ships in a Composer package, so __DIR__ points into
     * vendor/ and tells us nothing about the host application's layout -- an earlier version
     * defaulted to a sibling `views/` and a `public/` beside it, which is true of exactly one
     * project shape and silently wrong in every other.
     */
    public function __construct(string $viewPath, string $publicPath)
    {
        $this->viewPath = rtrim($viewPath, '/');
        $this->publicPath = rtrim($publicPath, '/');
    }

    /**
     * Expose a value to every template without threading it through each render() call.
     * Used for things a view needs but no controller should have to remember to pass -- e.g. the
     * PublicFormGuard behind partials/form-guard.php, which the login view alone renders from
     * eight different places. Per-render data of the same name still wins.
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Render a view template, optionally wrapped in a layout.
     */
    public function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = $this->renderTemplate($template, $data);

        if ($layout !== null) {
            $content = $this->renderTemplate($layout, array_merge($data, ['content' => $content]));
        }

        return $content;
    }

    /**
     * Render a partial from inside a template: `<?= $this->insert('partials/form-guard') ?>`.
     * Templates are require'd from within this class, so $this is the View instance there.
     */
    public function insert(string $template, array $data = []): string
    {
        return $this->renderTemplate($template, $data);
    }

    /**
     * Cache-busting URL for a file in public/: `$this->asset('/js/app.js')` -> `/js/app.js?v=1753…`.
     *
     * Static files are served straight off disk with no cache headers, so a browser holds its copy
     * for as long as its own heuristics allow. That is harmless until a JS file gains a function
     * the server-rendered page calls in the same deploy — the HTML is always fresh, the script is
     * not, and the page dies on a ReferenceError for a function that plainly exists on disk. Keyed
     * on mtime, so the URL changes exactly when the bytes do and never otherwise.
     *
     * Falls back to the bare path if the file can't be stat'ed (wrong path, or a template rendered
     * outside the web root, e.g. mail from CLI) — a missing version is better than a fatal.
     */
    public function asset(string $path): string
    {
        $mtime = @filemtime($this->publicPath . $path);

        return $mtime ? $path . '?v=' . $mtime : $path;
    }

    private function renderTemplate(string $template, array $data = []): string
    {
        $file = "{$this->viewPath}/{$template}.php";

        if (!file_exists($file)) {
            throw new \RuntimeException("View not found: $file");
        }

        extract(array_merge($this->shared, $data), EXTR_SKIP);
        ob_start();
        require $file;
        return ob_get_clean();
    }
}
