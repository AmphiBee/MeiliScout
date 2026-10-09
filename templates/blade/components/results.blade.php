{{-- <x-meiliscout::results listing="projects" /> --}}
@props(['listing'])
{!! meiliscout_get_listing_part($listing, 'results') !!}
