<?php

declare(strict_types=1);

namespace App\Dashboards;

use App\Livewire\Dashboards\ShowOverview;
use App\Models\User;
use NyonCode\WireCore\Core\Resources\Contracts\ProvidesNavigation;
use NyonCode\WireCore\Core\Resources\Navigation\NavigationItem;
use NyonCode\WireCore\Foundation\Routing\Contracts\ConfiguresRoutes;
use NyonCode\WireCore\Foundation\Routing\Contracts\ProvidesPages;
use NyonCode\WireCore\Widgets\Dashboard;
use NyonCode\WireCore\Widgets\Stat;
use NyonCode\WireCore\Widgets\StatsOverviewWidget;
use NyonCode\WireCore\Widgets\Widget;

/**
 * The page this admin opens on, and it is yours to edit.
 *
 * Written by `php artisan wire-admin:install` because an admin needs somewhere
 * to land. Without one the shell's own address has nothing of its own to show,
 * so it forwards to whichever screen happens to sort first in the sidebar — a
 * new installation signed you in and opened the media library.
 *
 * It is application code on purpose: a dashboard counts *your* rows, and no
 * package can know what those are. The one widget below counts the users in the
 * database, which is the only thing every application has; replace it.
 *
 * ## Why it sits at the admin's own address
 *
 * `routePrefix()` of `ConfiguresRoutes::ROOT` adds no URI segment, so this
 * dashboard's `index` page lands on the panel's own path — `/admin` — rather
 * than under it. Only one thing can claim that path, and claiming it is what
 * makes signing in open a dashboard rather than a redirect.
 *
 * Delete the three route methods and it becomes an ordinary page at
 * `/admin/overview`, with the shell's address going back to forwarding.
 *
 * ## Adding to it
 *
 *   StatsOverviewWidget, ChartWidget, BarChartWidget, ProgressWidget,
 *   ListWidget and TableWidget are the shipped kinds; `php artisan
 *   make:wire-widget` writes one of your own.
 *
 * Let each person rearrange it by adding `customisable()` returning true — then
 * every widget needs its own `key()`, which the one below already has.
 */
class OverviewDashboard extends Dashboard implements ConfiguresRoutes, ProvidesNavigation, ProvidesPages
{
    /**
     * The widgets on this dashboard, in the order they are laid out.
     *
     * @return array<int, Widget>
     */
    public function widgets(): array
    {
        return [
            StatsOverviewWidget::make()
                ->key('accounts')
                ->heading('Accounts')
                // As wide as the grid, so the row reads as a row rather than as
                // one card with the page empty beside it.
                ->columnSpan(2)
                ->columns(3)
                ->stats([
                    Stat::make('Users', (string) User::query()->count())
                        ->icon('users')
                        ->color('primary'),
                    Stat::make('Verified', (string) User::query()->whereNotNull('email_verified_at')->count())
                        ->description('confirmed their address')
                        ->icon('check-badge')
                        ->color('success'),
                    Stat::make('New this week', (string) User::query()->where('created_at', '>=', now()->subWeek())->count())
                        ->icon('user-plus'),
                ]),
        ];
    }

    /** Columns in the grid. */
    public function columns(): int
    {
        return 2;
    }

    /**
     * The page that renders it.
     *
     * @return array<string, class-string>
     */
    public static function pages(): array
    {
        return ['index' => ShowOverview::class];
    }

    /** No segment of its own: this lands on the panel's own path. */
    public static function routePrefix(): ?string
    {
        return self::ROOT;
    }

    /**
     * @return array<int, string>
     */
    public static function routeMiddleware(): array
    {
        return [];
    }

    public static function routeDomain(): ?string
    {
        return null;
    }

    /**
     * The menu entry.
     *
     * In no group, which is what puts it above every group the modules declare:
     * an ungrouped entry renders first and without a heading, the way an
     * overview reads.
     */
    public static function navigation(): NavigationItem
    {
        return NavigationItem::make('Overview')->icon('outline:home');
    }
}
