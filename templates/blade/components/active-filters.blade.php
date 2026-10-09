{{-- <x-meiliscout::active-filters listing="projects" /> --}}
@props(['listing'])
{!! meiliscout_get_listing_part($listing, 'active') !!}
