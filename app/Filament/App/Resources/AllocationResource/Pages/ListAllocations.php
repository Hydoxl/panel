<?php

namespace App\Filament\App\Resources\AllocationResource\Pages;

use App\Facades\Activity;
use App\Filament\App\Resources\AllocationResource;
use App\Models\Allocation;
use App\Models\Server;
use App\Services\Allocations\FindAssignableAllocationService;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\DetachAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

class ListAllocations extends ListRecords
{
    protected static string $resource = AllocationResource::class;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ip')
                    ->label('Address')
                    ->formatStateUsing(fn (Allocation $allocation) => $allocation->alias),
                TextColumn::make('alias')
                    ->hidden(),
                TextColumn::make('port'),
                TextInputColumn::make('notes')
                    ->label('Notes'),
                IconColumn::make('primary')
                    ->icon(fn ($state) => match ($state) {
                        true => 'tabler-star-filled',
                        default => 'tabler-star',
                    })
                    ->color(fn ($state) => match ($state) {
                        true => 'warning',
                        default => 'gray',
                    })
                    ->action(fn (Allocation $allocation) => Filament::getTenant()->update(['allocation_id' => $allocation->id]))
                    ->default(function (Allocation $allocation) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        return $allocation->id === $server->allocation_id;
                    })
                    ->label('Primary'),
            ])
            ->actions([
                DetachAction::make()
                    ->label('Delete')
                    ->icon('tabler-trash')
                    ->hidden(function (Allocation $allocation) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        return $allocation->id === $server->allocation_id;
                    })
                    ->action(function (Allocation $allocation) {

                        Allocation::query()->where('id', $allocation->id)->update([
                            'notes' => null,
                            'server_id' => null,
                        ]);

                        Activity::event('server:allocation.delete')
                            ->subject($allocation)
                            ->property('allocation', $allocation->toString())
                            ->log();
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('addAllocation')
                ->label(function () {
                    /** @var Server $server */
                    $server = Filament::getTenant();

                    return $server->allocations()->count() >= $server->allocation_limit ? 'Allocation Limit Reached' : 'Add Allocation';
                })
                ->hidden(fn () => !config('panel.client_features.allocations.enabled'))
                ->disabled(function () {
                    /** @var Server $server */
                    $server = Filament::getTenant();

                    return $server->allocations()->count() >= $server->allocation_limit;
                })
                ->color(function () {
                    /** @var Server $server */
                    $server = Filament::getTenant();

                    return $server->allocations()->count() >= $server->allocation_limit ? 'danger' : 'primary';
                })
                ->action(function (FindAssignableAllocationService $service) {
                    /** @var Server $server */
                    $server = Filament::getTenant();

                    $allocation = $service->handle($server);

                    Activity::event('server:allocation.create')
                        ->subject($allocation)
                        ->property('allocation', $allocation->toString())
                        ->log();
                }),
        ];
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
