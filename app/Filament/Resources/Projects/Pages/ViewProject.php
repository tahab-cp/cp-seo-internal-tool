<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\Actions\ArchiveAction;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tasks')
                ->label('Tasks')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('tasks', ['record' => $record])),
            Action::make('pages')
                ->label('Pages')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('pages', ['record' => $record])),
            Action::make('keywords')
                ->label('Keywords')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('keywords', ['record' => $record])),
            Action::make('backlinks')
                ->label('Backlinks')
                ->icon(Heroicon::OutlinedLink)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('backlinks', ['record' => $record])),
            Action::make('content')
                ->label('Content')
                ->icon(Heroicon::OutlinedPencil)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('content', ['record' => $record])),
            Action::make('analytics')
                ->label('Analytics')
                ->icon(Heroicon::OutlinedChartBar)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('analytics', ['record' => $record])),
            Action::make('monthlyWork')
                ->label('Monthly work')
                ->icon(Heroicon::OutlinedLightBulb)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('monthly-work', ['record' => $record])),
            Action::make('reports')
                ->label('Reports')
                ->icon(Heroicon::OutlinedDocumentChartBar)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('reports', ['record' => $record])),
            Action::make('reportSections')
                ->label('Report sections')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->visible(fn (Project $record): bool => Gate::allows('manageReportSections', $record))
                ->url(fn (Project $record): string => ProjectResource::getUrl('report-sections', ['record' => $record])),
            Action::make('monthlyCycles')
                ->label('Monthly cycles')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (Project $record): string => ProjectResource::getUrl('monthly-cycles', ['record' => $record])),
            EditAction::make()
                ->hidden(fn (Project $record): bool => $record->trashed()),
            ArchiveAction::make(),
            RestoreAction::make()
                ->successNotificationTitle('Project restored'),
        ];
    }
}
