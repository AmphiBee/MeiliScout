{{-- <x-meiliscout::listing id="projects" /> : the whole listing (meiliscout_get_listing()) --}}
@props(['id', 'search' => true])
{!! meiliscout_get_listing($id, ['search' => filter_var($search, FILTER_VALIDATE_BOOL)]) !!}
