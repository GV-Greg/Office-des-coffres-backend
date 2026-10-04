<?php

// Rétention des journaux applicatifs (brief admin/content/brief-politique-promesses.md §3) :
// /legal/privacy §5 promet des logs techniques « conservés 12 mois maximum, puis effacés ».
// Décision de Greg du 04/10/2026 : rotation quotidienne, 180 jours.
//
// Le test suit le canal par défaut jusqu'aux canaux qui écrivent réellement : un `single` ou un
// `stream` ajouté à la pile, ou un LOG_CHANNEL qui la contourne, ferait grandir un fichier sans
// limite — la promesse redeviendrait fausse en silence.

/** Canaux qui écrivent réellement, en dépliant les piles. */
function writingChannels(string $channel): array
{
    $config = config("logging.channels.{$channel}");

    if (($config['driver'] ?? null) !== 'stack') {
        return [$channel => $config];
    }

    return collect($config['channels'])
        ->flatMap(fn (string $child) => writingChannels($child))
        ->all();
}

test('les fichiers du stack par défaut tournent chaque jour et sont gardés 180 jours', function () {
    $writing = writingChannels('stack');

    expect($writing)->toHaveKey('daily');

    foreach ($writing as $name => $config) {
        expect($config['driver'])->toBe('daily', "canal « {$name} »")
            ->and($config['days'])->toBe(180, "canal « {$name} »");
    }
});

test('la durée de rétention tient la promesse de la politique (12 mois maximum)', function () {
    expect(config('logging.channels.daily.days'))->toBeGreaterThan(0)->toBeLessThanOrEqual(365);
});

test('le canal de .env.example passe par la rotation', function () {
    preg_match('/^LOG_CHANNEL=(\S+)/m', file_get_contents(base_path('.env.example')), $match);

    expect($match[1] ?? null)->not->toBeNull();

    foreach (writingChannels($match[1]) as $name => $config) {
        expect($config['driver'])->toBe('daily', "canal « {$name} »");
    }
});
