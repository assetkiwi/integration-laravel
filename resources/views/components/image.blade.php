@if ($src)
    <img
        src="{{ $src }}"
        alt="{{ $altText }}"
        @if ($imgWidth)  width="{{ $imgWidth }}"   @endif
        @if ($imgHeight) height="{{ $imgHeight }}"  @endif
        @if ($class)     class="{{ $class }}"        @endif
    />
@endif
