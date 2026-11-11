<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A local copy of the website's category list.
 *
 * Seeded rather than free-typed so a month of writing does not produce
 * "Handloom", "handlooms" and "Hand Loom" as three different things. The export
 * bundle carries the NAME, so if the site's list moves on the mismatch is a
 * visible label rather than a silently wrong link.
 */
class CategorySeeder extends Seeder
{
    /** @var list<string> */
    private const CATEGORIES = [
        'Handloom & textiles',
        'Pottery & terracotta',
        'Food & beverage',
        'Metalwork',
        'Woodcraft',
        'Jewellery',
        'Agriculture',
        'Social enterprise',
        'Women in business',
        'Youth & education',
        'Health & wellbeing',
        'Arts & performance',
    ];

    public function run(): void
    {
        /*
         | A starting list, not a definition the database must follow.
         |
         | This runs on every deploy, and re-creating "missing" categories
         | brought back the ones an admin had deleted and duplicated the ones
         | they had renamed. Once any category exists, the list is theirs.
         */
        if (Category::query()->exists()) {
            $this->command?->info('Categories already set up — left as they are.');

            return;
        }

        foreach (self::CATEGORIES as $index => $name) {
            Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'sort_order' => $index, 'is_active' => true],
            );
        }

        $this->command?->info(sprintf('Seeded %d categories.', count(self::CATEGORIES)));
    }
}
