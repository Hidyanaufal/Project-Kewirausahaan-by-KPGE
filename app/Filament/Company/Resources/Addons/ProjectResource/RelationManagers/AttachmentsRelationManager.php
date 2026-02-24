<?php

namespace App\Filament\Company\Resources\Addons\ProjectResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isLazy = false;

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Attachment Title')
                    ->placeholder('e.g. Project Contract, Invoice, Design Document')
                    ->required()
                    ->maxLength(255),

                Forms\Components\ToggleButtons::make('is_file')
                    ->label('Attachment Type')
                    ->options([
                        true => 'Upload File',
                        false => 'External Link',
                    ])
                    ->default(fn() => true)
                    ->reactive()
                    ->inline(),

                Forms\Components\FileUpload::make('file_path')
                    ->label('Upload File')
                    ->helperText('Maximum size: 20MB. Supported formats: PDF, Word, or image files.')
                    ->directory('attachments')
                    ->reactive()
                    ->visible(fn(Forms\Get $get) => $get('is_file'))
                    ->visibility('private')
                    ->downloadable()
                    ->maxSize(20480) // 20MB
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/*',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('file_url')
                    ->label('External File URL')
                    ->placeholder('https://drive.google.com/...')
                    ->helperText('Paste a link from Google Drive, Dropbox, or another cloud storage service.')
                    ->reactive()
                    ->visible(fn(Forms\Get $get) => !$get('is_file'))
                    ->url()
                    ->maxLength(2048)
                    ->columnSpanFull()
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('goToLink')
                            ->icon('heroicon-o-arrow-top-right-on-square')
                            ->label('Go to Link')
                            ->url(fn($state) => $state, true) // open in new tab
                            ->visible(fn($state) => filled($state))
                    ),
            ]);
    }


    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('file_type')
                    ->label('Type')
                    ->getStateUsing(fn($record) => $record->file_path ? 'File' : 'Link')
                    ->colors([
                        'success' => 'File',
                        'info'    => 'Link',
                    ])
                    ->icons([
                        'heroicon-o-document-text' => 'File',
                        'heroicon-o-link'         => 'Link',
                    ]),

                Tables\Columns\TextColumn::make('file_url')
                    ->label('URL')
                    ->url(fn($record) => $record->file_url, true)
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('size')
                    ->label('Size')
                    ->formatStateUsing(fn($state) => $state ? number_format($state / 1024, 2) . ' KB' : '-')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded At')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->slideOver()
                    ->modalWidth(MaxWidth::Medium),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()
                        ->slideOver()
                        ->modalWidth(MaxWidth::Medium),
                    Tables\Actions\DeleteAction::make(),
                ])
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
