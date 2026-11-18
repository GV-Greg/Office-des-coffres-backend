{{-- Page 503 du panneau d'administration (Blade). Remplace celle de Laravel pour y poser l'accès administrateur pendant une maintenance
     (Greg, 18/11/2026 : « un bouton pour contourner »). L'API, elle, répond en JSON (bootstrap/app.php) : cette vue ne la concerne pas.
     Le formulaire n'apparaît QUE pendant une maintenance (un 503 d'une autre origine n'a pas de secret à saisir). Le secret n'est
     jamais écrit dans la page : le navigateur ouvre `/{secret}`, adresse que Laravel reconnaît pour poser le cookie de contournement
     (Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance). Un secret faux n'est pas dit faux : l'adresse est
     inconnue, la même page revient — Laravel ne distingue pas, et c'est voulu. --}}
@php($maintenance = app()->isDownForMaintenance())
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $maintenance ? 'Maintenance en cours' : 'Service indisponible' }} — Office des Coffres</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
               font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background: #e2e8f0; color: #1e293b; }
        main { max-width: 32rem; text-align: center; }
        h1 { font-size: 1.75rem; margin: 0 0 .75rem; }
        p { line-height: 1.5; margin: 0 0 1rem; }
        details { margin-top: 1.5rem; font-size: .9rem; }
        summary { cursor: pointer; text-decoration: underline; }
        form { margin-top: .75rem; display: flex; flex-direction: column; align-items: center; gap: .5rem; }
        .row { display: flex; gap: .5rem; align-items: center; }
        input { width: 16rem; padding: .4rem .6rem; border: 1px solid #64748b; border-radius: .25rem; background: #fff; color: #0f172a; font: inherit; }
        button { padding: .4rem .9rem; border: 0; border-radius: .25rem; background: #334155; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        input:focus-visible, button:focus-visible, summary:focus-visible { outline: 3px solid #b45309; outline-offset: 2px; }
        small { color: #475569; }
        @media (prefers-color-scheme: dark) {
            body { background: #1e293b; color: #f1f5f9; }
            small { color: #cbd5e1; }
        }
    </style>
</head>
<body>
<main>
    <h1>{{ $maintenance ? 'Maintenance en cours' : 'Service indisponible' }}</h1>
    <p>
        {{ $maintenance
            ? "L'Office est momentanément fermé pour travaux. Revenez dans quelques instants."
            : 'Le service est momentanément indisponible. Réessayez dans quelques instants.' }}
    </p>

    @if($maintenance)
        <details>
            <summary>Accès administrateur</summary>
            <form id="maintenance-bypass" autocomplete="off">
                <label for="maintenance-secret">Secret de maintenance</label>
                <div class="row">
                    <input id="maintenance-secret" type="password" autocomplete="off" spellcheck="false" required
                           pattern="[A-Za-z0-9_\-]{16,128}" title="Lettres, chiffres, tiret et tiret bas (16 caractères au moins)">
                    <button type="submit">Entrer</button>
                </div>
                <small>Un secret incorrect ramène sur cette page.</small>
            </form>
        </details>
        <script>
            document.getElementById('maintenance-bypass').addEventListener('submit', function (event) {
                event.preventDefault();
                var secret = document.getElementById('maintenance-secret').value.trim();
                if (secret) { window.location.assign('/' + encodeURIComponent(secret)); }
            });
        </script>
    @endif
</main>
</body>
</html>
