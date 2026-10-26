<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a client's nutrition: weight, calories and macros.
 *
 * @property int $id
 * @property int $client_id
 * @property CarbonInterface $logged_on
 * @property string|null $weight
 * @property string $weight_unit
 * @property int|null $calories
 * @property int|null $protein
 * @property int|null $carbs
 * @property int|null $fat
 * @property string $source
 */
#[Fillable(['client_id', 'logged_on', 'weight', 'weight_unit', 'calories', 'protein', 'carbs', 'fat', 'source'])]
class NutritionLog extends Model
{
    public const SOURCES = [
        'manual' => 'Logged in Spotter',
        'macrofactor' => 'MacroFactor',
        'myfitnesspal' => 'MyFitnessPal',
        'loseit' => 'Lose It!',
        'csv' => 'Spreadsheet',
    ];

    protected function casts(): array
    {
        return ['logged_on' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }
}
