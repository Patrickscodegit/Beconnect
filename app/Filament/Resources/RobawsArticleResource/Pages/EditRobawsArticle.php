<?php

namespace App\Filament\Resources\RobawsArticleResource\Pages;

use App\Filament\Resources\RobawsArticleResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditRobawsArticle extends EditRecord
{
    protected static string $resource = RobawsArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('push_to_robaws')
                ->label('Push to Robaws')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Push article changes to Robaws?')
                ->form(function () {
                    $record = $this->record;
                    $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                    $pushableFields = $pushService->getPushableFields();
                    $changedFields = $pushService->getChangedFieldsSinceLastPush($record);
                    
                    $options = [];
                    $descriptions = [];
                    foreach ($pushableFields as $field) {
                        $options[$field['key']] = $field['label'];
                        $descriptions[$field['key']] = $field['robaws_field'] . ' (' . $field['group'] . ')';
                    }
                    
                    return [
                        Forms\Components\CheckboxList::make('fields_to_push')
                            ->label('Fields to Push')
                            ->options($options)
                            ->default($changedFields ?: array_keys($options))
                            ->required()
                            ->descriptions($descriptions)
                            ->columns(2)
                            ->helperText('Select which fields to push to Robaws. Changed fields are pre-selected.'),
                    ];
                })
                ->action(function (array $data) {
                    $pushService = app(\App\Services\Robaws\RobawsArticlePushService::class);
                    $result = $pushService->pushArticleToRobaws(
                        $this->record,
                        $data['fields_to_push'],
                        0,
                        true,
                        2
                    );
                    
                    if ($result['success']) {
                        $fieldsPushed = !empty($result['fields_pushed']) 
                            ? implode(', ', $result['fields_pushed']) 
                            : 'selected fields';
                        Notification::make()
                            ->title('Article pushed successfully')
                            ->body("Pushed {$fieldsPushed} for: {$this->record->article_name}")
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Push failed')
                            ->body($result['error'])
                            ->danger()
                            ->send();
                    }
                })
                ->visible(fn () => !empty($this->record->robaws_article_id)),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
    
    
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}

