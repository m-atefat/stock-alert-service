@component('mail::message')
# Your price alert fired

**{{ $alert->symbol->label() }}** just crossed {{ $alert->direction->label() }} your target of **{{ $alert->target_price }}**.

- Triggered price: **{{ $alert->triggered_price }}**
- Triggered at: {{ $alert->triggered_at?->toDayDateTimeString() }}

This alert is now closed and will not fire again.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
