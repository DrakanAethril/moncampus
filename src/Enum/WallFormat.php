<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a collaborative wall lays its cards out (design/design_handoff_murs_collaboratifs).
 *
 * The same lists and the same cards either way: « Colonnes » draws one column per list, « Grille »
 * pours every card into one masonry. The value is the wall's *default* view - whoever opens the
 * wall may switch for themselves without writing anything.
 */
enum WallFormat: string
{
    case Columns = 'columns';
    case Grid = 'grid';

    public function labelKey(): string
    {
        return match ($this) {
            self::Columns => 'wallFormatColumnsLabel',
            self::Grid => 'wallFormatGridLabel',
        };
    }

    /**
     * The lists a new wall starts with: a kanban's three for the columns, one bucket for the grid.
     *
     * @return list<string> translation keys
     */
    public function initialListKeys(): array
    {
        return match ($this) {
            self::Columns => ['wallInitialListTodo', 'wallInitialListDoing', 'wallInitialListDone'],
            self::Grid => ['wallInitialListCards'],
        };
    }
}
