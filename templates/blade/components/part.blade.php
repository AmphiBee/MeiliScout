{{-- <x-meiliscout::part listing="projects" part="sort" /> : one part (meiliscout_get_listing_part()) --}}
@props(['listing', 'part', 'facet' => null])
{!! meiliscout_get_listing_part($listing, $part, $facet === null ? [] : ['facet' => $facet]) !!}
