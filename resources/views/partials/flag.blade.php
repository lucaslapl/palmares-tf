@props(['country' => ''])

@php($flagUrl = country_flag_url((string) $country))
@if ($flagUrl !== null)
    <img class="flag" src="{{ $flagUrl }}" alt="{{ $country }}" title="{{ $country }}" loading="lazy"
         onerror="this.replaceWith(document.createTextNode(this.alt));">
@elseif ((string) $country !== '')
    <span class="flag-fallback">{{ $country }}</span>
@endif