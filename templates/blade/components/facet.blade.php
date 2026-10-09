{{-- <x-meiliscout::facet listing="projects" facet="type" /> --}}
@props(['listing', 'facet'])
{!! meiliscout_get_listing_part($listing, 'facet', ['facet' => $facet]) !!}
