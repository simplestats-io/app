@if(config('app.app_type') !== 'saas')
<small>{{ config('app.name') }} self-hosted is free because our cloud customers fund its development. If you enjoy it, tell others about <a href="https://simplestats.io/?utm_source=self-hosted&utm_medium=email" target="_blank">{{ config('app.name') }} Cloud</a>.</small>

@endif
