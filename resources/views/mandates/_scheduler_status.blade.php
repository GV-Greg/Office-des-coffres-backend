{{--
    Alerte du planificateur des mandats (fil admin/echanges/mandats-lot3, Q5-Q6).

    ⚠️ Incluse à DEUX endroits, qui n'ont pas le même rôle :
      - dashboard.blade.php : LE GARDE-FOU. Première page après connexion, Greg ne peut pas l'éviter.
      - mandates/_layout.blade.php : une COMMODITÉ. Si l'un des deux doit disparaître un jour, c'est
        celui-ci — jamais celui du tableau de bord.
    Elle ne dit rien quand tout va bien : seule une tâche en retard s'affiche, et elle est nommée.
--}}
@php($staleTasks = \App\Support\MandateHeartbeat::stale())
@if($staleTasks !== [])
    <div role="alert" class="mb-4 rounded border border-red-400 bg-red-900 p-3 text-sm text-red-100">
        <p class="font-semibold">{{ __('mandates.scheduler.stale_title') }}</p>
        <ul class="list-disc pl-5">
            @foreach($staleTasks as $task => $last)
                <li>{{ __('mandates.scheduler.stale_line', [
                    'task' => __('mandates.scheduler.tasks.'.$task),
                    'command' => \App\Support\MandateHeartbeat::TASKS[$task],
                    'last' => $last ? $last->setTimezone(config('mandates.timezone'))->format('d/m/Y H:i') : __('mandates.scheduler.never'),
                ]) }}</li>
            @endforeach
        </ul>
        <p class="text-xs">{{ __('mandates.scheduler.help', ['hours' => config('mandates.heartbeat_alert_hours')]) }}</p>
    </div>
@endif
