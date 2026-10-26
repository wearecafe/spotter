<?php

namespace Tests\Feature\Coaching;

use App\Models\InboxItem;
use App\Models\NutritionLog;
use App\Models\User;
use App\Services\NutritionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class NutritionTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;

    protected User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
        $this->coach = User::factory()->create();
        $this->client = User::factory()->clientOf($this->coach)->create(['name' => 'Maya Chen']);
    }

    public function test_client_logs_a_day_and_editing_it_updates_the_same_row(): void
    {
        $page = Livewire::actingAs($this->client)->test('pages::training.nutrition')
            ->set('weight', '64.2')
            ->set('calories', '1950')
            ->set('protein', '120')
            ->call('save')
            ->assertHasNoErrors();

        $page->set('calories', '2010')->call('save');

        $log = NutritionLog::sole();
        $this->assertSame([2010, 120, '64.2', 'manual'], [$log->calories, $log->protein, $log->weight, $log->source]);
        $this->assertTrue($log->logged_on->isToday());
    }

    public function test_future_days_and_empty_entries_are_rejected(): void
    {
        Livewire::actingAs($this->client)->test('pages::training.nutrition')
            ->set('date', today()->addDay()->toDateString())->set('calories', '2000')->call('save')->assertHasErrors('date')
            ->set('date', today()->toDateString())->set('calories', '')->call('save')->assertHasErrors('calories');

        $this->assertSame(0, NutritionLog::count());
    }

    public function test_coach_sets_targets_and_sees_averages(): void
    {
        foreach ([1900, 2000, 2400] as $i => $kcal) {
            NutritionLog::create(['client_id' => $this->client->id, 'logged_on' => today()->subDays($i), 'calories' => $kcal, 'protein' => 120]);
        }

        Livewire::actingAs($this->coach)->test('pages::coach.clients.nutrition', ['client' => $this->client])
            ->set('calories', '2000')->set('protein', '130')->set('carbs', '220')->set('fat', '65')
            ->assertSee('Macros add up to 1,985 kcal.')
            ->call('saveTargets')
            ->assertHasNoErrors()
            ->assertSee('2,100') // 7-day average
            ->assertSee('2 days within 10% of calories');

        $this->assertSame(2000, $this->client->fresh()->calorie_target);
        $this->actingAs($this->client->fresh())->get(route('training.nutrition'))->assertSee('daily target');
    }

    public function test_other_coaches_cannot_see_a_clients_nutrition(): void
    {
        $this->actingAs(User::factory()->create())->get(route('coach.clients.nutrition', $this->client))->assertForbidden();
    }

    public function test_imports_a_myfitnesspal_zip_summing_meals(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mfp').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('File-Export-2026-10-26/Nutrition-Summary-2026-10-01-to-2026-10-26.csv',
            "Date,Meal,Calories,Fat (g),Saturated Fat,Sodium (mg),Carbohydrates (g),Fiber,Sugar,Protein (g),Note\n"
            ."2026-10-24,Breakfast,520,18,5,400,60,5,10,30,\n2026-10-24,Dinner,900,30,9,700,95,7,8,55,\n2026-10-25,Lunch,\"1,100\",40,5,400,100,5,10,60,\n");
        $zip->addFromString('File-Export-2026-10-26/Measurement-Summary-2026-10-01-to-2026-10-26.csv', "Date,Weight\n2026-10-25,141.2\n");
        $zip->addFromString('File-Export-2026-10-26/Exercise-Summary-2026-10-01-to-2026-10-26.csv', "Date,Exercise,Calories\n2026-10-25,Running,300\n");
        $zip->close();

        Livewire::actingAs($this->client)->test('pages::training.nutrition')
            ->set('weightUnit', 'lb')
            ->set('importSource', 'myfitnesspal')
            ->set('importFile', UploadedFile::fake()->createWithContent('File-Export.zip', file_get_contents($path)))
            ->call('import')
            ->assertHasNoErrors();

        $day = NutritionLog::where('logged_on', '2026-10-24')->sole();
        $this->assertSame([1420, 85, 155, 48, 'myfitnesspal'], [$day->calories, $day->protein, $day->carbs, $day->fat, $day->source]);
        $next = NutritionLog::where('logged_on', '2026-10-25')->sole();
        $this->assertSame([1100, '141.2', 'lb'], [$next->calories, $next->weight, $next->weight_unit], 'Exercise calories are not added to food.');
    }

    public function test_imports_macrofactor_daily_rows_with_weight(): void
    {
        $csv = "Date,Expenditure,Trend Weight (kg),Weight (kg),Calories (kcal),Protein (g),Fat (g),Carbs (g)\n2026-10-25,2500,64.1,64.3,1980,130,60,210\n2026-10-26,2500,64.0,,2050,140,62,220\n";

        Livewire::actingAs($this->client)->test('pages::training.nutrition')
            ->set('importSource', 'macrofactor')
            ->set('importFile', UploadedFile::fake()->createWithContent('MacroFactor-export.csv', $csv))
            ->call('import')
            ->assertHasNoErrors();

        $this->assertSame(2, NutritionLog::count());
        $log = NutritionLog::where('logged_on', '2026-10-25')->sole();
        $this->assertSame(['64.3', 'kg', 1980, 210], [$log->weight, $log->weight_unit, $log->calories, $log->carbs]);
    }

    public function test_imports_a_lose_it_food_log_and_keeps_existing_weight(): void
    {
        NutritionLog::create(['client_id' => $this->client->id, 'logged_on' => '2026-10-25', 'weight' => 64.5, 'calories' => 1500, 'source' => 'manual']);
        $csv = "Date,Name,Type,Quantity,Units,Calories,Fat (g),Protein (g),Carbohydrates (g)\n10/25/2026,Oatmeal,Breakfast,1,Cup,300,6,10,54\n10/25/2026,Chicken,Lunch,6,Ounces,280,6,52,0\n";

        Livewire::actingAs($this->client)->test('pages::training.nutrition')
            ->set('importSource', 'loseit')
            ->set('importFile', UploadedFile::fake()->createWithContent('loseit-food-log.csv', $csv))
            ->call('import');

        $log = NutritionLog::sole();
        $this->assertSame([580, 62, 54, 12, '64.5', 'loseit'], [$log->calories, $log->protein, $log->carbs, $log->fat, $log->weight, $log->source]);
    }

    public function test_files_without_daily_totals_get_a_clear_error(): void
    {
        Livewire::actingAs($this->client)->test('pages::training.nutrition')
            ->set('importFile', UploadedFile::fake()->createWithContent('notes.csv', "Name,Note\nMaya,hello\n"))
            ->call('import')
            ->assertHasErrors('importFile');
    }

    public function test_parser_reads_common_date_and_number_formats(): void
    {
        $importer = app(NutritionImporter::class);

        $this->assertSame('2026-10-20', $importer->parseDate('20/10/2026'));
        $this->assertSame('2026-01-05', $importer->parseDate('1/5/2026'));
        $this->assertNull($importer->parseDate('2026-12-01'), 'Future dates are ignored.');
        $this->assertNull($importer->parseDate('13/13/2026'));

        $days = $importer->parseCsv("Date;Calories;Weight\n25.10.2026;1 980;64,2\n")['days'];
        $this->assertSame(['2026-10-25'], array_keys($days));
        $this->assertSame(64.2, $days['2026-10-25']['weight']);
    }

    public function test_check_in_drafts_mention_nutrition(): void
    {
        $this->client->update(['calorie_target' => 2000]);
        NutritionLog::create(['client_id' => $this->client->id, 'logged_on' => today(), 'calories' => 1800, 'protein' => 110]);

        Livewire::actingAs($this->client)->test('pages::training.check-in')->set('energy', 3)->set('stress', 3)->call('submit');

        $this->assertStringContainsString('1,800 kcal (target 2,000)', InboxItem::sole()->summary);
    }
}
