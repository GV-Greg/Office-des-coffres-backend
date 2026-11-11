<?php

namespace App\Support;

use App\Exceptions\MandateRefusal;

/**
 * Forme du document de réglages d'inventaire (le même modèle que le navigateur gardait dans `inventory_cat_config`,
 * `inventory_custom_cats`, `inventory_visibility` et `inventory_export_*`). Seul garde de forme : rien n'est stocké
 * qui ne passe par ici. Un document est un objet JSON de clés connues ; toute clé inconnue est REFUSÉE (un champ libre
 * deviendrait un dépôt de texte arbitraire dans une base de joueurs), les tailles sont bornées.
 *
 *  - partagé (les trois niveaux) : custom, hiddenBuiltIn, order, exportColor, itemCategories ;
 *  - personnel seulement : exportQtyFormat, exportShowEcus, exportWrapper, visibility (le goût d'un joueur et l'état
 *    d'un collage, pas un réglage que se partagent les titulaires d'un poste).
 *
 * Les clés de catégories sont des slugs ; on ne les confronte PAS à la liste des catégories prédéfinies (elle vit dans le
 * code du site) : le frontend nettoie déjà les assignations orphelines.
 */
final class InventorySettingsDocument
{
    public const LEVELS = ['personal', 'city', 'province'];

    public const MAX_CUSTOM = 30;

    public const MAX_HIDDEN = 20;

    public const MAX_ORDER = 60;

    public const MAX_ITEMS = 3000;

    public const MAX_NAME = 190;

    public const MAX_BYTES = 262144;

    private const SLUG = '/^[a-z0-9_]{1,30}$/';

    private const SHARED = ['custom', 'hiddenBuiltIn', 'order', 'exportColor', 'itemCategories'];

    private const PERSONAL_ONLY = ['exportQtyFormat', 'exportShowEcus', 'exportWrapper', 'visibility'];

    /** @return list<string> */
    public static function allowedKeys(string $level): array
    {
        return $level === 'personal' ? [...self::SHARED, ...self::PERSONAL_ONLY] : self::SHARED;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed> le document normalisé (seules les clés connues, dans l'ordre d'écriture)
     *
     * @throws MandateRefusal
     */
    public static function validate(array $data, string $level): array
    {
        if (strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE)) > self::MAX_BYTES) {
            throw MandateRefusal::make('data', 'inventory.api.document_too_large');
        }
        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::allowedKeys($level), true)) {
                throw MandateRefusal::make('data', 'inventory.api.document_unknown_key', ['key' => (string) $key]);
            }
        }

        $out = [];
        if (array_key_exists('custom', $data)) {
            $out['custom'] = self::custom($data['custom']);
        }
        foreach (['hiddenBuiltIn' => self::MAX_HIDDEN, 'order' => self::MAX_ORDER] as $key => $max) {
            if (array_key_exists($key, $data)) {
                $out[$key] = self::slugList($data[$key], $key, $max);
            }
        }
        if (array_key_exists('exportColor', $data)) {
            $out['exportColor'] = is_string($data['exportColor']) && preg_match('/^[a-z]{3,12}$/', $data['exportColor'])
                ? $data['exportColor'] : self::malformed('exportColor');
        }
        if (array_key_exists('itemCategories', $data)) {
            $out['itemCategories'] = self::itemCategories($data['itemCategories']);
        }
        if ($level === 'personal') {
            $out += self::personalOnly($data);
        }

        return $out;
    }

    /** @return list<array{key: string, fr: string, en: string}> */
    private static function custom(mixed $value): array
    {
        $list = self::listOf($value, 'custom', self::MAX_CUSTOM);
        $out = [];
        $seen = [];
        foreach ($list as $i => $entry) {
            $path = "custom.$i";
            if (! is_array($entry) || array_diff(array_keys($entry), ['key', 'fr', 'en'])) {
                self::malformed($path);
            }
            $key = $entry['key'] ?? null;
            $fr = $entry['fr'] ?? null;
            $en = $entry['en'] ?? '';
            if (! is_string($key) || ! preg_match(self::SLUG, $key) || isset($seen[$key])) {
                self::malformed("$path.key");
            }
            if (! is_string($fr) || trim($fr) === '' || mb_strlen($fr) > 60) {
                self::malformed("$path.fr");
            }
            if (! is_string($en) || mb_strlen($en) > 60) {
                self::malformed("$path.en");
            }
            $seen[$key] = true;
            $out[] = ['key' => $key, 'fr' => trim($fr), 'en' => trim($en) === '' ? trim($fr) : trim($en)];
        }

        return $out;
    }

    /** @return list<string> */
    private static function slugList(mixed $value, string $path, int $max): array
    {
        $out = [];
        foreach (self::listOf($value, $path, $max) as $i => $slug) {
            if (! is_string($slug) || ! preg_match(self::SLUG, $slug)) {
                self::malformed("$path.$i");
            }
            $out[] = $slug;
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, string> nom d'objet → clé de catégorie */
    private static function itemCategories(mixed $value): array
    {
        if (! is_array($value)) {
            self::malformed('itemCategories');
        }
        if (count($value) > self::MAX_ITEMS) {
            throw MandateRefusal::make('data', 'inventory.api.document_too_many', ['path' => 'itemCategories', 'max' => self::MAX_ITEMS]);
        }
        $out = [];
        foreach ($value as $name => $category) {
            $name = (string) $name;
            if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
                self::malformed('itemCategories');
            }
            if (! is_string($category) || ! preg_match(self::SLUG, $category)) {
                self::malformed("itemCategories.$name");
            }
            $out[$name] = $category;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function personalOnly(array $data): array
    {
        $out = [];
        if (array_key_exists('exportQtyFormat', $data)) {
            $out['exportQtyFormat'] = in_array($data['exportQtyFormat'], ['prefix', 'suffix'], true) ? $data['exportQtyFormat'] : self::malformed('exportQtyFormat');
        }
        if (array_key_exists('exportShowEcus', $data)) {
            $out['exportShowEcus'] = is_bool($data['exportShowEcus']) ? $data['exportShowEcus'] : self::malformed('exportShowEcus');
        }
        if (array_key_exists('exportWrapper', $data)) {
            $out['exportWrapper'] = in_array($data['exportWrapper'], ['none', 'rp', 'quote'], true) ? $data['exportWrapper'] : self::malformed('exportWrapper');
        }
        if (array_key_exists('visibility', $data)) {
            $v = $data['visibility'];
            if (! is_array($v) || array_diff(array_keys($v), ['hiddenCategories', 'hiddenItems'])) {
                self::malformed('visibility');
            }
            $items = [];
            foreach (self::listOf($v['hiddenItems'] ?? [], 'visibility.hiddenItems', self::MAX_ITEMS) as $i => $name) {
                if (! is_string($name) || $name === '' || mb_strlen($name) > self::MAX_NAME) {
                    self::malformed("visibility.hiddenItems.$i");
                }
                $items[] = $name;
            }
            $out['visibility'] = [
                'hiddenCategories' => self::slugList($v['hiddenCategories'] ?? [], 'visibility.hiddenCategories', self::MAX_ORDER),
                'hiddenItems' => array_values(array_unique($items)),
            ];
        }

        return $out;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value, string $path, int $max): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            self::malformed($path);
        }
        if (count($value) > $max) {
            throw MandateRefusal::make('data', 'inventory.api.document_too_many', ['path' => $path, 'max' => $max]);
        }

        return $value;
    }

    private static function malformed(string $path): never
    {
        throw MandateRefusal::make('data', 'inventory.api.document_malformed', ['path' => $path]);
    }
}
