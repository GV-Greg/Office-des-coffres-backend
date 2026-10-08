<?php

namespace App\Console\Commands;

use App\Support\ModuleDataEncrypter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Contrôle de la clé des données de module (admin/strategies/donnees-utilisateur.md §5). À lancer
 * après TOUT changement de MODULE_DATA_KEY ou de MODULE_DATA_PREVIOUS_KEYS, après `config:cache`.
 *
 * Né de l'incident du 09/10/2026 : une clé collée sans son préfixe `base64:` a donné un « Server
 * Error » muet à la première inscription au Registre. Vérifie, sans jamais afficher une clé :
 *  1. la forme de la clé courante et des clés antérieures (ModuleDataEncrypter::fromConfig) ;
 *  2. un aller-retour de chiffrement ;
 *  3. que chaque donnée déjà chiffrée se relit avec les clés présentes — une clé perdue se voit ici,
 *     avant qu'une réécriture ne la rende irrattrapable.
 * Lecture seule : n'écrit rien.
 */
class CheckModuleData extends Command
{
    protected $signature = 'module-data:check';

    protected $description = 'Vérifie la clé des données de module (forme, aller-retour, relecture des données existantes).';

    /** Tables dont une colonne est chiffrée par EncryptedModuleData : table => colonne. */
    public const ENCRYPTED_COLUMNS = ['mine_reports' => 'payload'];

    public function handle(): int
    {
        try {
            $encrypter = ModuleDataEncrypter::fromConfig(config('module_data', []));
        } catch (RuntimeException $e) {
            $this->error(__('module_data.check.invalid', ['message' => $e->getMessage()]));

            return self::FAILURE;
        }

        $this->info(__('module_data.check.key_ok', ['cipher' => config('module_data.cipher', 'AES-256-CBC')]));
        $this->line(__('module_data.check.previous', ['count' => count($encrypter->getPreviousKeys())]));

        $probe = 'module-data:check '.bin2hex(random_bytes(8));
        try {
            $roundTrip = $encrypter->decrypt($encrypter->encrypt($probe, false), false) === $probe;
        } catch (Throwable) {
            $roundTrip = false;
        }
        if (! $roundTrip) {
            $this->error(__('module_data.check.roundtrip_ko'));

            return self::FAILURE;
        }
        $this->info(__('module_data.check.roundtrip_ok'));

        $failed = 0;
        $count = 0;
        foreach (self::ENCRYPTED_COLUMNS as $table => $column) {
            foreach (DB::table($table)->whereNotNull($column)->orderBy('id')->lazyById(200) as $row) {
                $count++;
                try {
                    $encrypter->decrypt($row->{$column}, false);
                } catch (Throwable) {
                    $failed++;
                }
            }
        }
        if ($failed > 0) {
            $this->error(__('module_data.check.data_ko', ['failed' => $failed, 'count' => $count]));

            return self::FAILURE;
        }
        $this->info(__('module_data.check.data_ok', ['count' => $count]));
        $this->info(__('module_data.check.done'));

        return self::SUCCESS;
    }
}
