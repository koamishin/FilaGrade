<?php

namespace App\Providers\Filament;

use Filament\Pages;
use Filament\Panel;
use App\Models\Team;
use App\Models\User;
use Filament\Widgets;
use Filament\PanelProvider;
use Laravel\Fortify\Fortify;
use App\Listeners\SwitchTeam;
use Filament\Pages\Dashboard;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Laravel\Jetstream\Features;
use App\Filament\Pages\EditTeam;
use Laravel\Jetstream\Jetstream;
use App\Filament\Pages\ApiTokens;
use Filament\Navigation\MenuItem;
use App\Filament\Pages\CreateTeam;
use App\Filament\Pages\Gradesheet;
use App\Filament\Pages\Changelogs;
use Filament\Support\Colors\Color;
use App\Filament\Pages\EditProfile;
use Illuminate\Support\Facades\Auth;
use LaraZeus\Boredom\Enums\Variants;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use SebastianBergmann\Type\FalseType;
use Filament\Navigation\NavigationItem;
use App\Filament\Pages\ClassesResources;
use App\Filament\Resources\ExamResource;
use Filament\Navigation\NavigationGroup;
use App\Filament\Resources\ClassResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Navigation\NavigationBuilder;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\AttendanceResource;
use App\Filament\Resources\AttendanceQrCodeResource;
use Illuminate\Session\Middleware\StartSession;
use Devonab\FilamentEasyFooter\EasyFooterPlugin;
use Illuminate\Cookie\Middleware\EncryptCookies;
use App\Filament\Resources\ClassResourceResource;
use App\Filament\Pages\Dashboard as PagesDashboard;
use App\Filament\Resources\ResourceCategoryResource;
use AssistantEngine\Filament\FilamentAssistantPlugin;
use Illuminate\Routing\Middleware\SubstituteBindings;
use AssistantEngine\Filament\Chat\Pages\AssistantChat;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use TomatoPHP\FilamentSimpleTheme\FilamentSimpleThemePlugin;
use CodeWithDennis\FilamentThemeInspector\FilamentThemeInspectorPlugin;
use DutchCodingCompany\FilamentDeveloperLogins\FilamentDeveloperLoginsPlugin;
use App\Filament\Pages\WeeklySchedule;
use App\Filament\Pages\AttendanceManager;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Models\SocialiteUser;
use DutchCodingCompany\FilamentSocialite\Provider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\HtmlString;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use Devonab\FilamentEasyFooter\Services\GitHubService;
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id("app")
            ->path("app")
            ->login()
            ->spa()
            ->brandName("FilaGrade")
            // ->sidebarCollapsibleOnDesktop(true)
            ->sidebarFullyCollapsibleOnDesktop()
            ->emailVerification()
            // ->topNavigation()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->viteTheme("resources/css/filament/app/theme.css")
            ->colors([
                "primary" => Color::hex("#c6a0f6"),
                "gray" => Color::hex("#7c7f93"),
                "info" => Color::hex("#7287fd"),
                "danger" => Color::hex("#e78284"),
                "success" => Color::hex("#a6d189"),
                "warning" => Color::hex("#fe640b"),
            ])
            // ->userMenuItems([
            //     MenuItem::make()
            //         ->label('Profile')
            //         ->icon('heroicon-o-user-circle')
            //         ->url(function () use ($panel) {
            //             if ($this->shouldRegisterMenuItem()) {
            //                 // Check if we're in a tenant context
            //                 $tenant = Filament::getTenant();
            //                 if ($tenant) {
            //                     return url(EditProfile::getUrl(['tenant' => $tenant->getKey()]));
            //                 }
            //                 return url(EditProfile::getUrl());
            //             }
            //             return url($panel->getPath());
            //         }),
            // ])
            ->discoverResources(
                in: app_path("Filament/Resources"),
                for: "App\\Filament\\Resources"
            )
            ->discoverPages(
                in: app_path("Filament/Pages"),
                for: "App\\Filament\\Pages"
            )
            ->pages([
                \App\Filament\Pages\Dashboard::class,
                AssistantChat::class,
                EditProfile::class,
                ApiTokens::class,
                \App\Filament\Pages\Gradesheet::class,
                // \App\Filament\Pages\ChatPage::class,

                \App\Filament\Pages\ClassesResources::class,
                \App\Filament\Pages\WeeklySchedule::class,
                \App\Filament\Pages\AttendanceManager::class,
                \App\Filament\Pages\Changelogs::class,
            ])
            ->globalSearch(false)

            ->plugins([
                FilamentSocialitePlugin::make()
                    // (required) Add providers corresponding with providers in `config/services.php`.
                    ->providers([
                        // Create a provider 'gitlab' corresponding to the Socialite driver with the same name.
                        Provider::make("google")
                            ->label("Google")
                            ->icon("fab-google-plus-g")
                            ->color(Color::hex("#4285f4"))
                            ->outlined(true)
                            ->stateless(false),
                    ])
                    ->registration(true)
                    ->createUserUsing(function (
                        string $provider,
                        SocialiteUserContract $oauthUser,
                        FilamentSocialitePlugin $plugin
                    ) {
                        // Create the user with basic info first
                        $user = User::create([
                            "name" => $oauthUser->getName(),
                            "email" => $oauthUser->getEmail(),
                            "password" => null, // Important: Password should be nullable
                        ]);

                        // Get avatar URL from OAuth provider
                        $avatarUrl = $oauthUser->getAvatar();

                        if ($avatarUrl) {
                            try {
                                // Download the image to a temporary file
                                $tempFile = tempnam(
                                    sys_get_temp_dir(),
                                    "avatar_"
                                );
                                file_put_contents(
                                    $tempFile,
                                    file_get_contents($avatarUrl)
                                );

                                // Create an UploadedFile instance from the temp file
                                $uploadedFile = new \Illuminate\Http\UploadedFile(
                                    $tempFile,
                                    "avatar.jpg", // Filename
                                    "image/jpeg", // MIME type (adjust if needed)
                                    null,
                                    true // Test mode to avoid moving the file again
                                );

                                // Use Jetstream's method with the proper UploadedFile instance
                                $user->updateProfilePhoto($uploadedFile);

                                // Remove the temporary file
                                @unlink($tempFile);
                            } catch (\Exception $e) {
                                // Log error if avatar download fails
                                report($e);
                            }
                        }

                        return $user; // Return the created user
                    }),
                EasyFooterPlugin::make()
                    ->withLoadTime()
                    ->withSentence(
                        new HtmlString(
                            '<img src="https://static.cdnlogo.com/logos/l/23/laravel.svg" style="margin-right:.5rem;" alt="Laravel Logo" width="20" height="20"> Laravel'
                        )
                    )
                    ->withGithub(showLogo: true, showUrl: true)
                    ->withLogo(
                        "https://static.cdnlogo.com/logos/l/23/laravel.svg", // Path to logo
                        "https://laravel.com",
                        "Powered by Laravel"
                        // URL for logo link (optional)
                    )
                    ->withLinks([
                        [
                            "title" => "About",
                            "url" => "https://example.com/about",
                        ],
                        ["title" => "CGV", "url" => "https://example.com/cgv"],
                        [
                            "title" => "Privacy Policy",
                            "url" => "https://example.com/privacy-policy",
                        ],
                    ])
                    ->withBorder(),
                FilamentAssistantPlugin::make(),
                // FilamentSimpleThemePlugin::make(),
                \LaraZeus\Boredom\BoringAvatarPlugin::make()

                    ->variant(Variants::MARBLE)

                    ->size(60)

                    ->colors([
                        "0A0310",
                        "49007E",
                        "FF005B",
                        "FF7D10",
                        "FFB238",
                    ]),
            ])
            ->discoverWidgets(
                in: app_path("Filament/Widgets"),
                for: "App\\Filament\\Widgets"
            )
            ->navigation(function (
                NavigationBuilder $builder
            ): NavigationBuilder {
                return $builder
                    ->groups([
                        // NavigationGroup::make('Chat History')
                        //     ->items($chatItems),
                    ])
                    ->items([
                        ...Dashboard::getNavigationItems(),
                        ...ClassesResources::getNavigationItems(),
                        ...WeeklySchedule::getNavigationItems(),
                        ...Gradesheet::getNavigationItems(),
                        ...ActivityResource::getNavigationItems(),
                        ...ExamResource::getNavigationItems(),
                        ...ResourceCategoryResource::getNavigationItems(),
                        ...StudentResource::getNavigationItems(),
                        ...AttendanceManager::getNavigationItems(),
                        ...AttendanceResource::getNavigationItems(),
                        ...AttendanceQrCodeResource::getNavigationItems(),
                    ]);
            })
            ->userMenuItems([
                MenuItem::make()
                    ->label("Profile")
                    ->url(
                        fn(): string => EditProfile::getUrl([
                            "tenant" => Auth::user()?->currentTeam->id ?? 1,
                        ])
                    )
                    ->icon("heroicon-o-user-circle"),
                MenuItem::make()
                    ->label(function () {
                        $githubService = app(
                            \Devonab\FilamentEasyFooter\Services\GitHubService::class
                        );
                        $version = $githubService->getLatestTag();
                        return "Changelogs (" .
                            (str()->startsWith($version, "v")
                                ? $version
                                : "v" . $version) .
                            ")";
                    })
                    ->url(fn() => \App\Filament\Pages\Changelogs::getUrl())
                    ->icon("heroicon-o-document-text"),
            ])
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);

        if (Features::hasApiFeatures()) {
            $panel->userMenuItems([
                MenuItem::make()
                    ->label("API Tokens")
                    ->icon("heroicon-o-key")
                    ->url(
                        fn() => $this->shouldRegisterMenuItem()
                            ? url(ApiTokens::getUrl())
                            : url($panel->getPath())
                    ),
            ]);
        }

        if (Features::hasTeamFeatures()) {
            $panel
                ->tenant(Team::class)
                ->tenantRegistration(CreateTeam::class)
                ->tenantProfile(EditTeam::class)
                ->tenantMenu(false)
                ->userMenuItems([
                    // MenuItem::make()
                    //     ->label(fn () => __('Team Settings'))
                    //     ->icon('heroicon-o-cog-6-tooth')
                    //     ->url(fn () => $this->shouldRegisterMenuItem()
                    //         ? url(EditTeam::getUrl())
                    //         : url($panel->getPath())),
                ]);
        }

        return $panel;
    }

    public function boot()
    {
        /**
         * Disable Fortify routes
         */
        Fortify::$registersRoutes = false;

        /**
         * Disable Jetstream routes
         */
        Jetstream::$registersRoutes = false;

        /**
         * Listen and switch team if tenant was changed
         */
        Event::listen(TenantSet::class, SwitchTeam::class);

        /**
         * Register custom routes for team switching
         */
        Route::middleware([
            "web",
            "auth:sanctum",
            config("jetstream.auth_session"),
            "verified",
        ])->group(function () {
            Route::post("/app/team/switch/{team}", function (Team $team) {
                // This will trigger the TenantSet event which is handled by SwitchTeam listener
                Filament::setTenant($team);

                return redirect()->route("filament.app.pages.dashboard", [
                    "tenant" => $team->id,
                ]);
            })->name("filament.app.team.switch");
        });
    }

    public function shouldRegisterMenuItem(): bool
    {
        $hasVerifiedEmail = Auth::user()?->hasVerifiedEmail();

        return Filament::hasTenancy()
            ? $hasVerifiedEmail && Filament::getTenant()
            : $hasVerifiedEmail;
        return true;
    }
}
