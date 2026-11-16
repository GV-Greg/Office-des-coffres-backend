<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ItemIcon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Référentiel admin des icônes d'items (brief admin/content/brief-icones-items.md).
 * Les icônes ne sont jamais stockées sur ODC — seul l'ID Celsius est conservé, l'URL
 * CDN est construite à la volée. Mention de propriété Celsius visible dans la vue.
 */
class ItemIconController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $icons = ItemIcon::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('item_name', 'like', "%{$search}%")
                ->orWhere('item_name_plural', 'like', "%{$search}%")))
            ->orderBy('item_name')
            ->paginate(50)
            ->withQueryString();

        return view('item-icons.index', compact('icons', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            ...ItemIcon::nameRules(singular: (string) $request->input('item_name')),
            'game_item_id' => ['required', 'integer', 'min:1', 'unique:item_icons,game_item_id'],
        ]);

        ItemIcon::create($request->only('item_name', 'item_name_plural', 'game_item_id'));

        return back()->with('status', __('item_icons.created'));
    }

    public function update(Request $request, ItemIcon $icon): RedirectResponse
    {
        $request->validate([
            ...ItemIcon::nameRules(
                $icon->id,
                (string) $request->input('item_name', $icon->item_name),
                $request->has('item_name_plural') ? null : $icon->item_name_plural,
                partial: true,
            ),
            'game_item_id' => [
                'required', 'integer', 'min:1',
                'unique:item_icons,game_item_id,'.$icon->id,
            ],
        ]);

        $icon->update($request->only('item_name', 'item_name_plural', 'game_item_id'));

        return back()->with('status', __('item_icons.updated'));
    }

    public function destroy(ItemIcon $icon): RedirectResponse
    {
        $icon->delete();

        return back()->with('status', __('item_icons.deleted'));
    }
}
