<nav class="fixed left-0 top-16 bottom-0 z-10 w-14 bg-gray-100 dark:bg-gray-800">
    <x-nav-link-sidebar :href="route('users')" :active="request()->is('users*')">
        <i class="fa-solid fa-users"></i>
    </x-nav-link-sidebar>
</nav>
