<?php

namespace App\Filament\Pages;

use App\Traits\DatabaseManagementTrait;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class DatabaseManagementPage extends Page
{
    use \App\Traits\DatabaseManagementTrait;

    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';
    protected static ?string $title = 'Adatbázis';
    protected static ?string $navigationLabel = 'Adatbázis';
    // protected ?string $heading = '';
    protected static string $view = 'filament.pages.database-management-page';
    protected static ?int $navigationSort = 3;
    protected static bool $shouldRegisterNavigation = true;
    protected static Collection $tableNames;
    protected static Collection $databaseSchema;
    protected static Collection $dumpedFiles;

    public function __construct()
    {
        static::$tableNames = static::getTableNames();
        static::$databaseSchema = static::getDatabaseSchema();
        static::$dumpedFiles = static::getDumpedFiles();
    }

    public static function canAccess(): bool
    {
        $emailAccess = in_array(auth()?->user()?->email, config('reported-exception.allowed.emails'));
        $idAccess = in_array(auth()?->user()?->id, config('reported-exception.allowed.userids'));
        return ($emailAccess || $idAccess);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::$shouldRegisterNavigation && static::canAccess();
    }

    /**
     * Downloads the dump file by its index in the Collection.
     * @param int $index
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function download(int $index): \Symfony\Component\HttpFoundation\Response
    {
        $path = static::$dumpedFiles[$index]?->getPathname();
        $name = static::$dumpedFiles[$index]?->getFilename();
        if ($path && is_file($path) && static::canAccess()) {
            return response()->download($path, $name);
        }
        return response('', 404);
    }

    /**
     * Returns an array of Filament Actions to be displayed at the top of the Page.
     * @return array|Action[]|\Filament\Actions\ActionGroup[]
     */
    protected function getHeaderActions(): array
    {
        /**
         * Construct the new dump form schema
         */
        $formSchema = [
            Section::make("Táblák kiválasztása")->compact()->columnSpanFull()->columns(1)->collapsible()->schema([
                CheckboxList::make('__tables')
                    ->label('')
                    ->columnSpanFull()
                    ->columns(3)
                    ->bulkToggleable()
                    ->options(static::$tableNames->mapWithKeys(fn(string $value, string $key) => [$value => $value])),
            ]),
        ];
        $formState = [
            '__tables' => [],
        ];
        foreach (static::$databaseSchema as $tableName => $columns) {
            $columnSelectOptions = [];
            $formState[$tableName] = [];
            foreach ($columns as $columnName => $columnType) {
                $columnSelectOptions[$columnName] = $columnName;
                $formState[$tableName][] = [
                    'original' => $columnName,
                    'renamed' => $columnName,
                ];
            }
            $formSchema[] = Section::make($tableName)->compact()->columnSpanFull()->columns(1)->collapsible()->collapsed()->schema([
                Repeater::make($tableName)->label('')->columnSpanFull()->columns(1)->reorderable(true)->addActionLabel('Új oszlop')->schema([
                    Group::make([
                        Select::make('original')->label('Eredeti')->options($columnSelectOptions)->required(),
                        TextInput::make('renamed')->label('Átnevezett')->maxLength(255),
                    ])->columnSpanFull()->columns(2),
                ]),
            ]);
        }

        /**
         * Return the final Actions
         */
        return [
            Action::make('create_dump')
                ->label('Új dump')
                ->icon('heroicon-o-bolt')
                ->color('success')
                ->modalWidth(\Filament\Support\Enums\MaxWidth::FiveExtraLarge)
                ->slideOver()
                ->stickyModalHeader()
                ->stickyModalFooter()
                ->form($formSchema)
                ->fillForm($formState)
                ->action(function (array $data) {
                    $tables = $data['__tables'];
                    $morphMap = [];
                    foreach ($data as $tableName => $tableMorphs) {
                        // Skip the table checkbox list field
                        if ($tableName === '__tables') {
                            continue;
                        }
                        // An empty morph map entry must be set to make the dumper skip the table
                        $morphMap[$tableName] = [];
                        if (!in_array($tableName, $tables)) {
                            continue;
                        }
                        // But if the table is selected, assign the correct morph map for it
                        foreach ($tableMorphs as $morphPair) {
                            $original = $morphPair['original'];
                            $renamed = $morphPair['renamed'];
                            $morphMap[$tableName][$original] = $renamed;
                        }
                    }
                    // Download
                    $path = static::saveDump($morphMap);
                    if (is_file($path)) {
                        return response()->download($path, basename($path));
                    } else {
                        return response('', 404);
                    }
                }),
        ];
    }

}
