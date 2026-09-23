<div class="facility">
    <strong>{{ $facility['name'] ?? '' }}</strong>
    @if (! empty($facility['address'])) <span>{{ $facility['address'] }}</span> @endif
    @if (! empty($facility['phone'])) <span>Tél. {{ $facility['phone'] }}</span> @endif
    @if (! empty($facility['email'])) <span>{{ $facility['email'] }}</span> @endif
</div>
