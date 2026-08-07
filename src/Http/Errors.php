<?php

declare(strict_types=1);

namespace Framework\Http;

use Framework\View\View;

class Errors
{
    // The template/layout are parameterized so each router can 404 in its own shell -- the
    // marketing site renders in its own layout, and the app 404s in a standalone guest shell
    // (no sidebar) rather than the authenticated app chrome. The container auto-wires the app's
    // instance from the View alone using these defaults.
    public function __construct(
        private View $view,
        private string $template = 'errors/404',
        private ?string $layout = 'layouts/guest',
    ) {}

    public function notFound(): Response
    {
        return Response::html($this->view->render($this->template, [], $this->layout), 404);
    }
}
