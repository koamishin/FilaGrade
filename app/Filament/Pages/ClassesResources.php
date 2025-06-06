<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ClassResource;
use App\Models\ClassResource as ModelsClassResource;
use App\Models\ResourceCategory;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\IconPosition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\WithPagination;

class ClassesResources extends Page
{
    use WithPagination;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static string $view = 'filament.pages.class-resources';

    protected static ?int $navigationSort = 19;

    protected ?string $heading = 'Class Resources Hub';

    protected ?string $subheading = 'Organize, discover, and share your teaching materials in one central location.';

    public ?array $data = [];

    protected static ?string $navigationLabel = "Class Resources Hub";
    // Filter state
    public ?string $selectedType = null;

    public ?string $searchQuery = '';

    public ?string $selectedAccessLevel = null;

    public bool $showArchived = false;

    public string $viewingCategory = 'all'; // Default tab

    public string $layoutMode = 'grid'; // Default layout: grid, card, list

    protected $queryString = [
        'searchQuery' => ['except' => '', 'as' => 'search'],
        'selectedAccessLevel' => ['except' => ''],
        'showArchived' => ['except' => false],
        'viewingCategory' => ['except' => 'all'],
        'layoutMode' => ['except' => 'grid'], // Add layoutMode to query string
    ];

    public function mount(): void
    {
        // Initialize filter state
        $this->selectedType = request()->query('type', null);
        $this->selectedAccessLevel = request()->query('access', null);
        $this->layoutMode = session()->get('resource_layout_mode', 'grid'); // Load from session or default
    }

    protected function getHeaderActions(): array
    {
        $team = Filament::getTenant();
        $user = Auth::user();
        $isOwner = $team->userIsOwner($user);

        return [
            // Quick Upload Action
            Action::make('quick_upload')
                ->label('Quick Upload')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->size(ActionSize::Medium)
                ->form([
                    FileUpload::make('file')
                        ->label('Upload File')
                        ->disk('public')
                        ->directory('class-resources/'.$team->id)
                        ->acceptedFileTypes([
                            'application/pdf',
                            'text/plain',
                            'text/csv',
                            'text/markdown',
                            'image/jpeg',
                            'image/png',
                            'image/gif',
                            'image/webp',
                        ])
                        ->helperText('Upload files that can be processed by AI: PDFs, text files, and images')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (callable $set, $state): void {
                            if ($state) {
                                $filename = pathinfo($state, PATHINFO_FILENAME);
                                $title = Str::title(str_replace(['-', '_'], ' ', $filename));
                                $set('title', $title);
                            }
                        }),

                    TextInput::make('title')
                        ->label('Title')
                        ->required(),

                    Select::make('category_id')
                        ->label('Category')
                        ->options(function () {
                            $team = Filament::getTenant();

                            return ResourceCategory::where('team_id', $team->id)
                                ->pluck('name', 'id')
                                ->toArray();
                        })
                        ->searchable()
                        ->placeholder('Select a category (optional)')
                        ->live(),

                    Select::make('access_level')
                        ->label('Access Level')
                        ->options([
                            'all' => 'All Team Members',
                            'teacher' => 'Teachers Only',
                            'owner' => 'Team Owner Only',
                        ])
                        ->default('all')
                        ->required()
                        ->helperText('Who can access this resource'),
                ])
                ->action(function (array $data): void {
                    $team = Filament::getTenant();
                    $user = Auth::user();

                    // Create the resource first without the file
                    $resource = ModelsClassResource::create([
                        'team_id' => $team->id,
                        'title' => $data['title'],
                        'description' => null, // Description was removed from form
                        'category_id' => $data['category_id'] ?? null,
                        'access_level' => $data['access_level'],
                        'created_by' => $user->id,
                    ]);

                    // Add the file to media collection
                    if (! empty($data['file'])) {
                        // Handle the file upload using Spatie Media Library
                        $resource->addMediaFromDisk($data['file'], 'public')
                            ->toMediaCollection('resources');

                        // Try to extract text for AI processing if it's a PDF
                        if (Str::endsWith(strtolower($data['file']), '.pdf')) {
                            try {
                                $filePath = Storage::disk('public')->path($data['file']);
                                if (file_exists($filePath)) {
                                    $parser = new \Smalot\PdfParser\Parser;
                                    $pdf = $parser->parseFile($filePath);
                                    $text = $pdf->getText();

                                    // Store the first 1000 characters of text as description
                                    if (! empty($text)) {
                                        $resource->description = Str::limit($text, 1000);
                                        $resource->save();
                                    }
                                }
                            } catch (\Exception $e) {
                                \Illuminate\Support\Facades\Log::error('PDF parsing error: '.$e->getMessage());
                            }
                        }
                    }

                    // Show success notification
                    Notification::make()
                        ->title('Resource uploaded successfully')
                        ->success()
                        ->send();

                    // Refresh the resources list
                    $this->dispatch('resource-created');
                })
                ->visible(fn () => Gate::allows('create', ModelsClassResource::class)),

            // Filter dropdown
            Action::make('filters')
                ->label('Filters')
                ->icon('heroicon-m-funnel')
                ->size(ActionSize::Medium)
                ->color('gray')
                ->iconPosition(IconPosition::Before)
                ->extraAttributes(['class' => 'hidden md:flex'])
                ->form([
                    Section::make('Filter Resources')
                        ->schema([
                            Select::make('type')
                                ->label('Resource Type')
                                ->options([
                                    'teaching' => 'Teaching Materials',
                                    'student' => 'Student Resources',
                                    'admin' => 'Administrative Documents',
                                    '' => 'All Types',
                                ])
                                ->default($this->selectedType)
                                ->placeholder('All Types')
                                ->live(),

                            Select::make('access_level')
                                ->label('Access Level')
                                ->options([
                                    'all' => 'All Team Members',
                                    'teacher' => 'Teachers Only',
                                    'owner' => 'Team Owner Only',
                                    '' => 'All Access Levels',
                                ])
                                ->default($this->selectedAccessLevel)
                                ->placeholder('All Access Levels')
                                ->live(),

                            Forms\Components\Toggle::make('show_archived')
                                ->label('Show Archived Resources')
                                ->default($this->showArchived)
                                ->live(),
                        ])
                        ->columns(1),
                ])
                ->action(function (array $data): void {
                    $this->selectedType = $data['type'] ?? null;
                    $this->selectedAccessLevel = $data['access_level'] ?? null;
                    $this->showArchived = $data['show_archived'] ?? false;
                }),

            // Search action
            Action::make('search')
                ->label('Search')
                ->icon('heroicon-m-magnifying-glass')
                ->size(ActionSize::Medium)
                ->color('gray')
                ->form([
                    TextInput::make('query')
                        ->label('Search Resources')
                        ->placeholder('Enter keywords...')
                        ->default($this->searchQuery)
                        ->prefixIcon('heroicon-m-magnifying-glass')
                        ->extraAttributes(['class' => 'md:w-80']),
                ])
                ->action(function (array $data): void {
                    $this->searchQuery = $data['query'] ?? '';
                    $this->resetPage(); // Reset pagination on search
                }),

            // Manage categories (only for team owners)
            Action::make('manage_categories')
                ->label('Manage Categories')
                ->url(route('filament.app.resources.resource-categories.index', ['tenant' => Filament::getTenant()]))
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->visible($isOwner || Gate::allows('manageCategories', ModelsClassResource::class)),

        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Class Resources';
    }

    public function getTitle(): string
    {
        return 'Class Resources';
    }

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    #[On('resource-created')]
    #[On('resource-updated')]
    #[On('resource-deleted')]
    public function refreshResources(): void
    {
        // This method will be called when resource events are dispatched
        // The blade view will re-render with fresh data
    }

    #[On('toggle-pinned')]
    public function togglePinned(string $resourceId): void
    {
        $resource = ModelsClassResource::findOrFail($resourceId);

        // Check authorization
        if (! Gate::allows('update', $resource)) {
            Notification::make()
                ->title('Permission denied')
                ->danger()
                ->send();

            return;
        }

        $resource->is_pinned = ! $resource->is_pinned;
        $resource->save();

        $status = $resource->is_pinned ? 'pinned' : 'unpinned';

        Notification::make()
            ->title("Resource {$status} successfully")
            ->success()
            ->send();
    }

    #[On('archive-resource')]
    public function archiveResource(string $resourceId): void
    {
        $resource = ModelsClassResource::findOrFail($resourceId);

        // Check authorization
        if (! Gate::allows('update', $resource)) {
            Notification::make()
                ->title('Permission denied')
                ->danger()
                ->send();

            return;
        }

        $resource->is_archived = true;
        $resource->save();

        Notification::make()
            ->title('Resource archived successfully')
            ->success()
            ->send();

        $this->dispatch('resource-updated');
    }

    #[On('restore-resource')]
    public function restoreResource(string $resourceId): void
    {
        $resource = ModelsClassResource::findOrFail($resourceId);

        // Check authorization
        if (! Gate::allows('update', $resource)) {
            Notification::make()
                ->title('Permission denied')
                ->danger()
                ->send();

            return;
        }

        $resource->is_archived = false;
        $resource->save();

        Notification::make()
            ->title('Resource restored successfully')
            ->success()
            ->send();

        $this->dispatch('resource-updated');
    }

    #[On('delete-resource')]
    public function deleteResource(string $resourceId): void
    {
        $resource = ModelsClassResource::findOrFail($resourceId);

        // Check authorization - only owners can delete
        $team = Filament::getTenant();
        $user = Auth::user();

        if (! $team->userIsOwner($user) && $resource->created_by !== $user->id) {
            Notification::make()
                ->title('Permission denied')
                ->body('Only team owners or the resource creator can delete resources.')
                ->danger()
                ->send();

            return;
        }

        // Delete the resource
        $resource->delete();

        Notification::make()
            ->title('Resource deleted successfully')
            ->success()
            ->send();

        $this->dispatch('resource-deleted');
    }

    #[On('filter-changed')]
    public function applyFilter(?string $type = null, ?string $access = null): void
    {
        $this->selectedType = $type;
        $this->selectedAccessLevel = $access;
    }

    #[On('search')]
    public function search(string $query): void
    {
        $this->searchQuery = $query;
        $this->resetPage(); // Reset pagination on search
    }

    // Method to update viewing category and reset pagination
    public function setViewingCategory(string $category): void
    {
        $this->viewingCategory = $category;
        $this->resetPage(); // Reset pagination when changing tabs
    }

    // Method to update layout mode
    public function setLayoutMode(string $mode): void
    {
        if (in_array($mode, ['grid', 'card', 'list'])) {
            $this->layoutMode = $mode;
            session()->put('resource_layout_mode', $mode); // Store preference in session
            $this->resetPage(); // Optionally reset page when changing layout
        }
    }

    public function getViewData(): array
    {
        $team = Filament::getTenant();
        $user = Auth::user();
        $isOwner = $team->userIsOwner($user);

        // Define resource types (can be simplified if only used for tabs now)
        $resourceTypes = [
            'all' => ['title' => 'All Resources', 'icon' => 'heroicon-o-squares-2x2'],
            'teaching' => ['title' => 'Teaching Materials', 'icon' => 'heroicon-o-academic-cap', 'color' => 'blue'],
            'student' => ['title' => 'Student Resources', 'icon' => 'heroicon-o-user-group', 'color' => 'green'],
            'admin' => ['title' => 'Administrative Docs', 'icon' => 'heroicon-o-briefcase', 'color' => 'purple'],
            'uncategorized' => ['title' => 'Uncategorized', 'icon' => 'heroicon-o-question-mark-circle', 'color' => 'gray'],
        ];

        // --- Pinned Resources Query ---
        $pinnedQuery = ModelsClassResource::query()
            ->where('team_id', $team->id)
            ->where('is_pinned', true);

        // Apply permission filters based on authenticated user to Pinned Resources
        if (! $isOwner) {
            if ($user->hasTeamRole($team, 'teacher')) {
                $pinnedQuery->where(function ($query) use ($user): void {
                    $query->where('access_level', 'all')
                        ->orWhere('access_level', 'teacher')
                        ->orWhere('created_by', $user->id);
                });
            } else {
                $pinnedQuery->where('access_level', 'all');
            }
        }
        $pinnedResources = $pinnedQuery->with(['media', 'category', 'creator', 'team'])->orderBy('updated_at', 'desc')->get();

        // --- Main Resource Query (Non-Pinned) ---
        $mainResourceQuery = ModelsClassResource::query()
            ->where('team_id', $team->id)
            ->where(function ($q): void {
                $q->where('is_pinned', false)->orWhereNull('is_pinned');
            });

        // Apply archived filter
        try {
            if (! $this->showArchived) {
                $mainResourceQuery->where(function ($query): void {
                    $query->where('is_archived', false)
                        ->orWhereNull('is_archived');
                });
            }
        } catch (\Exception $e) {
            Log::warning('is_archived filter error: '.$e->getMessage());
        }

        // Apply permission filters to Main Resources
        if (! $isOwner) {
            if ($user->hasTeamRole($team, 'teacher')) {
                $mainResourceQuery->where(function ($query) use ($user): void {
                    $query->where('access_level', 'all')
                        ->orWhere('access_level', 'teacher')
                        ->orWhere('created_by', $user->id);
                });
            } else {
                $mainResourceQuery->where('access_level', 'all');
            }
        }

        // Apply search filter
        if (! empty($this->searchQuery)) {
            $searchQuery = $this->searchQuery;
            $mainResourceQuery->where(function ($query) use ($searchQuery): void {
                $query->where('title', 'like', "%{$searchQuery}%")
                    ->orWhere('description', 'like', "%{$searchQuery}%");
            });
        }

        // Apply type filter (from the viewingCategory property)
        if ($this->viewingCategory !== 'all') {
            if ($this->viewingCategory === 'uncategorized') {
                $mainResourceQuery->whereNull('category_id');
            } else {
                // Get category IDs for the selected type
                $categoryIds = ResourceCategory::where('team_id', $team->id)
                    ->where('type', $this->viewingCategory)
                    ->pluck('id');

                // Ensure categoryIds is not empty before applying whereIn
                if ($categoryIds->isNotEmpty()) {
                    $mainResourceQuery->whereIn('category_id', $categoryIds);
                } else {
                    // If no categories exist for this type, return no results
                    $mainResourceQuery->whereRaw('1 = 0');
                }
            }
        }

        // Apply access level filter (from header action form)
        if (! empty($this->selectedAccessLevel)) {
            $mainResourceQuery->where('access_level', $this->selectedAccessLevel);
        }

        // Fetch main resources with pagination
        $mainResourcesPaginated = $mainResourceQuery
            ->with(['media', 'category', 'creator', 'team'])
            ->orderBy('updated_at', 'desc')
            ->paginate(12); // Use 12 for grid/card, okay for list too

        // --- Stats Calculation Query (independent of main pagination/filters except team/permissions) ---
        $statsBaseQuery = ModelsClassResource::query()->where('team_id', $team->id);
        if (! $this->showArchived) { // Apply archived filter to stats count
            $statsBaseQuery->where(function ($query): void {
                $query->where('is_archived', false)->orWhereNull('is_archived');
            });
        }
        // Apply permission filter to stats count
        if (! $isOwner) {
            if ($user->hasTeamRole($team, 'teacher')) {
                $statsBaseQuery->where(function ($query) use ($user): void {
                    $query->where('access_level', 'all')->orWhere('access_level', 'teacher')->orWhere('created_by', $user->id);
                });
            } else {
                $statsBaseQuery->where('access_level', 'all');
            }
        }

        // Clone base query for different counts
        $totalCountQuery = clone $statsBaseQuery;
        $pinnedCountQuery = clone $statsBaseQuery;

        $teachingCategoryIds = ResourceCategory::where('team_id', $team->id)->where('type', 'teaching')->pluck('id');
        $studentCategoryIds = ResourceCategory::where('team_id', $team->id)->where('type', 'student')->pluck('id');
        $adminCategoryIds = ResourceCategory::where('team_id', $team->id)->where('type', 'admin')->pluck('id');

        $teachingCountQuery = clone $statsBaseQuery;
        $studentCountQuery = clone $statsBaseQuery;
        $adminCountQuery = clone $statsBaseQuery;
        $uncategorizedCountQuery = clone $statsBaseQuery;

        $resourceStats = [
            'total' => $totalCountQuery->count(),
            'teaching' => $teachingCategoryIds->isNotEmpty() ? $teachingCountQuery->whereIn('category_id', $teachingCategoryIds)->count() : 0,
            'student' => $studentCategoryIds->isNotEmpty() ? $studentCountQuery->whereIn('category_id', $studentCategoryIds)->count() : 0,
            'admin' => $adminCategoryIds->isNotEmpty() ? $adminCountQuery->whereIn('category_id', $adminCategoryIds)->count() : 0,
            'uncategorized' => $uncategorizedCountQuery->whereNull('category_id')->count(),
            'pinned' => $pinnedCountQuery->where('is_pinned', true)->count(), // Count pinned based on permissions/archived status
        ];

        return [
            // Keep data needed by the view
            'pinnedResources' => $pinnedResources,
            'mainResources' => $mainResourcesPaginated,
            'resourceTypes' => $resourceTypes, // For tabs
            'resourceStats' => $resourceStats,
            'viewingCategory' => $this->viewingCategory, // For tab state
            'layoutMode' => $this->layoutMode, // Pass layout mode to view

            // Keep data needed by header actions / filter form
            'selectedType' => $this->selectedType,
            'selectedAccessLevel' => $this->selectedAccessLevel,
            'searchQuery' => $this->searchQuery,
            'showArchived' => $this->showArchived,

            // Permissions and URLs
            'canManageCategories' => $isOwner || Gate::allows('manageCategories', ModelsClassResource::class),
            'canCreateResources' => Gate::allows('create', ModelsClassResource::class),
            'isTeamOwner' => $isOwner,
            'user' => $user,
            'team' => $team,
            'resourceViewUrl' => fn ($resourceId) => ClassResource::getUrl('view', ['record' => $resourceId]),
            'resourceEditUrl' => fn ($resourceId) => ClassResource::getUrl('edit', ['record' => $resourceId]),
        ];
    }
}
