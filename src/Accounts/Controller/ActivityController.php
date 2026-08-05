<?php

declare(strict_types=1);

namespace Keel\Accounts\Controller;

use Keel\Accounts\Model\AdminEventModel;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Accounts\Model\UserModel;
use Keel\Accounts\Service\AdminLog;
use Keel\Auth;
use Keel\Http\Request;
use Keel\Http\Response;
use Keel\View\View;

// Admin-only reader for the platform activity log (Keel\Accounts\Service\AdminLog).
//
// Built around a support call, not an audit export: the fastest path from "a customer is telling
// me something is wrong" to the handful of events that explain it. That means one search box that
// matches names, emails and domains alike, filters that narrow rather than construct a query, and
// deep links (?org= / ?user=) from wherever an admin is already looking at that org or person.
class ActivityController
{
    public function __construct(private View $view) {}

    public function index(Request $request): Response
    {
        if (!Auth::check()) return Response::redirect('/login');
        if (!Auth::isAdmin()) return Response::redirect('/dashboard');

        // ?org= / ?user= carry a uid (never an internal id — see CLAUDE.md). Resolved here so the
        // page can name what it's scoped to instead of showing a filtered list with no explanation.
        $org = ($uid = trim((string) $request->query('org', ''))) !== ''
            ? OrganizationModel::findByUid($uid) : null;
        $user = ($uid = trim((string) $request->query('user', ''))) !== ''
            ? UserModel::findByUid($uid) : null;

        return Response::html($this->view->render('activity/index', [
            'events' => AdminLog::EVENTS,
            'categories' => AdminLog::CATEGORIES,
            'scopeOrg' => $org === null ? null : ['uid' => $org->uid, 'name' => $org->displayName()],
            'scopeUser' => $user === null ? null : ['uid' => $user->uid, 'name' => $user->fullName(), 'email' => $user->email],
        ]));
    }

    public function get(Request $request): Response
    {
        if (!Auth::check()) return Response::json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if (!Auth::isAdmin()) return Response::json(['success' => false, 'message' => 'Forbidden.'], 403);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));

        $org = ($uid = trim((string) $request->query('org', ''))) !== ''
            ? OrganizationModel::findByUid($uid) : null;
        $user = ($uid = trim((string) $request->query('user', ''))) !== ''
            ? UserModel::findByUid($uid) : null;

        $result = AdminEventModel::search([
            'org_id' => $org?->id,
            // A person's own row is what a support call is about — everything that happened TO
            // them, not what they did to others, so this filters on subject rather than actor.
            'subject_user_id' => $user?->id,
            'event' => trim((string) $request->query('event', '')),
            'category' => trim((string) $request->query('category', '')),
            'from' => trim((string) $request->query('from', '')),
            'to' => trim((string) $request->query('to', '')),
            'search' => trim((string) $request->query('search', '')),
        ], $page, $perPage);

        $data = array_map(static function (AdminEventModel $e): array {
            return [
                'id' => $e->id,
                'created_at' => $e->created_at,
                'event' => $e->event,
                'event_label' => AdminLog::labelFor($e->event),
                'category' => $e->category,
                'summary' => $e->summary,
                'actor_label' => $e->actor_label,
                'org_label' => $e->org_label,
                'subject_label' => $e->subject_label,
                'impersonated' => (bool) $e->impersonated,
                'ip' => $e->ip,
                'meta' => $e->meta(),
            ];
        }, $result['items']);

        $totalPages = (int) ceil($result['total'] / $perPage);
        return Response::json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'total_pages' => $totalPages ?: 1,
            ],
        ]);
    }
}
