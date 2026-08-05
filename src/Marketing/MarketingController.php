<?php

declare(strict_types=1);

namespace Keel\Marketing;

use Keel\Host;
use Keel\Http\Request;
use Keel\Http\Response;
use Keel\View\View;

/**
 * The public surface, served on the apex domain by its own router.
 *
 * No Auth, no Csrf, no models. That is a constraint worth keeping rather than an accident of how
 * little this does: it has to stay renderable with the session cold and the database irrelevant,
 * which is what makes it cheap under load and easy to lift out into its own project later if the
 * marketing site outgrows living beside the app.
 *
 * If a page here ever needs to read the database, that is the signal it belongs on the app host
 * instead.
 */
class MarketingController
{
    public function __construct(private View $view) {}

    public function index(Request $request): Response
    {
        return Response::html($this->view->render('marketing/index', [
            'title' => \Keel\Brand::name(),
            // Passed in rather than read in the template, so a page that gets extracted later has
            // one obvious dependency instead of a call to Host buried in its markup.
            'appUrl' => Host::appUrl(),
        ], 'layouts/marketing'));
    }
}
