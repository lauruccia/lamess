<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tickets;

use App\Domain\Access\Superadmin;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\EditTicket;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Ticket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Ticket di assistenza e reclami. */
class TicketResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'support.view';
    }

    protected static function managePermission(): string
    {
        return 'support.manage';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return Superadmin::is();
    }

    public static function canDelete($record): bool
    {
        return Superadmin::is();
    }

    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'ticket';

    protected static ?string $pluralModelLabel = 'Ticket e reclami';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $n = Ticket::where('status', Ticket::OPEN)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('last_message_at', 'desc')->columns([
            TextColumn::make('number')->label('Numero')->searchable(),
            TextColumn::make('category')->label('Categoria')->formatStateUsing(fn (string $state) => Ticket::CATEGORIES[$state] ?? $state)->badge(),
            TextColumn::make('subject')->label('Oggetto')->searchable()->limit(60),
            TextColumn::make('consultant.code')->label('Incaricato')->placeholder('—')->searchable(),
            TextColumn::make('status')->label('Stato')->badge()
                ->formatStateUsing(fn (string $state) => ['open' => 'Da rispondere', 'answered' => 'Risposto', 'closed' => 'Chiuso'][$state] ?? $state)
                ->color(fn (string $state) => ['open' => 'warning', 'answered' => 'info', 'closed' => 'gray'][$state] ?? 'gray'),
            TextColumn::make('assigned_to')->label('In carico a')->placeholder('—')->toggleable(),
            TextColumn::make('last_message_at')->label('Ultimo messaggio')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['open' => 'Da rispondere', 'answered' => 'Risposto', 'closed' => 'Chiuso']),
            SelectFilter::make('category')->label('Categoria')->options(Ticket::CATEGORIES),
        ])->recordUrl(fn (Ticket $t) => static::getUrl('view', ['record' => $t]));
    }

    public static function getPages(): array
    {
        return ['index' => ListTickets::route('/'), 'view' => ViewTicket::route('/{record}'), 'edit' => EditTicket::route('/{record}/edit')];
    }
}
