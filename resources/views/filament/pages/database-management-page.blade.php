<x-filament-panels::page>

    {{--  Styles  --}}
    <style>
        .custom-table, .custom-table th, .custom-table td {
            border: 1px solid #cecece !important;
        }
    </style>

    {{--  Display available dumps  --}}
    <x-filament::section icon="heroicon-o-arrow-down-on-square-stack" collapsible compact>
        <x-slot name="heading">
            Tárolt dump-ok
        </x-slot>
        <table class="table-fixed w-full rounded-lg border-collapse">
            @foreach(static::$dumpedFiles as $index => $dump)
                <tr>
                    <td class="px-4 py-2">{{ $dump }}</td>
                    <td class="px-4 py-2 text-right">
                        <x-filament::button
                                wire:click="download({{$index}})"
                                name="download-dump-{{$index}}"
                                icon="heroicon-o-arrow-down-tray">
                            Letöltés
                        </x-filament::button>
                    </td>
                </tr>
            @endforeach
        </table>
    </x-filament::section>

    {{--  Display the database schema as a table  --}}
    <x-filament::section icon="heroicon-o-table-cells" collapsible compact>
        <x-slot name="heading">
            Struktúra
        </x-slot>
        @foreach(static::$databaseSchema as $tableName => $columns)
            <table class="custom-table table-fixed w-full rounded-lg border-collapse border">
                <thead>
                <tr>
                    <th colspan="2" class="px-4 py-2 border">{{ $tableName }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($columns as $columnName => $columnType)
                    <tr>
                        <td class="px-4 py-2 border">{{ $columnType }}</td>
                        <td class="px-4 py-2 border">{{ $columnName }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    </x-filament::section>

</x-filament-panels::page>
