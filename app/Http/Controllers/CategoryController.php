<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Models\Category;
use App\Models\Event;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CategoryController extends Controller
{
    public function store(StoreCategoryRequest $request, Event $event): RedirectResponse
    {
        $event->categories()->create([
            'name' => $request->validated('category_name'),
        ]);

        return to_route('events.show', $event)
            ->with('status', 'Category added successfully.');
    }

    public function destroy(Event $event, Category $category): RedirectResponse
    {
        DB::transaction(static function () use ($category): void {
            $lockedCategory = Category::query()->lockForUpdate()->findOrFail($category->getKey());

            if ($lockedCategory->competitions()->exists()) {
                throw ValidationException::withMessages([
                    'category' => 'Only empty categories can be deleted. This category still contains contests.',
                ]);
            }

            $lockedCategory->delete();
        });

        return to_route('events.show', $event)
            ->with('status', 'Category deleted successfully.');
    }
}
