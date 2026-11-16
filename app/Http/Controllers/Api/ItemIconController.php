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
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('item_name', 'like', "%{$search}%")
                ->orWhere('item_name_plural', 'like', "%{$search}%")))
            ->orderBy('item_name')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'icons' => $icons->map(fn (ItemIcon $icon) => $this->present($icon)),
            'meta' => [
                'current_page' => $icons->currentPage(),
                'last_page' => $icons->lastPage(),
                'total' => $icons->total(),
            ],
        ]);
    }

    /** Crée une association item_name (+ pluriel facultatif) → game_item_id. (admin) */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            ...ItemIcon::nameRules(singular: (string) $request->input('item_name')),
            'game_item_id' => ['required', 'integer', 'min:1', 'unique:item_icons,game_item_id'],
        ]);

        $icon = ItemIcon::create($validated);

        return response()->json(['success' => true, 'icon' => $this->present($icon)], 201);
    }

    /**
     * Corrige une association existante : `item_name` (singulier), `item_name_plural` (absent = inchangé,
     * vide = retiré) et `game_item_id`. (admin)
     */
    public function update(Request $request, ItemIcon $icon): JsonResponse
    {
        $validated = $request->validate([
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
     * → { "success": true, "icons": { "Miches de pain": 50, "Sacs de maïs": 52 } }
     *
     * Seuls les items ayant une association sont inclus. 🔴 Chaque clé est le nom **tel que demandé**
     * (jamais celui enregistré) : le site retrouve ainsi ses propres noms dans la réponse, quelle que
     * soit la casse de son collage. Le rapprochement se fait en PHP (`ItemIcon::normalizeName`) sur
     * tout le référentiel, quelques centaines de lignes : un seul résultat, identique sur SQLite et
     * MariaDB.
     */
    public function lookup(Request $request): JsonResponse
    {
        $request->validate([
            'names' => ['required', 'array', 'max:200'],
            'names.*' => ['required', 'string', 'max:150'],
        ]);

        // Chaque ligne se retrouve sous son singulier ET sous son pluriel : le jeu écrit « 1 Kilo… » mais « 2 Kilos… ».
        $index = ItemIcon::query()
            ->get(['item_name', 'item_name_plural', 'game_item_id'])
            ->flatMap(fn (ItemIcon $icon) => collect($icon->names())
                ->mapWithKeys(fn (string $name) => [ItemIcon::normalizeName($name) => $icon->game_item_id]));

        $map = [];
        foreach (array_unique($request->input('names', [])) as $name) {
            $key = ItemIcon::normalizeName($name);
            if ($index->has($key)) {
                $map[$name] = $index[$key];
            }
        }

        // (object) : `{}` et non `[]` quand rien ne correspond.
        // Cache 24 h imposé par le brief (13/11/2026) : donnée de référence publique qui ne bouge presque jamais.
        return response()
            ->json(['success' => true, 'icons' => (object) $map])
            ->header('Cache-Control', 'public, max-age=86400, stale-while-revalidate=3600');
    }

    /** Représentation JSON d'une icône (id, item_name, item_name_plural, game_item_id, icon_url). */
    private function present(ItemIcon $icon): array
    {
        return [
            'id' => $icon->id,
            'item_name' => $icon->item_name,
            'item_name_plural' => $icon->item_name_plural,
            'game_item_id' => $icon->game_item_id,
            'icon_url' => $icon->iconUrl(),
        ];
    }
}
