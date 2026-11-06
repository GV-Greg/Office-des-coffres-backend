<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ItemIcon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Référentiel des icônes d'items (brief admin/content/brief-icones-items.md).
 * Les routes admin (index/store/update/destroy) exigent role:admin dans api.php.
 * L'endpoint public lookup() n'exige aucune authentification.
 */
class ItemIconController extends Controller
{
    /** Liste paginée des associations, avec recherche par item_name. (admin) */
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $icons = ItemIcon::query()
            ->when($search !== '', fn ($q) => $q->where('item_name', 'like', "%{$search}%"))
            ->orderBy('item_name')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'icons' => $icons->map(fn (ItemIcon $icon) => $this->present($icon)),
            'meta' => [
                'current_page' => $icons->currentPage(),
                'last_page'    => $icons->lastPage(),
                'total'        => $icons->total(),
            ],
        ]);
    }

    /** Crée une association item_name → game_item_id. (admin) */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_name'    => ['required', 'string', 'max:150', 'unique:item_icons,item_name'],
            'game_item_id' => ['required', 'integer', 'min:1', 'unique:item_icons,game_item_id'],
        ]);

        $icon = ItemIcon::create($validated);

        return response()->json(['success' => true, 'icon' => $this->present($icon)], 201);
    }

    /** Corrige le game_item_id d'une association existante. (admin) */
    public function update(Request $request, ItemIcon $icon): JsonResponse
    {
        $validated = $request->validate([
            'game_item_id' => [
                'required', 'integer', 'min:1',
                'unique:item_icons,game_item_id,' . $icon->id,
            ],
        ]);

        $icon->update($validated);

        return response()->json(['success' => true, 'icon' => $this->present($icon->fresh())]);
    }

    /** Supprime une association. L'item perdra son icône dans les exports futurs. (admin) */
    public function destroy(ItemIcon $icon): JsonResponse
    {
        $icon->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Résout les icônes d'une liste de noms d'items. (public, sans authentification)
     *
     * GET /api/v1/item-icons?names[]=Miches+de+pain&names[]=Sacs+de+maïs
     * → { "Miches de pain": 50, "Sacs de maïs": 52 }
     *
     * Seuls les items ayant une association sont inclus dans la réponse.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'names'   => ['required', 'array', 'max:200'],
            'names.*' => ['required', 'string', 'max:150'],
        ]);

        $names = $request->input('names', []);

        $map = ItemIcon::query()
            ->whereIn('item_name', $names)
            ->pluck('game_item_id', 'item_name');

        return response()->json(['success' => true, 'icons' => $map]);
    }

    /** Représentation JSON d'une icône (id, item_name, game_item_id, icon_url). */
    private function present(ItemIcon $icon): array
    {
        return [
            'id'           => $icon->id,
            'item_name'    => $icon->item_name,
            'game_item_id' => $icon->game_item_id,
            'icon_url'     => $icon->iconUrl(),
        ];
    }
}
