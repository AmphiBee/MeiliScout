{{-- <x-meiliscout::pagination listing="projects" /> --}}
@props(['listing'])
{!! meiliscout_get_listing_part($listing, 'pagination') !!}
