<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Attendance;
use App\Models\AttendanceQrCode;
use App\Models\Student;
use App\Models\Team;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Actions\Action;

class AttendanceManager extends Page implements HasForms
{
    use InteractsWithForms;
    
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Mark Attendance';
    protected static ?string $navigationGroup = 'Class Management';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.attendance-manager';
    
    public Team $team;
    public string $date;
    public Collection $students;
    public array $attendance = [];
    public string $selectedStatus = 'present';
    public ?string $notes = null;
    public bool $showQrCode = false;
    public ?AttendanceQrCode $activeQrCode = null;
    public int $qrCodeExpiryMinutes = 30;
    public string $qrCodeDescription = '';
    public array $stats = [
        'total_students' => 0,
        'present' => 0,
        'absent' => 0,
        'late' => 0,
        'excused' => 0,
        'unrecorded' => 0,
    ];
    
    public function mount(): void
    {
        $user = Auth::user();
        $this->team = $user->currentTeam;
        $this->date = now()->toDateString();
        $this->loadStudents();
        $this->loadAttendance();
        $this->loadStats();
        $this->checkActiveQrCode();
    }
    
    public function loadStudents(): void
    {
        $this->students = $this->team->students()
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }
    
    public function loadAttendance(): void
    {
        $existingAttendance = Attendance::where('team_id', $this->team->id)
            ->whereDate('date', $this->date)
            ->get()
            ->keyBy('student_id');

        $this->attendance = [];

        foreach ($this->students as $student) {
            if ($existingAttendance->has($student->id)) {
                $record = $existingAttendance->get($student->id);
                $this->attendance[$student->id] = [
                    'student_id' => $student->id,
                    'status' => $record->status,
                    'time_in' => $record->time_in ? $record->time_in->format('H:i') : null,
                    'time_out' => $record->time_out ? $record->time_out->format('H:i') : null,
                    'notes' => $record->notes,
                    'id' => $record->id,
                    'qr_verified' => $record->qr_verified,
                    'uuid' => $record->id,
                ];
            } else {
                $this->attendance[$student->id] = [
                    'student_id' => $student->id,
                    'status' => null,
                    'time_in' => null,
                    'time_out' => null,
                    'notes' => null,
                    'id' => null,
                    'qr_verified' => false,
                    'uuid' => null,
                ];
            }
        }
    }
    
    public function loadStats(): void
    {
        $totalStudents = $this->students->count();
        $presentCount = Attendance::where('team_id', $this->team->id)
            ->whereDate('date', $this->date)
            ->where('status', 'present')
            ->count();
        $absentCount = Attendance::where('team_id', $this->team->id)
            ->whereDate('date', $this->date)
            ->where('status', 'absent')
            ->count();
        $lateCount = Attendance::where('team_id', $this->team->id)
            ->whereDate('date', $this->date)
            ->where('status', 'late')
            ->count();
        $excusedCount = Attendance::where('team_id', $this->team->id)
            ->whereDate('date', $this->date)
            ->where('status', 'excused')
            ->count();

        // Calculate unrecorded students
        $unrecordedCount = $totalStudents - $presentCount - $absentCount - $lateCount - $excusedCount;

        $this->stats = [
            'total_students' => $totalStudents,
            'present' => $presentCount,
            'absent' => $absentCount,
            'late' => $lateCount,
            'excused' => $excusedCount,
            'unrecorded' => $unrecordedCount,
        ];
    }
    
    public function checkActiveQrCode(): void
    {
        $this->activeQrCode = AttendanceQrCode::where('team_id', $this->team->id)
            ->where('date', $this->date)
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }
    
    public function updateDate($newDate): void
    {
        $this->date = $newDate;
        $this->loadAttendance();
        $this->loadStats();
        $this->checkActiveQrCode();
        
        Notification::make()
            ->title('Attendance date updated')
            ->success()
            ->send();
    }
    
    public function batchMarkAttendance(array $studentIds, string $status): void
    {
        foreach ($studentIds as $studentId) {
            $this->attendance[$studentId]['status'] = $status;
            
            // For present students, automatically set time_in if not already set
            if ($status === 'present' && empty($this->attendance[$studentId]['time_in'])) {
                $this->attendance[$studentId]['time_in'] = now()->format('H:i');
            }
            
            $this->saveAttendance($studentId);
        }
        
        $this->loadStats();
        
        $count = count($studentIds);
        Notification::make()
            ->title("{$count} students marked {$status}")
            ->success()
            ->send();
    }
    
    public function markAttendance(string $studentId, string $status): void
    {
        $this->attendance[$studentId]['status'] = $status;
        
        // For present students, automatically set time_in if not already set
        if ($status === 'present' && empty($this->attendance[$studentId]['time_in'])) {
            $this->attendance[$studentId]['time_in'] = now()->format('H:i');
        }
        
        $this->saveAttendance($studentId);
        
        // Get student name for notification
        $student = $this->students->firstWhere('id', $studentId);
        $studentName = $student ? $student->name : 'Student';
        
        Notification::make()
            ->title("{$studentName} marked {$status}")
            ->success()
            ->send();
    }
    
    public function markAllWithStatus(string $status): void
    {
        foreach ($this->students as $student) {
            $this->attendance[$student->id]['status'] = $status;
            
            // For present students, automatically set time_in if not already set
            if ($status === 'present' && empty($this->attendance[$student->id]['time_in'])) {
                $this->attendance[$student->id]['time_in'] = now()->format('H:i');
            }
            
            $this->saveAttendance($student->id);
        }

        $this->loadStats();
        
        Notification::make()
            ->title("All students marked {$status}")
            ->success()
            ->send();
    }
    
    public function saveAttendance(string $studentId): void
    {
        $data = $this->attendance[$studentId];
        $existingRecord = null;
        
        if (!empty($data['uuid'])) {
            $existingRecord = Attendance::find($data['uuid']);
        } else {
            $existingRecord = Attendance::where('team_id', $this->team->id)
                ->where('student_id', $studentId)
                ->whereDate('date', $this->date)
                ->first();
        }

        $timeIn = $data['time_in'] ? Carbon::parse($this->date . ' ' . $data['time_in']) : ($data['status'] === 'present' ? now() : null);
        $timeOut = $data['time_out'] ? Carbon::parse($this->date . ' ' . $data['time_out']) : null;

        if ($existingRecord) {
            $existingRecord->update([
                'status' => $data['status'],
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'notes' => $data['notes'],
            ]);
        } else {
            $newRecord = Attendance::create([
                'team_id' => $this->team->id,
                'student_id' => $studentId,
                'created_by' => Auth::id(),
                'status' => $data['status'],
                'date' => $this->date,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'notes' => $data['notes'],
            ]);

            $this->attendance[$studentId]['id'] = $newRecord->id;
            $this->attendance[$studentId]['uuid'] = $newRecord->id;
        }

        $this->loadStats();
    }
    
    public function markTimeOut(string $studentId): void
    {
        $this->attendance[$studentId]['time_out'] = now()->format('H:i');
        $this->saveAttendance($studentId);
        
        // Get student name for notification
        $student = $this->students->firstWhere('id', $studentId);
        $studentName = $student ? $student->name : 'Student';
        
        Notification::make()
            ->title("{$studentName} time out recorded")
            ->success()
            ->send();
    }
    
    public function toggleShowQrCode(): void
    {
        $this->showQrCode = !$this->showQrCode;
    }
    
    public function generateQrCode(): void
    {
        if ($this->activeQrCode) {
            $this->activeQrCode->deactivate();
        }

        $this->activeQrCode = AttendanceQrCode::createForTeam(
            $this->team,
            Auth::user(),
            Carbon::parse($this->date),
            $this->qrCodeExpiryMinutes,
            $this->qrCodeDescription ?: 'Attendance for ' . $this->date
        );

        $this->showQrCode = true;
        
        Notification::make()
            ->title('QR code generated')
            ->success()
            ->send();
    }
    
    public function extendQrCodeExpiry(int $minutes): void
    {
        if ($this->activeQrCode) {
            $this->activeQrCode->extendExpiry($minutes);
            $this->activeQrCode->refresh();
            
            Notification::make()
                ->title('QR code expiry extended')
                ->success()
                ->send();
        }
    }
    
    public function deactivateQrCode(): void
    {
        if ($this->activeQrCode) {
            $this->activeQrCode->deactivate();
            $this->activeQrCode = null;
            
            Notification::make()
                ->title('QR code deactivated')
                ->success()
                ->send();
        }
    }
    
    public static function getNavigationItems(): array
    {
        return [
            \Filament\Navigation\NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->isActiveWhen(fn (): bool => request()->routeIs('filament.app.pages.attendance-manager'))
                ->sort(static::getNavigationSort())
                ->url(static::getNavigationUrl()),
        ];
    }
    
    public static function getNavigationUrl(): string
    {
        $teamId = Auth::user()?->currentTeam?->id;
        
        if (!$teamId) {
            return '';
        }
        
        return route('filament.app.pages.attendance-manager', ['tenant' => $teamId]);
    }
    
    protected function getHeaderActions(): array
    {
        return [
            Action::make('today')
                ->label('Today')
                ->icon('heroicon-o-calendar-days')
                ->action(fn () => $this->updateDate(now()->toDateString())),
                
            Action::make('yesterday')
                ->label('Yesterday')
                ->icon('heroicon-o-calendar')
                ->action(fn () => $this->updateDate(now()->subDay()->toDateString())),
                
            Action::make('previousDay')
                ->label('Previous Day')
                ->icon('heroicon-o-arrow-left')
                ->action(fn () => $this->updateDate(Carbon::parse($this->date)->subDay()->toDateString())),
                
            Action::make('nextDay')
                ->label('Next Day')
                ->icon('heroicon-o-arrow-right')
                ->action(fn () => $this->updateDate(Carbon::parse($this->date)->addDay()->toDateString())),
        ];
    }
} 