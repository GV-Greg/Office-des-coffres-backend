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
            ->when($search !== '', fn ($q) => $q->where('item_name', 'like', "%{$search}%"))
            ->orderBy('item_name')
            ->paginate(50)
            ->withQueryString();

        return view('item-icons.index', compact('icons', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'item_name'    => ['required', 'string', 'max:150', 'unique:item_icons,item_name'],
            'game_item_id' => ['required', 'integer', 'min:1', 'unique:item_icons,game_item_id'],
        ]);

        ItemIcon::create($request->only('item_name', 'game_item_id'));

        return back()->with('status', __('item_icons.created'));
    }

    public function update(Request $request, ItemIcon $icon): RedirectResponse
    {
        $request->validate([
            'game_item_id' => [
                'required', 'integer', 'min:1',
                'unique:item_icons,game_item_id,' . $icon->id,
            ],
        ]);

        $icon->update($request->only('game_item_id'));

        return back()->with('status', __('item_icons.updated'));
    }

    public function destroy(ItemIcon $icon): RedirectResponse
    {
        $icon->delete();

        return back()->with('status', __('item_icons.deleted'));
    }
}
