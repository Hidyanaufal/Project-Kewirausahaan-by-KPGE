<?php

namespace App\Actions\Addons;

use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Maatwebsite\Excel\Facades\Excel;

class ExcelImportAction
{
    public static function make(
        string $importClass,
        string $actionName = 'import',
        bool $hidden = false,
        string $label = 'Import',
        $icon = 'heroicon-o-document-arrow-down',
        string $color = 'primary',
        string $disk = 'local',
        string $successMessage = 'Please wait while your data import is being processed in the background. You can refresh this page later to see the updated results. Thank you.',
        ?string $templatePath = null,
        array $context = [],
        array $extraFormSchema = [],
    ): Action {
        $templateUrl = null;
        if ($templatePath) {
            $templateUrl = str_starts_with($templatePath, 'http://') || str_starts_with($templatePath, 'https://')
                ? $templatePath
                : url($templatePath);
        }

        /** @var array<Component> $formSchema */
        $formSchema = [
            ...$extraFormSchema,
            FileUpload::make('file')
                ->label('Upload Excel File')
                ->required()
                ->disk($disk)
                ->directory('imports')
                ->acceptedFileTypes([
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'text/csv',
                ])
                ->helperText(
                    $templateUrl
                        ? new HtmlString('Download template, <a href="' . e($templateUrl) . '" target="_blank" class="underline text-primary-500">Click here</a>.')
                        : null
                ),
        ];

        return Action::make($actionName)
            ->label($label)
            ->hidden($hidden)
            ->color($color)
            ->outlined()
            ->icon($icon)
            ->slideOver()
            ->modalWidth(MaxWidth::Small)
            ->form($formSchema)
            ->action(function (array $data) use (
                $importClass,
                $successMessage,
                $disk,
                $context
            ) {
                try {
                    $filePath = Storage::disk($disk)->path($data['file']);

                    $extraContext = Arr::except($data, ['file']);
                    $resolvedContext = array_merge($context, $extraContext);

                    Log::info("Starting Excel import", [
                        'import_class' => $importClass,
                        'file' => $data['file'],
                        'disk' => $disk,
                        'full_path' => $filePath,
                        'context' => $resolvedContext,
                    ]);
                    Excel::import(new $importClass(...$resolvedContext), $filePath);
                    Notification::make()
                        ->success()
                        ->title('Import Queued')
                        ->body($successMessage)
                        ->send();
                } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
                    $failures = $e->failures();
                    $messages = collect($failures)->map(function ($failure) {
                        return "Row {$failure->row()}: " . implode(', ', $failure->errors());
                    })->implode("\n");

                    Log::warning("Excel import validation failed", [
                        'errors' => $messages,
                        'file' => $data['file'],
                    ]);

                    Notification::make()
                        ->danger()
                        ->title('Import Failed - Validation Error')
                        ->body($messages)
                        ->send();
                } catch (\Throwable $e) {

                    Log::error("Excel import failed with system error", [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    Notification::make()
                        ->danger()
                        ->title('Import Failed - System Error')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
