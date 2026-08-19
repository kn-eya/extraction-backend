<x-mail::message>
# {{ $notification->titre }}

{{ $notification->message }}

<x-mail::button :url="config('app.url')">
Voir l'application
</x-mail::button>

Merci,<br>
{{ config('app.name') }}
</x-mail::message>